<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\PppoeUser;
use App\Models\PppoePayment;
use App\Models\TenantPaybillSetting;
use App\Jobs\ReconnectPppoeUserJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * M-Pesa C2B (Customer to Business) Service
 * 
 * Handles Paybill payments where customers initiate payment from M-Pesa menu.
 * Supports multi-tenant configuration where each tenant has their own M-Pesa credentials.
 */
class MpesaC2BService
{
    protected ?Tenant $tenant = null;
    protected array $config = [];

    /**
     * Set the tenant context for this service instance
     */
    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;
        $this->config = $this->getTenantMpesaConfig($tenant);
        return $this;
    }

    /**
     * Get M-Pesa configuration for a tenant
     * Uses tenant_paybill_settings table with landlord fallback
     */
    protected function getTenantMpesaConfig(Tenant $tenant): array
    {
        // Switch to tenant schema to read tenant_paybill_settings
        $this->setTenantSchema();

        $settings = TenantPaybillSetting::first();

        if ($settings && $settings->hasOwnPaybill()) {
            return [
                'env' => $settings->environment,
                'consumer_key' => $settings->consumer_key,
                'consumer_secret' => $settings->consumer_secret,
                'shortcode' => $settings->business_shortcode,
                'passkey' => $settings->passkey,
                'validation_url' => $settings->validation_url,
                'confirmation_url' => $settings->confirmation_url,
            ];
        }

        // Fallback to landlord config via PaymentConfigService
        $configService = app(\App\Services\PaymentConfigService::class);
        $configService->setTenantId($tenant->id);
        return $configService->resolve();
    }

    /**
     * Get base URL based on environment
     */
    protected function getBaseUrl(): string
    {
        return $this->config['env'] === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Get OAuth access token from Safaricom
     */
    public function getAccessToken(): ?string
    {
        $cacheKey = "mpesa_token_{$this->tenant->id}";
        
        return Cache::remember($cacheKey, 3500, function () {
            try {
                $response = Http::withBasicAuth(
                    $this->config['consumer_key'],
                    $this->config['consumer_secret']
                )->get($this->getBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials');

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('M-Pesa: Failed to get access token', [
                    'tenant_id' => $this->tenant->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('M-Pesa: Access token exception', [
                    'tenant_id' => $this->tenant->id,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Register C2B URLs with Safaricom
     * Must be called once per shortcode to register validation and confirmation URLs
     */
    public function registerUrls(string $validationUrl, string $confirmationUrl): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Failed to get access token'];
        }

        try {
            $payload = [
                'ShortCode' => $this->config['shortcode'],
                'ResponseType' => 'Completed', // or 'Cancelled'
                'ConfirmationURL' => $confirmationUrl,
                'ValidationURL' => $validationUrl,
            ];

            Log::info('M-Pesa C2B: Registering URLs', [
                'tenant_id' => $this->tenant->id,
                'shortcode' => $this->config['shortcode'],
                'validation_url' => $validationUrl,
                'confirmation_url' => $confirmationUrl,
            ]);

            $response = Http::withToken($token)
                ->post($this->getBaseUrl() . '/mpesa/c2b/v1/registerurl', $payload);

            $data = $response->json();

            if ($response->successful() && ($data['ResponseCode'] ?? '') === '0') {
                // Save URLs to tenant settings
                $this->updateTenantMpesaSettings([
                    'validation_url' => $validationUrl,
                    'confirmation_url' => $confirmationUrl,
                    'urls_registered_at' => now()->toISOString(),
                ]);

                return [
                    'success' => true,
                    'message' => 'URLs registered successfully',
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => $data['ResponseDescription'] ?? 'URL registration failed',
                'data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('M-Pesa C2B: URL registration failed', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Handle validation callback from Safaricom
     * Called before payment is completed to validate the account
     */
    public function handleValidation(array $data): array
    {
        Log::info('M-Pesa C2B: Validation request', [
            'tenant_id' => $this->tenant->id,
            'data' => $data,
        ]);

        $accountNumber = $data['BillRefNumber'] ?? null;
        $amount = (float) ($data['TransAmount'] ?? 0);

        if (!$accountNumber) {
            return $this->validationResponse('C2B00012', 'Invalid account number');
        }

        // Find PPPoE user by account number
        $this->setTenantSchema();
        $user = PppoeUser::where('account_number', $accountNumber)
            ->orWhere('username', $accountNumber)
            ->first();

        if (!$user) {
            Log::warning('M-Pesa C2B: User not found for validation', [
                'tenant_id' => $this->tenant->id,
                'account_number' => $accountNumber,
            ]);
            return $this->validationResponse('C2B00013', 'Account not found');
        }

        // Optional: Validate minimum amount
        if ($amount < 1) {
            return $this->validationResponse('C2B00014', 'Amount too low');
        }

        Log::info('M-Pesa C2B: Validation successful', [
            'tenant_id' => $this->tenant->id,
            'account_number' => $accountNumber,
            'user_id' => $user->id,
        ]);

        return $this->validationResponse('0', 'Accepted');
    }

    /**
     * Handle confirmation callback from Safaricom
     * Called after payment is completed
     */
    public function handleConfirmation(array $data): array
    {
        Log::info('M-Pesa C2B: Confirmation received', [
            'tenant_id' => $this->tenant->id,
            'data' => $data,
        ]);

        $transactionId = $data['TransID'] ?? null;
        $accountNumber = $data['BillRefNumber'] ?? null;
        $amount = (float) ($data['TransAmount'] ?? 0);
        $phoneNumber = $data['MSISDN'] ?? null;
        $transactionTime = $data['TransTime'] ?? null;

        if (!$transactionId || !$accountNumber || $amount <= 0) {
            Log::error('M-Pesa C2B: Invalid confirmation data', [
                'tenant_id' => $this->tenant->id,
                'data' => $data,
            ]);
            return $this->confirmationResponse('1', 'Invalid data');
        }

        $this->setTenantSchema();

        // Check for duplicate transaction
        $existingPayment = PppoePayment::where('transaction_id', $transactionId)->first();
        if ($existingPayment) {
            Log::warning('M-Pesa C2B: Duplicate transaction', [
                'tenant_id' => $this->tenant->id,
                'transaction_id' => $transactionId,
            ]);
            return $this->confirmationResponse('0', 'Already processed');
        }

        // Find user
        $user = PppoeUser::where('account_number', $accountNumber)
            ->orWhere('username', $accountNumber)
            ->first();

        if (!$user) {
            Log::error('M-Pesa C2B: User not found for confirmation', [
                'tenant_id' => $this->tenant->id,
                'account_number' => $accountNumber,
            ]);
            // Still accept payment - will need manual reconciliation
            return $this->confirmationResponse('0', 'Accepted - user not found');
        }

        try {
            DB::beginTransaction();

            // Create payment record
            $payment = PppoePayment::create([
                'pppoe_user_id' => $user->id,
                'account_number' => $accountNumber,
                'amount' => $amount,
                'payment_method' => 'paybill',
                'payment_reference' => $phoneNumber,
                'transaction_id' => $transactionId,
                'status' => 'completed',
                'payment_date' => $this->parseTransactionTime($transactionTime),
                'verified_at' => now(),
                'period_start' => now(),
                'period_end' => now()->addDays(30),
                'metadata' => [
                    'mpesa_data' => $data,
                    'phone_number' => $phoneNumber,
                    'shortcode' => $data['BusinessShortCode'] ?? null,
                ],
            ]);

            // Activate user if suspended/pending
            $this->activateUserAfterPayment($user, $payment);

            DB::commit();

            Log::info('M-Pesa C2B: Payment processed successfully', [
                'tenant_id' => $this->tenant->id,
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'amount' => $amount,
            ]);

            return $this->confirmationResponse('0', 'Success');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('M-Pesa C2B: Payment processing failed', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return $this->confirmationResponse('1', 'Processing error');
        }
    }

    /**
     * Activate user after successful payment
     */
    protected function activateUserAfterPayment(PppoeUser $user, PppoePayment $payment): void
    {
        $wasInactive = in_array($user->status, ['suspended', 'pending', 'expired']);

        // Update user payment info
        $user->update([
            'last_payment_date' => now(),
            'next_payment_due' => now()->addDays(30),
            'status' => 'active',
            'payment_status' => 'paid',
        ]);

        if ($wasInactive) {
            Log::info('M-Pesa C2B: User activated after payment', [
                'user_id' => $user->id,
                'previous_status' => $user->getOriginal('status'),
            ]);

            // Remove Auth-Type Reject from RADIUS to allow reconnection
            DB::table('radcheck')
                ->where('username', $user->username)
                ->where('attribute', 'Auth-Type')
                ->where('value', 'Reject')
                ->delete();

            // Dispatch reconnection job
            ReconnectPppoeUserJob::dispatch($user->id, $this->tenant->id);
        }
    }

    /**
     * Format validation response for Safaricom
     */
    protected function validationResponse(string $code, string $desc): array
    {
        return [
            'ResultCode' => $code,
            'ResultDesc' => $desc,
        ];
    }

    /**
     * Format confirmation response for Safaricom
     */
    protected function confirmationResponse(string $code, string $desc): array
    {
        return [
            'ResultCode' => $code,
            'ResultDesc' => $desc,
        ];
    }

    /**
     * Parse M-Pesa transaction time format (YYYYMMDDHHmmss)
     */
    protected function parseTransactionTime(?string $time): \DateTime
    {
        if (!$time) {
            return now();
        }

        try {
            return \DateTime::createFromFormat('YmdHis', $time) ?: now();
        } catch (\Exception $e) {
            return now();
        }
    }

    /**
     * Update tenant M-Pesa settings
     */
    protected function updateTenantMpesaSettings(array $updates): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['mpesa'] = array_merge($settings['mpesa'] ?? [], $updates);
        $this->tenant->update(['settings' => $settings]);
    }

    /**
     * Set tenant database schema (include public for shared tables)
     */
    protected function setTenantSchema(): void
    {
        if ($this->tenant && $this->tenant->schema_name) {
            DB::statement("SET search_path TO {$this->tenant->schema_name}, public");
        }
    }

    /**
     * Find tenant by shortcode using tenant_paybill_settings table
     */
    public static function findTenantByShortcode(string $shortcode): ?Tenant
    {
        $tenants = Tenant::where('is_active', true)
            ->where('schema_created', true)
            ->get();

        foreach ($tenants as $tenant) {
            try {
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                $settings = TenantPaybillSetting::where('business_shortcode', $shortcode)
                    ->where('is_active', true)
                    ->first();
                if ($settings) {
                    return $tenant;
                }
            } catch (\Exception $e) {
                Log::warning('MpesaC2BService: Failed to check shortcode for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                DB::statement("SET search_path TO public");
            }
        }

        return null;
    }
}
