<?php

namespace App\Services;

use Dapphp\Radius\Radius;
use Illuminate\Support\Facades\Cache;

class RadiusService extends TenantAwareService
{
    /**
     * Singleton instance for connection reuse
     * PERFORMANCE: Reusing connection avoids overhead of creating new instance
     */
    private static ?Radius $radiusInstance = null;

    /**
     * Cache key for NAS IP to avoid repeated DNS lookups
     * PERFORMANCE: DNS lookup (gethostbyname) takes 10-50ms, cache avoids this
     */
    private const NAS_IP_CACHE_KEY = 'radius:nas_ip';
    private const NAS_IP_CACHE_TTL = 3600; // 1 hour

    public function __construct()
    {
        // Lazy initialization - connection created on first use
    }

    /**
     * Get or create RADIUS connection (singleton pattern)
     * PERFORMANCE: Reuses connection across multiple auth attempts in same request
     */
    private function getRadius(): Radius
    {
        if (self::$radiusInstance === null) {
            self::$radiusInstance = $this->createRadiusConnection();
        }
        return self::$radiusInstance;
    }

    /**
     * Create new RADIUS connection with all settings
     */
    private function createRadiusConnection(): Radius
    {
        $radius = new Radius();

        // Add server configuration — use config() so values survive config:cache
        $radius->setServer(
            config('radius.server_ip', 'wificore-freeradius')
        );

        $radius->setSecret(
            config('radius.secret', 'testing123')
        );

        $radius->setAuthenticationPort(
            (int) config('radius.auth_port', 1812)
        );

        // Set timeout - use shorter timeout for faster failure detection
        // Can be overridden via config for different environments
        $radius->setTimeout((int) config('radius.timeout', 2));

        // Use cached NAS IP to avoid DNS lookup overhead
        $radius->setNasIpAddress($this->getCachedNasIp());

        return $radius;
    }

    /**
     * Get NAS IP with caching to avoid DNS lookup overhead
     * PERFORMANCE: Caches for 1 hour, saves 10-50ms per request
     */
    private function getCachedNasIp(): string
    {
        return Cache::remember(self::NAS_IP_CACHE_KEY, self::NAS_IP_CACHE_TTL, function () {
            $ip = gethostbyname(gethostname());
            \Log::debug('RADIUS: Cached NAS IP', ['ip' => $ip]);
            return $ip ?: '127.0.0.1';
        });
    }

    /**
     * Clear cached NAS IP (useful if container IP changes)
     */
    public function clearNasIpCache(): void
    {
        Cache::forget(self::NAS_IP_CACHE_KEY);
        self::$radiusInstance = null;
        \Log::info('RADIUS: Cleared NAS IP cache');
    }

    /**
     * Authenticate user via RADIUS
     * 
     * NOTE: Schema lookup is handled automatically by PostgreSQL functions.
     * No need to set search_path - functions determine correct schema from username.
     * This provides high performance without connection state changes.
     * 
     * PERFORMANCE: Added detailed timing logs and connection singleton for speed.
     * Timeout is set to 2 seconds to prevent long delays.
     * 
     * @return array{success: bool, elapsed_ms: float} Returns auth result and timing
     */
    public function authenticateWithTiming(string $username, string $password): array
    {
        $startTime = microtime(true);
        $timeout = (int) config('radius.timeout', 2);
        
        try {
            \Log::info("RADIUS: Attempting authentication for user: {$username}", [
                'timeout' => $timeout,
                'server' => config('radius.server_ip', 'wificore-freeradius'),
            ]);
            
            // PERFORMANCE: Use singleton connection to avoid setup overhead
            $radius = $this->getRadius();
            
            // PostgreSQL functions automatically determine correct tenant schema
            $result = $radius->accessRequest($username, $password);
            
            $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($result === true) {
                \Log::info("RADIUS: Authentication successful for user: {$username}", [
                    'elapsed_ms' => $elapsedMs,
                ]);
                return ['success' => true, 'elapsed_ms' => $elapsedMs];
            } else {
                \Log::warning("RADIUS: Authentication failed for user: {$username}", [
                    'elapsed_ms' => $elapsedMs,
                ]);
                return ['success' => false, 'elapsed_ms' => $elapsedMs];
            }
        } catch (\Exception $e) {
            $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
            \Log::error("RADIUS error for user {$username}", [
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
                'timeout' => $timeout,
            ]);
            return ['success' => false, 'elapsed_ms' => $elapsedMs];
        }
    }

    /**
     * Legacy authenticate method for backward compatibility
     * Use authenticateWithTiming() for better performance and fallback support
     */
    public function authenticate(string $username, string $password): bool
    {
        $result = $this->authenticateWithTiming($username, $password);
        return $result['success'];
    }

