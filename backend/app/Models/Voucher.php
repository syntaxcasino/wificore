<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;
use App\Models\Scopes\TenantScope;

class Voucher extends Model
{
    use HasFactory, BelongsToTenant;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'mac_address',
        'payment_id',
        'package_id',
        'duration_hours',
        'status',
        'expires_at',
        'mikrotik_response'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'mikrotik_response' => 'array'
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}