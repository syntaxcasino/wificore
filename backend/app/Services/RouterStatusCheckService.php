<?php

namespace App\Services;

use App\Models\Router;
use App\Models\VpnConfiguration;
use App\Models\WireguardPeer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Router Status Check Service
 *
 * Implements a strict two-phase status checking system:
 *
 * 1. PROVISIONING PHASE (status: pending, deploying, provisioning, verifying):
 *    - Uses WireGuard controller API to check peer handshake status
 *    - WireGuard container (host network) monitors peers, backend queries via API
 *    - Handshake timestamp determines if router is reachable via VPN
 *
 * 2. OPERATIONAL PHASE (status: online, offline, failed, etc.):
 *    - Uses WireGuard handshake timestamp ONLY
 *    - ICMP is blocked by firewall after hardening
 *    - Handshake age determines online/offline status
 *
 * This design ensures:
 * - Reliable provisioning status detection via WireGuard controller
 * - Secure operational status detection via encrypted tunnel handshake
 * - No false negatives when firewall blocks ICMP post-hardening
 */
class RouterStatusCheckService
{
    /**
     * Check router status using the appropriate method based on router state.
     *
     * @param Router $router
     * @param string|null $phase 'provisioning' or 'operational'. Auto-detected if null.
     * @return array Status check result with method used
     */
    public function checkStatus(Router $router, ?string $phase = null): array
    {
        // Auto-detect phase if not specified
        if ($phase === null) {
            $phase = $this->determinePhase($router);
        }

        Log::debug('Checking router status', [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'current_status' => $router->status,
            'phase' => $phase,
        ]);

        if ($phase === 'provisioning') {
            return $this->checkStatusProvisioning($router);
        }

        return $this->checkStatusOperational($router);
    }

    /**
     * Check router status during PROVISIONING phase using WireGuard controller API.
     * The WireGuard container (host network) pings the router, backend queries the API.
     *
     * If ICMP is blocked post-deployment, fall back to WireGuard handshake checks
     * to avoid false offline status.
     *
     * @param Router $router
     * @return array
     */
    public function checkStatusProvisioning(Router $router): array
    {
        $vpnConfig = VpnConfiguration::where('router_id', $router->id)->first();

        if (!$vpnConfig) {
            return [
                'online' => false,
                'method' => 'wireguard_api',
                'phase' => 'provisioning',
                'reason' => 'No VPN configuration found',
                'vpn_status' => 'inactive',
            ];
        }

        // Query WireGuard controller API for peer status
        // The WireGuard container (on host network) can reach the VPN, not the backend
        $peerStatus = $this->checkPeerViaWireguardController($vpnConfig);

        $isOnline = $peerStatus['online'] ?? false;
        $method = 'wireguard_api';
        $details = $peerStatus;

        if (!$isOnline) {
            // Fallback to handshake-only checks for post-deployment routers
            $handshakeResult = $this->handshakeOnlyCheck($router);
            if ($handshakeResult['online'] ?? false) {
                $isOnline = true;
                $method = 'handshake_fallback';
                $details = [
                    'ping' => $peerStatus,
                    'handshake' => $handshakeResult,
                ];
            }
        }

        Log::info('Provisioning status check result', [
            'router_id' => $router->id,
            'method' => $method,
            'online' => $isOnline,
            'peer_public_key' => substr($vpnConfig->client_public_key, 0, 20) . '...',
        ]);

        return [
            'online' => $isOnline,
            'method' => $method,
            'phase' => 'provisioning',
            'latency_ms' => null,
            'packet_loss' => $isOnline ? 0 : 100,
            'vpn_status' => $isOnline ? 'active' : 'inactive',
            'details' => $details,
        ];
    }

