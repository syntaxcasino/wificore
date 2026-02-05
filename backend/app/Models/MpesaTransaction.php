<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

/**
 * MPesa Transaction Model
 * 
 * Stores all MPesa transactions for audit trail and payment matching.
 */
class MpesaTransaction extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'transaction_id',
        'transaction_type',
        'amount',
        'msisdn',
        'bill_ref_number',
        'business_shortcode',
        'transaction_time',
        'pppoe_user_id',
        'pppoe_payment_id',
        'is_matched',
        'matched_at',
        'match_method',
        'is_landlord_paybill',
        'source_tenant_id',
        'status',
        'failure_reason',
        'retry_count',
        'last_retry_at',
        'raw_payload',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_time' => 'datetime',
        'is_matched' => 'boolean',
        'matched_at' => 'datetime',
        'is_landlord_paybill' => 'boolean',
        'retry_count' => 'integer',
        'last_retry_at' => 'datetime',
        'raw_payload' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Get the PPPoE user this transaction was matched to
     */
    public function pppoeUser()
    {
        return $this->belongsTo(PppoeUser::class);
    }

    /**
     * Get the payment record created from this transaction
     */
    public function pppoePayment()
    {
        return $this->belongsTo(PppoePayment::class);
    }

    /**
     * Mark transaction as matched to a user
     */
    public function markAsMatched(string $pppoeUserId, string $matchMethod): void
    {
        $this->update([
            'pppoe_user_id' => $pppoeUserId,
            'is_matched' => true,
            'matched_at' => now(),
            'match_method' => $matchMethod,
            'status' => 'processing',
        ]);
    }

    /**
     * Mark transaction as completed with payment
     */
    public function markAsCompleted(string $paymentId): void
    {
        $this->update([
            'pppoe_payment_id' => $paymentId,
            'status' => 'completed',
        ]);
    }

    /**
     * Mark transaction as failed
     */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
        ]);
    }

    /**
     * Check if transaction can be retried
     */
    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->retry_count < 3;
    }

    /**
     * Scope for unmatched transactions
     */
    public function scopeUnmatched($query)
    {
        return $query->where('is_matched', false)
                     ->whereIn('status', ['pending', 'failed']);
    }

    /**
     * Scope for transactions by shortcode
     */
    public function scopeByShortcode($query, string $shortcode)
    {
        return $query->where('business_shortcode', $shortcode);
    }

    /**
     * Scope for recent transactions
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('transaction_time', '>=', now()->subHours($hours));
    }
}
