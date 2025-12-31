<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/**
 * GenieACS Fault Model
 * 
 * Tracks device faults/errors from TR-069
 * Table is in TENANT schema - no tenant_id needed
 */
class GenieacsFault extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'genieacs_device_id',
        'device_id',
        'fault_code',
        'fault_string',
        'detail',
        'timestamp',
        'resolved',
        'resolved_at',
    ];

    protected $casts = [
        'id' => 'string',
        'genieacs_device_id' => 'string',
        'timestamp' => 'datetime',
        'resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the device this fault belongs to
     */
    public function device()
    {
        return $this->belongsTo(GenieacsDevice::class, 'genieacs_device_id');
    }

    /**
     * Check if fault is resolved
     */
    public function isResolved(): bool
    {
        return $this->resolved === true;
    }

    /**
     * Scope to get unresolved faults
     */
    public function scopeUnresolved($query)
    {
        return $query->where('resolved', false);
    }

    /**
     * Scope to get resolved faults
     */
    public function scopeResolved($query)
    {
        return $query->where('resolved', true);
    }

    /**
     * Scope to get faults by code
     */
    public function scopeByCode($query, string $code)
    {
        return $query->where('fault_code', $code);
    }
}
