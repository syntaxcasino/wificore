<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantIpPool extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'service_type',
        'pool_name',
        'network_cidr',
        'gateway_ip',
        'range_start',
        'range_end',
        'dns_primary',
        'dns_secondary',
        'total_ips',
        'allocated_ips',
        'available_ips',
        'auto_generated',
        'status',
        'metadata',
    ];

    protected $casts = [
        'total_ips' => 'integer',
        'allocated_ips' => 'integer',
        'available_ips' => 'integer',
        'auto_generated' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function routerServices(): HasMany
    {
        return $this->hasMany(RouterService::class, 'ip_pool_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
                     ->where('available_ips', '>', 0);
    }

    public function scopeForService($query, string $serviceType)
    {
        return $query->where('service_type', $serviceType);
    }

    public function allocateIp(): void
    {
        $this->increment('allocated_ips');
        $this->decrement('available_ips');

        if ($this->available_ips <= 0) {
            $this->update(['status' => 'exhausted']);
        }
    }

    public function releaseIp(): void
    {
        $this->decrement('allocated_ips');
        $this->increment('available_ips');

        if ($this->status === 'exhausted' && $this->available_ips > 0) {
            $this->update(['status' => 'active']);
        }
    }

    public function getUsagePercentage(): float
    {
        if ($this->total_ips <= 0) {
            return 0;
        }

        return round(($this->allocated_ips / $this->total_ips) * 100, 2);
    }

    public function needsExpansion(int $threshold = 10): bool
    {
        return $this->getUsagePercentage() >= (100 - $threshold);
    }

    public function isExhausted(): bool
    {
        return $this->status === 'exhausted' || $this->available_ips <= 0;
    }
}
