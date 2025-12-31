<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/**
 * GenieACS Task Model
 * 
 * Tracks device operations/tasks in GenieACS
 * Table is in TENANT schema - no tenant_id needed
 */
class GenieacsTask extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'genieacs_device_id',
        'device_id',
        'task_name',
        'parameters',
        'status',
        'started_at',
        'completed_at',
        'error_message',
        'result',
    ];

    protected $casts = [
        'id' => 'string',
        'genieacs_device_id' => 'string',
        'parameters' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Task status constants
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // Task name constants
    const TASK_REBOOT = 'reboot';
    const TASK_FACTORY_RESET = 'factoryReset';
    const TASK_DOWNLOAD = 'download';
    const TASK_SET_PARAMETER_VALUES = 'setParameterValues';
    const TASK_GET_PARAMETER_VALUES = 'getParameterValues';
    const TASK_ADD_OBJECT = 'addObject';
    const TASK_DELETE_OBJECT = 'deleteObject';

    /**
     * Get the device this task belongs to
     */
    public function device()
    {
        return $this->belongsTo(GenieacsDevice::class, 'genieacs_device_id');
    }

    /**
     * Check if task is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if task is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if task failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Scope to get pending tasks
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get completed tasks
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get failed tasks
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