    /**
     * Create new user in RADIUS (tenant-aware)
     * 
     * IMPORTANT: This method uses the current tenant context (search_path).
     * Ensure tenant context is set before calling this method.
     * 
     * SECURITY: By default, stores SHA-256 hashed password. Cleartext-Password
     * can be disabled via RADIUS_ALLOW_CLEARTEXT=false env variable.
     * NT-Password is always stored for MSCHAPv2 compatibility.
     * 
     * @param string $username
     * @param string $password
     * @param string|null $tenantSchemaName Optional tenant schema (if not set, uses current context)
     */
    public function createUser(string $username, string $password, ?string $tenantSchemaName = null): bool
    {
        try {
            // Set tenant schema if provided
            if ($tenantSchemaName) {
                $this->setTenantSchemaContext($tenantSchemaName);
            }
            
            \Log::info("RADIUS: Creating user in tenant schema: {$username}");
            
            // Get current search path for logging
            $searchPath = \DB::selectOne("SHOW search_path")->search_path ?? 'unknown';
            \Log::debug("RADIUS: Current search_path: {$searchPath}");
            
            // Check if cleartext passwords are allowed (for backward compatibility)
            $allowCleartext = config('radius.allow_cleartext', true);
            
            // Store SHA-256 hashed password (recommended security practice)
            $sha256Hash = hash('sha256', $password);
            \DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'SHA2-256-Password',
                'op' => ':=',
                'value' => $sha256Hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \Log::debug("RADIUS: Stored SHA-256 password hash for {$username}");
            
            // Optionally store cleartext for backward compatibility (to be deprecated)
            if ($allowCleartext) {
                \DB::table('radcheck')->insert([
                    'username' => $username,
                    'attribute' => 'Cleartext-Password',
                    'op' => ':=',
                    'value' => $password,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                \Log::warning("RADIUS: Stored cleartext password for {$username} - set RADIUS_ALLOW_CLEARTEXT=false to disable");
            }

            // Store NT-Password hash for CHAP/MSCHAP2 authentication
            // Without this, only PAP works — MSCHAP2 (most common PPPoE auth) fails
            $ntHash = strtoupper(hash('md4', mb_convert_encoding($password, 'UTF-16LE', 'UTF-8')));
            \DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'NT-Password',
                'op' => ':=',
                'value' => $ntHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Add default admin attributes (optional)
            \DB::table('radreply')->insert([
                [
                    'username' => $username,
                    'attribute' => 'Service-Type',
                    'op' => ':=',
                    'value' => 'Administrative-User',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
            
            \Log::info("RADIUS: User created successfully in tenant schema: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to create user {$username}: " . $e->getMessage());
            return false;
        } finally {
            // Reset to public schema if we changed it
            if ($tenantSchemaName) {
                \DB::statement("SET search_path TO public");
            }
        }
    }

    /**
     * Delete user from RADIUS
     */
    public function deleteUser(string $username): bool
    {
        try {
            \Log::info("RADIUS: Deleting user: {$username}");
            
            \DB::table('radcheck')->where('username', $username)->delete();
            \DB::table('radreply')->where('username', $username)->delete();
            
            \Log::info("RADIUS: User deleted successfully: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to delete user {$username}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update user password in RADIUS
     * 
     * SECURITY: Updates SHA-256 hash by default. Cleartext-Password is updated
     * only if RADIUS_ALLOW_CLEARTEXT is true (for backward compatibility).
     */
    public function updatePassword(string $username, string $newPassword): bool
    {
        try {
            \Log::info("RADIUS: Updating password for user: {$username}");
            
            // Check if cleartext passwords are allowed
            $allowCleartext = env('RADIUS_ALLOW_CLEARTEXT', true);
            
            // Update SHA-256 hashed password (primary)
            $sha256Hash = hash('sha256', $newPassword);
            \DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'SHA2-256-Password'],
                ['op' => ':=', 'value' => $sha256Hash, 'updated_at' => now()]
            );
            \Log::debug("RADIUS: Updated SHA-256 password hash for {$username}");
            
            // Optionally update cleartext for backward compatibility
            if ($allowCleartext) {
                \DB::table('radcheck')
                    ->where('username', $username)
                    ->where('attribute', 'Cleartext-Password')
                    ->update(['value' => $newPassword]);
            }

            // Update NT-Password hash for CHAP/MSCHAP2 compatibility
            $ntHash = strtoupper(hash('md4', mb_convert_encoding($newPassword, 'UTF-16LE', 'UTF-8')));
            \DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'NT-Password'],
                ['op' => ':=', 'value' => $ntHash, 'updated_at' => now()]
            );
            
            \Log::info("RADIUS: Password updated successfully: {$username}");
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS: Failed to update password for {$username}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Enforce PPPoE authentication and update RADIUS auth type
     */
    public function enforceAuthentication(string $username): bool
    {
        if (!$this->authenticate($username, '')) {
            \Log::warning("RADIUS: User {$username} failed authentication, blocking access.");
            $this->updateRADIUSAuth($username, 'Reject');
            return false;
        }
        \Log::info("RADIUS: User {$username} authenticated successfully, allowing access.");
        $this->updateRADIUSAuth($username, 'Accept');
        return true;
    }

    /**
     * Update RADIUS auth type
     */
    private function updateRADIUSAuth(string $username, string $authType): bool
    {
        try {
            $exists = \DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Auth-Type')
                ->exists();

            if ($exists) {
                \DB::table('radcheck')
                    ->where('username', $username)
                    ->where('attribute', 'Auth-Type')
                    ->update(['value' => $authType]);
            } else {
                \DB::table('radcheck')->insert([
                    'username' => $username,
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => $authType,
                ]);
            }

            \Log::info("RADIUS auth updated", [
                'username' => $username,
                'auth_type' => $authType,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to update RADIUS auth", [
                'username' => $username,
                'auth_type' => $authType,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send RADIUS CoA (Change of Authorization) request
     * 
     * CoA allows changing user authorization in real-time without
     * requiring the user to re-authenticate. Used for:
     * - Disconnecting users (PoD - Packet of Disconnect)
     * - Changing bandwidth limits
     * - Updating session timeouts
     * 
     * @param string $username User to target
     * @param string $action 'disconnect', 'update'
     * @param array $attributes Additional RADIUS attributes to send
     * @return bool Success status
     */
    public function sendCoA(string $username, string $action = 'disconnect', array $attributes = []): bool
    {
        try {
            // Get CoA server configuration
            $coaServer = env('RADIUS_COA_SERVER', env('RADIUS_SERVER_HOST', 'wificore-freeradius'));
            $coaPort = (int) env('RADIUS_COA_PORT', 3799);
            $coaSecret = env('RADIUS_COA_SECRET', env('RADIUS_SECRET', 'testing123'));
            
            // Build CoA request using radclient or custom socket
            // For production, consider using a dedicated CoA library
            $result = $this->sendCoAPacket($coaServer, $coaPort, $coaSecret, $username, $action, $attributes);
            
            if ($result) {
                \Log::info("RADIUS CoA: {$action} successful for {$username}");
            } else {
                \Log::warning("RADIUS CoA: {$action} failed for {$username}");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS CoA error for {$username}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Disconnect a user via RADIUS CoA (PoD - Packet of Disconnect)
     * 
     * This is more graceful than SSH session termination as it:
     * 1. Notifies the router via RADIUS
     * 2. Router sends PPPoE PADT or Hotspot logout
     * 3. Clean session termination with accounting stop
     * 
     * @param string $username User to disconnect
     * @param string $nasIpAddress NAS (router) IP address
     * @param string|null $sessionId Optional session ID for specific session
     * @return bool Success status
     */
    public function disconnectUser(string $username, string $nasIpAddress, ?string $sessionId = null): bool
    {
        $attributes = [
            'NAS-IP-Address' => $nasIpAddress,
            'User-Name' => $username,
        ];
        
        if ($sessionId) {
            $attributes['Acct-Session-Id'] = $sessionId;
        }
        
        return $this->sendCoA($username, 'disconnect', $attributes);
    }
    
    /**
     * Send CoA packet using radclient or socket
     * 
     * @param string $server RADIUS server IP
     * @param int $port CoA port (usually 3799)
     * @param string $secret Shared secret
     * @param string $username Target username
     * @param string $action Action type
     * @param array $attributes RADIUS attributes
     * @return bool Success status
     */
    private function sendCoAPacket(string $server, int $port, string $secret, string $username, string $action, array $attributes): bool
    {
        // Method 1: Use radclient CLI if available
        $radclientPath = env('RADIUS_RADCLIENT_PATH', '/usr/bin/radclient');
        if (file_exists($radclientPath)) {
            return $this->sendCoAViaRadclient($radclientPath, $server, $port, $secret, $username, $action, $attributes);
        }
        
        // Method 2: Use socket (simplified implementation)
        \Log::warning("RADIUS CoA: radclient not found at {$radclientPath}, CoA via socket not implemented");
        return false;
    }
    
    /**
     * Send CoA using radclient CLI
     */
    private function sendCoAViaRadclient(string $radclientPath, string $server, int $port, string $secret, string $username, string $action, array $attributes): bool
    {
        try {
            // Build attributes string
            $attrs = "User-Name={$username}\n";
            foreach ($attributes as $key => $value) {
                $attrs .= "{$key}={$value}\n";
            }
            
            // Determine CoA code
            $coaCode = ($action === 'disconnect') ? '40' : '43'; // 40=Disconnect, 43=CoA-Request
            
            // Build radclient command
            $cmd = sprintf(
                'echo %s | %s -x %s:%d %s %s 2>&1',
                escapeshellarg($attrs),
                escapeshellarg($radclientPath),
                escapeshellarg($server),
                $port,
                $coaCode,
                escapeshellarg($secret)
            );
            
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            
            $outputStr = implode("\n", $output);
            
            if ($returnCode === 0 && str_contains($outputStr, 'code 44')) {
                // Code 44 = CoA-ACK (success)
                return true;
            }
            
            \Log::warning("RADIUS CoA radclient failed", [
                'cmd' => $cmd,
                'return_code' => $returnCode,
                'output' => $outputStr,
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            \Log::error("RADIUS CoA radclient error: " . $e->getMessage());
            return false;
        }
    }
}
