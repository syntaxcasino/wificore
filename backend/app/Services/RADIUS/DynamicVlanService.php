<?php

namespace App\Services\RADIUS;

use App\Models\User;
use App\Services\TenantAwareService;
use App\Models\VlanManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dynamic VLAN Assignment Service
 * 
 * Manages VLAN assignment for users via RADIUS Tunnel-Private-Group-Id attribute.
 * Supports dynamic VLAN changes during active sessions (via CoA) and 
 * static VLAN assignment for new sessions.
 * 
 * RFC 2868 compliant Tunnel Attributes:
 * - Tunnel-Type (64): 13 = VLAN
 * - Tunnel-Medium-Type (65): 6 = IEEE-802
 * - Tunnel-Private-Group-Id (81): VLAN ID
 */
class DynamicVlanService extends TenantAwareService
{
    private VlanManager $vlanManager;
    private CoAService $coaService;

    public function __construct(
        ?VlanManager $vlanManager = null,
        ?CoAService $coaService = null
    ) {
        $this->vlanManager = $vlanManager ?? app(VlanManager::class);
        $this->coaService = $coaService ?? app(CoAService::class);
    }

    /**
     * Assign VLAN to user for future sessions
     * 
     * Updates radreply table with Tunnel attributes that will be
     * sent to the NAS during the next authentication.
     * 
     * @param User $user The user to assign VLAN
     * @param int $vlanId VLAN ID (1-4094)
     * @param string|null $reason Optional reason for assignment
     * @return VlanAssignmentResult Result of the assignment
     */
    public function assignVlanToUser(
        User $user,
        int $vlanId,
        ?string $reason = null
    ): VlanAssignmentResult {
        try {
            // Validate VLAN ID
            if (!$this->isValidVlanId($vlanId)) {
                return new VlanAssignmentResult(
                    success: false,
                    message: "Invalid VLAN ID: {$vlanId}. Must be between 1 and 4094."
                );
            }

            // Verify VLAN exists and is available in tenant
            if (!$this->vlanManager->vlanExists($vlanId)) {
                return new VlanAssignmentResult(
                    success: false,
                    message: "VLAN {$vlanId} not found or not available for this tenant."
                );
            }

            DB::beginTransaction();

            // Remove any existing VLAN assignments
            $this->clearVlanAttributes($user->username);

            // Insert RFC 2868 Tunnel attributes
            $this->setTunnelAttributes($user->username, $vlanId);

            // Log the assignment
            DB::table('user_vlan_assignments')->insert([
                'user_id' => $user->id,
                'vlan_id' => $vlanId,
                'assigned_at' => now(),
                'reason' => $reason ?? 'Manual assignment',
                'is_active' => true,
            ]);

            DB::commit();

            Log::info('VLAN assigned to user', [
                'user_id' => $user->id,
                'username' => $user->username,
                'vlan_id' => $vlanId,
                'reason' => $reason,
            ]);

            return new VlanAssignmentResult(
                success: true,
                message: "VLAN {$vlanId} assigned to {$user->username}. Will take effect on next authentication.",
                vlanId: $vlanId,
                effective: 'next_session'
            );

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to assign VLAN', [
                'user_id' => $user->id,
                'vlan_id' => $vlanId,
                'error' => $e->getMessage(),
            ]);

