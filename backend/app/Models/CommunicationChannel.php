<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunicationChannel extends Model
{
    use HasFactory;

    protected $table = 'communication_channels';

    protected $fillable = [
        'name',
        'type',
        'provider',
        'credentials',
        'sender_id',
        'phone_number',
        'is_active',
        'is_default',
        'settings',
        'last_tested_at',
        'last_test_status',
    ];

    protected $casts = [
        'credentials' => 'encrypted',
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = [
        'credentials',
    ];

    /**
     * Get the decrypted credentials as an array.
     */
    public function getDecryptedCredentials(): array
    {
        $raw = $this->credentials;
        if (is_string($raw)) {
            return json_decode($raw, true) ?? [];
        }
        return is_array($raw) ? $raw : [];
    }

    /**
     * Set credentials from an array (will be encrypted by cast).
     */
    public function setCredentialsFromArray(array $creds): void
    {
        $this->credentials = json_encode($creds);
    }

    /**
     * Scope: active channels only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: by type (sms, whatsapp, email).
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: default channel for a type.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Get the default active channel for a given type.
     */
    public static function getDefaultForType(string $type): ?self
    {
        return static::active()
            ->ofType($type)
            ->default()
            ->first()
            ?? static::active()->ofType($type)->first();
    }

    /**
     * Supported types.
     */
    public static function supportedTypes(): array
    {
        return ['sms', 'whatsapp', 'email'];
    }

    /**
     * Supported providers per type.
     */
    public static function supportedProviders(string $type): array
    {
        return match ($type) {
            'sms' => ['africastalking', 'twilio', 'custom'],
            'whatsapp' => ['twilio', 'whatsapp_business', 'custom'],
            'email' => ['smtp', 'mailgun', 'sendgrid', 'custom'],
            default => [],
        };
    }

    /**
     * Get safe representation (no credentials exposed).
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'provider' => $this->provider,
            'sender_id' => $this->sender_id,
            'phone_number' => $this->phone_number,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'settings' => $this->settings,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_test_status' => $this->last_test_status,
            'has_credentials' => !empty($this->getDecryptedCredentials()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
