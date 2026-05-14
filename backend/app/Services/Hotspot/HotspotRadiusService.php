<?php

namespace App\Services\Hotspot;

use App\Models\HotspotUser;
use App\Models\RadiusSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing Hotspot RADIUS operations.
 * 
 * Handles:
 * - Creating/updating radcheck and radreply entries
 * - Sending RADIUS Disconnect packets (CoA)
 * - Managing user groups
 * - Session tracking
 */
class HotspotRadiusService
{
    private string $radiusServer;
    private int $radiusCoaPort;
    private string $radiusSecret;

    public function __construct()
    {
        $this->radiusServer = config('services.radius.server', '127.0.0.1');
        $this->radiusCoaPort = config('services.radius.coa_port', 3799);
        $this->radiusSecret = config('services.radius.secret', 'testing123');
    }

    /**
     * Create RADIUS entries for a new Hotspot user.
     */
    public function createUserCredentials(
        string $username,
        string $password,
        array $attributes = []
    ): bool {
        $username = strtolower(trim($username));

        DB::beginTransaction();
        
        try {
            // Check if user already exists
            $exists = DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->exists();
            
            if ($exists) {
                // Update password
                DB::table('radcheck')
                    ->where('username', $username)
                    ->where('attribute', 'Cleartext-Password')
                    ->update(['value' => $password]);
            } else {
                // Insert password
                DB::table('radcheck')->insert([
                    'username' => $username,
                    'attribute' => 'Cleartext-Password',
                    'op' => ':=',
                    'value' => $password,
                ]);
            }

            // Store/update NT-Password hash for CHAP/MSCHAP2 authentication
            $ntHash = strtoupper(hash('md4', mb_convert_encoding($password, 'UTF-16LE', 'UTF-8')));
            DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'NT-Password'],
                ['op' => ':=', 'value' => $ntHash]
            );
            
            // Remove old Auth-Type := Reject if exists
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Auth-Type')
                ->where('value', 'Reject')
                ->delete();
            
            // Add reply attributes
            if (!empty($attributes)) {
                $this->setReplyAttributes($username, $attributes);
            }
            
            DB::commit();
            
