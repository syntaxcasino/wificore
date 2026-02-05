<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use App\Traits\HasUuid;

/**
 * Tenant Paybill Settings Model
 * 
 * Stores encrypted MPesa Paybill credentials per tenant.
 * Supports landlord fallback for tenants without own Paybill.
 */
class TenantPaybillSetting extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'business_shortcode',
        'consumer_key',
        'consumer_secret',
        'passkey',
        'account_reference',
        'environment',
        'validation_url',
        'confirmation_url',
        'urls_registered_at',
        'use_landlord_paybill',
        'landlord_commission_percent',
        'is_active',
        'is_verified',
        'verified_at',
        'last_transaction_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'consumer_key',
        'consumer_secret',
        'passkey',
    ];

    protected $casts = [
        'urls_registered_at' => 'datetime',
        'use_landlord_paybill' => 'boolean',
        'landlord_commission_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'last_transaction_at' => 'datetime',
    ];

    /**
     * Encrypt consumer key before saving
     */
    public function setConsumerKeyAttribute(?string $value): void
    {
        $this->attributes['consumer_key'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt consumer key when accessing
     */
    public function getConsumerKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Encrypt consumer secret before saving
     */
    public function setConsumerSecretAttribute(?string $value): void
    {
        $this->attributes['consumer_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt consumer secret when accessing
     */
    public function getConsumerSecretAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Encrypt passkey before saving
     */
    public function setPasskeyAttribute(?string $value): void
    {
        $this->attributes['passkey'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt passkey when accessing
     */
    public function getPasskeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if this tenant has own configured Paybill
     */
    public function hasOwnPaybill(): bool
    {
        return $this->is_active && 
               !$this->use_landlord_paybill && 
               !empty($this->business_shortcode) &&
               !empty($this->consumer_key);
    }

    /**
     * Check if credentials are complete
     */
    public function hasCompleteCredentials(): bool
    {
        return !empty($this->business_shortcode) &&
               !empty($this->consumer_key) &&
               !empty($this->consumer_secret) &&
               !empty($this->passkey);
    }

    /**
     * Get masked credentials for display
     */
    public function getMaskedCredentials(): array
    {
        return [
            'business_shortcode' => $this->business_shortcode,
            'consumer_key' => $this->consumer_key 
                ? substr($this->consumer_key, 0, 6) . '****' 
                : null,
            'consumer_secret' => $this->attributes['consumer_secret'] 
                ? '********' 
                : null,
            'passkey' => $this->attributes['passkey'] 
                ? '********' 
                : null,
            'account_reference' => $this->account_reference,
            'environment' => $this->environment,
            'is_active' => $this->is_active,
            'is_verified' => $this->is_verified,
            'use_landlord_paybill' => $this->use_landlord_paybill,
            'validation_url' => $this->validation_url,
            'confirmation_url' => $this->confirmation_url,
            'urls_registered_at' => $this->urls_registered_at?->toIso8601String(),
            'last_transaction_at' => $this->last_transaction_at?->toIso8601String(),
        ];
    }

    /**
     * Get the creator user
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the last updater user
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
