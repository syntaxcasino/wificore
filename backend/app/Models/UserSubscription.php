<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'package_id',
        'payment_id',
        'mac_address',
        'start_time',
        'end_time',
        'status',
        'mikrotik_username',
        'mikrotik_password',
        'data_used_mb',
        'time_used_minutes',
        // NEW: Payment management fields
        'next_payment_date',
        'grace_period_days',
        'grace_period_ends_at',
        'auto_renew',
        'disconnected_at',
        'disconnection_reason',
        'last_reminder_sent_at',
        'reminder_count',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'data_used_mb' => 'integer',
        'time_used_minutes' => 'integer',
        // NEW: Payment management casts
        'next_payment_date' => 'date',
        'grace_period_days' => 'integer',
        'grace_period_ends_at' => 'datetime',
        'auto_renew' => 'boolean',
        'disconnected_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
        'reminder_count' => 'integer',
    ];

    /**
     * Get the user that owns the subscription
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the package for this subscription
     */
    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Get the payment for this subscription
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Check if subscription is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->end_time > now();
    }

    /**
     * Check if subscription is expired
     */
    public function isExpired(): bool
    {
        return $this->end_time <= now();
    }

    /**
     * Get remaining time in minutes
     */
    public function getRemainingMinutes(): int
    {
        if ($this->isExpired()) {
            return 0;
        }

        return now()->diffInMinutes($this->end_time);
    }

    /**
     * Mark subscription as expired
     */
    public function markAsExpired(): bool
    {
        $this->status = 'expired';
        return $this->save();
    }

    /**
     * Suspend subscription
     */
    public function suspend(): bool
    {
        $this->status = 'suspended';
        return $this->save();
    }

    /**
     * Activate subscription
     */
    public function activate(): bool
    {
        $this->status = 'active';
        return $this->save();
    }

    // ========================================
    // NEW: Payment Management Relationships
    // ========================================

    /**
     * Get service control logs for this subscription
     */
    public function serviceControlLogs()
    {
        return $this->hasMany(ServiceControlLog::class, 'subscription_id');
    }

    /**
     * Get payment reminders for this subscription
     */
    public function paymentReminders()
    {
        return $this->hasMany(PaymentReminder::class, 'subscription_id');
    }

    // ========================================
    // NEW: Payment Management Methods
    // ========================================

    /**
     * Check if subscription is in grace period
     */
    public function isInGracePeriod(): bool
    {
        return $this->status === 'grace_period' && 
               $this->grace_period_ends_at && 
               $this->grace_period_ends_at > now();
    }

    /**
     * Check if subscription is disconnected
     */
    public function isDisconnected(): bool
    {
        return $this->status === 'disconnected';
    }

    /**
     * Check if payment is due soon
     */
    public function isPaymentDueSoon(int $days = 7): bool
    {
        if (!$this->next_payment_date) {
            return false;
        }

        return $this->next_payment_date->diffInDays(now()) <= $days;
    }

    /**
     * Check if payment is overdue
     */
    public function isPaymentOverdue(): bool
    {
        if (!$this->next_payment_date) {
            return false;
        }

        return $this->next_payment_date < now();
    }

    /**
     * Check if needs payment reminder
     */
    public function needsPaymentReminder(): bool
    {
        if (!$this->next_payment_date) {
            return false;
        }

        // Don't send if already sent today
        if ($this->last_reminder_sent_at && 
            $this->last_reminder_sent_at->isToday()) {
            return false;
        }

        // Send if payment due in 7, 3, or 1 days
        $daysUntilDue = now()->diffInDays($this->next_payment_date, false);
        return in_array($daysUntilDue, [7, 3, 1, 0]);
    }

    /**
     * Start grace period
     */
    public function startGracePeriod(): bool
    {
        $this->status = 'grace_period';
        $this->grace_period_ends_at = now()->addDays($this->grace_period_days ?? 3);
        return $this->save();
    }

    /**
     * Mark as disconnected
     */
    public function markAsDisconnected(string $reason): bool
    {
        $this->status = 'disconnected';
        $this->disconnected_at = now();
        $this->disconnection_reason = $reason;
        return $this->save();
    }

    /**
     * Reconnect subscription
     */
    public function reconnect(): bool
    {
        $this->status = 'active';
        $this->disconnected_at = null;
        $this->disconnection_reason = null;
        return $this->save();
    }

    /**
     * Record reminder sent
     */
    public function recordReminderSent(): bool
    {
        $this->last_reminder_sent_at = now();
        $this->reminder_count = ($this->reminder_count ?? 0) + 1;
        return $this->save();
    }

    /**
     * Get days until payment due
     */
    public function getDaysUntilPaymentDue(): ?int
    {
        if (!$this->next_payment_date) {
            return null;
        }

        return now()->diffInDays($this->next_payment_date, false);
    }

    /**
     * Get days in grace period remaining
     */
    public function getGracePeriodDaysRemaining(): int
    {
        if (!$this->isInGracePeriod()) {
            return 0;
        }

        return now()->diffInDays($this->grace_period_ends_at, false);
    }

    /**
     * Scope to get subscriptions needing reminders
     */
    public function scopeNeedingReminders($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('next_payment_date')
            ->where(function($q) {
                $q->whereNull('last_reminder_sent_at')
                  ->orWhereDate('last_reminder_sent_at', '<', now()->toDateString());
            });
    }

    /**
     * Scope to get subscriptions in grace period
     */
    public function scopeInGracePeriod($query)
    {
        return $query->where('status', 'grace_period')
            ->where('grace_period_ends_at', '>', now());
    }

    /**
     * Scope to get disconnected subscriptions
     */
    public function scopeDisconnected($query)
    {
        return $query->where('status', 'disconnected');
    }
}