            return new VlanAssignmentResult(
                success: false,
                message: 'Failed to assign VLAN: ' . $e->getMessage()
            );
        }
    }

    /**
     * Change VLAN for active user session
     * 
     * Uses CoA to dynamically change VLAN without requiring reauthentication.
     * Requires CoA support on the NAS.
     * 
     * @param User $user The user
     * @param int $newVlanId New VLAN ID
     * @param string|null $reason Reason for change
     * @return VlanAssignmentResult Result with CoA status
     */
    public function changeActiveSessionVlan(
        User $user,
        int $newVlanId,
        ?string $reason = null
    ): VlanAssignmentResult {
        try {
            // Validate VLAN
            if (!$this->isValidVlanId($newVlanId)) {
                return new VlanAssignmentResult(
                    success: false,
                    message: "Invalid VLAN ID: {$newVlanId}"
                );
            }

            // Check if user has active session
            $activeSession = $this->getActiveSession($user->username);
            if (!$activeSession) {
                // No active session, just assign for future
                return $this->assignVlanToUser($user, $newVlanId, $reason);
            }

            // Use CoA to change VLAN dynamically
            $coaResult = $this->coaService->changeVlan(
                $user->username,
                $newVlanId,
                $activeSession['session_id'] ?? null
            );

            if ($coaResult->isSuccessful()) {
                // Update database for persistence
                DB::beginTransaction();
                
                $this->clearVlanAttributes($user->username);
                $this->setTunnelAttributes($user->username, $newVlanId);
                
                DB::table('user_vlan_assignments')
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
                
                DB::table('user_vlan_assignments')->insert([
                    'user_id' => $user->id,
                    'vlan_id' => $newVlanId,
                    'assigned_at' => now(),
                    'reason' => $reason ?? 'Dynamic VLAN change via CoA',
                    'is_active' => true,
                ]);
                
                DB::commit();

                Log::info('VLAN changed via CoA', [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'old_vlan' => $activeSession['vlan_id'] ?? null,
                    'new_vlan' => $newVlanId,
                ]);

                return new VlanAssignmentResult(
                    success: true,
                    message: "VLAN dynamically changed to {$newVlanId} for active session.",
                    vlanId: $newVlanId,
                    effective: 'immediate',
                    coaUsed: true
                );
            } else {
                // CoA failed, fall back to assigning for next session
                Log::warning('CoA VLAN change failed, falling back to next-session assignment', [
                    'user_id' => $user->id,
                    'coa_error' => $coaResult->message,
                ]);

                return $this->assignVlanToUser($user, $newVlanId, $reason);
            }

        } catch (\Exception $e) {
            Log::error('Failed to change active session VLAN', [
                'user_id' => $user->id,
                'vlan_id' => $newVlanId,
                'error' => $e->getMessage(),
            ]);

            return new VlanAssignmentResult(
                success: false,
                message: 'Failed to change VLAN: ' . $e->getMessage()
            );
        }
    }

    /**
     * Remove VLAN assignment from user
     * 
     * @param User $user The user
     * @return VlanAssignmentResult Result
     */
    public function removeVlanAssignment(User $user): VlanAssignmentResult
    {
        try {
            DB::beginTransaction();

            // Remove from radreply
            $this->clearVlanAttributes($user->username);

            // Deactivate in assignments table
            DB::table('user_vlan_assignments')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'removed_at' => now()]);

            DB::commit();

            Log::info('VLAN assignment removed from user', [
                'user_id' => $user->id,
                'username' => $user->username,
            ]);

            return new VlanAssignmentResult(
                success: true,
                message: "VLAN assignment removed from {$user->username}.",
                effective: 'next_session'
            );

        } catch (\Exception $e) {
            DB::rollBack();

            return new VlanAssignmentResult(
                success: false,
                message: 'Failed to remove VLAN: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get current VLAN assignment for user
     * 
     * @param User $user The user
     * @return array|null VLAN info or null if not assigned
     */
    public function getCurrentVlanAssignment(User $user): ?array
    {
        $assignment = DB::table('user_vlan_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('assigned_at')
            ->first();

        if (!$assignment) {
            return null;
        }

        return [
            'vlan_id' => $assignment->vlan_id,
            'assigned_at' => $assignment->assigned_at,
            'reason' => $assignment->reason,
        ];
    }

    /**
     * Bulk assign VLANs to multiple users
     * 
     * @param array $userIds Array of user IDs
     * @param int $vlanId VLAN ID
     * @param string|null $reason Reason for assignment
     * @return array Results by user
     */
    public function bulkAssignVlan(
        array $userIds,
        int $vlanId,
        ?string $reason = null
    ): array {
        $results = [];
        
        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                $results[$userId] = new VlanAssignmentResult(
                    success: false,
                    message: "User {$userId} not found"
                );
                continue;
            }

            $results[$userId] = $this->assignVlanToUser($user, $vlanId, $reason);
        }

        return $results;
    }

    /**
     * Auto-assign VLAN based on service plan
     * 
     * Automatically assigns appropriate VLAN based on user's subscription plan.
     * 
     * @param User $user The user
     * @return VlanAssignmentResult Result
     */
    public function autoAssignVlanByPlan(User $user): VlanAssignmentResult
    {
        try {
            // Get user's active subscription
            $subscription = $user->subscriptions()
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$subscription || !$subscription->plan) {
                return new VlanAssignmentResult(
                    success: false,
                    message: "No active subscription found for {$user->username}"
                );
            }

            $plan = $subscription->plan;
            $vlanId = $plan->default_vlan_id;

            if (!$vlanId) {
                return new VlanAssignmentResult(
                    success: false,
                    message: "Plan {$plan->name} has no default VLAN configured"
                );
            }

            return $this->assignVlanToUser(
                $user,
                $vlanId,
                "Auto-assigned via plan: {$plan->name}"
            );

        } catch (\Exception $e) {
            return new VlanAssignmentResult(
                success: false,
                message: 'Auto-assignment failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Validate VLAN ID is within valid range
     */
    private function isValidVlanId(int $vlanId): bool
    {
        return $vlanId >= 1 && $vlanId <= 4094;
    }

    /**
     * Clear existing VLAN tunnel attributes from radreply
     */
    private function clearVlanAttributes(string $username): void
    {
        $tunnelAttributes = [
            'Tunnel-Type',
            'Tunnel-Medium-Type',
            'Tunnel-Private-Group-Id',
        ];

        DB::table('radreply')
            ->where('username', $username)
            ->whereIn('attribute', $tunnelAttributes)
            ->delete();
    }

    /**
     * Set RFC 2868 tunnel attributes in radreply
     */
    private function setTunnelAttributes(string $username, int $vlanId): void
    {
        $attributes = [
            ['Tunnel-Type', 13],           // 13 = VLAN
            ['Tunnel-Medium-Type', 6],     // 6 = IEEE-802
            ['Tunnel-Private-Group-Id', $vlanId],
        ];

        foreach ($attributes as [$attr, $value]) {
            DB::table('radreply')->insert([
                'username' => $username,
                'attribute' => $attr,
                'op' => ':=',
                'value' => (string) $value,
            ]);
        }
    }

    /**
     * Get active RADIUS session for user
     */
    private function getActiveSession(string $username): ?array
    {
        $session = DB::table('radacct')
            ->where('username', $username)
            ->whereNull('acctstoptime')
            ->orderBy('acctstarttime', 'desc')
            ->first();

        if (!$session) {
            return null;
        }

        return [
            'session_id' => $session->acctsessionid,
            'nas_ip' => $session->nasipaddress,
            'vlan_id' => $this->getVlanFromSession($session),
        ];
    }

    /**
     * Extract VLAN ID from session if available
     */
    private function getVlanFromSession($session): ?int
    {
        // Check if tunnel attributes are stored in acct output
        // This would require custom FreeRADIUS configuration
        return null;
    }
}
