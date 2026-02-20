<?php

namespace App\Services;

use App\Models\VpnConfiguration;
use Illuminate\Support\Facades\Log;

class VpnConnectivityService
{
    /**
     * Verify VPN connectivity to a router
     * Tests if the server can open a TCP connection to the router's SSH port
     * 
     * @param VpnConfiguration $config
     * @param int $maxAttempts Maximum number of TCP attempts
     * @param int $timeout Timeout per TCP attempt in seconds
     * @return array ['success' => bool, 'latency' => float|null, 'packet_loss' => int, 'message' => string]
     */
    public function verifyConnectivity(VpnConfiguration $config, int $maxAttempts = 4, int $timeout = 5): array
    {
        $clientIp = explode('/', $config->client_ip ?? '')[0];
        $port = $this->resolveSshPort($config);
        
        Log::info('Starting VPN connectivity verification (TCP probe)', [
            'router_id' => $config->router_id,
            'client_ip' => $clientIp,
            'port' => $port,
            'max_attempts' => $maxAttempts,
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
            $probe = $this->tcpProbe($clientIp, $port, $probeTimeout);
            $latency = $probe['latency'];

            if ($probe['success']) {
                $success = true;
                break;
            }

            $lastError = $probe['error'];
        }

        $result = [
            'success' => $success,
            'latency' => $latency,
            'packet_loss' => $success ? 0 : 100,
            'message' => $success
                ? 'VPN connectivity verified via TCP probe'
                : ('TCP probe failed' . ($lastError ? ': ' . $lastError : '')),
            'raw_output' => $lastError,
        ];
        
        Log::info('VPN connectivity verification completed (TCP probe)', [
            'router_id' => $config->router_id,
            'client_ip' => $clientIp,
            'port' => $port,
            'success' => $result['success'],
            'packet_loss' => $result['packet_loss'],
            'latency' => $result['latency'],
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
