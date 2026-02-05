<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

/**
 * Payment Check Log Model
 * 
 * Audit trail for automatic payment checking operations.
 */
class PaymentCheckLog extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'check_type',
        'paybill_shortcode',
        'is_landlord_paybill',
        'transactions_found',
        'transactions_matched',
        'users_activated',
        'users_disconnected',
        'status',
        'error_message',
        'details',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'is_landlord_paybill' => 'boolean',
        'transactions_found' => 'integer',
        'transactions_matched' => 'integer',
        'users_activated' => 'integer',
        'users_disconnected' => 'integer',
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Start a new payment check log
     */
    public static function startCheck(string $type, ?string $shortcode = null, bool $isLandlord = false): self
    {
        return self::create([
            'check_type' => $type,
            'paybill_shortcode' => $shortcode,
            'is_landlord_paybill' => $isLandlord,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Complete the check with results
     */
    public function complete(array $results): void
    {
        $this->update([
            'status' => 'completed',
            'transactions_found' => $results['transactions_found'] ?? 0,
            'transactions_matched' => $results['transactions_matched'] ?? 0,
            'users_activated' => $results['users_activated'] ?? 0,
            'users_disconnected' => $results['users_disconnected'] ?? 0,
            'details' => $results['details'] ?? null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function fail(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'completed_at' => now(),
        ]);
    }

    /**
     * Get duration in seconds
     */
    public function getDurationAttribute(): ?int
    {
        if (!$this->completed_at) return null;
        return $this->started_at->diffInSeconds($this->completed_at);
    }
}