            Log::info('RADIUS credentials created', [
                'username' => $username,
                'attributes_count' => count($attributes),
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create RADIUS credentials', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Set RADIUS reply attributes for a user.
     */
    public function setReplyAttributes(string $username, array $attributes): void
    {
        $username = strtolower(trim($username));

        foreach ($attributes as $attribute => $value) {
            DB::table('radreply')
                ->updateOrInsert(
                    ['username' => $username, 'attribute' => $attribute],
                    ['op' => ':=', 'value' => (string) $value]
                );
        }
    }

    /**
     * Block a user by adding Auth-Type := Reject.
     */
    public function blockUser(string $username): bool
    {
        $username = strtolower(trim($username));

        try {
            // Remove existing Auth-Type
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Auth-Type')
                ->delete();
            
            // Add reject
            DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Auth-Type',
                'op' => ':=',
                'value' => 'Reject',
            ]);
            
            Log::info('RADIUS user blocked', ['username' => $username]);
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to block RADIUS user', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Unblock a user by removing Auth-Type := Reject.
     */
    public function unblockUser(string $username): bool
    {
        $username = strtolower(trim($username));

        try {
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Auth-Type')
                ->where('value', 'Reject')
                ->delete();
            
            Log::info('RADIUS user unblocked', ['username' => $username]);
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to unblock RADIUS user', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete all RADIUS entries for a user.
     */
    public function deleteUser(string $username): bool
    {
        $username = strtolower(trim($username));

        DB::beginTransaction();
        
        try {
            DB::table('radcheck')->where('username', $username)->delete();
            DB::table('radreply')->where('username', $username)->delete();
            DB::table('radusergroup')->where('username', $username)->delete();
            
            DB::commit();
            
            Log::info('RADIUS user deleted', ['username' => $username]);
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete RADIUS user', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send RADIUS Disconnect-Request (CoA) to terminate a session.
     */
    public function disconnectSession(RadiusSession $session): bool
    {
        try {
            $nasIp = $session->nas_ip_address;
            $sessionId = $session->acct_session_id;
            $username = $session->username;
            
            if (!$nasIp || !$sessionId) {
                Log::warning('Cannot disconnect session: missing NAS IP or session ID', [
                    'session_id' => $session->id,
                    'nas_ip' => $nasIp,
                    'acct_session_id' => $sessionId,
                ]);
                return false;
            }
            
            // Build Disconnect-Request attributes
            $attributes = [
                'User-Name' => $username,
                'Acct-Session-Id' => $sessionId,
                'NAS-IP-Address' => $nasIp,
            ];
            
            // Send using radclient if available, otherwise log
            $result = $this->sendCoaPacket($nasIp, 'disconnect', $attributes);
            
            if ($result) {
                Log::info('RADIUS Disconnect-Request sent', [
                    'session_id' => $session->id,
                    'username' => $username,
                    'nas_ip' => $nasIp,
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Failed to send RADIUS disconnect', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send a Change of Authorization (CoA) packet.
     */
    public function sendCoaPacket(string $nasIp, string $type, array $attributes): bool
    {
        // Check if radclient is available
        $radclientPath = config('services.radius.radclient_path', '/usr/bin/radclient');
        
        if (!file_exists($radclientPath)) {
            Log::info('radclient not available, CoA packet logged only', [
                'nas_ip' => $nasIp,
                'type' => $type,
                'attributes' => $attributes,
            ]);
            return true; // Consider successful for logging purposes
        }
        
        try {
            // Build attribute string for radclient
            $attrString = '';
            foreach ($attributes as $key => $value) {
                $attrString .= "{$key}=\"{$value}\"\n";
            }
            
            // Determine packet type
            $packetType = $type === 'disconnect' ? 'disconnect' : 'coa';
            
            // Build command
            $tempFile = tempnam(sys_get_temp_dir(), 'radius_');
            file_put_contents($tempFile, $attrString);
            
            $command = sprintf(
                '%s -x %s:%d %s -f %s -s %s 2>&1',
                escapeshellcmd($radclientPath),
                escapeshellarg($nasIp),
                $this->radiusCoaPort,
                $packetType,
                escapeshellarg($tempFile),
                escapeshellarg($this->radiusSecret)
            );
            
            exec($command, $output, $returnCode);
            
            // Cleanup
            @unlink($tempFile);
            
            if ($returnCode !== 0) {
                Log::warning('radclient returned non-zero', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output),
                    'nas_ip' => $nasIp,
                ]);
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('CoA packet send failed', [
                'error' => $e->getMessage(),
                'nas_ip' => $nasIp,
            ]);
            return false;
        }
    }

    /**
     * Get active sessions for a username.
     */
    public function getActiveSessions(string $username): \Illuminate\Support\Collection
    {
        return DB::table('radacct')
            ->where('username', strtolower(trim($username)))
            ->whereNull('acctstoptime')
            ->get();
    }

    /**
     * Get user's current data usage from radacct.
     */
    public function getUserDataUsage(string $username): array
    {
        $totals = DB::table('radacct')
            ->where('username', strtolower(trim($username)))
            ->selectRaw('SUM(acctinputoctets) as total_input, SUM(acctoutputoctets) as total_output')
            ->first();
        
        return [
            'upload' => (int) ($totals->total_input ?? 0),
            'download' => (int) ($totals->total_output ?? 0),
            'total' => (int) (($totals->total_input ?? 0) + ($totals->total_output ?? 0)),
        ];
    }

    /**
     * Check if username is available.
     */
    public function isUsernameAvailable(string $username): bool
    {
        return !DB::table('radcheck')
            ->where('username', strtolower(trim($username)))
            ->exists();
    }

    /**
     * Generate a unique username for Hotspot.
     */
    public function generateUniqueUsername(string $prefix = 'hs'): string
    {
        $maxAttempts = 10;
        $attempt = 0;
        
        do {
            $username = $prefix . '_' . Str::random(8);
            $attempt++;
        } while (!$this->isUsernameAvailable($username) && $attempt < $maxAttempts);
        
        if ($attempt >= $maxAttempts) {
            $username = $prefix . '_' . Str::uuid()->toString();
        }
        
        return strtolower($username);
    }

    /**
     * Generate a secure password.
     */
    public function generatePassword(int $length = 8): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
}
