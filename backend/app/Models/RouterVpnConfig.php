<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class RouterVpnConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'router_id',
        'wireguard_public_key',
        'wireguard_private_key',
        'vpn_ip_address',
        'listen_port',
        'vpn_connected',
        'last_handshake',
        'bytes_received',
        'bytes_sent',
        'radius_server_ip',
        'radius_auth_port',
        'radius_acct_port',
        'radius_secret',
    ];

    protected $casts = [
        'vpn_connected' => 'boolean',
        'last_handshake' => 'datetime',
        'bytes_received' => 'integer',
        'bytes_sent' => 'integer',
        'listen_port' => 'integer',
        'radius_auth_port' => 'integer',
        'radius_acct_port' => 'integer',
    ];

    protected $hidden = [
        'wireguard_private_key',
        'radius_secret',
    ];

    /**
     * Get the router that owns the VPN config
     */
    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Get decrypted private key
     */
    public function getDecryptedPrivateKeyAttribute(): ?string
    {
        if (!$this->wireguard_private_key) {
            return null;
        }

        try {
            return Crypt::decryptString($this->wireguard_private_key);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set encrypted private key
     */
    public function setWireguardPrivateKeyAttribute($value): void
    {
        if ($value) {
            $this->attributes['wireguard_private_key'] = Crypt::encryptString($value);
        }
    }

    /**
     * Check if VPN is connected
     */
    public function isConnected(): bool
    {
        return $this->vpn_connected && 
               $this->last_handshake && 
               $this->last_handshake->gt(now()->subMinutes(5));
    }

    /**
     * Get formatted data usage
     */
    public function getFormattedDataUsageAttribute(): array
    {
        return [
            'received' => $this->formatBytes($this->bytes_received),
            'sent' => $this->formatBytes($this->bytes_sent),
            'total' => $this->formatBytes($this->bytes_received + $this->bytes_sent),
        ];
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' B';
    }

    /**
     * Generate MikroTik configuration script
     */
    public function generateMikroTikConfig(string $serverPublicKey, string $serverEndpoint): string
    {
        $privateKey = $this->getDecryptedPrivateKeyAttribute();
        
        return <<<ROUTEROS
# ============================================================================
# WireGuard VPN Configuration for {$this->router->name}
# Generated: {$this->created_at->format('Y-m-d H:i:s')}
# ============================================================================

# Create WireGuard interface
/interface/wireguard
add name=wg-radius listen-port={$this->listen_port} private-key="{$privateKey}"

# Assign VPN IP address
/ip/address
add address={$this->vpn_ip_address}/32 interface=wg-radius

# Add server peer
/interface/wireguard/peers
add interface=wg-radius \\
    public-key="{$serverPublicKey}" \\
    endpoint-address={$serverEndpoint} \\
    endpoint-port=51820 \\
    allowed-address=10.10.10.0/24 \\
    persistent-keepalive=25s

# Add route to VPN network
/ip/route
add dst-address=10.10.10.0/24 gateway=wg-radius comment="WireGuard VPN Route"

# ============================================================================
# RADIUS Configuration
# ============================================================================

# Add RADIUS server
/radius
add address={$this->radius_server_ip} \\
    secret="{$this->radius_secret}" \\
    service=hotspot \\
    authentication-port={$this->radius_auth_port} \\
    accounting-port={$this->radius_acct_port} \\
    timeout=3s \\
    comment="Hotspot RADIUS via VPN"

# Configure hotspot profile to use RADIUS
/ip/hotspot/profile
set default use-radius=yes

# Enable RADIUS accounting
/ip/hotspot/profile
set default radius-accounting=yes

# ============================================================================
# Verification Commands
# ============================================================================

# Test VPN connectivity
# /ping {$this->radius_server_ip} count=5

# Check WireGuard status
# /interface/wireguard/peers/print

# Monitor RADIUS
# /radius/monitor [find]

# ============================================================================
ROUTEROS;
    }
}
