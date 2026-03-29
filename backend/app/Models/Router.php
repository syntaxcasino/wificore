<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasUuid;
use App\Traits\TenantRouteBindable;
use App\Services\VpnService;
use App\Models\VpnConfiguration;
use Illuminate\Support\Facades\Log;

class Router extends Model
{
    use HasFactory, HasUuid, TenantRouteBindable;

    protected static function booted(): void
    {
        // No global scope needed - this table is in tenant schema (schema-based isolation)

        // Keep router_tenant_map in sync for cross-schema lookups
        static::created(function (Router $router) {
            try {
                $tenantContext = app(\App\Services\TenantContext::class);
                $tenantId = $tenantContext->getTenantId() ?? auth()->user()?->tenant_id;
                if ($tenantId) {
                    RouterTenantMap::registerRouter(
                        $router->id, $tenantId,
                        $router->ip_address, $router->vpn_ip, $router->config_token
                    );
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to register router in tenant map: ' . $e->getMessage());
            }
        });

        static::updated(function (Router $router) {
            try {
                if ($router->isDirty(['ip_address', 'vpn_ip', 'config_token'])) {
                    $map = RouterTenantMap::find($router->id);
                    if ($map) {
                        $map->update([
                            'ip_address' => $router->ip_address,
                            'vpn_ip' => $router->vpn_ip,
                            'config_token' => $router->config_token,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to update router tenant map: ' . $e->getMessage());
            }
        });

        // Delete services individually so their deleting hooks fire (releases IP allocations)
        static::deleting(function (Router $router) {
            $vpnConfigs = VpnConfiguration::where('router_id', $router->id)->get();
            if ($vpnConfigs->isNotEmpty()) {
                $vpnService = app(VpnService::class);
                foreach ($vpnConfigs as $config) {
                    try {
                        $vpnService->deleteVpnConfiguration($config);
                    } catch (\Exception $e) {
                        Log::warning('Failed to clean VPN configuration during router deletion', [
                            'router_id' => $router->id,
                            'vpn_config_id' => $config->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $router->services->each(function (RouterService $service) {
                $service->delete();
            });
        });

        static::deleted(function (Router $router) {
            try {
                RouterTenantMap::unregisterRouter($router->id);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to unregister router from tenant map: ' . $e->getMessage());
            }
        });
    }

    /**
     * Get the tenant ID for this router from the public-schema lookup table.
     * Use this instead of $router->tenant_id (which doesn't exist in tenant schema).
     */
    public function getTenantIdAttribute(): ?string
    {
        if (empty($this->id)) {
            return null;
        }
        return RouterTenantMap::findTenantByRouterId($this->id);
    }

    /**
     * Get the Tenant model for this router.
     * Resolves via router_tenant_map (public schema cross-schema lookup).
     * Used by hotspot/hybrid generators to build captive portal URLs.
     */
    public function getTenantAttribute(): ?\App\Models\Tenant
    {
        $tenantId = $this->getTenantIdAttribute();
        if (!$tenantId) {
            return null;
        }
        return \App\Models\Tenant::find($tenantId);
    }

    protected $fillable = [
        // tenant_id removed for schema isolation
        'name',
        'ip_address',
        'vpn_ip',
        'vpn_status',
        'vpn_enabled',
        'vpn_last_handshake',
        'model',
        'router_type',
        'os_version',
        'serial_number',
        'firmware',
        'last_seen',
        'port',
        'wan_interface',
        'username',
        'password',
        'ssh_key',
        'ssh_key_created_at',
        'ssh_key_rotated_at',
        'location',
        'status',
        'provisioning_stage',
        'interface_assignments',
        'configurations',
        'config_token',
        'vendor',
        'device_type',
        'capabilities',
        'interface_list',
        'reserved_interfaces',

        'snmp_enabled',
        'snmp_version',
        'snmp_community',
        'snmp_v3_user',
        'snmp_v3_auth_protocol',
        'snmp_v3_auth_password',
        'snmp_v3_priv_protocol',
        'snmp_v3_priv_password',
        'snmp_trap_enabled',
        'snmp_trap_version',
        'snmp_trap_community',
        'snmp_trap_target',
    ];

    protected $hidden = ['password', 'ssh_key', 'snmp_v3_auth_password', 'snmp_v3_priv_password', 'snmp_trap_community'];

    protected $appends = [
        'vpn_last_handshake_utc',
        'vpn_last_handshake_eat',
        'vpn_last_handshake_timezones',
    ];

    protected $casts = [
        'id' => 'string',
        'last_seen' => 'datetime',
        'vpn_last_handshake' => 'datetime',
        'vpn_enabled' => 'boolean',
        'ssh_key_created_at' => 'datetime',
        'ssh_key_rotated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'interface_assignments' => 'array',
        'configurations' => 'array',
        'capabilities' => 'array',
        'interface_list' => 'array',
        'reserved_interfaces' => 'array',

        'snmp_enabled' => 'boolean',
        'snmp_trap_enabled' => 'boolean',
        'snmp_v3_auth_password' => 'encrypted',
        'snmp_v3_priv_password' => 'encrypted',
        'snmp_trap_community' => 'encrypted',
    ];

    public function getVpnLastHandshakeUtcAttribute(): ?string
    {
        $handshake = $this->vpn_last_handshake;

        return $handshake?->copy()->timezone('UTC')->toIso8601String();
    }

    public function getVpnLastHandshakeEatAttribute(): ?string
    {
        $handshake = $this->vpn_last_handshake;

        return $handshake?->copy()->timezone('Africa/Nairobi')->toIso8601String();
    }

    public function getVpnLastHandshakeTimezonesAttribute(): array
    {
        $utc = $this->vpn_last_handshake_utc;
        $eat = $this->vpn_last_handshake_eat;

        return [
            'UTC' => $utc,
            'Africa/Nairobi' => $eat,
        ];
    }

    public function wireguardPeers()
    {
        return $this->hasMany(WireguardPeer::class, 'router_id', 'id');
    }

    public function routerConfigs()
    {
        return $this->hasMany(RouterConfig::class, 'router_id', 'id');
    }

    public function packages()
    {
        return $this->belongsToMany(Package::class, 'package_router')
            ->withTimestamps();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'router_id', 'id');
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class, 'router_id', 'id');
    }

    /**
     * Get total revenue generated by this router
     */
    public function getTotalRevenue()
    {
        return $this->payments()->where('status', 'completed')->sum('amount');
    }

    /**
     * Get revenue breakdown by package for this router
     */
    public function getRevenueByPackage()
    {
        return $this->payments()
            ->where('status', 'completed')
            ->selectRaw('package_id, SUM(amount) as total_revenue, COUNT(*) as transaction_count')
            ->groupBy('package_id')
            ->with('package:id,name')
            ->get();
    }

    /**
     * Get VPN configuration for this router
     */
    public function vpnConfiguration()
    {
        return $this->hasOne(VpnConfiguration::class, 'router_id', 'id');
    }

    /**
     * Check if router has VPN configured
     */
    public function hasVpn(): bool
    {
        return $this->vpnConfiguration !== null || $this->vpn_enabled;
    }

    /**
     * Check if VPN is connected
     */
    public function isVpnConnected(): bool
    {
        if (in_array($this->vpn_status, ['active', 'connected']) && $this->vpn_last_handshake) {
            // Consider connected if handshake within last 3 minutes
            return $this->vpn_last_handshake->diffInMinutes(now()) < 3;
        }
        return false;
    }

    /**
     * Get effective IP address (VPN if available, otherwise regular IP)
     */
    public function getEffectiveIpAttribute(): string
    {
        return $this->vpn_ip ?? $this->ip_address;
    }

    // ========================================
    // NEW: Service Management Relationships
    // ========================================

    /**
     * Get services running on this router
     */
    public function services()
    {
        return $this->hasMany(RouterService::class, 'router_id', 'id');
    }

    /**
     * Get access points connected to this router
     */
    public function accessPoints()
    {
        return $this->hasMany(AccessPoint::class, 'router_id', 'id');
    }

    // ========================================
    // NEW: Service Management Methods
    // ========================================

    /**
     * Get active services
     */
    public function activeServices()
    {
        return $this->services()->active();
    }

    /**
     * Get service by type
     */
    public function getServiceByType(string $type): ?RouterService
    {
        return $this->services()->where('service_type', $type)->first();
    }

    /**
     * Check if router has a specific service
     */
    public function hasService(string $type): bool
    {
        return $this->services()->where('service_type', $type)->exists();
    }

    /**
     * Check if router has active service of type
     */
    public function hasActiveService(string $type): bool
    {
        return $this->services()
            ->where('service_type', $type)
            ->where('status', RouterService::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * Get online access points
     */
    public function onlineAccessPoints()
    {
        return $this->accessPoints()->online();
    }

    /**
     * Get total active users across all services
     */
    public function getTotalActiveUsers(): int
    {
        return $this->services()->sum('active_users');
    }

    /**
     * Get total active users across all access points
     */
    public function getTotalAPUsers(): int
    {
        return $this->accessPoints()->sum('active_users');
    }

    /**
     * Check if interface is available
     */
    public function isInterfaceAvailable(string $interface): bool
    {
        $reserved = $this->reserved_interfaces ?? [];
        return !isset($reserved[$interface]);
    }

    /**
     * Reserve interface for service
     */
    public function reserveInterface(string $interface, string $serviceType): bool
    {
        $reserved = $this->reserved_interfaces ?? [];
        $reserved[$interface] = $serviceType;
        $this->reserved_interfaces = $reserved;
        return $this->save();
    }

    /**
     * Release interface
     */
    public function releaseInterface(string $interface): bool
    {
        $reserved = $this->reserved_interfaces ?? [];
        unset($reserved[$interface]);
        $this->reserved_interfaces = $reserved;
        return $this->save();
    }

    /**
     * Get available interfaces
     */
    public function getAvailableInterfaces(): array
    {
        $all = $this->interface_list ?? [];
        $reserved = array_keys($this->reserved_interfaces ?? []);
        return array_diff($all, $reserved);
    }
}