<?php

namespace App\Models;

use App\Traits\HasUuid;
use App\Models\ProvisioningRun;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RouterTask extends Model
{
    use HasFactory, HasUuid;

    public const TYPE_DEPLOY_SERVICE_CONFIG = 'deploy_service_config';
    public const TYPE_APPLY_SERVICE_CONFIGS = 'apply_service_configs';
    public const TYPE_VERIFY_CONNECTIVITY = 'verify_connectivity';
    public const TYPE_DISCOVER_INTERFACES = 'discover_interfaces';
    public const TYPE_SERVICE_CONTROL_ACTION = 'service_control_action';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'user_id',
        'type',
        'status',
        'progress',
        'message',
        'request_payload',
        'result_payload',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'router_id' => 'string',
        'user_id' => 'string',
        'progress' => 'integer',
        'request_payload' => 'array',
        'result_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeForRouter($query, string $tenantId, string $routerId)
    {
        return $query->where('tenant_id', $tenantId)
            ->where('router_id', $routerId);
    }

    public function markRunning(int $progress = 0, ?string $message = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'progress' => $progress,
            'message' => $message,
            'started_at' => $this->started_at ?? now(),
            'error_message' => null,
        ])->save();
    }

    public function markCompleted(array $result = [], int $progress = 100, ?string $message = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'progress' => $progress,
            'message' => $message,
            'result_payload' => $result,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $error, int $progress = 0, ?string $message = null, array $result = []): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'progress' => $progress,
            'message' => $message,
            'result_payload' => $result,
            'completed_at' => now(),
            'error_message' => $error,
        ])->save();
    }

    public function provisioningRuns()
    {
        return $this->hasMany(ProvisioningRun::class, 'router_task_id');
    }
}
