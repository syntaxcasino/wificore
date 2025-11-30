<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;
use App\Models\Scopes\TenantScope;

class SystemLog extends Model
{
    /** @use HasFactory<\Database\Factories\SystemLogFactory> */
    use HasFactory, BelongsToTenant;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $table = 'system_logs';

    protected $fillable = [
        'tenant_id',
        'action',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}