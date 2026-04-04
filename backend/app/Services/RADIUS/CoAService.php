<?php

namespace App\Services\RADIUS;

use App\Models\Router;
use App\Models\User;
use Dapphp\Radius\Radius;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RADIUS Change of Authorization (CoA) Service
 * 
 * Implements RFC 5176 for dynamic session modifications:
 * - CoA-Request: Modify active session attributes (bandwidth, timeout)
 * - Disconnect-Request: Terminate active sessions immediately
 * 
 * @see https://github.com/dapphp/radius/blob/master/example/CoA-Disconnect.php
 */
class CoAService
{
    private string $radiusServer;
    private int $radiusCoaPort;
    private string $radiusSecret;
    private int $timeout;
    private int $maxRetries;

    public function __construct(
        ?string $radiusServer = null,
        ?int $radiusCoaPort = null,
        ?string $radiusSecret = null
    ) {
        $this->radiusServer = $radiusServer ?? config('services.radius.server', '127.0.0.1');
        $this->radiusCoaPort = $radiusCoaPort ?? config('services.radius.coa_port', 3799);
        $this->radiusSecret = $radiusSecret ?? config('services.radius.secret', 'testing123');
        $this->timeout = config('services.radius.coa_timeout', 5);
        $this->maxRetries = config('services.radius.coa_retries', 3);
    }

