<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class HotspotUser extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
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
        'has_active_subscription' => 'boolean',
        'subscription_starts_at' => 'datetime',
        'subscription_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'data_limit' => 'integer',
        'data_used' => 'integer',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function sessions()
    {
        return $this->hasMany(HotspotSession::class);
    }

    public function radiusSessions()
    {
        return $this->hasMany(RadiusSession::class);
    }

    public function credentials()
    {
        return $this->hasMany(HotspotCredential::class);
    }

    public function dataUsageLogs()
    {
        return $this->hasMany(DataUsageLog::class);
    }
}
