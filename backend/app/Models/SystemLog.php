<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;

class SystemLog extends Model
{
    /** @use HasFactory<\Database\Factories\SystemLogFactory> */
    use HasFactory, BelongsToTenant, HasUuid;

    protected $table = 'system_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'category',
        'action',
        'details',
        'ip_address',
        'user_agent',
        'entity_type',
        'entity_id',
        'level',
        'description',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function requiresTenantContext(): bool
    {
        return false;
    }

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