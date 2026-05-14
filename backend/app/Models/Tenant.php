<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use App\Traits\HasUuid;

class Tenant extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Notifiable;

    protected $fillable = [
        'name',
        'slug',
        'subdomain',
        'custom_domain',
        'schema_name',
        'email',
        'email_verified_at',
        'phone',
        'address',
        'settings',
        'branding',
        'is_active',
        'is_landlord',
        'is_default',
        'is_suspended',
        'suspended_at',
        'subscription_status',
        'subscription_plan',
        'subscription_started_at',
        'subscription_ends_at',
        'trial_ends_at',
        'last_payment_at',
        'next_payment_due',
        'suspension_reason',
        'schema_created',
        'schema_created_at',
        'public_packages_enabled',
        'public_registration_enabled',
        'pppoe_rate',
        'hotspot_revenue_pct',
        'router_rate',
        'landlord_override',
        'landlord_override_reason',
        'landlord_override_until',
        'last_invoice_at',
        'last_invoice_amount',
        'subscription_warning_sent_at',
        'custom_paybill',
        'account_prefix',
    ];

    protected $casts = [
        'id' => 'string',
        'settings' => 'array',
        'branding' => 'array',
        'is_active' => 'boolean',
        'is_landlord' => 'boolean',
        'is_default' => 'boolean',
        'is_suspended' => 'boolean',
        'landlord_override' => 'boolean',
        'schema_created' => 'boolean',
        'public_packages_enabled' => 'boolean',
        'public_registration_enabled' => 'boolean',
        'pppoe_rate' => 'decimal:2',
        'hotspot_revenue_pct' => 'decimal:2',
        'router_rate' => 'decimal:2',
        'last_invoice_amount' => 'decimal:2',
        'email_verified_at' => 'datetime',
        'subscription_started_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'next_payment_due' => 'datetime',
        'suspended_at' => 'datetime',
        'schema_created_at' => 'datetime',
        'landlord_override_until' => 'datetime',
        'last_invoice_at' => 'datetime',
        'subscription_warning_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
    
    /**
     * Get the email address for verification
     */
    public function getEmailForVerification(): string
    {
        return $this->email;
    }

    /**
     * Get users belonging to this tenant
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    // Note: routers, packages, payments are in tenant schemas (no tenant_id column).
    // They cannot be queried via hasMany from the public-schema tenants table.
    // Use TenantContext::runInTenantContext() to query these tables.

    /**
     * Check if tenant is active
     */
    public function isActive(): bool
    {
        return $this->is_active && !$this->suspended_at;
    }

    /**
     * Check if tenant is suspended
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Check if tenant is on trial
     */
    public function isOnTrial(): bool
    {
        return $this->subscription_status === 'trial' && $this->trial_ends_at && $this->trial_ends_at > now();
    }

    /**
     * Check if trial has expired
     */
    public function isTrialExpired(): bool
    {
        return $this->subscription_status === 'trial' && $this->trial_ends_at && $this->trial_ends_at <= now();
    }

    /**
     * Check if tenant is exempt from subscription payment.
     * Default tenants and landlord tenants never need to pay.
     */
    public function isExemptFromSubscription(): bool
    {
        return (bool) $this->is_default || (bool) $this->is_landlord;
    }

    /**
     * Check if subscription is active (paid)
     * Default/landlord tenants are always considered active.
     */
    public function hasActiveSubscription(): bool
    {
        if ($this->isExemptFromSubscription()) {
            return true;
        }

        return in_array($this->subscription_status, ['active', 'paid']) && 
               (!$this->subscription_ends_at || $this->subscription_ends_at > now());
    }

    /**
     * Check if subscription has expired
     * Default/landlord tenants never expire.
     */
    public function isSubscriptionExpired(): bool
    {
        if ($this->isExemptFromSubscription()) {
            return false;
        }

        return $this->subscription_status === 'expired' || 
               ($this->subscription_ends_at && $this->subscription_ends_at <= now());
    }

    /**
     * Check if payment is overdue
     */
    public function isPaymentOverdue(): bool
    {
        return $this->next_payment_due && $this->next_payment_due < now();
    }

    /**
     * Activate subscription (after payment)
     */
    public function activateSubscription(string $plan = 'monthly'): bool
    {
        $this->subscription_status = 'active';
        $this->subscription_plan = $plan;
        $this->subscription_started_at = now();
        $this->subscription_ends_at = now()->addMonth();
        $this->last_payment_at = now();
        $this->next_payment_due = now()->addMonth();
        return $this->save();
    }

    /**
     * Renew subscription (monthly payment)
     */
    public function renewSubscription(): bool
    {
        $this->subscription_ends_at = now()->addMonth();
        $this->last_payment_at = now();
        $this->next_payment_due = now()->addMonth();
        $this->subscription_status = 'active';
        return $this->save();
    }

    /**
     * Expire subscription
     */
    public function expireSubscription(): bool
    {
        $this->subscription_status = 'expired';
        return $this->save();
    }

    /**
     * Suspend tenant
     */
    public function suspend(?string $reason = null): bool
    {
        $this->suspended_at = now();
        $this->suspension_reason = $reason;
        return $this->save();
    }

    /**
     * Activate tenant
     */
    public function activate(): bool
    {
        $this->suspended_at = null;
        $this->suspension_reason = null;
        $this->is_active = true;
        return $this->save();
    }

    /**
     * Scope to get active tenants
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->whereNull('suspended_at');
    }

    /**
     * Scope to get suspended tenants
     */
    public function scopeSuspended($query)
    {
        return $query->whereNotNull('suspended_at');
    }

    /**
     * Get tenant setting
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set tenant setting
     */
    public function setSetting(string $key, $value): bool
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        return $this->save();
    }

    /**
     * Get full subdomain URL
     */
    public function getSubdomainUrl(string $protocol = 'https'): string
    {
        if ($this->custom_domain) {
            return "{$protocol}://{$this->custom_domain}";
        }

        $baseDomain = config('app.base_domain', 'yourdomain.com');
        return "{$protocol}://{$this->subdomain}.{$baseDomain}";
    }

    /**
     * Get public packages URL
     */
    public function getPublicPackagesUrl(): string
    {
        return $this->getSubdomainUrl() . '/packages';
    }

    /**
     * Get branding setting
     */
    public function getBranding(string $key, $default = null)
    {
        return data_get($this->branding, $key, $default);
    }

    /**
     * Set branding setting
     */
    public function setBranding(string $key, $value): bool
    {
        $branding = $this->branding ?? [];
        data_set($branding, $key, $value);
        $this->branding = $branding;
        return $this->save();
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->id)) {
                $tenant->id = \Illuminate\Support\Str::uuid();
            }

            // Generate secure schema name if not set
            if (empty($tenant->schema_name)) {
                $tenant->schema_name = \App\Services\TenantMigrationManager::generateSecureSchemaName(
                    (string) $tenant->slug,
                    (string) $tenant->id
                );
            }
        });

        // Schema setup is handled explicitly by CreateTenantWorkspaceJob.
        // Do NOT auto-run setupTenantSchema here — it runs DDL (migrations)
        // which cannot safely execute inside a caller's DB::beginTransaction()
        // because PostgreSQL aborts the entire transaction on any DDL error.

        static::creating(function ($tenant) {
            if (empty($tenant->account_prefix)) {
                $tenant->account_prefix = static::generateAccountPrefix($tenant->slug ?? $tenant->name);
            }
        });

        static::deleting(function ($tenant) {
            // Clean up tenant schema when tenant is deleted
            $migrationManager = app(\App\Services\TenantMigrationManager::class);
            $migrationManager->dropTenantSchema($tenant);
        });
    }

    /**
     * Generate a unique 3-character alphanumeric account prefix for this tenant.
     * Used as the first 3 characters of customer account numbers (e.g. TRD-P00001).
     */
    public static function generateAccountPrefix(string $seed): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $seed));
        $base = str_pad(substr($base, 0, 3), 3, '0');

        $candidate = $base;
        $suffix = 0;

        while (static::where('account_prefix', $candidate)->exists()) {
            $suffix++;
            $candidate = strtoupper(substr(substr($base, 0, 2) . base_convert($suffix, 10, 36), 0, 3));
        }

        return $candidate;
    }

}
