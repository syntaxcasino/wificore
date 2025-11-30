<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterVpnConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class WireGuardService extends TenantAwareService
{
    protected string $configPath = '/etc/wireguard/wg0.conf';
    protected string $serverPublicKeyPath = '/etc/wireguard/server_public.key';

    /**
     * Generate WireGuard key pair
     */
    public function generateKeyPair(): array
    {
        // Generate private key
        $privateKey = trim(shell_exec('wg genkey'));
        
        // Generate public key from private key
        $publicKey = trim(shell_exec("echo '{$privateKey}' | wg pubkey"));
        
        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }

    /**
     * Get next available VPN IP address
     */
    public function getNextAvailableIp(): string
    {
        // Get all used IPs
        $usedIps = RouterVpnConfig::pluck('vpn_ip_address')->toArray();
        
        // Start from 10.10.10.2 (10.10.10.1 is server)
        for ($i = 2; $i <= 254; $i++) {
            $ip = "10.10.10.{$i}";
            if (!in_array($ip, $usedIps)) {
                return $ip;
            }
        }
        
        throw new \Exception('No available VPN IP addresses');
    }

    /**
     * Create VPN configuration for a router
     */
    public function createRouterVpnConfig(Router $router): RouterVpnConfig
    {
        // Generate keys
        $keys = $this->generateKeyPair();
        
        // Get next available IP
        $vpnIp = $this->getNextAvailableIp();
        
        // Generate RADIUS secret
        $radiusSecret = Str::random(32);
        
        // Create VPN config
        $vpnConfig = RouterVpnConfig::create([
            'router_id' => $router->id,
            'wireguard_public_key' => $keys['public_key'],
            'wireguard_private_key' => $keys['private_key'], // Will be encrypted by model
            'vpn_ip_address' => $vpnIp,
            'listen_port' => 13231,
            'radius_secret' => $radiusSecret,
        ]);
        
        // Add peer to WireGuard server
        $this->addPeerToServer($vpnConfig);
        
        // Add to RADIUS clients
        $this->addRadiusClient($vpnConfig);
        
        // Add to database nas table
        $this->addToNasTable($vpnConfig);
        
        Log::info('VPN configuration created for router', [
            'router_id' => $router->id,
            'vpn_ip' => $vpnIp,
            'public_key' => $keys['public_key'],
        ]);
        
        return $vpnConfig;
    }

    /**
     * Add peer to WireGuard server configuration
     */
    public function addPeerToServer(RouterVpnConfig $vpnConfig): void
    {
        $router = $vpnConfig->router;
        
        $peerConfig = "\n# {$router->name} (ID: {$router->id})\n";
        $peerConfig .= "[Peer]\n";
        $peerConfig .= "PublicKey = {$vpnConfig->wireguard_public_key}\n";
        $peerConfig .= "AllowedIPs = {$vpnConfig->vpn_ip_address}/32\n";
        $peerConfig .= "PersistentKeepalive = 25\n";
        $peerConfig .= "# Created: " . now()->toDateTimeString() . "\n\n";
        
        // Append to WireGuard config
        File::append($this->configPath, $peerConfig);
        
        // Reload WireGuard without disrupting connections
        $this->reloadWireGuard();
        
        Log::info('Peer added to WireGuard server', [
            'router_id' => $router->id,
            'public_key' => $vpnConfig->wireguard_public_key,
        ]);
    }

    /**
     * Remove peer from WireGuard server
     */
    public function removePeerFromServer(RouterVpnConfig $vpnConfig): void
    {
        $config = File::get($this->configPath);
        
        // Remove peer section
        $pattern = "/\n# .*\(ID: {$vpnConfig->router_id}\).*?\[Peer\].*?(?=\n\[|$)/s";
        $config = preg_replace($pattern, '', $config);
        
        File::put($this->configPath, $config);
        
        $this->reloadWireGuard();
        
        Log::info('Peer removed from WireGuard server', [
            'router_id' => $vpnConfig->router_id,
        ]);
    }

    /**
     * Reload WireGuard configuration
     */
    protected function reloadWireGuard(): void
    {
        try {
            // Use wg syncconf to reload without disrupting connections
            exec('sudo wg syncconf wg0 <(wg-quick strip wg0) 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::warning('WireGuard reload failed', [
                    'output' => implode("\n", $output),
                    'return_code' => $returnCode,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to reload WireGuard', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add router to RADIUS clients configuration
     */
    protected function addRadiusClient(RouterVpnConfig $vpnConfig): void
    {
        $router = $vpnConfig->router;
        $clientConfig = "\n# {$router->name}\n";
        $clientConfig .= "client {$router->name} {\n";
        $clientConfig .= "    ipaddr = {$vpnConfig->vpn_ip_address}\n";
        $clientConfig .= "    secret = {$vpnConfig->radius_secret}\n";
        $clientConfig .= "    shortname = {$router->name}\n";
        $clientConfig .= "    nas_type = mikrotik\n";
        $clientConfig .= "}\n\n";
        
        // Append to RADIUS clients.conf
        $radiusClientsPath = '/etc/freeradius/3.0/clients.conf';
        if (File::exists($radiusClientsPath)) {
            File::append($radiusClientsPath, $clientConfig);
            
            // Reload FreeRADIUS
            exec('sudo systemctl reload freeradius 2>&1', $output, $returnCode);
            
            Log::info('RADIUS client added', [
                'router_id' => $router->id,
                'vpn_ip' => $vpnConfig->vpn_ip_address,
            ]);
        }
    }

    /**
     * Add router to database nas table
     */
    protected function addToNasTable(RouterVpnConfig $vpnConfig): void
    {
        $router = $vpnConfig->router;
        
        \DB::table('nas')->insert([
            'nasname' => $vpnConfig->vpn_ip_address,
            'shortname' => $router->name,
            'type' => 'mikrotik',
            'secret' => $vpnConfig->radius_secret,
            'description' => "{$router->name} via WireGuard VPN",
        ]);
        
        Log::info('Router added to nas table', [
            'router_id' => $router->id,
            'nasname' => $vpnConfig->vpn_ip_address,
        ]);
    }

    /**
     * Get WireGuard peer status
     */
    public function getPeerStatus(string $publicKey): ?array
    {
        $output = shell_exec('sudo wg show wg0 dump');
        
        if (!$output) {
            return null;
        }
        
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            
            if (count($parts) >= 6 && $parts[0] === $publicKey) {
                return [
                    'public_key' => $parts[0],
                    'endpoint' => $parts[2] ?? null,
                    'allowed_ips' => $parts[3] ?? null,
                    'latest_handshake' => $parts[4] ? now()->subSeconds((int)$parts[4]) : null,
                    'bytes_received' => (int)($parts[5] ?? 0),
                    'bytes_sent' => (int)($parts[6] ?? 0),
                    'connected' => ((int)$parts[4]) < 180, // Connected if handshake within 3 minutes
                ];
            }
        }
        
        return null;
    }

    /**
     * Update VPN connection status for all routers
     */
    public function updateAllPeerStatuses(): void
    {
        $vpnConfigs = RouterVpnConfig::all();
        
        foreach ($vpnConfigs as $vpnConfig) {
            $status = $this->getPeerStatus($vpnConfig->wireguard_public_key);
            
            if ($status) {
                $vpnConfig->update([
                    'vpn_connected' => $status['connected'],
                    'last_handshake' => $status['latest_handshake'],
                    'bytes_received' => $status['bytes_received'],
                    'bytes_sent' => $status['bytes_sent'],
                ]);
            } else {
                $vpnConfig->update([
                    'vpn_connected' => false,
                ]);
            }
        }
    }

    /**
     * Get server public key
     */
    public function getServerPublicKey(): string
    {
        if (File::exists($this->serverPublicKeyPath)) {
            return trim(File::get($this->serverPublicKeyPath));
        }
        
        throw new \Exception('Server public key not found');
    }

    /**
     * Get server endpoint (public IP:port)
     */
    public function getServerEndpoint(): string
    {
        $publicIp = config('wireguard.server_public_ip');
        $port = config('wireguard.server_port', 51820);
        
        return "{$publicIp}:{$port}";
    }

    /**
     * Generate MikroTik configuration script for router
     */
    public function generateMikroTikScript(RouterVpnConfig $vpnConfig): string
    {
        $serverPublicKey = $this->getServerPublicKey();
        $serverEndpoint = $this->getServerEndpoint();
        
        return $vpnConfig->generateMikroTikConfig($serverPublicKey, $serverEndpoint);
    }
}
