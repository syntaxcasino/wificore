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
        // tenant_id removed
        'router_id',
        'service_type',
        'service_name',
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
    const TYPE_VPN = 'vpn';
    const TYPE_FIREWALL = 'firewall';
    const TYPE_DHCP = 'dhcp';
    const TYPE_DNS = 'dns';

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
}
