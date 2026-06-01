<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProvisioningStep extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provisioning_run_id',
        'tenant_id',
        'router_id',
        'router_task_id',
        'sequence',
        'stage',
        'action',
        'status',
        'command',
        'command_payload',
        'response_payload',
        'trap_message',
        'error_message',
        'is_terminal',
        'duration_ms',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'provisioning_run_id' => 'string',
        'tenant_id' => 'string',
        'router_id' => 'string',
        'router_task_id' => 'string',
        'sequence' => 'integer',
        'command_payload' => 'array',
        'response_payload' => 'array',
        'is_terminal' => 'boolean',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(ProvisioningRun::class, 'provisioning_run_id');
    }
}

