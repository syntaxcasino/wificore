<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Traits\HasUuid;
use App\Traits\TenantRouteBindable;

class HotspotUser extends Model
{
    use HasFactory, HasUuid, SoftDeletes, TenantRouteBindable;

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

    protected static function boot()
    {
        parent::boot();

        static::saved(function (self $hotspotUser) {
            $criticalFields = [
                'has_active_subscription',
                'package_name',
                'package_id',
                'subscription_starts_at',
                'subscription_expires_at',
                'data_limit',
                'data_used',
                'is_active',
                'status',
                'last_login_at',
                'last_login_ip',
                'phone_number',
                'mac_address',
            ];

            if (!$hotspotUser->wasRecentlyCreated && !$hotspotUser->wasChanged($criticalFields)) {
                return;
            }

            $tenant = self::resolveCurrentTenant();
            if (!$tenant) {
                return;
            }

            $tenantId = (string) $tenant->id;
            foreach (['users', 'sessions', 'live_sessions', 'stats'] as $bucket) {
                $versionKey = 'hotspot_cache_version:' . $tenantId . ':' . $bucket;
                Cache::forever($versionKey, ((int) Cache::get($versionKey, 1)) + 1);
            }
        });
    }

    private static function resolveCurrentTenant(): ?Tenant
    {
        if (app()->has('current_tenant')) {
            $tenant = app('current_tenant');
            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        try {
            $schemaName = DB::selectOne('SELECT current_schema() AS current_schema')?->current_schema ?? null;
            if ($schemaName && $schemaName !== 'public') {
                return Tenant::where('schema_name', $schemaName)->first();
            }
        } catch (\Throwable $e) {
            // Ignore and fall through to null.
        }

        return null;
    }

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
