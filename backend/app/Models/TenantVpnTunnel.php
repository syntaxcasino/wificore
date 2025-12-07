<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantVpnTunnel extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'interface_name',
        'server_private_key',
        'server_public_key',
        'server_ip',
        'subnet_cidr',
        'listen_port',
        'status',
        'last_handshake_at',
        'connected_peers',
        'bytes_received',
        'bytes_sent',
    ];

    protected $casts = [
        'last_handshake_at' => 'datetime',
        'connected_peers' => 'integer',
        'bytes_received' => 'integer',
        'bytes_sent' => 'integer',
    ];

    protected $hidden = [
        'server_private_key',
    ];

    /**
     * Get the tenant that owns this VPN tunnel
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get all VPN configurations (router peers) for this tunnel
     */
    public function vpnConfigurations(): HasMany
    {
        return $this->hasMany(VpnConfiguration::class, 'tenant_vpn_tunnel_id');
    }

    /**
     * Get all routers connected to this tunnel
     */
    public function routers()
    {
        return $this->hasManyThrough(
            Router::class,
            VpnConfiguration::class,
            'tenant_vpn_tunnel_id', // Foreign key on vpn_configurations
            'id', // Foreign key on routers
            'id', // Local key on tenant_vpn_tunnels
            'router_id' // Local key on vpn_configurations
        );
    }

    /**
     * Accessor for decrypted server private key
     */
    public function getServerPrivateKeyDecryptedAttribute(): string
    {
        return decrypt($this->server_private_key);
    }

    /**
     * Mutator to encrypt server private key
     */
    public function setServerPrivateKeyAttribute($value): void
    {
        $this->attributes['server_private_key'] = encrypt($value);
    }

    /**
     * Check if tunnel is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if tunnel has recent handshake
     */
    public function hasRecentHandshake(): bool
    {
        if (!$this->last_handshake_at) {
            return false;
        }

        return $this->last_handshake_at->diffInMinutes(now()) < 5;
    }

    /**
     * Get next available IP in subnet
     */
    public function getNextAvailableIp(): string
    {
        // Extract base subnet (e.g., 10.100 from 10.100.0.0/16)
        [$base, $mask] = explode('/', $this->subnet_cidr);
        $parts = explode('.', $base);
        $baseSubnet = "{$parts[0]}.{$parts[1]}";

        // Get all allocated IPs for this tunnel
        $allocatedIps = $this->vpnConfigurations()
            ->pluck('client_ip')
            ->map(function ($ip) {
                // Extract last octet (e.g., 1 from 10.100.1.1)
                $parts = explode('.', $ip);
                return (int) $parts[3];
            })
            ->toArray();

        // Find next available IP (start from .1.1)
        for ($i = 1; $i <= 254; $i++) {
            if (!in_array($i, $allocatedIps)) {
                return "{$baseSubnet}.1.{$i}";
            }
        }

        // If .1.x is full, try .2.x, .3.x, etc.
        for ($subnet = 2; $subnet <= 254; $subnet++) {
            for ($host = 1; $host <= 254; $host++) {
                $ip = "{$baseSubnet}.{$subnet}.{$host}";
                if (!$this->vpnConfigurations()->where('client_ip', $ip)->exists()) {
                    return $ip;
                }
            }
        }

        throw new \Exception('No available IPs in subnet');
    }

    /**
     * Format bytes for display
     */
    public function getFormattedBytesReceivedAttribute(): string
    {
        return $this->formatBytes($this->bytes_received);
    }

    public function getFormattedBytesSentAttribute(): string
    {
        return $this->formatBytes($this->bytes_sent);
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Scope to get active tunnels
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get tunnels with recent handshakes
     */
    public function scopeConnected($query)
    {
        return $query->where('last_handshake_at', '>=', now()->subMinutes(5));
    }
}
