<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;
use App\Traits\BelongsToTenant;
use App\Models\Scopes\TenantScope;

class HotspotUser extends Model
{
    use HasFactory, SoftDeletes, HasUuid, BelongsToTenant;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'username',
        'password',
        'phone_number',
        'mac_address',
        'has_active_subscription',
        'package_name',
        'package_id',
        'subscription_starts_at',
        'subscription_expires_at',
        'data_limit',
        'data_used',
        'last_login_at',
        'last_login_ip',
        'is_active',
        'status',
    ];

    protected $casts = [
        'id' => 'string',
        'has_active_subscription' => 'boolean',
        'subscription_starts_at' => 'datetime',
        'subscription_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'data_limit' => 'integer',
        'data_used' => 'integer',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Get the package associated with the hotspot user
     */
    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Get all sessions for the hotspot user
     */
    public function sessions()
    {
        return $this->hasMany(HotspotSession::class);
    }

    /**
     * Get the active session for the hotspot user
     */
    public function activeSession()
    {
        return $this->hasOne(HotspotSession::class)->where('is_active', true);
    }

    /**
     * Check if user has an active subscription
     */
    public function hasActiveSubscription(): bool
    {
        return $this->has_active_subscription && 
               $this->subscription_expires_at && 
               $this->subscription_expires_at->isFuture();
    }

    /**
     * Get remaining data in bytes
     */
    public function getRemainingDataAttribute(): int
    {
        if (!$this->data_limit) {
            return 0;
        }
        
        return max(0, $this->data_limit - $this->data_used);
    }

    /**
     * Get data usage percentage
     */
    public function getDataUsagePercentageAttribute(): float
    {
        if (!$this->data_limit || $this->data_limit == 0) {
            return 0;
        }
        
        return min(100, ($this->data_used / $this->data_limit) * 100);
    }

    /**
     * Check if data limit is exceeded
     */
    public function isDataLimitExceeded(): bool
    {
        if (!$this->data_limit) {
            return false;
        }
        
        return $this->data_used >= $this->data_limit;
    }
}
