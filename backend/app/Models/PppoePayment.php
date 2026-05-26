<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use App\Traits\HasUuid;

class PppoePayment extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    /**
     * Boot the model and add cache invalidation on status changes
     * CRITICAL: Prevents serving stale data to PPPoE portal
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($payment) {
            $freshnessFields = [
                'status',
                'payment_reference',
                'verified_at',
                'notes',
                'amount',
                'payment_method',
                'payment_date',
                'metadata',
            ];

            // Dashboard/payments keys are versioned (e.g. pppoe_portal_dashboard:{id}:v3),
            // so we must bump the version counters when any portal-visible payment detail changes.
            if ($payment->wasRecentlyCreated || $payment->wasChanged($freshnessFields)) {
                $dashboardVersionKey = 'pppoe_portal_dashboard_version:' . $payment->pppoe_user_id;
                $paymentsVersionKey  = 'pppoe_portal_payments_version:'  . $payment->pppoe_user_id;
                Cache::forever($dashboardVersionKey, ((int) Cache::get($dashboardVersionKey, 1)) + 1);
                Cache::forever($paymentsVersionKey,  ((int) Cache::get($paymentsVersionKey,  1)) + 1);
                Cache::forget('payment_status:' . $payment->pppoe_user_id . ':' . md5($payment->transaction_id));
            }
        });
    }

    protected $fillable = [
        'pppoe_user_id',
        'account_number',
        'amount',
        'payment_method',
        'payment_reference',
        'transaction_id',
        'status',
        'payment_date',
        'verified_at',
        'verified_by',
        'period_start',
        'period_end',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'id' => 'string',
        'pppoe_user_id' => 'string',
        'verified_by' => 'string',
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'verified_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function pppoeUser()
    {
        return $this->belongsTo(PppoeUser::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function markAsCompleted(?string $verifiedBy = null): bool
    {
        $this->status = 'completed';
        $this->verified_at = now();
        if ($verifiedBy) {
            $this->verified_by = $verifiedBy;
        }
        return $this->save();
    }

    public function markAsFailed(): bool
    {
        $this->status = 'failed';
        return $this->save();
    }
}
