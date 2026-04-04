<?php

namespace App\Services;

use App\Models\SystemPaymentSetting;
use App\Models\TenantPaybillSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Payment Configuration Service
 * 
 * Central service for resolving MPesa Paybill credentials.
 * Implements the tenant-first, landlord-fallback pattern:
 * 
 * 1. If tenant has active own Paybill → use tenant credentials
 * 2. Else → fallback to landlord (system_payment_settings) credentials
 * 3. If neither → fallback to .env config (legacy compatibility)
 * 
 * All payment services MUST use this service to get credentials.
 */
class PaymentConfigService
{
    protected ?string $tenantId = null;
    protected ?array $resolvedConfig = null;
    protected bool $usingLandlordPaybill = false;

    /**
     * Set tenant context
     */
    public function setTenantId(string $tenantId): self
    {
        $this->tenantId = $tenantId;
        $this->resolvedConfig = null; // Reset cache when tenant changes
        return $this;
    }

    /**
     * Resolve and return the active MPesa configuration.
     * Tenant-first, landlord-fallback.
     */
    public function resolve(): array
    {
        if ($this->resolvedConfig !== null) {
            return $this->resolvedConfig;
        }

        // Step 1: Try tenant's own Paybill (from tenant schema)
        $tenantSettings = $this->getTenantSettings();
        if ($tenantSettings && $tenantSettings->hasOwnPaybill()) {
            $this->usingLandlordPaybill = false;
            $this->resolvedConfig = [
                'env' => $tenantSettings->environment,
                'consumer_key' => $tenantSettings->consumer_key,
                'consumer_secret' => $tenantSettings->consumer_secret,
                'shortcode' => $tenantSettings->business_shortcode,
                'passkey' => $tenantSettings->passkey,
                'validation_url' => $tenantSettings->validation_url,
                'confirmation_url' => $tenantSettings->confirmation_url,
            ];

            Log::debug('PaymentConfigService: Using tenant Paybill', [
                'tenant_id' => $this->tenantId,
                'shortcode' => $tenantSettings->business_shortcode,
            ]);

            return $this->resolvedConfig;
        }

        // Step 2: Fallback to landlord (system_payment_settings DB table)
        $systemSettings = $this->getSystemSettings();
        if ($systemSettings && $systemSettings->isConfigured()) {
            $this->usingLandlordPaybill = true;
            $this->resolvedConfig = $systemSettings->toConfigArray();

            Log::debug('PaymentConfigService: Using landlord Paybill from DB', [
                'tenant_id' => $this->tenantId,
                'shortcode' => $systemSettings->shortcode,
            ]);

            return $this->resolvedConfig;
        }

        // Step 3: Legacy fallback to .env config
        $this->usingLandlordPaybill = true;
        $this->resolvedConfig = [
            'env' => config('mpesa.env', 'sandbox'),
            'consumer_key' => config('mpesa.consumer_key'),
            'consumer_secret' => config('mpesa.consumer_secret'),
            'shortcode' => config('mpesa.shortcode'),
            'passkey' => config('mpesa.passkey'),
            'validation_url' => config('mpesa.validation_url'),
            'confirmation_url' => config('mpesa.confirmation_url'),
        ];

        Log::debug('PaymentConfigService: Using .env fallback (legacy)', [
            'tenant_id' => $this->tenantId,
            'shortcode' => config('mpesa.shortcode'),
        ]);

        return $this->resolvedConfig;
    }

    /**
     * Check if currently using landlord Paybill
     */
    public function isUsingLandlordPaybill(): bool
    {
        if ($this->resolvedConfig === null) {
            $this->resolve();
        }
        return $this->usingLandlordPaybill;
    }

    /**
     * Get the resolved shortcode
     */
    public function getShortcode(): ?string
    {
        return $this->resolve()['shortcode'] ?? null;
    }

    /**
     * Get the base URL based on environment
     */
    public function getBaseUrl(): string
    {
        $env = $this->resolve()['env'] ?? 'sandbox';
        return $env === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Get tenant Paybill settings from tenant schema
     */
    protected function getTenantSettings(): ?TenantPaybillSetting
    {
        try {
            return TenantPaybillSetting::first();
        } catch (\Exception $e) {
            Log::warning('PaymentConfigService: Failed to load tenant settings', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get system (landlord) payment settings from public schema
     */
    protected function getSystemSettings(): ?SystemPaymentSetting
    {
        try {
            return Cache::remember('system_payment_settings', 30, function () {
                return SystemPaymentSetting::getActive();
            });
        } catch (\Exception $e) {
            Log::warning('PaymentConfigService: Failed to load system settings', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Clear cached system settings (call after updating system_payment_settings)
     */
    public static function clearSystemSettingsCache(): void
    {
        Cache::forget('system_payment_settings');
    }
}
