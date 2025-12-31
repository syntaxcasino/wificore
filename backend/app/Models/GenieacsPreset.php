<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/**
 * GenieACS Preset Model
 * 
 * Tracks provisioning presets/configurations
 * Table is in TENANT schema - no tenant_id needed
 */
class GenieacsPreset extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'device_id',
        'weight',
        'precondition',
        'configurations',
        'is_active',
        'description',
    ];

    protected $casts = [
        'id' => 'string',
        'weight' => 'integer',
        'precondition' => 'array',
        'configurations' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope to get active presets
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get presets by device
     */
    public function scopeForDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope to get presets ordered by weight
     */
    public function scopeByWeight($query)
    {
        return $query->orderBy('weight', 'desc');
    }
}
