<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Tracks individual IP assignments to provisioned devices within a tenant.
 * 
 * This model lives in the tenant schema (schema-based isolation, no tenant_id).
 * The CIDR pool definitions live in the public schema (TenantIpPool).
 */
class IpAllocation extends Model
{
    use HasFactory, HasUuids;

    /**
     * No TenantScope needed - this table is in tenant schema (schema-based isolation).
     */
    protected static function booted()
    {
        // Schema-based isolation - no global scope needed
    }

    protected $fillable = [
        'ip_pool_id',
        'ip_address',
        'type',
        'allocatable_id',
        'allocatable_type',
        'description',
        'status',
        'allocated_at',
        'released_at',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'allocated_at' => 'datetime',
        'released_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Type constants
    const TYPE_ROUTER_SERVICE = 'router_service';
    const TYPE_ACCESS_POINT = 'access_point';
    const TYPE_USER_DEVICE = 'user_device';
    const TYPE_MANAGEMENT = 'management';

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_RESERVED = 'reserved';
    const STATUS_RELEASED = 'released';
    const STATUS_EXPIRED = 'expired';

    /**
     * Get the IP pool this allocation belongs to (public schema).
     */
    public function ipPool(): BelongsTo
    {
        return $this->belongsTo(TenantIpPool::class, 'ip_pool_id');
    }

    /**
     * Get the allocatable entity (RouterService, AccessPoint, etc.)
     */
    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: active allocations only
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: for a specific pool
     */
    public function scopeForPool($query, string $poolId)
    {
        return $query->where('ip_pool_id', $poolId);
    }

    /**
     * Scope: for a specific IP address
     */
    public function scopeForIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * Check if this allocation is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Release this IP allocation
     */
    public function release(): void
    {
        $this->update([
            'status' => self::STATUS_RELEASED,
            'released_at' => now(),
        ]);
    }
}
