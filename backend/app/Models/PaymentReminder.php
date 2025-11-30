<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class PaymentReminder extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'reminder_type',
        'days_before_due',
        'sent_at',
        'channel',
        'status',
        'response',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'subscription_id' => 'string',
        'days_before_due' => 'integer',
        'sent_at' => 'datetime',
        'response' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Reminder type constants
    const TYPE_DUE_SOON = 'due_soon';
    const TYPE_OVERDUE = 'overdue';
    const TYPE_GRACE_PERIOD = 'grace_period';
    const TYPE_DISCONNECTED = 'disconnected';
    const TYPE_FINAL_WARNING = 'final_warning';

    // Channel constants
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_SMS = 'sms';
    const CHANNEL_IN_APP = 'in_app';
    const CHANNEL_PUSH = 'push';

    // Status constants
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';
    const STATUS_DELIVERED = 'delivered';

    /**
     * Get the user that owns this reminder
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subscription that owns this reminder
     */
    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    /**
     * Check if reminder was sent
     */
    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT || $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if reminder failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if reminder is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Mark as sent
     */
    public function markAsSent(array $response = null): bool
    {
        $this->status = self::STATUS_SENT;
        $this->sent_at = now();
        
        if ($response) {
            $this->response = $response;
        }
        
        return $this->save();
    }

    /**
     * Mark as delivered
     */
    public function markAsDelivered(array $response = null): bool
    {
        $this->status = self::STATUS_DELIVERED;
        
        if ($response) {
            $this->response = $response;
        }
        
        return $this->save();
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(array $response = null): bool
    {
        $this->status = self::STATUS_FAILED;
        
        if ($response) {
            $this->response = $response;
        }
        
        return $this->save();
    }

    /**
     * Get reminder type label
     */
    public function getTypeLabel(): string
    {
        return match($this->reminder_type) {
            self::TYPE_DUE_SOON => 'Payment Due Soon',
            self::TYPE_OVERDUE => 'Payment Overdue',
            self::TYPE_GRACE_PERIOD => 'Grace Period',
            self::TYPE_DISCONNECTED => 'Service Disconnected',
            self::TYPE_FINAL_WARNING => 'Final Warning',
            default => ucfirst(str_replace('_', ' ', $this->reminder_type)),
        };
    }

    /**
     * Get channel label
     */
    public function getChannelLabel(): string
    {
        return match($this->channel) {
            self::CHANNEL_EMAIL => 'Email',
            self::CHANNEL_SMS => 'SMS',
            self::CHANNEL_IN_APP => 'In-App',
            self::CHANNEL_PUSH => 'Push Notification',
            default => ucfirst($this->channel),
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_SENT => 'Sent',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_PENDING => 'Pending',
            self::STATUS_DELIVERED => 'Delivered',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            self::STATUS_SENT => 'info',
            self::STATUS_FAILED => 'danger',
            self::STATUS_PENDING => 'warning',
            self::STATUS_DELIVERED => 'success',
            default => 'secondary',
        };
    }

    /**
     * Scope to get sent reminders
     */
    public function scopeSent($query)
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /**
     * Scope to get failed reminders
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to get reminders by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('reminder_type', $type);
    }

    /**
     * Scope to get reminders by channel
     */
    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope to get recent reminders
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('sent_at', '>=', now()->subDays($days));
    }
}
