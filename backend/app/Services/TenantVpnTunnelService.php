<?php

namespace App\Services;

use App\Models\TenantVpnTunnel;
use App\Models\VpnConfiguration;
use App\Models\Scopes\TenantScope;
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
        $mode = config('vpn.mode', 'dedicated');

        if ($mode === 'host') {
            return $this->createHostTunnel($tenantId);
        }

        return $this->createDedicatedTunnel($tenantId);
    }

    /**
     * Create dedicated VPN tunnel (original logic)
     */
    protected function createDedicatedTunnel(string $tenantId): TenantVpnTunnel
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

        Log::info('VPN tunnel created successfully (Dedicated)', [
            'tenant_id' => $tenantId,
            'interface' => $interfaceName,
            'subnet' => $subnet,
            'port' => $port,
        ]);

        return $tunnel;
    }

    /**
     * Create host (shared) VPN tunnel
     */
    protected function createHostTunnel(string $tenantId): TenantVpnTunnel
    {
        // 1. Allocate subnet (10.X.0.0/16)
        $subnetIndex = $this->allocateSubnetIndex();
        $subnet = "10.{$subnetIndex}.0.0/16";
        
        // Host mode uses fixed server IP and Interface
        $serverIp = config('vpn.server_ip', '10.8.0.1');
        $interfaceName = config('vpn.interface_name', 'wg0');
        $listenPort = config('vpn.listen_port', 51830);
        
        // Get configured keys
        $privateKey = config('vpn.server_private_key');
        $publicKey = config('vpn.server_public_key');

        if (empty($privateKey) || empty($publicKey)) {
             // Fallback for dev/testing if not set, but log warning
             Log::warning('VPN keys not configured for Host mode, generating temporary keys. This will cause issues if container restarts.');
             $keys = $this->generateKeys();
             $privateKey = $keys['private'];
             $publicKey = $keys['public'];
        }

        // 2. Create tunnel record
        // Note: multiple tenants will share interface_name, server_ip, keys, port
        // But have unique subnet_cidr
        $tunnel = TenantVpnTunnel::create([
            'tenant_id' => $tenantId,
            'interface_name' => $interfaceName,
            'server_private_key' => $privateKey,
            'server_public_key' => $publicKey,
            'server_ip' => $serverIp,
            'subnet_cidr' => $subnet,
            'listen_port' => $listenPort,
            'status' => 'active',
        ]);

        // 3. Ensure WireGuard interface is up (Idempotent)
        try {
            $this->ensureHostInterfaceUp($tunnel);
        } catch (\Exception $e) {
            Log::error('Failed to ensure Host WireGuard interface', [
                'tenant_id' => $tenantId,
                'interface' => $interfaceName,
                'error' => $e->getMessage(),
            ]);
            $tunnel->update(['status' => 'error']);
            throw $e;
        }

        Log::info('VPN tunnel created successfully (Host)', [
            'tenant_id' => $tenantId,
            'interface' => $interfaceName,
            'subnet' => $subnet,
        ]);

        return $tunnel;
    }

    /**
     * Read keys from existing WireGuard config file
     */
    protected function readKeysFromConfig(string $interface): ?array
    {
        $configPath = "/etc/wireguard/{$interface}.conf";
        
        if (!file_exists($configPath)) {
            return null;
        }

        $content = file_get_contents($configPath);
        $privateKey = null;
        $publicKey = null;

        // Parse PrivateKey
        if (preg_match('/PrivateKey\s*=\s*(.*)/', $content, $matches)) {
            $privateKey = trim($matches[1]);
        }

        // We can't derive Public Key from Private Key easily in PHP without libsodium or shell
        // But if we have the private key, we can generate the public key
        if ($privateKey && $this->isWireGuardInstalled()) {
            try {
                // Ensure we don't log the private key in error messages
                $publicKey = trim(shell_exec("echo '{$privateKey}' | wg pubkey"));
            } catch (\Exception $e) {
                Log::error('Failed to derive public key from config: ' . $e->getMessage());
            }
        }

        if ($privateKey && $publicKey) {
            return [
                'private' => $privateKey,
                'public' => $publicKey,
            ];
        }

        return null;
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
     * Ensure host WireGuard interface is up (idempotent for host mode)
     * Uses WireGuard Controller API instead of direct system calls
     */
    protected function ensureHostInterfaceUp(TenantVpnTunnel $tunnel): void
    {
        $controllerUrl = config('services.wireguard.controller_url');
        $apiKey = config('services.wireguard.api_key');
        
        if (empty($controllerUrl) || empty($apiKey)) {
            Log::error('WireGuard controller not configured', [
                'controller_url' => $controllerUrl,
                'api_key_set' => !empty($apiKey),
            ]);
            throw new \Exception('WireGuard controller configuration missing');
        }

        // Generate WireGuard config
        $config = $this->generateServerConfig($tunnel);
        
        try {
            // Send config to WireGuard controller
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($controllerUrl . '/vpn/apply', [
                    'interface' => $tunnel->interface_name,
                    'config' => $config,
                ]);
            
            if ($response->failed()) {
                $error = $response->json('error') ?? $response->body();
                throw new \Exception('Controller returned error: ' . $error);
            }
            
            $result = $response->json();
            
            Log::info('WireGuard interface configured via controller', [
                'interface' => $tunnel->interface_name,
                'action' => $result['action'] ?? 'unknown',
                'status' => $result['status'] ?? 'unknown',
            ]);
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Failed to connect to WireGuard controller', [
                'interface' => $tunnel->interface_name,
                'controller_url' => $controllerUrl,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('WireGuard controller unreachable: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to configure WireGuard via controller', [
                'interface' => $tunnel->interface_name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
        
        // In host network mode, Docker hostnames don't resolve in iptables
        // Use the FreeRADIUS container IP from the Docker bridge network
        // The custom bridge network is 172.70.0.0/16, FreeRADIUS typically gets .2 or .3
        // We'll use an environment variable to make this configurable
        $radiusHost = env('RADIUS_SERVER_IP', '172.70.0.2');

        // PostUp:
        // 1. Forward traffic from WG to eth0 (Internet/Services)
        // 2. Forward traffic from WG to WG (Intra-tenant Router-to-Router)
        // 3. MASQUERADE outgoing traffic on eth0
        // 4. DNAT RADIUS Auth (1812) to FreeRADIUS container
        // 5. DNAT RADIUS Acct (1813) to FreeRADIUS container
        
        $postUp = "iptables -A FORWARD -i {$tunnel->interface_name} -o eth0 -j ACCEPT; " .
                  "iptables -A FORWARD -i {$tunnel->interface_name} -o {$tunnel->interface_name} -j ACCEPT; " .
                  "iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE; " .
                  "ip route add 10.0.0.0/8 dev {$tunnel->interface_name}; " .
                  "iptables -t nat -A PREROUTING -i {$tunnel->interface_name} -p udp --dport 1812 -j DNAT --to-destination {$radiusHost}:1812; " .
                  "iptables -t nat -A PREROUTING -i {$tunnel->interface_name} -p udp --dport 1813 -j DNAT --to-destination {$radiusHost}:1813";

        $postDown = "iptables -D FORWARD -i {$tunnel->interface_name} -o eth0 -j ACCEPT; " .
                    "iptables -D FORWARD -i {$tunnel->interface_name} -o {$tunnel->interface_name} -j ACCEPT; " .
                    "iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE; " .
                    "ip route del 10.0.0.0/8 dev {$tunnel->interface_name}; " .
                    "iptables -t nat -D PREROUTING -i {$tunnel->interface_name} -p udp --dport 1812 -j DNAT --to-destination {$radiusHost}:1812; " .
                    "iptables -t nat -D PREROUTING -i {$tunnel->interface_name} -p udp --dport 1813 -j DNAT --to-destination {$radiusHost}:1813";

        return <<<EOT
[Interface]
Address = {$tunnel->server_ip}/24
ListenPort = {$tunnel->listen_port}
PrivateKey = {$privateKey}
PostUp = {$postUp}
PostDown = {$postDown}

# Peers will be added dynamically via 'wg set' command
EOT;
    }

    /**
     * Add router peer to tenant tunnel
     * Uses WireGuard Controller API to add peer to the server
     */
    public function addRouterPeer(TenantVpnTunnel $tunnel, VpnConfiguration $config): void
    {
        $controllerUrl = config('services.wireguard.controller_url');
        $apiKey = config('services.wireguard.api_key');
        
        if (empty($controllerUrl) || empty($apiKey)) {
            Log::warning('WireGuard controller not configured, skipping peer addition', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
            ]);
            return;
        }

        try {
            // Add peer via WireGuard Controller API
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($controllerUrl . '/vpn/peer/add', [
                    'interface' => $tunnel->interface_name,
                    'public_key' => $config->client_public_key,
                    'preshared_key' => $config->preshared_key,
                    'allowed_ips' => $config->client_ip . '/32',
                    'persistent_keepalive' => 25,
                ]);

            if ($response->failed()) {
                $error = $response->json('error') ?? $response->body();
                throw new \Exception('Controller returned error: ' . $error);
            }

            $result = $response->json();

            Log::info('Router peer added to tunnel via controller', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
                'client_ip' => $config->client_ip,
                'client_public_key' => substr($config->client_public_key, 0, 16) . '...',
                'status' => $result['status'] ?? 'unknown',
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Failed to connect to WireGuard controller for peer addition', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
                'controller_url' => $controllerUrl,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('WireGuard controller unreachable: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to add router peer via controller', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Remove router peer from tenant tunnel
     * Uses WireGuard Controller API to remove peer from the server
     */
    public function removeRouterPeer(TenantVpnTunnel $tunnel, VpnConfiguration $config): void
    {
        $controllerUrl = config('services.wireguard.controller_url');
        $apiKey = config('services.wireguard.api_key');
        
        if (empty($controllerUrl) || empty($apiKey)) {
            Log::warning('WireGuard controller not configured, skipping peer removal');
            return;
        }

        try {
            // Remove peer via WireGuard Controller API
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($controllerUrl . '/vpn/peer/remove', [
                    'interface' => $tunnel->interface_name,
                    'public_key' => $config->client_public_key,
                ]);

            if ($response->failed()) {
                $error = $response->json('error') ?? $response->body();
                Log::warning('Failed to remove peer via controller', [
                    'interface' => $tunnel->interface_name,
                    'router_id' => $config->router_id,
                    'error' => $error,
                ]);
                return;
            }

            Log::info('Router peer removed from tunnel via controller', [
                'interface' => $tunnel->interface_name,
                'router_id' => $config->router_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove router peer via controller', [
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
