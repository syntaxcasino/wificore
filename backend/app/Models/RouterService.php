<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
use App\Traits\BelongsToTenant;
use App\Models\Scopes\TenantScope;

class RouterService extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'router_id',
        'interface_name',
        'service_type',
        'service_name',
        'ip_pool_id',
        'vlan_id',
        'vlan_required',
        'radius_profile',
        'advanced_config',
        'deployment_status',
        'deployed_at',
        'interfaces',
        'configuration',
        'status',
        'active_users',
        'total_sessions',
        'last_checked_at',
        'enabled',
    ];

    protected $casts = [
        'id' => 'string',
        'router_id' => 'string',
        'ip_pool_id' => 'string',
        'vlan_id' => 'integer',
        'vlan_required' => 'boolean',
        'advanced_config' => 'array',
        'deployed_at' => 'datetime',
        'interfaces' => 'array',
        'configuration' => 'array',
        'active_users' => 'integer',
        'total_sessions' => 'integer',
        'last_checked_at' => 'datetime',
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Service type constants
    const TYPE_HOTSPOT = 'hotspot';
    const TYPE_PPPOE = 'pppoe';
    const TYPE_HYBRID = 'hybrid';
    const TYPE_NONE = 'none';
    const TYPE_VPN = 'vpn';
    const TYPE_FIREWALL = 'firewall';
    const TYPE_DHCP = 'dhcp';
    const TYPE_DNS = 'dns';

    // Deployment status constants
    const DEPLOYMENT_PENDING = 'pending';
    const DEPLOYMENT_IN_PROGRESS = 'deploying';
    const DEPLOYMENT_DEPLOYING = 'deploying';
    const DEPLOYMENT_DEPLOYED = 'deployed';
    const DEPLOYMENT_FAILED = 'failed';

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_ERROR = 'error';
    const STATUS_STARTING = 'starting';
    const STATUS_STOPPING = 'stopping';

    /**
     * Get the router that owns this service
     */
    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Get the IP pool assigned to this service
     */
    public function ipPool()
    {
        return $this->belongsTo(TenantIpPool::class, 'ip_pool_id');
    }

    /**
     * Get the VLANs for this service
     */
    public function vlans()
    {
        return $this->hasMany(ServiceVlan::class);
    }

    /**
     * Check if service is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->enabled;
    }

    /**
     * Check if service is running
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if service has errors
     */
    public function hasErrors(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Get service type label
     */
    public function getTypeLabel(): string
    {
        return match($this->service_type) {
            self::TYPE_HOTSPOT => 'Hotspot',
            self::TYPE_PPPOE => 'PPPoE',
            self::TYPE_VPN => 'VPN',
            self::TYPE_FIREWALL => 'Firewall',
            self::TYPE_DHCP => 'DHCP',
            self::TYPE_DNS => 'DNS',
            default => ucfirst($this->service_type),
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_ERROR => 'Error',
            self::STATUS_STARTING => 'Starting',
            self::STATUS_STOPPING => 'Stopping',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_INACTIVE => 'secondary',
            self::STATUS_ERROR => 'danger',
            self::STATUS_STARTING => 'info',
            self::STATUS_STOPPING => 'warning',
            default => 'secondary',
        };
    }

    /**
     * Scope to get active services
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where('enabled', true);
    }

    /**
     * Scope to get services by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('service_type', $type);
    }

    /**
     * Scope to get enabled services
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to get deployed services
     */
    public function scopeDeployed($query)
    {
        return $query->where('deployment_status', self::DEPLOYMENT_DEPLOYED);
    }

    /**
     * Scope to get pending services
     */
    public function scopePending($query)
    {
        return $query->where('deployment_status', self::DEPLOYMENT_PENDING);
    }

    /**
     * Check if service is deployed
     */
    public function isDeployed(): bool
    {
        return $this->deployment_status === self::DEPLOYMENT_DEPLOYED;
    }

    /**
     * Check if service requires VLAN
     */
    public function requiresVlan(): bool
    {
        return $this->vlan_required || $this->service_type === self::TYPE_HYBRID;
    }

    /**
     * Mark service as deployed
     */
    public function markAsDeployed(): void
    {
        $this->update([
            'deployment_status' => self::DEPLOYMENT_DEPLOYED,
            'deployed_at' => now(),
        ]);
    }

    /**
     * Mark service as failed
     */
    public function markAsFailed(): void
    {
        $this->update([
            'deployment_status' => self::DEPLOYMENT_FAILED,
        ]);
    }
}
