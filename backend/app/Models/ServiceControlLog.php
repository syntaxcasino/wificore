<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class ServiceControlLog extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'action',
        'reason',
        'status',
        'radius_response',
        'executed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'subscription_id' => 'string',
        'radius_response' => 'array',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Action constants
    const ACTION_DISCONNECT = 'disconnect';
    const ACTION_RECONNECT = 'reconnect';
    const ACTION_SUSPEND = 'suspend';
    const ACTION_ACTIVATE = 'activate';
    const ACTION_TERMINATE = 'terminate';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRYING = 'retrying';

    /**
     * Get the user that owns this log
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subscription that owns this log
     */
    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    /**
     * Check if action is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if action failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if action is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->executed_at = now();
        return $this->save();
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $reason = null): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->executed_at = now();
        
        if ($reason) {
            $this->reason = $reason;
        }
        
        return $this->save();
    }

    /**
     * Get action label
     */
    public function getActionLabel(): string
    {
        return match($this->action) {
            self::ACTION_DISCONNECT => 'Disconnect',
            self::ACTION_RECONNECT => 'Reconnect',
            self::ACTION_SUSPEND => 'Suspend',
            self::ACTION_ACTIVATE => 'Activate',
            self::ACTION_TERMINATE => 'Terminate',
            default => ucfirst($this->action),
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_RETRYING => 'Retrying',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_RETRYING => 'warning',
            default => 'secondary',
        };
    }

    /**
     * Scope to get completed logs
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get failed logs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to get logs by action
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to get recent logs
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