    /**
     * Change bandwidth for an active user session
     * 
     * @param string $username The username of the session to modify
     * @param string $rateLimit New rate limit (e.g., "10M/10M", "0/0" for unlimited)
     * @param string|null $sessionId Optional specific session ID (if null, applies to all user sessions)
     * @return CoAResult Result of the CoA request
     */
    public function changeBandwidth(
        string $username,
        string $rateLimit,
        ?string $sessionId = null
    ): CoAResult {
        try {
            $radius = $this->createRadiusClient();
            
            // Build CoA request attributes
            $attributes = [
                'User-Name' => $username,
                'Event-Timestamp' => time(),
            ];
            
            if ($sessionId) {
                $attributes['Acct-Session-Id'] = $sessionId;
            }
            
            // Set MikroTik-Rate-Limit (Vendor-Specific Attribute)
            // Vendor ID 14988 (MikroTik), Attribute 8 (Mikrotik-Rate-Limit)
            $radius->setVendorSpecificAttribute(
                \Dapphp\Radius\VendorId::MIKROTIK,
                8,
                $rateLimit
            );
            
            // Set include message authenticator for security
            $radius->setIncludeMessageAuthenticator(true);
            
            foreach ($attributes as $attr => $value) {
                $radius->setAttribute($attr, $value);
            }
            
            // Send CoA-Request with retry logic
            $attempt = 0;
            $response = false;
            $lastError = '';
            
            while ($attempt < $this->maxRetries && $response === false) {
                try {
                    $response = $radius->coaRequest();
                    if ($response !== false) {
                        break;
                    }
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                    Log::warning('CoA attempt failed, retrying', [
                        'attempt' => $attempt + 1,
                        'username' => $username,
                        'error' => $lastError,
                    ]);
                }
                $attempt++;
                if ($attempt < $this->maxRetries) {
                    usleep(100000 * $attempt); // Exponential backoff: 100ms, 200ms, 300ms
                }
            }
            
            if ($response === false) {
                Log::error('CoA-Request failed after retries', [
                    'username' => $username,
                    'rate_limit' => $rateLimit,
                    'error' => $lastError,
                    'radius_error' => $radius->getErrorMessage(),
                ]);
                
                return new CoAResult(
                    success: false,
                    message: 'Failed to change bandwidth: ' . ($lastError ?: $radius->getErrorMessage()),
                    errorCode: $radius->getErrorCode()
                );
            }
            
            // Update radreply for persistence
            $this->updateRateLimitInDatabase($username, $rateLimit);
            
            Log::info('Bandwidth changed via CoA', [
                'username' => $username,
                'rate_limit' => $rateLimit,
                'session_id' => $sessionId,
            ]);
            
            return new CoAResult(
                success: true,
                message: "Bandwidth changed to {$rateLimit} successfully",
                attributes: ['rate_limit' => $rateLimit]
            );
            
        } catch (\Exception $e) {
            Log::error('Exception in CoA bandwidth change', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return new CoAResult(
                success: false,
                message: 'Exception: ' . $e->getMessage()
            );
        }
    }

    /**
     * Disconnect a user session immediately
     * 
     * @param string $username The username to disconnect
     * @param string $reason Reason for disconnection (logged)
     * @param string|null $sessionId Optional specific session ID
     * @param string|null $nasIpAddress NAS IP address (required for CoA)
     * @return CoAResult Result of the disconnect request
     */
    public function disconnectUser(
        string $username,
        string $reason = 'Administrative disconnect',
        ?string $sessionId = null,
        ?string $nasIpAddress = null
    ): CoAResult {
        try {
            // Get active session info if not provided
            if (!$sessionId || !$nasIpAddress) {
                $sessionInfo = $this->getActiveSessionInfo($username);
                if ($sessionInfo) {
                    $sessionId = $sessionId ?? $sessionInfo['session_id'];
                    $nasIpAddress = $nasIpAddress ?? $sessionInfo['nas_ip'];
                }
            }
            
            if (!$nasIpAddress) {
                // Try to get from router configuration
                $nasIpAddress = $this->getDefaultNasIp();
            }
            
            $radius = $this->createRadiusClient();
            
            // Build Disconnect-Request attributes
            $radius->setUsername($username)
                ->setNasIPAddress($nasIpAddress ?? '127.0.0.1')
                ->setAttribute('Event-Timestamp', time())
                ->setAttribute('Acct-Terminate-Cause', 1) // User request
                ->setIncludeMessageAuthenticator(true);
            
            if ($sessionId) {
                $radius->setAttribute('Acct-Session-Id', $sessionId);
            }
            
            // Send Disconnect-Request with retry logic
            $attempt = 0;
            $response = false;
            $lastError = '';
            
            while ($attempt < $this->maxRetries && $response === false) {
                try {
                    $response = $radius->disconnectRequest();
                    if ($response !== false) {
                        break;
                    }
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                    Log::warning('Disconnect attempt failed, retrying', [
                        'attempt' => $attempt + 1,
                        'username' => $username,
                        'error' => $lastError,
                    ]);
                }
                $attempt++;
                if ($attempt < $this->maxRetries) {
                    usleep(100000 * $attempt);
                }
            }
            
            if ($response === false) {
                Log::error('Disconnect-Request failed after retries', [
                    'username' => $username,
                    'reason' => $reason,
                    'error' => $lastError,
                    'radius_error' => $radius->getErrorMessage(),
                ]);
                
                // Fallback: Update radcheck to reject future auth
                $this->blockFutureAuth($username);
                
                return new CoAResult(
                    success: false,
                    message: 'Disconnect failed: ' . ($lastError ?: $radius->getErrorMessage()) . '. User blocked for future auth.',
                    errorCode: $radius->getErrorCode()
                );
            }
            
            Log::info('User disconnected via CoA', [
                'username' => $username,
                'reason' => $reason,
                'session_id' => $sessionId,
            ]);
            
            return new CoAResult(
                success: true,
                message: "User {$username} disconnected successfully",
                attributes: ['reason' => $reason]
            );
            
        } catch (\Exception $e) {
            Log::error('Exception in CoA disconnect', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            
            return new CoAResult(
                success: false,
                message: 'Exception: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extend session timeout for an active user
     * 
     * @param string $username The username
     * @param int $additionalSeconds Seconds to add to session timeout
     * @param string|null $sessionId Optional specific session ID
     * @return CoAResult Result of the request
     */
    public function extendSessionTimeout(
        string $username,
        int $additionalSeconds,
        ?string $sessionId = null
    ): CoAResult {
        try {
            // Get current session to calculate new timeout
            $sessionInfo = $this->getActiveSessionInfo($username);
            if (!$sessionInfo) {
                return new CoAResult(
                    success: false,
                    message: "No active session found for user {$username}"
                );
            }
            
            // Calculate new session timeout from now
            $currentSessionTime = $sessionInfo['session_time'] ?? 0;
            $newTimeout = $currentSessionTime + $additionalSeconds;
            
            $radius = $this->createRadiusClient();
            
            $attributes = [
                'User-Name' => $username,
                'Session-Timeout' => $newTimeout,
                'Event-Timestamp' => time(),
            ];
            
            if ($sessionId) {
                $attributes['Acct-Session-Id'] = $sessionId;
            }
            
            foreach ($attributes as $attr => $value) {
                $radius->setAttribute($attr, $value);
            }
            
            $radius->setIncludeMessageAuthenticator(true);
            
            $response = $radius->coaRequest();
            
            if ($response === false) {
                return new CoAResult(
                    success: false,
                    message: 'Failed to extend session: ' . $radius->getErrorMessage(),
                    errorCode: $radius->getErrorCode()
                );
            }
            
            // Update radreply for persistence
            $this->updateSessionTimeoutInDatabase($username, $newTimeout);
            
            Log::info('Session timeout extended via CoA', [
                'username' => $username,
                'additional_seconds' => $additionalSeconds,
                'new_timeout' => $newTimeout,
            ]);
            
            return new CoAResult(
                success: true,
                message: "Session extended by {$additionalSeconds} seconds",
                attributes: ['new_timeout' => $newTimeout]
            );
            
        } catch (\Exception $e) {
            Log::error('Exception extending session', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            
            return new CoAResult(
                success: false,
                message: 'Exception: ' . $e->getMessage()
            );
        }
    }

    /**
     * Change VLAN assignment for an active session
     * 
     * @param string $username The username
     * @param int $vlanId New VLAN ID
     * @param string|null $sessionId Optional specific session ID
     * @return CoAResult Result of the request
     */
    public function changeVlan(
        string $username,
        int $vlanId,
        ?string $sessionId = null
    ): CoAResult {
        try {
            $radius = $this->createRadiusClient();
            
            $attributes = [
                'User-Name' => $username,
                'Tunnel-Type' => 13, // VLAN
                'Tunnel-Medium-Type' => 6, // IEEE-802
                'Tunnel-Private-Group-Id' => $vlanId,
                'Event-Timestamp' => time(),
            ];
            
            if ($sessionId) {
                $attributes['Acct-Session-Id'] = $sessionId;
            }
            
            foreach ($attributes as $attr => $value) {
                $radius->setAttribute($attr, $value);
            }
            
            $radius->setIncludeMessageAuthenticator(true);
            
            $response = $radius->coaRequest();
            
            if ($response === false) {
                return new CoAResult(
                    success: false,
                    message: 'Failed to change VLAN: ' . $radius->getErrorMessage(),
                    errorCode: $radius->getErrorCode()
                );
            }
            
            // Update radreply for persistence
            $this->updateVlanInDatabase($username, $vlanId);
            
            Log::info('VLAN changed via CoA', [
                'username' => $username,
                'vlan_id' => $vlanId,
            ]);
            
            return new CoAResult(
                success: true,
                message: "VLAN changed to {$vlanId}",
                attributes: ['vlan_id' => $vlanId]
            );
            
        } catch (\Exception $e) {
            Log::error('Exception changing VLAN', [
                'username' => $username,
                'vlan_id' => $vlanId,
                'error' => $e->getMessage(),
            ]);
            
            return new CoAResult(
                success: false,
                message: 'Exception: ' . $e->getMessage()
            );
        }
    }

    /**
     * Test CoA connectivity to RADIUS server
     * 
     * @return array Connectivity test results
     */
    public function testConnectivity(): array
    {
        try {
            $radius = $this->createRadiusClient();
            
            // Try to create a socket connection
            $socket = @fsockopen(
                $this->radiusServer,
                $this->radiusCoaPort,
                $errno,
                $errstr,
                $this->timeout
            );
            
            if (!$socket) {
                return [
                    'success' => false,
                    'message' => "Cannot connect to {$this->radiusServer}:{$this->radiusCoaPort}",
                    'error' => $errstr,
                    'error_code' => $errno,
                ];
            }
            
            fclose($socket);
            
            return [
                'success' => true,
                'message' => "CoA port {$this->radiusCoaPort} is reachable on {$this->radiusServer}",
                'server' => $this->radiusServer,
                'port' => $this->radiusCoaPort,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connectivity test failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create configured Radius client
     */
    private function createRadiusClient(): Radius
    {
        $radius = new Radius();
        $radius->setServer($this->radiusServer)
            ->setSecret($this->radiusSecret)
            ->setTimeout($this->timeout);
        
        return $radius;
    }

    /**
     * Get active session information for a user
     */
    private function getActiveSessionInfo(string $username): ?array
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
            'session_time' => $session->acctsessiontime,
            'ip_address' => $session->framedipaddress,
        ];
    }

    /**
     * Get default NAS IP from configuration
     */
    private function getDefaultNasIp(): ?string
    {
        // Try to get from first active router
        $router = Router::first();
        if ($router && $router->ip_address) {
            return $router->ip_address;
        }
        
        return config('services.radius.default_nas_ip');
    }

    /**
     * Update rate limit in radreply table
     */
    private function updateRateLimitInDatabase(string $username, string $rateLimit): void
    {
        DB::table('radreply')
            ->updateOrInsert(
                ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit'],
                ['op' => ':=', 'value' => $rateLimit]
            );
    }

    /**
     * Update session timeout in radreply table
     */
    private function updateSessionTimeoutInDatabase(string $username, int $timeout): void
    {
        DB::table('radreply')
            ->updateOrInsert(
                ['username' => $username, 'attribute' => 'Session-Timeout'],
                ['op' => ':=', 'value' => (string) $timeout]
            );
    }

    /**
     * Update VLAN in radreply table
     */
    private function updateVlanInDatabase(string $username, int $vlanId): void
    {
        DB::table('radreply')
            ->updateOrInsert(
                ['username' => $username, 'attribute' => 'Tunnel-Private-Group-Id'],
                ['op' => ':=', 'value' => (string) $vlanId]
            );
    }

    /**
     * Block future authentication as fallback
     */
    private function blockFutureAuth(string $username): void
    {
        DB::table('radcheck')
            ->updateOrInsert(
                ['username' => $username, 'attribute' => 'Auth-Type'],
                ['op' => ':=', 'value' => 'Reject']
            );
    }
}
