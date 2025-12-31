<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/**
 * GenieACS Device Model
 * 
 * Tracks TR-069/CWMP devices managed by GenieACS
 * Table is in TENANT schema - no tenant_id needed
 */
class GenieacsDevice extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'device_id',
        'access_point_id',
        'serial_number',
        'mac_address',
        'manufacturer',
        'model',
        'software_version',
        'hardware_version',
        'ip_address',
        'connection_status',
        'last_inform',
        'last_boot',
        'tags',
        'parameters',
        'provisioning_status',
        'provisioned_at',
        'provisioning_error',
    ];

    protected $casts = [
        'id' => 'string',
        'access_point_id' => 'string',
        'tags' => 'array',
        'parameters' => 'array',
        'last_inform' => 'datetime',
        'last_boot' => 'datetime',
        'provisioned_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Connection status constants
    const STATUS_ONLINE = 'online';
    const STATUS_OFFLINE = 'offline';
    const STATUS_ERROR = 'error';
    const STATUS_UNKNOWN = 'unknown';

    // Provisioning status constants
    const PROVISIONING_PENDING = 'pending';
    const PROVISIONING_PROVISIONED = 'provisioned';
    const PROVISIONING_FAILED = 'failed';

    /**
     * Get the access point this device is linked to
     */
    public function accessPoint()
    {
        return $this->belongsTo(AccessPoint::class);
    }

    /**
     * Get tasks for this device
     */
    public function tasks()
    {
        return $this->hasMany(GenieacsTask::class);
    }

    /**
     * Get faults for this device
     */
    public function faults()
    {
        return $this->hasMany(GenieacsFault::class);
    }

    /**
     * Check if device is online
     */
    public function isOnline(): bool
    {
        return $this->connection_status === self::STATUS_ONLINE;
    }

    /**
     * Check if device is provisioned
     */
    public function isProvisioned(): bool
    {
        return $this->provisioning_status === self::PROVISIONING_PROVISIONED;
    }

    /**
     * Scope to get online devices
     */
    public function scopeOnline($query)
    {
        return $query->where('connection_status', self::STATUS_ONLINE);
    }

    /**
     * Scope to get provisioned devices
     */
    public function scopeProvisioned($query)
    {
        return $query->where('provisioning_status', self::PROVISIONING_PROVISIONED);
    }

    /**
     * Scope to get devices by manufacturer
     */
    public function scopeByManufacturer($query, string $manufacturer)
    {
        return $query->where('manufacturer', $manufacturer);
    }
}
