<?php

namespace App\Services;

use App\Models\Router;
use App\Services\MikroTik\SshExecutor;
use Illuminate\Support\Facades\Log;

/**
 * SSH-based MikroTik router management service
 * Used as fallback when API is unavailable or times out
 */
class MikrotikSshService
{
    /**
     * Fetch router interfaces via SSH
     * @param Router $router
     * @param bool $filterConfigurable Only return configurable interfaces
     * @return array
     */
    public function fetchInterfaces(Router $router, bool $filterConfigurable = false): array
    {
        try {
            // Use shared SshExecutor for consistent connection handling
            $sshExecutor = new SshExecutor($router, 5); // Reduced timeout from 10 to 5 seconds for faster failure detection
            
            // Ensure SSH connection is reused across operations
            if (!$sshExecutor->isConnected()) {
                $sshExecutor->connect();
            }
            
            // Get interfaces
            $interfaceOutput = $sshExecutor->exec('/interface print detail without-paging');
            
            // Get system info
            $resourceOutput = $sshExecutor->exec('/system resource print');
            $identityOutput = $sshExecutor->exec('/system identity print');
            
            $sshExecutor->disconnect();
            
            // Parse interface output
            $interfaces = $this->parseInterfaces($interfaceOutput);
            
            // Filter if requested
            if ($filterConfigurable) {
                $interfaces = array_filter($interfaces, function($iface) {
                    return $this->isConfigurableInterface($iface);
                });
            }
            
            // Parse system info
            $systemInfo = $this->parseSystemInfo($resourceOutput, $identityOutput);
            
            return [
                'interfaces' => array_values($interfaces),
                'board_name' => $systemInfo['board_name'],
                'version' => $systemInfo['version'],
                'uptime' => $systemInfo['uptime'],
                'identity' => $systemInfo['identity'],
            ];
            
        } catch (\Exception $e) {
            Log::error('SSH interface fetch failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Parse interface output from SSH
     */
    private function parseInterfaces(string $output): array
    {
        $interfaces = [];
        $lines = explode("\n", $output);
        $currentInterface = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // New interface starts with a number
            if (preg_match('/^\d+\s+R?\s+name="([^"]+)"/', $line, $matches)) {
                if ($currentInterface) {
                    $interfaces[] = $currentInterface;
                }
                $currentInterface = [
                    'name' => $matches[1],
                    'type' => 'ether',
                    'running' => strpos($line, ' R ') !== false ? 'true' : 'false',
                    'mtu' => '1500',
                    'comment' => '',
                ];
            }
            
            // Parse type
            if ($currentInterface && preg_match('/type=(\S+)/', $line, $matches)) {
                $currentInterface['type'] = $matches[1];
            }
            
            // Parse MTU
            if ($currentInterface && preg_match('/mtu=(\d+)/', $line, $matches)) {
                $currentInterface['mtu'] = $matches[1];
            }
            
            // Parse comment
            if ($currentInterface && preg_match('/comment="([^"]*)"/', $line, $matches)) {
                $currentInterface['comment'] = $matches[1];
            }
        }
        
        if ($currentInterface) {
            $interfaces[] = $currentInterface;
        }
        
        return $interfaces;
    }

    /**
     * Parse system resource and identity output
     */
    private function parseSystemInfo(string $resourceOutput, string $identityOutput): array
    {
        $info = [
            'board_name' => 'N/A',
            'version' => 'N/A',
            'uptime' => 'N/A',
            'identity' => 'N/A',
            // Extended metrics (may be null if not present)
            'cpu_load' => null,
            'free_memory' => null,
            'total_memory' => null,
            'free_hdd_space' => null,
            'total_hdd_space' => null,
        ];
        
        // Parse board name
        if (preg_match('/board-name:\s*(.+)/', $resourceOutput, $matches)) {
            $info['board_name'] = trim($matches[1]);
        }
        
        // Parse version
        if (preg_match('/version:\s*(.+)/', $resourceOutput, $matches)) {
            $info['version'] = trim($matches[1]);
        }
        
        // Parse uptime
        if (preg_match('/uptime:\s*(.+)/', $resourceOutput, $matches)) {
            $info['uptime'] = trim($matches[1]);
        }

        // Parse CPU load (e.g. "cpu-load: 5%")
        if (preg_match('/cpu-load:\s*(\d+)/', $resourceOutput, $matches)) {
            $info['cpu_load'] = (int) trim($matches[1]);
        }

        // Parse memory metrics (e.g. "free-memory: 402.2MiB", "total-memory: 512.0MiB")
        if (preg_match('/free-memory:\s*([^\r\n]+)/', $resourceOutput, $matches)) {
            $info['free_memory'] = trim($matches[1]);
        }
        if (preg_match('/total-memory:\s*([^\r\n]+)/', $resourceOutput, $matches)) {
            $info['total_memory'] = trim($matches[1]);
        }

        // Parse disk metrics (e.g. "free-hdd-space: 200.5MiB", "total-hdd-space: 1024.0MiB")
        if (preg_match('/free-hdd-space:\s*([^\r\n]+)/', $resourceOutput, $matches)) {
            $info['free_hdd_space'] = trim($matches[1]);
        }
        if (preg_match('/total-hdd-space:\s*([^\r\n]+)/', $resourceOutput, $matches)) {
            $info['total_hdd_space'] = trim($matches[1]);
        }
        
        // Parse identity
        if (preg_match('/name:\s*(.+)/', $identityOutput, $matches)) {
            $info['identity'] = trim($matches[1]);
        }
        
        return $info;
    }

    /**
     * Check if an interface is configurable
     */
    private function isConfigurableInterface(array $interface): bool
    {
        $type = $interface['type'] ?? '';
        
        // Exclude system/virtual interfaces
        $excludedTypes = ['bridge', 'vlan', 'vrrp', 'vpls', 'ovpn-out', 'ovpn-in', 'wg', 'gre', 'ipip', 'eoip'];
        if (in_array($type, $excludedTypes)) {
            return false;
        }
        
        return true;
    }

    /**
     * Test SSH connectivity
     */
    public function testConnection(Router $router): bool
    {
        try {
            $sshExecutor = new SshExecutor($router, 5);
            
            // Ensure SSH connection is reused across operations
            if (!$sshExecutor->isConnected()) {
                $sshExecutor->connect();
            }
            
            $output = $sshExecutor->exec('/system identity print');
            $sshExecutor->disconnect();
            
            return !empty($output);
        } catch (\Exception $e) {
            Log::warning('SSH test connection failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Fetch live router metrics via SSH
     * Used by MikrotikProvisioningService::fetchLiveRouterData for monitoring
     */
    public function fetchLiveData(Router $router, bool $includeInterfaces = false): array
    {
        try {
            // Use shared SSH executor with reasonable timeout for live data
            $sshExecutor = new SshExecutor($router, 5); // Reduced timeout from 10 to 5 seconds for faster failure detection
            
            // Ensure SSH connection is reused across operations
            if (!$sshExecutor->isConnected()) {
                $sshExecutor->connect();
            }

            // Core system info
            $resourceOutput = $sshExecutor->exec('/system resource print');
            $identityOutput = $sshExecutor->exec('/system identity print');

            // Lightweight counters (best-effort, may fail on older RouterOS)
            $interfacesCountOutput = $sshExecutor->exec('/interface print count-only');
            $hotspotActiveOutput = $sshExecutor->exec('/ip hotspot active print count-only');
            $pppoeActiveOutput = $sshExecutor->exec('/ppp active print count-only');
            $dhcpLeasesOutput = $sshExecutor->exec('/ip dhcp-server lease print count-only');

            $interfaces = null;
            if ($includeInterfaces) {
                $interfaceOutput = $sshExecutor->exec('/interface print detail without-paging');
                $interfaces = $this->parseInterfaces($interfaceOutput);
            }

            $sshExecutor->disconnect();

            // Parse system info using existing helper
            $systemInfo = $this->parseSystemInfo($resourceOutput, $identityOutput);

            $interfacesCount = (int) trim($interfacesCountOutput ?? '0');
            $hotspotActive = (int) preg_replace('/[^0-9]/', '', (string) $hotspotActiveOutput);
            $pppoeActive = (int) preg_replace('/[^0-9]/', '', (string) $pppoeActiveOutput);
            $dhcpLeases = (int) preg_replace('/[^0-9]/', '', (string) $dhcpLeasesOutput);

            $activeConnections = $hotspotActive + $pppoeActive;

            return [
                'status' => 'online',
                'board_name' => $systemInfo['board_name'],
                'version' => $systemInfo['version'],
                'uptime' => $systemInfo['uptime'],
                'identity' => $systemInfo['identity'],
                // CPU / Memory / Disk metrics
                'cpu_load' => $systemInfo['cpu_load'],
                'free_memory' => $systemInfo['free_memory'],
                'total_memory' => $systemInfo['total_memory'],
                'free_hdd_space' => $systemInfo['free_hdd_space'],
                'total_hdd_space' => $systemInfo['total_hdd_space'],
                // Interface and user counters
                'interfaces_count' => $interfacesCount,
                'interface_count' => $interfacesCount, // alias for frontend expectations
                'hotspot_active' => $hotspotActive,
                'pppoe_active' => $pppoeActive,
                'active_connections' => $activeConnections,
                'dhcp_leases' => $dhcpLeases,
                'interfaces' => is_array($interfaces) ? array_values($interfaces) : [],
                'last_updated' => now()->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            Log::error('SSH live data fetch failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
