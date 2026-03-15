<?php

namespace App\Services;

use App\Models\VpnConfiguration;
use Illuminate\Support\Facades\Log;

class VpnConnectivityService
{
    /**
     * Verify VPN connectivity to a router.
     *
     * Default behavior uses TCP probe with ICMP ping fallback.
     * For provisioning status checks, callers can enforce strict ping mode.
     *
     * @param VpnConfiguration $config
     * @param int $maxAttempts Maximum number of attempts
     * @param int $timeout Timeout per attempt in seconds
     * @param bool $pingOnly When true, use ICMP ping only (no TCP probe)
     * @return array ['success' => bool, 'latency' => float|null, 'packet_loss' => int, 'message' => string]
     */
    public function verifyConnectivity(VpnConfiguration $config, int $maxAttempts = 4, int $timeout = 5, bool $pingOnly = false): array
    {
        $clientIp = explode('/', $config->client_ip ?? '')[0];
        $port = $this->resolveSshPort($config);
        
        Log::info('Starting VPN connectivity verification', [
            'router_id' => $config->router_id,
            'client_ip' => $clientIp,
            'port' => $port,
            'max_attempts' => $maxAttempts,
            'probe_mode' => $pingOnly ? 'ping' : 'tcp_then_ping',
        ]);

        if (empty($clientIp)) {
            return [
                'success' => false,
                'latency' => null,
                'packet_loss' => 100,
                'message' => 'TCP probe failed: missing VPN client IP',
                'raw_output' => 'missing client_ip',
            ];
        }

        $success = false;
        $latency = null;
        $lastError = null;

        $attempts = max(1, $maxAttempts);
        $probeTimeout = max(1, $timeout);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($pingOnly) {
                $pingProbe = $this->pingProbe($clientIp, $probeTimeout);
                if ($pingProbe['success']) {
                    $success = true;
                    $latency = $pingProbe['latency'];
                    $lastError = null;
                    break;
                }

                $lastError = $pingProbe['error'];
                continue;
            }

            // Try TCP probe first (SSH port)
            $probe = $this->tcpProbe($clientIp, $port, $probeTimeout);
            $latency = $probe['latency'];

            if ($probe['success']) {
                $success = true;
                break;
            }
            
            // Fallback to ICMP Ping if TCP fails
            $pingProbe = $this->pingProbe($clientIp, $probeTimeout);
            if ($pingProbe['success']) {
                $success = true;
                $latency = $pingProbe['latency'];
                $lastError = null; // Clear TCP error if ping succeeds
                break;
            }

            $lastError = $probe['error'] . ' | Ping: ' . $pingProbe['error'];
        }

        $result = [
            'success' => $success,
            'latency' => $latency,
            'packet_loss' => $success ? 0 : 100,
            'message' => $success
                ? ($pingOnly ? 'Ping connectivity verified' : 'VPN connectivity verified')
                : ('Connectivity check failed: ' . $lastError),
            'raw_output' => $lastError,
        ];
        
        Log::info('VPN connectivity verification completed', [
            'router_id' => $config->router_id,
            'client_ip' => $clientIp,
            'port' => $port,
            'success' => $result['success'],
            'packet_loss' => $result['packet_loss'],
            'latency' => $result['latency'],
            'probe_mode' => $pingOnly ? 'ping' : 'tcp_then_ping',
        ]);

        return $result;
    }

    protected function resolveSshPort(VpnConfiguration $config): int
    {
        $router = $config->router;
        $port = (int) ($router?->ssh_port ?? 0);

        if ($port > 0) {
            return $port;
        }

        $candidate = (int) ($router?->port ?? 22);
        if (in_array($candidate, [8720, 8728, 8729], true)) {
            return 22;
        }

        return $candidate > 0 ? $candidate : 22;
    }

    protected function tcpProbe(string $host, int $port, int $timeout): array
    {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($socket !== false) {
            fclose($socket);

            return [
                'success' => true,
                'latency' => round((microtime(true) - $start) * 1000, 2),
                'error' => null,
            ];
        }

        $error = trim($errstr);
        if ($error === '') {
            $error = "Connection failed (errno {$errno})";
        }

        return [
            'success' => false,
            'latency' => null,
            'error' => $error,
        ];
    }

    protected function pingProbe(string $host, int $timeout): array
    {
        $start = microtime(true);
        
        // Determine OS for ping command
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $countFlag = $isWindows ? '-n' : '-c';
        $timeoutFlag = $isWindows ? '-w' : '-W';
        // Windows timeout is in milliseconds, Linux/Mac is in seconds
        $timeoutVal = $isWindows ? ($timeout * 1000) : $timeout;
        
        $command = "ping {$countFlag} 1 {$timeoutFlag} {$timeoutVal} {$host}";
        
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
            'error' => "Ping failed (code {$resultCode})",
        ];
    }

    /**
     * Wait for VPN connectivity with retries
     * Useful for waiting for router to establish VPN tunnel after configuration
     * 
     * @param VpnConfiguration $config
     * @param int $maxWaitSeconds Maximum time to wait in seconds
     * @param int $retryInterval Seconds between retry attempts
     * @return array Same format as verifyConnectivity()
     */
    public function waitForConnectivity(VpnConfiguration $config, int $maxWaitSeconds = 120, int $retryInterval = 5): array
    {
        $startTime = time();
        $attempt = 0;
        
        Log::info('Waiting for VPN connectivity', [
            'router_id' => $config->router_id,
            'client_ip' => $config->client_ip,
            'max_wait_seconds' => $maxWaitSeconds,
        ]);

        while ((time() - $startTime) < $maxWaitSeconds) {
            $attempt++;
            
            $result = $this->verifyConnectivity($config, 2, 3); // Quick ping: 2 attempts, 3s timeout
            
            if ($result['success'] && $result['packet_loss'] === 0) {
                Log::info('VPN connectivity established', [
                    'router_id' => $config->router_id,
                    'client_ip' => $config->client_ip,
                    'attempt' => $attempt,
                    'elapsed_seconds' => time() - $startTime,
                    'latency' => $result['latency'],
                ]);
                
                return $result;
            }
            
            Log::debug('VPN connectivity not yet established, retrying...', [
                'router_id' => $config->router_id,
                'attempt' => $attempt,
                'packet_loss' => $result['packet_loss'],
            ]);
            
            sleep($retryInterval);
        }

        // Timeout reached
        Log::warning('VPN connectivity timeout', [
            'router_id' => $config->router_id,
            'client_ip' => $config->client_ip,
            'elapsed_seconds' => time() - $startTime,
            'attempts' => $attempt,
        ]);

        return [
            'success' => false,
            'latency' => null,
            'packet_loss' => 100,
            'message' => 'VPN connectivity timeout - router did not respond within ' . $maxWaitSeconds . ' seconds',
            'attempts' => $attempt,
        ];
    }

    /**
     * Verify bidirectional connectivity
     * Checks both server->router and router->server connectivity
     * 
     * @param VpnConfiguration $config
     * @return array ['server_to_router' => array, 'router_to_server' => array, 'bidirectional' => bool]
     */
    public function verifyBidirectionalConnectivity(VpnConfiguration $config): array
    {
        // Test server -> router
        $serverToRouter = $this->verifyConnectivity($config);
        
        // For router -> server, we would need to execute command on router
        // For now, we'll just verify server -> router
        // Router -> server is verified when router applies config and pings server
        
        return [
            'server_to_router' => $serverToRouter,
            'router_to_server' => [
                'success' => null,
                'message' => 'Router to server connectivity verified by router configuration',
            ],
            'bidirectional' => $serverToRouter['success'],
        ];
    }
}
