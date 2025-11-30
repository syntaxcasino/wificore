<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use App\Models\ServiceControlLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RADIUSServiceController extends TenantAwareService
{
    /**
     * Disconnect user from RADIUS
     * 
     * @param User $user
     * @param string $reason
     * @return bool
     */
    public function disconnectUser(User $user, string $reason): bool
    {
        try {
            // Update radcheck to reject authentication
            $this->updateRADIUSAuth($user->email, 'Reject');
            
            // Terminate active sessions
            $this->terminateActiveSessions($user);
            
            // Log the action
            $this->logServiceControl($user, 'disconnect', $reason, 'completed');
            
            Log::info("User disconnected from RADIUS", [
                'user_id' => $user->id,
                'username' => $user->email,
                'reason' => $reason,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to disconnect user from RADIUS", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logServiceControl($user, 'disconnect', $reason, 'failed');
            
            return false;
        }
    }

    /**
     * Reconnect user to RADIUS
     * 
     * @param User $user
     * @return bool
     */
    public function reconnectUser(User $user): bool
    {
        try {
            // Update radcheck to accept authentication
            $this->updateRADIUSAuth($user->email, 'Accept');
            
            // Log the action
            $this->logServiceControl($user, 'reconnect', 'Payment received', 'completed');
            
            Log::info("User reconnected to RADIUS", [
                'user_id' => $user->id,
                'username' => $user->email,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reconnect user to RADIUS", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logServiceControl($user, 'reconnect', 'Payment received', 'failed');
            
            return false;
        }
    }

    /**
     * Update RADIUS authentication status
     * 
     * @param string $username
     * @param string $authType
     * @return bool
     */
    private function updateRADIUSAuth(string $username, string $authType): bool
    {
        try {
            // Check if Auth-Type entry exists
            $exists = DB::connection('radius')->table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Auth-Type')
                ->exists();
            
            if ($exists) {
                // Update existing entry
                DB::connection('radius')->table('radcheck')
                    ->where('username', $username)
                    ->where('attribute', 'Auth-Type')
                    ->update(['value' => $authType]);
            } else {
                // Insert new entry
                DB::connection('radius')->table('radcheck')->insert([
                    'username' => $username,
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => $authType,
                ]);
            }
            
            Log::info("RADIUS auth updated", [
                'username' => $username,
                'auth_type' => $authType,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to update RADIUS auth", [
                'username' => $username,
                'auth_type' => $authType,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Terminate active sessions
     * 
     * @param User $user
     * @return void
     */
    private function terminateActiveSessions(User $user): void
    {
        try {
            $activeSessions = $this->getActiveSessions($user);
            
            foreach ($activeSessions as $session) {
                $this->sendCoADisconnect($session);
            }
            
            Log::info("Active sessions terminated", [
                'user_id' => $user->id,
                'sessions_count' => count($activeSessions),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to terminate active sessions", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send CoA Disconnect-Request
     * 
     * @param array $session
     * @return bool
     */
    private function sendCoADisconnect(array $session): bool
    {
        // TODO: Implement actual CoA disconnect using RADIUS protocol
        // This requires php-radius extension or custom implementation
        
        Log::info("CoA disconnect sent (placeholder)", [
            'username' => $session['username'] ?? 'unknown',
            'session_id' => $session['acctsessionid'] ?? 'unknown',
        ]);
        
        return true;
    }

    /**
     * Get active RADIUS sessions for user
     * 
     * @param User $user
     * @return array
     */
    private function getActiveSessions(User $user): array
    {
        try {
            $sessions = DB::connection('radius')->table('radacct')
                ->where('username', $user->email)
                ->whereNull('acctstoptime')
                ->get()
                ->toArray();
            
            return array_map(function($session) {
                return (array) $session;
            }, $sessions);
        } catch (\Exception $e) {
            Log::error("Failed to get active sessions", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }
    }

    /**
     * Log service control action
     * 
     * @param User $user
     * @param string $action
     * @param string $reason
     * @param string $status
     * @return void
     */
    private function logServiceControl(User $user, string $action, string $reason, string $status): void
    {
        try {
            $subscription = $user->subscriptions()->latest()->first();
            
            ServiceControlLog::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id ?? null,
                'action' => $action,
                'reason' => $reason,
                'status' => $status,
                'executed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to log service control action", [
                'user_id' => $user->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if user has active RADIUS sessions
     * 
     * @param User $user
     * @return bool
     */
    public function hasActiveSessions(User $user): bool
    {
        return count($this->getActiveSessions($user)) > 0;
    }

    /**
     * Get session count for user
     * 
     * @param User $user
     * @return int
     */
    public function getSessionCount(User $user): int
    {
        return count($this->getActiveSessions($user));
    }
}
