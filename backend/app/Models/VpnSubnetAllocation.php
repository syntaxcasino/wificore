<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Tenant;
use App\Models\VpnConfiguration;

class VpnSubnetAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'subnet_cidr',
        'subnet_octet_2',
        'gateway_ip',
        'range_start',
        'range_end',
        'total_ips',
        'allocated_ips',
        'available_ips',
        'status',
    ];

    protected $casts = [
        'subnet_octet_2' => 'integer',
        'total_ips' => 'integer',
        'allocated_ips' => 'integer',
        'available_ips' => 'integer',
    ];

    /**
     * Relationships
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vpnConfigurations()
    {
        return $this->hasMany(VpnConfiguration::class, 'subnet_cidr', 'subnet_cidr');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
                     ->where('available_ips', '>', 0);
    }

    /**
     * Helper methods
     */
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
}
