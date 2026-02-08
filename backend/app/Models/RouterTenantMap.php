<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Public-schema lookup table that maps router IDs/IPs to tenant IDs.
 * 
 * This is needed because routers live in tenant schemas, but public endpoints
 * (captive portal, payment callbacks) need to find which tenant a router
 * belongs to without knowing the schema.
 */
class RouterTenantMap extends Model
{
    protected $table = 'router_tenant_map';

    protected $primaryKey = 'router_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'router_id',
        'tenant_id',
        'ip_address',
        'vpn_ip',
        'config_token',
    ];

    protected $casts = [
        'router_id' => 'string',
        'tenant_id' => 'string',
    ];

    /**
     * Get the tenant for this router mapping
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Find tenant ID by router ID
     */
    public static function findTenantByRouterId(string $routerId): ?string
    {
        return static::where('router_id', $routerId)->value('tenant_id');
    }

    /**
     * Find tenant ID by router IP address
     */
    public static function findTenantByIp(string $ipAddress): ?string
    {
        return static::where('ip_address', $ipAddress)
            ->orWhere('vpn_ip', $ipAddress)
            ->value('tenant_id');
    }

    /**
     * Find tenant ID by config token
     */
    public static function findTenantByConfigToken(string $token): ?string
    {
        return static::where('config_token', $token)->value('tenant_id');
    }

    /**
     * Register or update a router mapping
     */
    public static function registerRouter(string $routerId, string $tenantId, ?string $ipAddress = null, ?string $vpnIp = null, ?string $configToken = null): self
    {
        return static::updateOrCreate(
            ['router_id' => $routerId],
            [
                'tenant_id' => $tenantId,
                'ip_address' => $ipAddress,
                'vpn_ip' => $vpnIp,
                'config_token' => $configToken,
            ]
        );
    }

    /**
     * Remove a router mapping
     */
    public static function unregisterRouter(string $routerId): bool
    {
        return static::where('router_id', $routerId)->delete() > 0;
    }
}