    /**
     * Check peer status via WireGuard controller API.
     * Sends command to WireGuard container (host network) to ping the router.
     *
     * @param VpnConfiguration $config
     * @param int $attempts
     * @param int $timeout
     * @return array
     */
    private function checkPeerViaWireguardController(VpnConfiguration $config, int $attempts = 3, int $timeout = 3): array
    {
        try {
            $controllerUrl = config('services.wireguard.controller_url', 'http://172.70.0.1:8080');
            $apiKey = config('services.wireguard.api_key');
            
            // Extract client IP from config
            $clientIp = explode('/', $config->client_ip ?? '')[0];
            
            if (empty($clientIp)) {
                return [
                    'online' => false,
                    'error' => 'No VPN client IP configured',
                ];
            }
            
            // Send ping command to WireGuard controller
            // WireGuard container runs on host network and CAN reach VPN subnet
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(15)
            ->post("{$controllerUrl}/vpn/ping", [
                'ip' => $clientIp,
                'timeout' => $timeout,
                'attempts' => $attempts,
            ]);
            
            if (!$response->successful()) {
                Log::warning('WireGuard controller ping API returned error', [
                    'ip' => $clientIp,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return [
                    'online' => false,
                    'error' => 'WireGuard controller API error: ' . $response->status(),
                ];
            }
            
            $data = $response->json();
            
            // Check if ping was successful
            $success = $data['success'] ?? false;
            
            Log::info('WireGuard controller ping result', [
                'router_id' => $config->router_id,
                'ip' => $clientIp,
                'success' => $success,
                'latency_ms' => $data['latency_ms'] ?? null,
                'attempts' => $data['attempts'] ?? $attempts,
            ]);
            
            return [
                'online' => $success,
                'latency' => $data['latency_ms'] ?? null,
                'packet_loss' => $success ? 0 : 100,
                'attempts' => $data['attempts'] ?? $attempts,
                'error' => $data['error'] ?? null,
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to query WireGuard controller for ping', [
                'router_id' => $config->router_id,
                'ip' => $clientIp ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            
            return [
                'online' => false,
                'error' => 'Failed to query WireGuard controller: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check router status during OPERATIONAL phase using WIREGUARD HANDSHAKE ONLY.
     * ICMP is expected to be blocked by firewall after hardening.
     *
     * @param Router $router
     * @return array
     */
    public function checkStatusOperational(Router $router): array
    {
        // STRICT HANDSHAKE-ONLY CHECK during operational phase
        // Do NOT attempt ping - it's blocked by firewall
        $handshakeResult = $this->handshakeOnlyCheck($router);

        Log::info('Operational status check result', [
            'router_id' => $router->id,
            'method' => 'handshake_only',
            'online' => $handshakeResult['online'],
            'handshake_age_seconds' => $handshakeResult['handshake_age_seconds'],
            'has_handshake' => $handshakeResult['has_handshake'],
        ]);

        return [
            'online' => $handshakeResult['online'],
            'method' => 'handshake',
            'phase' => 'operational',
            'vpn_status' => $handshakeResult['online'] ? 'active' : 'inactive',
            'handshake_at' => $handshakeResult['handshake_at'],
            'handshake_age_seconds' => $handshakeResult['handshake_age_seconds'],
            'details' => $handshakeResult,
        ];
    }

    /**
     * Perform PING-ONLY connectivity check.
     * Used exclusively during provisioning phase.
     *
     * @param VpnConfiguration $config
     * @param int $attempts
     * @param int $timeout
     * @return array
     */
    public function pingOnlyCheck(VpnConfiguration $config, int $attempts = 3, int $timeout = 3): array
    {
        $clientIp = explode('/', $config->client_ip ?? '')[0];

        if (empty($clientIp)) {
            return [
                'success' => false,
                'latency' => null,
                'packet_loss' => 100,
                'error' => 'No VPN client IP configured',
            ];
        }

        $success = false;
        $latency = null;
        $lastError = null;

        for ($i = 1; $i <= $attempts; $i++) {
            $result = $this->executePing($clientIp, $timeout);

            if ($result['success']) {
                $success = true;
                $latency = $result['latency'];
                break;
            }

            $lastError = $result['error'];
        }

        return [
            'success' => $success,
            'latency' => $latency,
            'packet_loss' => $success ? 0 : 100,
            'attempts' => $attempts,
            'error' => $lastError,
        ];
    }

    /**
     * Perform HANDSHAKE-ONLY status check.
     * Used exclusively during operational phase.
     *
     * @param Router $router
     * @return array
     */
    public function handshakeOnlyCheck(Router $router): array
    {
        $inactiveThreshold = (int) config('vpn.monitoring.inactive_threshold', 190);
        $gracePeriod = (int) config('vpn.monitoring.offline_grace_period', 60);

        // Get latest handshake from WireGuard peers table
        $peer = WireguardPeer::where('router_id', $router->id)
            ->orderBy('last_handshake', 'desc')
            ->first();

        $latestHandshake = $peer?->last_handshake;

        // If no handshake record exists, router is definitely offline
        if (!$latestHandshake) {
            return [
                'online' => false,
                'has_handshake' => false,
                'handshake_at' => null,
                'handshake_age_seconds' => null,
                'reason' => 'No WireGuard handshake recorded',
            ];
        }

        // Calculate handshake age using absolute difference (handles clock skew)
        // diffInSeconds with false parameter returns negative if timestamp is in future
        // We use abs() to handle router clock being ahead/behind server
        $handshakeTime = $latestHandshake instanceof Carbon ? $latestHandshake : Carbon::parse($latestHandshake);
        $handshakeAge = abs(now()->diffInSeconds($handshakeTime, false));
        $isHandshakeActive = $handshakeAge <= $inactiveThreshold;

        // Grace period logic: if handshake just went stale, give brief grace period
        // This prevents flickering during brief network hiccups
        if (!$isHandshakeActive && $router->status === 'online') {
            $lastSeen = $router->last_seen;
            if ($lastSeen) {
                $lastSeenTime = $lastSeen instanceof Carbon ? $lastSeen : Carbon::parse($lastSeen);
                $secondsSinceLastSeen = now()->diffInSeconds($lastSeenTime);
                if ($secondsSinceLastSeen < ($inactiveThreshold + $gracePeriod)) {
                    // Within grace period - keep online but mark VPN inactive
                    return [
                        'online' => true,
                        'has_handshake' => true,
                        'handshake_at' => $latestHandshake,
                        'handshake_age_seconds' => $handshakeAge,
                        'grace_period_active' => true,
                        'reason' => 'Stale handshake within grace period',
                    ];
                }
            }
        }

        return [
            'online' => $isHandshakeActive,
            'has_handshake' => true,
            'handshake_at' => $latestHandshake,
            'handshake_age_seconds' => $handshakeAge,
            'threshold_seconds' => $inactiveThreshold,
            'reason' => $isHandshakeActive ? 'Active handshake' : 'Stale handshake',
        ];
    }

    /**
     * Determine which phase a router is in based on its status.
     *
     * @param Router $router
     * @return string 'provisioning' or 'operational'
     */
    public function determinePhase(Router $router): string
    {
        $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];

        if (in_array($router->status, $provisioningStatuses, true)) {
            return 'provisioning';
        }

        // Also check if router has never been online (first-time setup)
        if ($router->status === 'offline' && !$router->last_seen) {
            return 'provisioning';
        }

        return 'operational';
    }

    /**
     * Wait for router to come online during provisioning.
     * Uses WireGuard controller API checks with retries.
     *
     * @param Router $router
     * @param int $maxWaitSeconds Maximum time to wait
     * @param int $checkInterval Seconds between checks
     * @return array
     */
    public function waitForProvisioningOnline(Router $router, int $maxWaitSeconds = 300, int $checkInterval = 5): array
    {
        $startTime = time();
        $attempt = 0;

        Log::info('Waiting for router to come online during provisioning', [
            'router_id' => $router->id,
            'max_wait_seconds' => $maxWaitSeconds,
            'check_interval' => $checkInterval,
        ]);

        while ((time() - $startTime) < $maxWaitSeconds) {
            $attempt++;
            $result = $this->checkStatusProvisioning($router);

            if ($result['online']) {
                Log::info('Router came online during provisioning', [
                    'router_id' => $router->id,
                    'attempt' => $attempt,
                    'elapsed_seconds' => time() - $startTime,
                ]);

                return [
                    'success' => true,
                    'method' => 'wireguard_api',
                    'phase' => 'provisioning',
                    'attempts' => $attempt,
                    'elapsed_seconds' => time() - $startTime,
                ];
            }

            Log::debug('Router not yet online, retrying...', [
                'router_id' => $router->id,
                'attempt' => $attempt,
                'elapsed_seconds' => time() - $startTime,
            ]);

            sleep($checkInterval);
        }

        Log::warning('Router did not come online within provisioning timeout', [
            'router_id' => $router->id,
            'attempts' => $attempt,
            'elapsed_seconds' => time() - $startTime,
        ]);

        return [
            'success' => false,
            'method' => 'wireguard_api',
            'phase' => 'provisioning',
            'attempts' => $attempt,
            'elapsed_seconds' => time() - $startTime,
            'reason' => 'Timeout waiting for router WireGuard handshake',
        ];
    }

    /**
     * Execute a single ping command.
     *
     * @param string $host
     * @param int $timeout
     * @return array
     */
    private function executePing(string $host, int $timeout): array
    {
        $start = microtime(true);

        // Determine OS for ping command
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $countFlag = $isWindows ? '-n' : '-c';
        $timeoutFlag = $isWindows ? '-w' : '-W';
        // Windows timeout is in milliseconds, Linux/Mac is in seconds
        $timeoutVal = $isWindows ? ($timeout * 1000) : $timeout;

        $command = "ping {$countFlag} 1 {$timeoutFlag} {$timeoutVal} {$host} 2>&1";

        exec($command, $output, $resultCode);

        if ($resultCode === 0) {
            return [
                'success' => true,
                'latency' => round((microtime(true) - $start) * 1000, 2),
                'error' => null,
            ];
        }

        return [
            'success' => false,
            'latency' => null,
            'error' => "Ping failed (code {$resultCode}): " . implode(' ', $output),
        ];
    }
}
