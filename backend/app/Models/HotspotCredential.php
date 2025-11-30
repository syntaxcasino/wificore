<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotspotCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotspot_user_id',
        'payment_id',
        'username',
        'plain_password',
        'phone_number',
        'sms_sent',
        'sms_sent_at',
        'sms_message_id',
        'sms_status',
        'credentials_expires_at',
    ];

    protected $casts = [
        'sms_sent' => 'boolean',
        'sms_sent_at' => 'datetime',
        'credentials_expires_at' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Get the hotspot user
     */
    public function hotspotUser()
    {
        return $this->belongsTo(HotspotUser::class);
    }

    /**
     * Get the payment
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Check if credentials are expired
     */
    public function isExpired(): bool
    {
        return $this->credentials_expires_at && $this->credentials_expires_at->isPast();
    }

    /**
     * Mark SMS as sent
     */
    public function markSmsSent(string $messageId, string $status = 'sent'): void
    {
        $this->update([
            'sms_sent' => true,
            'sms_sent_at' => now(),
            'sms_message_id' => $messageId,
            'sms_status' => $status,
        ]);
    }
}
