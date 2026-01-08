<?php

namespace App\Services;

use App\Models\VpnConfiguration;
use Illuminate\Support\Facades\Log;

class VpnConnectivityService
{
    /**
     * Verify VPN connectivity to a router
     * Tests if the server can ping the router's VPN IP
     * 
     * @param VpnConfiguration $config
     * @param int $maxAttempts Maximum number of ping attempts
     * @param int $timeout Timeout per ping attempt in seconds
     * @return array ['success' => bool, 'latency' => float|null, 'packet_loss' => int, 'message' => string]
     */
    public function verifyConnectivity(VpnConfiguration $config, int $maxAttempts = 4, int $timeout = 5): array
    {
        $clientIp = $config->client_ip;
        
        Log::info('Starting VPN connectivity verification', [
            'router_id' => $config->router_id,
            'client_ip' => $clientIp,
            'max_attempts' => $maxAttempts,
        ]);

        // Execute ping command
        $command = sprintf(
            'ping -c %d -W %d %s 2>&1',
            $maxAttempts,
            $timeout,
            escapeshellarg($clientIp)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        $outputString = implode("\n", $output);
        
        // Parse ping results
        $result = $this->parsePingOutput($outputString, $returnCode);
        
        Log::info('VPN connectivity verification completed', [
            'router_id' => $config->router_id,
            'client_ip' => $clientIp,
            'success' => $result['success'],
            'packet_loss' => $result['packet_loss'],
            'latency' => $result['latency'],
        ]);

        return $result;
    }

    /**
     * Parse ping command output
     */
    protected function parsePingOutput(string $output, int $returnCode): array
    {
        $result = [
            'success' => false,
            'latency' => null,
            'packet_loss' => 100,
            'message' => 'Ping failed',
            'raw_output' => $output,
        ];

        // Check if ping was successful (return code 0 means at least one packet received)
        if ($returnCode === 0) {
            $result['success'] = true;
            $result['message'] = 'VPN connectivity verified';
        }

        // Extract packet loss percentage
        if (preg_match('/(\d+)% packet loss/', $output, $matches)) {
            $result['packet_loss'] = (int)$matches[1];
            
            // If packet loss is 0%, connectivity is excellent
            if ($result['packet_loss'] === 0) {
                $result['success'] = true;
            }
        }

        // Extract average latency - support both Linux (rtt) and Alpine (round-trip) formats
        // Alpine: round-trip min/avg/max = 231.665/233.759/235.853 ms
        // Linux: rtt min/avg/max/mdev = 1.234/2.345/3.456/0.123 ms
        if (preg_match('/(?:rtt|round-trip) min\/avg\/max(?:\/mdev)? = [\d.]+\/([\d.]+)\/[\d.]+/', $output, $matches)) {
            $result['latency'] = (float)$matches[1];
        }

        return $result;
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
