<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class VpnConfiguration extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Note: tenant_id removed - table is in tenant schema, no need for tenant_id column
        'tenant_vpn_tunnel_id',
        'router_id',
        'vpn_type',
        'server_public_key',
        'server_private_key',
        'client_public_key',
        'client_private_key',
        'preshared_key',
        'server_ip',
        'client_ip',
        'subnet_cidr',
        'listen_port',
        'server_endpoint',
        'server_public_ip',
        'status',
        'last_handshake_at',
        'rx_bytes',
        'tx_bytes',
        'mikrotik_script',
        'linux_script',
        'interface_name',
        'keepalive_interval',
        'allowed_ips',
        'dns_servers',
    ];

    protected $casts = [
        'last_handshake_at' => 'datetime',
        'allowed_ips' => 'array',
        'dns_servers' => 'array',
        'rx_bytes' => 'integer',
        'tx_bytes' => 'integer',
        'listen_port' => 'integer',
        'keepalive_interval' => 'integer',
    ];

    protected $hidden = [
        'server_private_key',
        'client_private_key',
        'preshared_key',
    ];

    /**
     * Relationships
     */
    // Note: tenant() relationship removed - table is in tenant schema
    // Tenant context is implicit from schema

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function tenantVpnTunnel()
    {
        return $this->belongsTo(TenantVpnTunnel::class, 'tenant_vpn_tunnel_id');
    }

    /**
     * Accessors & Mutators for encrypted fields
     */
    public function setServerPrivateKeyAttribute($value)
    {
        if ($value) {
            $this->attributes['server_private_key'] = Crypt::encryptString($value);
        }
    }

    public function getServerPrivateKeyAttribute($value)
    {
        if ($value) {
            return Crypt::decryptString($value);
        }
        return null;
    }

    public function setClientPrivateKeyAttribute($value)
    {
        if ($value) {
            $this->attributes['client_private_key'] = Crypt::encryptString($value);
        }
    }

    public function getClientPrivateKeyAttribute($value)
    {
        if ($value) {
            return Crypt::decryptString($value);
        }
        return null;
    }

    public function setPresharedKeyAttribute($value)
    {
        if ($value) {
            $this->attributes['preshared_key'] = Crypt::encryptString($value);
        }
    }

    public function getPresharedKeyAttribute($value)
    {
        if ($value) {
            return Crypt::decryptString($value);
        }
        return null;
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Note: scopeForTenant removed - table is in tenant schema
    // No need to filter by tenant_id, schema isolation handles it

    public function scopeWireguard($query)
    {
        return $query->where('vpn_type', 'wireguard');
    }

    /**
     * Helper methods
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isConnected(): bool
    {
        if (!$this->last_handshake_at) {
            return false;
        }

        // Consider connected if handshake within last 3 minutes
        return $this->last_handshake_at->diffInMinutes(now()) < 3;
    }

    public function getFormattedTraffic(): array
    {
        return [
            'rx' => $this->formatBytes($this->rx_bytes),
            'tx' => $this->formatBytes($this->tx_bytes),
            'total' => $this->formatBytes($this->rx_bytes + $this->tx_bytes),
        ];
    }

    private function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
