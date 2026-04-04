<?php

namespace App\Services;

use App\Models\Router;
use App\Models\VpnConfiguration;
use App\Models\WireguardPeer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Router Status Check Service
 *
 * Implements a strict two-phase status checking system:
 *
 * 1. PROVISIONING PHASE (status: pending, deploying, provisioning, verifying):
 *    - Uses ICMP ping ONLY to check router connectivity
 *    - ICMP is allowed during provisioning for initial setup
 *    - WireGuard handshake is secondary/validation only
 *
 * 2. OPERATIONAL PHASE (status: online, offline, failed, etc.):
 *    - Uses WireGuard handshake timestamp ONLY
 *    - ICMP is blocked by firewall after hardening
 *    - Handshake age determines online/offline status
 *
 * This design ensures:
 * - Reliable provisioning status detection via ping
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
     * Check router status during PROVISIONING phase using PING ONLY.
     * This is the only reliable method during initial setup.
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
                'method' => 'ping',
                'phase' => 'provisioning',
                'reason' => 'No VPN configuration found',
                'vpn_status' => 'inactive',
            ];
        }

        // STRICT PING-ONLY CHECK during provisioning
        // Do NOT fall back to handshake - ping is the authoritative method here
        $pingResult = $this->pingOnlyCheck($vpnConfig);

        $isOnline = $pingResult['success'] && $pingResult['packet_loss'] === 0;

        Log::info('Provisioning status check result', [
            'router_id' => $router->id,
            'method' => 'ping_only',
            'online' => $isOnline,
            'latency_ms' => $pingResult['latency'],
            'packet_loss' => $pingResult['packet_loss'],
        ]);

        return [
            'online' => $isOnline,
            'method' => 'ping',
            'phase' => 'provisioning',
            'latency_ms' => $pingResult['latency'],
            'packet_loss' => $pingResult['packet_loss'],
            'vpn_status' => $isOnline ? 'active' : 'inactive',
            'details' => $pingResult,
        ];
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
        $inactiveThreshold = (int) config('vpn.monitoring.inactive_threshold', 180);
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
        $handshakeAge = abs(now()->diffInSeconds($latestHandshake, false));
        $isHandshakeActive = $handshakeAge <= $inactiveThreshold;

        // Grace period logic: if handshake just went stale, give brief grace period
        // This prevents flickering during brief network hiccups
        if (!$isHandshakeActive && $router->status === 'online') {
            $lastSeen = $router->last_seen;
            if ($lastSeen) {
                $secondsSinceLastSeen = now()->diffInSeconds($lastSeen);
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
     * Uses ping-only checks with retries.
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
                    'method' => 'ping',
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
            'method' => 'ping',
            'phase' => 'provisioning',
            'attempts' => $attempt,
            'elapsed_seconds' => time() - $startTime,
            'reason' => 'Timeout waiting for router to respond to ping',
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
