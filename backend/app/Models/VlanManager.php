<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasUuid;

/**
 * VLAN Manager Model
 * 
 * Manages VLAN assignments and availability for tenants.
 */
class VlanManager extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'vlans';

    protected $fillable = [
        'vlan_id',
        'name',
        'description',
        'network_range',
        'is_active',
        'is_available',
    ];

    protected $casts = [
        'vlan_id' => 'integer',
        'is_active' => 'boolean',
        'is_available' => 'boolean',
    ];

    /**
     * Check if VLAN exists and is available
     */
    public function vlanExists(int $vlanId): bool
    {
        return self::where('vlan_id', $vlanId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get available VLANs for tenant
     */
    public function getAvailableVlans(): array
    {
        return self::where('is_active', true)
            ->where('is_available', true)
            ->pluck('vlan_id')
            ->toArray();
    }
}
