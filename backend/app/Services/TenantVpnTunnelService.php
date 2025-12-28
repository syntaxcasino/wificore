<?php

namespace App\Services;

use App\Models\TenantVpnTunnel;
use App\Models\VpnConfiguration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class TenantVpnTunnelService
{
    /**
     * Get or create VPN tunnel for tenant
     */
    public function getOrCreateTenantTunnel(string $tenantId): TenantVpnTunnel
    {
        // Check if tenant already has tunnel
        $tunnel = TenantVpnTunnel::where('tenant_id', $tenantId)->first();

        if ($tunnel) {
            Log::info('Using existing VPN tunnel for tenant', [
                'tenant_id' => $tenantId,
                'interface' => $tunnel->interface_name,
            ]);
            return $tunnel;
        }

        // Create new tunnel
        Log::info('Creating new VPN tunnel for tenant', ['tenant_id' => $tenantId]);
        return $this->createTenantTunnel($tenantId);
    }

    /**
     * Create new VPN tunnel for tenant
     */
    protected function createTenantTunnel(string $tenantId): TenantVpnTunnel
    {
        // 1. Allocate subnet (10.X.0.0/16)
        $subnetIndex = $this->allocateSubnetIndex();
        $subnet = "10.{$subnetIndex}.0.0/16";
        $serverIp = "10.{$subnetIndex}.0.1";

        // 2. Generate server keys
        $serverKeys = $this->generateKeys();

        // 3. Allocate interface name (wg0, wg1, etc.)
        $interfaceName = $this->allocateInterface();

        // 4. Allocate port (51820, 51821, etc.)
        $port = $this->allocatePort();

        // 5. Create tunnel record
        $tunnel = TenantVpnTunnel::create([
            'tenant_id' => $tenantId,
            'interface_name' => $interfaceName,
            'server_private_key' => $serverKeys['private'], // Will be encrypted by mutator
            'server_public_key' => $serverKeys['public'],
            'server_ip' => $serverIp,
            'subnet_cidr' => $subnet,
            'listen_port' => $port,
            'status' => 'active',
        ]);

        // 6. Create WireGuard interface on server
        try {
            $this->createWireGuardInterface($tunnel);
        } catch (\Exception $e) {
            Log::error('Failed to create WireGuard interface', [
                'tenant_id' => $tenantId,
                'interface' => $interfaceName,
                'error' => $e->getMessage(),
            ]);
            $tunnel->update(['status' => 'error']);
            throw $e;
        }

        Log::info('VPN tunnel created successfully', [
            'tenant_id' => $tenantId,
            'interface' => $interfaceName,
            'subnet' => $subnet,
            'port' => $port,
        ]);

        return $tunnel;
    }

    /**
     * Allocate subnet index (100-999)
     */
    protected function allocateSubnetIndex(): int
    {
        // Get all existing subnet indices across ALL tenants (bypass tenant scope)
        $existingIndices = TenantVpnTunnel::withoutGlobalScope(TenantScope::class)
            ->get()
            ->map(function ($tunnel) {
                // Extract X from 10.X.0.0/16
                $parts = explode('.', explode('/', $tunnel->subnet_cidr)[0]);
                return (int) $parts[1];
            })
            ->toArray();

        // Find next available index (start from 100)
        for ($i = 100; $i <= 999; $i++) {
            if (!in_array($i, $existingIndices)) {
                return $i;
            }
        }

        throw new \Exception('No available subnet indices');
    }

    /**
     * Allocate interface name (wg0, wg1, etc.)
     */
    protected function allocateInterface(): string
    {
        // Get all existing interfaces across ALL tenants (bypass tenant scope)
        $existingInterfaces = TenantVpnTunnel::withoutGlobalScope(TenantScope::class)
            ->pluck('interface_name')
            ->toArray();

        // Find next available interface
        for ($i = 0; $i <= 99; $i++) {
            $interface = "wg{$i}";
            if (!in_array($interface, $existingInterfaces)) {
                return $interface;
            }
        }

        throw new \Exception('No available interface names');
    }

    /**
     * Allocate port (51820, 51821, etc.)
     */
    protected function allocatePort(): int
    {
        // Get all existing ports across ALL tenants (bypass tenant scope)
        $existingPorts = TenantVpnTunnel::withoutGlobalScope(TenantScope::class)
            ->pluck('listen_port')
            ->toArray();

        // Find next available port (start from 51820)
        for ($port = 51820; $port <= 51920; $port++) {
            if (!in_array($port, $existingPorts)) {
                return $port;
            }
        }

        throw new \Exception('No available ports');
    }

    /**
     * Generate WireGuard key pair
     */
    protected function generateKeys(): array
    {
        // Check if wg command is available
        if (!$this->isWireGuardInstalled()) {
            Log::warning('WireGuard not installed, using mock keys');
            return $this->generateMockKeys();
        }

        try {
            // Generate private key
            $privateKey = trim(shell_exec('wg genkey'));

            // Generate public key from private key
            $publicKey = trim(shell_exec("echo '{$privateKey}' | wg pubkey"));

            return [
                'private' => $privateKey,
                'public' => $publicKey,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to generate real keys, using mock', ['error' => $e->getMessage()]);
            return $this->generateMockKeys();
        }
    }

    /**
     * Generate mock keys for development
     */
    protected function generateMockKeys(): array
    {
        return [
            'private' => base64_encode(random_bytes(32)),
            'public' => base64_encode(random_bytes(32)),
        ];
    }

    /**
     * Check if WireGuard is installed
     */
    protected function isWireGuardInstalled(): bool
    {
        return !empty(shell_exec('which wg'));
    }

    /**
     * Create WireGuard interface on server
     */
    protected function createWireGuardInterface(TenantVpnTunnel $tunnel): void
    {
        if (!$this->isWireGuardInstalled()) {
            Log::warning('WireGuard not installed, skipping interface creation');
            return;
        }

        // Generate WireGuard config
        $config = $this->generateServerConfig($tunnel);

        // Save to /etc/wireguard/{interface}.conf
        $configPath = "/etc/wireguard/{$tunnel->interface_name}.conf";

        try {
            file_put_contents($configPath, $config);
            chmod($configPath, 0600);

            // Start interface
            shell_exec("wg-quick up {$tunnel->interface_name} 2>&1");

            // Enable on boot
            shell_exec("systemctl enable wg-quick@{$tunnel->interface_name} 2>&1");

            Log::info('WireGuard interface created', [
                'interface' => $tunnel->interface_name,
                'config_path' => $configPath,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create WireGuard interface', [
                'interface' => $tunnel->interface_name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate WireGuard server config
     */
    protected function generateServerConfig(TenantVpnTunnel $tunnel): string
    {
        $privateKey = decrypt($tunnel->server_private_key);
        $radiusHost = config('services.radius.host', env('RADIUS_SERVER_HOST', 'wificore-freeradius'));

        // PostUp:
        // 1. Forward traffic from WG to eth0 (Internet/Services)
        // 2. Forward traffic from WG to WG (Intra-tenant Router-to-Router)
        // 3. MASQUERADE outgoing traffic on eth0
        // 4. DNAT RADIUS Auth (1812) to FreeRADIUS container
        // 5. DNAT RADIUS Acct (1813) to FreeRADIUS container
        
        $postUp = "iptables -A FORWARD -i {$tunnel->interface_name} -o eth0 -j ACCEPT; " .
                  "iptables -A FORWARD -i {$tunnel->interface_name} -o {$tunnel->interface_name} -j ACCEPT; " .
                  "iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE; " .
                  "iptables -t nat -A PREROUTING -i {$tunnel->interface_name} -p udp --dport 1812 -j DNAT --to-destination {$radiusHost}:1812; " .
                  "iptables -t nat -A PREROUTING -i {$tunnel->interface_name} -p udp --dport 1813 -j DNAT --to-destination {$radiusHost}:1813";

        $postDown = "iptables -D FORWARD -i {$tunnel->interface_name} -o eth0 -j ACCEPT; " .
                    "iptables -D FORWARD -i {$tunnel->interface_name} -o {$tunnel->interface_name} -j ACCEPT; " .
                    "iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE; " .
                    "iptables -t nat -D PREROUTING -i {$tunnel->interface_name} -p udp --dport 1812 -j DNAT --to-destination {$radiusHost}:1812; " .
                    "iptables -t nat -D PREROUTING -i {$tunnel->interface_name} -p udp --dport 1813 -j DNAT --to-destination {$radiusHost}:1813";

        return <<<EOT
[Interface]
Address = {$tunnel->server_ip}/16
ListenPort = {$tunnel->listen_port}
PrivateKey = {$privateKey}
PostUp = {$postUp}
PostDown = {$postDown}

# Peers will be added dynamically via 'wg set' command
EOT;
    }

    /**
     * Add router peer to tenant tunnel
     */
    public function addRouterPeer(TenantVpnTunnel $tunnel, VpnConfiguration $config): void
    {
        if (!$this->isWireGuardInstalled()) {
            Log::warning('WireGuard not installed, skipping peer addition');
            return;
        }

        try {
            // Add peer to WireGuard interface
            $command = sprintf(
                'wg set %s peer %s allowed-ips %s/32 persistent-keepalive 25',
                $tunnel->interface_name,
                $config->client_public_key,
                $config->client_ip
            );

            shell_exec($command . ' 2>&1');

            // Persist config
            shell_exec("wg-quick save {$tunnel->interface_name} 2>&1");

            Log::info('Router peer added to tunnel', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
                'client_ip' => $config->client_ip,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to add router peer', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Remove router peer from tenant tunnel
     */
    public function removeRouterPeer(TenantVpnTunnel $tunnel, VpnConfiguration $config): void
    {
        if (!$this->isWireGuardInstalled()) {
            return;
        }

        try {
            // Remove peer from WireGuard interface
            $command = sprintf(
                'wg set %s peer %s remove',
                $tunnel->interface_name,
                $config->client_public_key
            );

            shell_exec($command . ' 2>&1');

            // Persist config
            shell_exec("wg-quick save {$tunnel->interface_name} 2>&1");

            Log::info('Router peer removed from tunnel', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove router peer', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update tunnel statistics
     */
    public function updateTunnelStatistics(TenantVpnTunnel $tunnel): void
    {
        if (!$this->isWireGuardInstalled()) {
            return;
        }

        try {
            // Get tunnel stats
            $output = shell_exec("wg show {$tunnel->interface_name} dump 2>&1");

            if (empty($output)) {
                return;
            }

            $lines = explode("\n", trim($output));
            $connectedPeers = 0;
            $totalReceived = 0;
            $totalSent = 0;
            $latestHandshake = null;

            foreach ($lines as $line) {
                $parts = explode("\t", $line);
                if (count($parts) >= 6) {
                    $connectedPeers++;
                    $totalReceived += (int) ($parts[5] ?? 0);
                    $totalSent += (int) ($parts[6] ?? 0);

                    $handshake = (int) ($parts[4] ?? 0);
                    if ($handshake > 0) {
                        $handshakeTime = now()->subSeconds(time() - $handshake);
                        if (!$latestHandshake || $handshakeTime->gt($latestHandshake)) {
                            $latestHandshake = $handshakeTime;
                        }
                    }
                }
            }

            $tunnel->update([
                'connected_peers' => $connectedPeers,
                'bytes_received' => $totalReceived,
                'bytes_sent' => $totalSent,
                'last_handshake_at' => $latestHandshake,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update tunnel statistics', [
                'interface' => $tunnel->interface_name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
