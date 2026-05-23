<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;
use App\Traits\TenantRouteBindable;

class Voucher extends Model
{
    use HasFactory, HasUuid, SoftDeletes, TenantRouteBindable;

    protected $fillable = [
        'code',
        'value',
        'package_duration_days',
        'package_id',
        'router_id',
        'status',
        'used_by',
        'used_by_type',
        'used_at',
        'expires_at',
        'prefix',
        'notes',
        'batch_id',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function usedBy()
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
