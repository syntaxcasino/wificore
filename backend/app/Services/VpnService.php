<?php

namespace App\Services;

use App\Models\VpnConfiguration;
use App\Models\Tenant;
use App\Models\Router;
use App\Models\TenantVpnTunnel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VpnService
{
    protected TenantVpnTunnelService $tunnelService;

    public function __construct(TenantVpnTunnelService $tunnelService)
    {
        $this->tunnelService = $tunnelService;
    }
    /**
     * Generate WireGuard keypair
     */
    public function generateWireGuardKeys(): array
    {
        // Generate private key
        $privateKey = $this->executeCommand('wg genkey');
        
        // Generate public key from private key
        $publicKey = $this->executeCommand("echo '{$privateKey}' | wg pubkey");
        
        return [
            'private_key' => trim($privateKey),
            'public_key' => trim($publicKey),
        ];
    }

    /**
     * Generate preshared key for additional security
     */
    public function generatePresharedKey(): string
    {
        return trim($this->executeCommand('wg genpsk'));
    }

    /**
     * Allocate or get tenant's VPN subnet
     */
    public function allocateTenantSubnet(Tenant $tenant): VpnSubnetAllocation
    {
        // Check if tenant already has a subnet
        $existing = VpnSubnetAllocation::where('tenant_id', $tenant->id)->first();
        
        if ($existing) {
            return $existing;
        }

        // Find next available subnet octet (10.X.0.0/16)
        // Start from 100 to avoid conflicts with common private networks
        $usedOctets = VpnSubnetAllocation::pluck('subnet_octet_2')->toArray();
        
        $octet = 100;
        while (in_array($octet, $usedOctets) && $octet < 255) {
            $octet++;
        }

        if ($octet >= 255) {
            throw new \Exception('No available VPN subnets. All subnets are allocated.');
        }

        // Create subnet allocation
        $subnet = VpnSubnetAllocation::create([
            'tenant_id' => $tenant->id,
            'subnet_cidr' => "10.{$octet}.0.0/16",
            'subnet_octet_2' => $octet,
            'gateway_ip' => "10.{$octet}.0.1",
            'range_start' => "10.{$octet}.1.1",
            'range_end' => "10.{$octet}.255.254",
            'total_ips' => 65534,
            'allocated_ips' => 0,
            'available_ips' => 65534,
            'status' => 'active',
        ]);

        Log::info('VPN subnet allocated', [
            'tenant_id' => $tenant->id,
            'subnet' => $subnet->subnet_cidr,
        ]);

        return $subnet;
    }

    /**
     * Get next available IP in tenant's subnet
     */
    public function getNextAvailableIp(VpnSubnetAllocation $subnet): string
    {
        // Get all allocated IPs in this subnet
        $allocatedIps = VpnConfiguration::where('subnet_cidr', $subnet->subnet_cidr)
            ->pluck('client_ip')
            ->toArray();

        // Start from .1.1 and find first available
        $octet2 = $subnet->subnet_octet_2;
        
        for ($octet3 = 1; $octet3 <= 255; $octet3++) {
            for ($octet4 = 1; $octet4 <= 254; $octet4++) {
                $ip = "10.{$octet2}.{$octet3}.{$octet4}";
                
                // Skip gateway IP
                if ($ip === $subnet->gateway_ip) {
                    continue;
                }
                
                if (!in_array($ip, $allocatedIps)) {
                    return $ip;
                }
            }
        }

        throw new \Exception('No available IPs in subnet');
    }

    /**
     * Create VPN configuration for a router
     */
    public function createVpnConfiguration(Tenant $tenant, Router $router): VpnConfiguration
    {
        return DB::transaction(function () use ($tenant, $router) {
            // 1. Get or create tenant VPN tunnel (ONE per tenant)
            $tunnel = $this->tunnelService->getOrCreateTenantTunnel($tenant->id);
            
            // 2. Allocate IP address for this router from tunnel subnet
            $clientIp = $tunnel->getNextAvailableIp();
            
            // 3. Generate client (router) keys
            $clientKeys = $this->generateWireGuardKeys();
            
            // 4. Get server configuration from tunnel
            $serverEndpoint = config('vpn.server_endpoint');
            
            // 5. Create VPN configuration for this router
            // Note: No tenant_id needed - table is in tenant schema, isolation is implicit
            $interfaceName = 'wg-' . substr($router->id, 0, 8); // wg-531ddd0e
            
            $vpnConfig = VpnConfiguration::create([
                'tenant_vpn_tunnel_id' => $tunnel->id,
                'router_id' => $router->id,
                'client_private_key' => $clientKeys['private_key'],
                'client_public_key' => $clientKeys['public_key'],
                'client_ip' => $clientIp,
                'server_ip' => $tunnel->server_ip,
                'server_public_key' => $tunnel->server_public_key,
                'subnet_cidr' => $tunnel->subnet_cidr,
                'server_endpoint' => $serverEndpoint,
                'listen_port' => $tunnel->listen_port,
                'interface_name' => $interfaceName,
                'preshared_key' => $this->generatePresharedKey(),
                'status' => 'pending',
            ]);
            
            // 6. Add router as peer to tenant tunnel
            $this->tunnelService->addRouterPeer($tunnel, $vpnConfig);
            
            // 7. Generate configuration scripts
            $vpnConfig->mikrotik_script = $this->generateMikrotikScript($vpnConfig);
            $vpnConfig->linux_script = $this->generateLinuxScript($vpnConfig);
            
            // 8. Update router with VPN IP
            $router->update([
                'vpn_ip' => $clientIp,
                'ip_address' => $clientIp . '/32', // Use VPN IP as primary management IP
            ]);
            
            // 9. Set VPN config to active
            $vpnConfig->status = 'active';
            $vpnConfig->save();
            
            Log::info('VPN configuration created and activated for router', [
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'tunnel_id' => $tunnel->id,
                'interface' => $tunnel->interface_name,
                'client_ip' => $clientIp,
                'vpn_status' => 'active',
            ]);
            
            return $vpnConfig;
        });
    }

    /**
     * Generate MikroTik RouterOS script
     */
    public function generateMikroTikScript(VpnConfiguration $config): string
    {
        $interfaceName = $config->interface_name;
        $listenPort = $config->listen_port;
        
        return <<<SCRIPT
/interface wireguard add name={$interfaceName} listen-port={$listenPort} private-key="{$config->client_private_key}"
/ip address add address={$config->client_ip}/32 interface={$interfaceName}
/interface wireguard peers add interface={$interfaceName} public-key="{$config->server_public_key}" preshared-key="{$config->preshared_key}" endpoint-address={$this->getEndpointHost($config->server_endpoint)} endpoint-port={$this->getEndpointPort($config->server_endpoint)} allowed-address=0.0.0.0/0 persistent-keepalive=00:00:25
/ip firewall filter add chain=input action=accept protocol=udp dst-port={$listenPort} comment="Allow WireGuard VPN"
SCRIPT;
    }

    /**
     * Generate Linux WireGuard configuration
     */
    public function generateLinuxScript(VpnConfiguration $config): string
    {
        $interfaceName = $config->interface_name;
        
        return <<<SCRIPT
# WireGuard VPN Configuration for Linux
# Generated for Tenant: {$config->tenant_id}
# Client IP: {$config->client_ip}

[Interface]
PrivateKey = {$config->client_private_key}
Address = {$config->client_ip}/16
DNS = {$this->formatDnsServers($config->dns_servers)}

[Peer]
PublicKey = {$config->server_public_key}
PresharedKey = {$config->preshared_key}
Endpoint = {$config->server_endpoint}
AllowedIPs = {$this->formatAllowedIps($config->allowed_ips)}
PersistentKeepalive = {$config->keepalive_interval}

# To use this configuration:
# 1. Save this file as /etc/wireguard/{$interfaceName}.conf
# 2. Run: sudo wg-quick up {$interfaceName}
# 3. Enable on boot: sudo systemctl enable wg-quick@{$interfaceName}
SCRIPT;
    }

    /**
     * Update VPN connection status
     */
    public function updateConnectionStatus(VpnConfiguration $config, array $stats): void
    {
        $config->update([
            'last_handshake_at' => $stats['last_handshake_at'] ?? null,
            'rx_bytes' => $stats['rx_bytes'] ?? $config->rx_bytes,
            'tx_bytes' => $stats['tx_bytes'] ?? $config->tx_bytes,
            'status' => $stats['status'] ?? $config->status,
        ]);
    }

    /**
     * Delete VPN configuration and release IP
     */
    public function deleteVpnConfiguration(VpnConfiguration $config): void
    {
        DB::transaction(function () use ($config) {
            $subnet = VpnSubnetAllocation::where('subnet_cidr', $config->subnet_cidr)->first();
            
            if ($subnet) {
                $subnet->releaseIp();
            }

            $config->delete();

            Log::info('VPN configuration deleted', [
                'vpn_config_id' => $config->id,
                'tenant_id' => $config->tenant_id,
                'client_ip' => $config->client_ip,
            ]);
        });
    }

    /**
     * Helper methods
     */
    private function executeCommand(string $command): string
    {
        // Execute actual WireGuard commands
        try {
            $output = shell_exec($command . ' 2>&1');
            
            if ($output === null) {
                throw new \Exception('Failed to execute command: ' . $command);
            }
            
            return $output;
        } catch (\Exception $e) {
            Log::error('Failed to execute WireGuard command', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to generate WireGuard keys: ' . $e->getMessage());
        }
    }

    private function getEndpointHost(string $endpoint): string
    {
        return explode(':', $endpoint)[0];
    }

    private function getEndpointPort(string $endpoint): int
    {
        $parts = explode(':', $endpoint);
        return isset($parts[1]) ? (int)$parts[1] : 51830;
    }

    private function formatDnsServers(?array $servers): string
    {
        return $servers ? implode(', ', $servers) : '8.8.8.8, 8.8.4.4';
    }

    private function formatAllowedIps(?array $ips): string
    {
        return $ips ? implode(', ', $ips) : '0.0.0.0/0';
    }
}
