<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProvisioningRun extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'router_task_id',
        'triggered_by_user_id',
        'source',
        'mode',
        'status',
        'progress',
        'current_stage',
        'metadata',
        'error_message',
        'duration_ms',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'router_id' => 'string',
        'router_task_id' => 'string',
        'triggered_by_user_id' => 'string',
        'metadata' => 'array',
        'progress' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function steps()
    {
        return $this->hasMany(ProvisioningStep::class, 'provisioning_run_id');
    }
}

