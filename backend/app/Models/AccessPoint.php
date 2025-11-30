<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
use App\Traits\BelongsToTenant;
use App\Models\Scopes\TenantScope;

class AccessPoint extends Model
{
    use HasFactory, HasUuid, BelongsToTenant;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'router_id',
        'name',
        'vendor',
        'model',
        'ip_address',
        'mac_address',
        'management_protocol',
        'credentials',
        'location',
        'status',
        'active_users',
        'total_capacity',
        'signal_strength',
        'uptime_seconds',
        'last_seen_at',
    ];

    protected $casts = [
        'id' => 'string',
        'router_id' => 'string',
        'credentials' => 'encrypted:array',
        'active_users' => 'integer',
        'total_capacity' => 'integer',
        'signal_strength' => 'integer',
        'uptime_seconds' => 'integer',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Vendor constants
    const VENDOR_RUIJIE = 'ruijie';
    const VENDOR_TENDA = 'tenda';
    const VENDOR_TPLINK = 'tplink';
    const VENDOR_MIKROTIK = 'mikrotik';
    const VENDOR_UBIQUITI = 'ubiquiti';
    const VENDOR_OTHER = 'other';

    // Status constants
    const STATUS_ONLINE = 'online';
    const STATUS_OFFLINE = 'offline';
    const STATUS_UNKNOWN = 'unknown';
    const STATUS_ERROR = 'error';

    // Protocol constants
    const PROTOCOL_SNMP = 'snmp';
    const PROTOCOL_SSH = 'ssh';
    const PROTOCOL_API = 'api';
    const PROTOCOL_TELNET = 'telnet';
    const PROTOCOL_HTTP = 'http';

    /**
     * Get the router that owns this access point
     */
    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Get active sessions for this access point
     */
    public function activeSessions()
    {
        return $this->hasMany(ApActiveSession::class);
    }

    /**
     * Check if AP is online
     */
    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }

    /**
     * Check if AP is offline
     */
    public function isOffline(): bool
    {
        return $this->status === self::STATUS_OFFLINE;
    }

    /**
     * Check if AP has capacity
     */
    public function hasCapacity(): bool
    {
        if (!$this->total_capacity) {
            return true; // No limit set
        }
        
        return $this->active_users < $this->total_capacity;
    }

    /**
     * Get capacity percentage
     */
    public function getCapacityPercentage(): int
    {
        if (!$this->total_capacity) {
            return 0;
        }
        
        return (int) (($this->active_users / $this->total_capacity) * 100);
    }

    /**
     * Get vendor label
     */
    public function getVendorLabel(): string
    {
        return match($this->vendor) {
            self::VENDOR_RUIJIE => 'Ruijie',
            self::VENDOR_TENDA => 'Tenda',
            self::VENDOR_TPLINK => 'TP-Link',
            self::VENDOR_MIKROTIK => 'MikroTik',
            self::VENDOR_UBIQUITI => 'Ubiquiti',
            self::VENDOR_OTHER => 'Other',
            default => ucfirst($this->vendor),
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_ONLINE => 'Online',
            self::STATUS_OFFLINE => 'Offline',
            self::STATUS_UNKNOWN => 'Unknown',
            self::STATUS_ERROR => 'Error',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            self::STATUS_ONLINE => 'success',
            self::STATUS_OFFLINE => 'danger',
            self::STATUS_UNKNOWN => 'secondary',
            self::STATUS_ERROR => 'warning',
            default => 'secondary',
        };
    }

    /**
     * Get uptime in human readable format
     */
    public function getUptimeFormatted(): string
    {
        $seconds = $this->uptime_seconds;
        
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h {$minutes}m";
        } else {
            return "{$minutes}m";
        }
    }

    /**
     * Scope to get online APs
     */
    public function scopeOnline($query)
    {
        return $query->where('status', self::STATUS_ONLINE);
    }

    /**
     * Scope to get offline APs
     */
    public function scopeOffline($query)
    {
        return $query->where('status', self::STATUS_OFFLINE);
    }

    /**
     * Scope to get APs by vendor
     */
    public function scopeByVendor($query, string $vendor)
    {
        return $query->where('vendor', $vendor);
    }
}
