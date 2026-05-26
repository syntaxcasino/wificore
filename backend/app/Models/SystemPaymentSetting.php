<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use App\Traits\HasUuid;

/**
 * System Payment Settings Model (Landlord/Public DB)
 * 
 * Stores the landlord's default MPesa Paybill configuration.
 * Used as fallback when tenants don't have their own Paybill.
 */
class SystemPaymentSetting extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'system_payment_settings';

    protected $fillable = [
        'default_paybill_number',
        'shortcode',
        'passkey',
        'consumer_key',
        'consumer_secret',
        'environment',
        'validation_url',
        'confirmation_url',
        'urls_registered_at',
        'is_active',
        'payment_trace_mode',
        'account_reference_prefix',
        'updated_by',
    ];

    protected $hidden = [
        'consumer_key',
        'consumer_secret',
        'passkey',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'urls_registered_at' => 'datetime',
    ];

    // --- Encryption accessors ---

    public function setConsumerKeyAttribute(?string $value): void
    {
        $this->attributes['consumer_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getConsumerKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function setConsumerSecretAttribute(?string $value): void
    {
        $this->attributes['consumer_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getConsumerSecretAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function setPasskeyAttribute(?string $value): void
    {
        $this->attributes['passkey'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getPasskeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    // --- Helpers ---

    /**
     * Get the active system payment settings (singleton pattern - only one row)
     */
    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Check if landlord Paybill is configured and active
     */
    public function isConfigured(): bool
    {
        return $this->is_active &&
               !empty($this->shortcode) &&
               !empty($this->consumer_key) &&
               !empty($this->consumer_secret) &&
               !empty($this->passkey);
    }

    /**
     * Get config array for use by payment services
     */
    public function toConfigArray(): array
    {
        return [
            'env' => $this->environment,
            'consumer_key' => $this->consumer_key,
            'consumer_secret' => $this->consumer_secret,
            'shortcode' => $this->shortcode,
            'passkey' => $this->passkey,
            'validation_url' => $this->validation_url,
            'confirmation_url' => $this->confirmation_url,
        ];
    }

    /**
     * Get masked credentials for display
     */
    public function getMaskedCredentials(): array
    {
        return [
            'default_paybill_number' => $this->default_paybill_number,
            'shortcode' => $this->shortcode,
            'consumer_key' => $this->consumer_key
                ? substr($this->consumer_key, 0, 6) . '****'
                : null,
            'consumer_secret' => $this->attributes['consumer_secret']
                ? '********'
                : null,
            'passkey' => $this->attributes['passkey']
                ? '********'
                : null,
            'environment' => $this->environment,
            'is_active' => $this->is_active,
            'payment_trace_mode' => $this->payment_trace_mode ?? 'stdout',
            'account_reference_prefix' => $this->account_reference_prefix,
            'validation_url' => $this->validation_url,
            'confirmation_url' => $this->confirmation_url,
        ];
    }
}
