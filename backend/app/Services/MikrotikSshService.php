<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

/**
 * SSH-based MikroTik router management service
 * Used as fallback when API is unavailable or times out
 */
class MikrotikSshService
{
    /**
     * Connect to router via SSH
     */
    private function connect(Router $router, string $decryptedPassword): SSH2
    {
        $ip = $router->vpn_ip ?? $router->ip_address;
        $host = explode('/', $ip)[0];
        
        $ssh = new SSH2($host, 22, 10); // 10 second timeout
        
        if (!$ssh->login($router->username, $decryptedPassword)) {
            throw new \Exception('SSH authentication failed');
        }
        
        return $ssh;
    }

    /**
     * Fetch router interfaces via SSH
     * @param Router $router
     * @param bool $filterConfigurable Only return configurable interfaces
     * @return array
     */
    public function fetchInterfaces(Router $router, bool $filterConfigurable = false): array
    {
        try {
            $decryptedPassword = Crypt::decryptString($router->password);
            $ssh = $this->connect($router, $decryptedPassword);
            
            // Get interfaces
            $interfaceOutput = $ssh->exec('/interface print detail without-paging');
            
            // Get system info
            $resourceOutput = $ssh->exec('/system resource print');
            $identityOutput = $ssh->exec('/system identity print');
            
            $ssh->disconnect();
            
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
            $decryptedPassword = Crypt::decryptString($router->password);
            $ssh = $this->connect($router, $decryptedPassword);
            $output = $ssh->exec('/system identity print');
            $ssh->disconnect();
            
            return !empty($output);
        } catch (\Exception $e) {
            return false;
        }
    }
}
