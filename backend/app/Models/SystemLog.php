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
        'user_id',
        'category',
        'action',
        'details',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that performed the action
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tenant this log belongs to
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}