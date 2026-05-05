<?php

namespace App\Services;

use App\Helpers\PackageExpiryHelper;
use App\Models\Tenant;
use App\Models\TenantPaybillSetting;
use App\Models\SystemPaymentSetting;
use App\Models\MpesaTransaction;
use App\Models\PppoeUser;
use App\Models\PppoePayment;
use App\Models\PaymentCheckLog;
use App\Events\PaymentReceived;
use App\Events\PppoeUserPaymentStatusChanged;
use App\Events\PppoeGracePeriodStarted;
use App\Jobs\DisconnectPppoeUserJob;
use App\Jobs\ReconnectPppoeUserJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant Paybill Service
 * 
 * Handles MPesa Paybill operations with tenant isolation and landlord fallback.
 * All operations are tenant-aware and broadcast real-time updates via Soketi.
 */
class TenantPaybillService extends TenantAwareService
{
    protected ?string $tenantId = null;
    protected ?array $config = null;
    protected ?TenantPaybillSetting $settings = null;
    protected bool $usingLandlordPaybill = false;

    /**
     * Set tenant ID for this service instance
     */
    public function setTenantId(string $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Initialize service with tenant's Paybill settings
     */
    public function initialize(): self
    {
        $this->settings = TenantPaybillSetting::first();
        
        if (!$this->settings || !$this->settings->hasOwnPaybill()) {
            $this->usingLandlordPaybill = true;
            $this->config = $this->getLandlordConfig();
        } else {
            $this->config = $this->getTenantConfig();
        }
        
        return $this;
    }

    /**
     * Get tenant's own Paybill configuration
     */
    protected function getTenantConfig(): array
    {
        if (!$this->settings) {
            return [];
        }

        return [
            'env' => $this->settings->environment,
            'consumer_key' => $this->settings->consumer_key,
            'consumer_secret' => $this->settings->consumer_secret,
            'shortcode' => $this->settings->business_shortcode,
            'passkey' => $this->settings->passkey,
            'validation_url' => $this->settings->validation_url,
            'confirmation_url' => $this->settings->confirmation_url,
        ];
    }

    /**
     * Get landlord's global Paybill configuration (fallback)
     * Reads from system_payment_settings DB table first, then .env as legacy fallback.
     */
    protected function getLandlordConfig(): array
    {
        // Try DB-driven system settings first (30 seconds max to prevent stale data)
        $systemSettings = Cache::remember('system_payment_settings', 30, function () {
            return SystemPaymentSetting::getActive();
        });

        if ($systemSettings && $systemSettings->isConfigured()) {
            return $systemSettings->toConfigArray();
        }

        // Legacy fallback to .env
        return [
            'env' => config('mpesa.env', 'sandbox'),
            'consumer_key' => config('mpesa.consumer_key'),
            'consumer_secret' => config('mpesa.consumer_secret'),
            'shortcode' => config('mpesa.shortcode'),
            'passkey' => config('mpesa.passkey'),
            'validation_url' => config('mpesa.validation_url'),
            'confirmation_url' => config('mpesa.confirmation_url'),
        ];
    }

    /**
     * Check if using landlord's Paybill
     */
    public function isUsingLandlordPaybill(): bool
    {
        return $this->usingLandlordPaybill;
    }

    /**
     * Get base URL based on environment
     */
    protected function getBaseUrl(): string
    {
        return ($this->config['env'] ?? 'sandbox') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Get OAuth access token from Safaricom
     */
    public function getAccessToken(): ?string
    {
        $tenantId = $this->tenantId ?? 'landlord';
        $cacheKey = "mpesa_token_{$tenantId}";
        
        return Cache::remember($cacheKey, 3500, function () {
            try {
                $response = Http::withBasicAuth(
                    $this->config['consumer_key'] ?? '',
                    $this->config['consumer_secret'] ?? ''
                )->get($this->getBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials');

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('TenantPaybillService: Failed to get access token', [
                    'tenant_id' => $this->tenantId,
                    'using_landlord' => $this->usingLandlordPaybill,
                    'status' => $response->status(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('TenantPaybillService: Access token exception', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Register C2B URLs with Safaricom
     */
    public function registerUrls(): array
    {
        if ($this->usingLandlordPaybill) {
            return [
                'success' => false,
                'message' => 'Cannot register URLs when using landlord Paybill',
            ];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Failed to get access token'];
        }

        $baseUrl = config('app.url');
        $validationUrl = "{$baseUrl}/api/mpesa/paybill/validation/{$this->tenantId}";
        $confirmationUrl = "{$baseUrl}/api/mpesa/paybill/confirmation/{$this->tenantId}";

        try {
            $payload = [
                'ShortCode' => $this->config['shortcode'],
                'ResponseType' => 'Completed',
                'ConfirmationURL' => $confirmationUrl,
                'ValidationURL' => $validationUrl,
            ];

            Log::info('TenantPaybillService: Registering URLs', [
                'tenant_id' => $this->tenantId,
                'shortcode' => $this->config['shortcode'],
            ]);

            $response = Http::withToken($token)
                ->post($this->getBaseUrl() . '/mpesa/c2b/v1/registerurl', $payload);

            $data = $response->json();

            if ($response->successful() && ($data['ResponseCode'] ?? '') === '0') {
                $this->settings->update([
                    'validation_url' => $validationUrl,
                    'confirmation_url' => $confirmationUrl,
                    'urls_registered_at' => now(),
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
            ];

        } catch (\Exception $e) {
            Log::error('TenantPaybillService: URL registration failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Handle validation callback from Safaricom
     */
    public function handleValidation(array $data): array
    {
        Log::info('TenantPaybillService: Validation request', [
            'tenant_id' => $this->tenantId,
            'data' => $data,
        ]);

        $accountNumber = $data['BillRefNumber'] ?? null;
        $amount = (float) ($data['TransAmount'] ?? 0);

        if (!$accountNumber) {
            return $this->validationResponse('C2B00012', 'Invalid account number');
        }

        // Find PPPoE user by account number or username (scoped within tenant)
        $user = PppoeUser::where(function ($query) use ($accountNumber) {
            $query->where('account_number', $accountNumber)
                ->orWhere('username', $accountNumber);
        })->first();

        if (!$user) {
            Log::warning('TenantPaybillService: User not found for validation', [
                'tenant_id' => $this->tenantId,
                'account_number' => $accountNumber,
            ]);
            return $this->validationResponse('C2B00013', 'Account not found');
        }

        if ($amount < 1) {
            return $this->validationResponse('C2B00014', 'Amount too low');
        }

        Log::info('TenantPaybillService: Validation successful', [
            'tenant_id' => $this->tenantId,
            'account_number' => $accountNumber,
            'user_id' => $user->id,
        ]);

        return $this->validationResponse('0', 'Accepted');
    }

    /**
     * Handle confirmation callback from Safaricom
     */
    public function handleConfirmation(array $data): array
    {
        Log::info('TenantPaybillService: Confirmation received', [
            'tenant_id' => $this->tenantId,
            'data' => $data,
        ]);

        $transactionId = $data['TransID'] ?? null;
        $accountNumber = $data['BillRefNumber'] ?? null;
        $amount = (float) ($data['TransAmount'] ?? 0);
        $phoneNumber = $data['MSISDN'] ?? null;
        $transactionTime = $data['TransTime'] ?? null;
        $shortcode = $data['BusinessShortCode'] ?? null;

        if (!$transactionId || !$accountNumber || $amount <= 0) {
            Log::error('TenantPaybillService: Invalid confirmation data', [
                'tenant_id' => $this->tenantId,
                'data' => $data,
            ]);

            return $this->confirmationResponse('1', 'Invalid data');
        }

        $existingTransaction = MpesaTransaction::where('transaction_id', $transactionId)->first();
        if ($existingTransaction) {
            Log::warning('TenantPaybillService: Duplicate transaction', [
                'tenant_id' => $this->tenantId,
                'transaction_id' => $transactionId,
            ]);

            return $this->confirmationResponse('0', 'Already processed');
        }

        try {
            DB::beginTransaction();

            $transaction = MpesaTransaction::create([
                'transaction_id' => $transactionId,
                'transaction_type' => 'C2B',
                'amount' => $amount,
                'msisdn' => $phoneNumber,
                'bill_ref_number' => $accountNumber,
                'business_shortcode' => $shortcode,
                'transaction_time' => $this->parseTransactionTime($transactionTime),
                'is_landlord_paybill' => $this->usingLandlordPaybill,
                'source_tenant_id' => $this->usingLandlordPaybill ? $this->tenantId : null,
                'status' => 'pending',
                'raw_payload' => $data,
            ]);

            $user = PppoeUser::where(function ($query) use ($accountNumber) {
                $query->where('account_number', $accountNumber)
                    ->orWhere('username', $accountNumber);
            })->first();

            if ($user) {
                $transaction->markAsMatched($user->id, 'account_number');
                $payment = $this->createPaymentAndActivateUser($user, $transaction, $data);
                $transaction->markAsCompleted($payment->id);
            } else {
                Log::warning('TenantPaybillService: User not found for confirmation', [
                    'tenant_id' => $this->tenantId,
                    'account_number' => $accountNumber,
                ]);
            }

            DB::commit();

            return $this->confirmationResponse('0', 'Success');
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($transaction)) {
                $transaction->markAsFailed($e->getMessage());
            }

            Log::error('TenantPaybillService: Confirmation processing failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return $this->confirmationResponse('1', 'Processing error');
        }
    }

    /**
     * Create payment record and activate user
     */
    protected function createPaymentAndActivateUser(PppoeUser $user, MpesaTransaction $transaction, array $data): PppoePayment
    {
        $periodStart = Carbon::parse($transaction->transaction_time ?? now());
        $package     = $user->package ?? $user->load('package')->package;
        $periodEnd   = $package
            ? PackageExpiryHelper::calculateExpiresAt($package, $periodStart)
            : $periodStart->copy()->addDays(30);

        $payment = PppoePayment::create([
            'pppoe_user_id'  => $user->id,
            'account_number' => $user->account_number,
            'amount'         => $transaction->amount,
            'payment_method' => 'paybill',
            'payment_reference' => $transaction->msisdn,
            'transaction_id' => $transaction->transaction_id,
            'status'         => 'completed',
            'payment_date'   => $transaction->transaction_time,
            'verified_at'    => now(),
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'metadata' => [
                'mpesa_transaction_id'  => $transaction->id,
                'phone_number'          => $transaction->msisdn,
                'shortcode'             => $transaction->business_shortcode,
                'is_landlord_paybill'   => $this->usingLandlordPaybill,
                'confirmation_payload'  => $data,
            ],
        ]);

        $user->customer_phone = $user->customer_phone ?: $transaction->msisdn;
        $user->save();

        app(PppoeBillingLifecycleService::class)
            ->handleSuccessfulPayment($user, $payment, $this->tenantId, 'tenant_paybill_service');

        return $payment;
    }

    /**
     * Try to match a single transaction
     */
    protected function tryMatchTransaction(MpesaTransaction $transaction): bool
    {
        $user = PppoeUser::where(function ($query) use ($transaction) {
            $query->where('account_number', $transaction->bill_ref_number)
                ->orWhere('username', $transaction->bill_ref_number);
        })->first();

        if ($user) {
            try {
                DB::beginTransaction();

                $transaction->markAsMatched($user->id, 'account_number');
                $payment = $this->createPaymentAndActivateUser($user, $transaction, []);
                $transaction->markAsCompleted($payment->id);

                DB::commit();

                return true;
            } catch (\Exception $e) {
                DB::rollBack();
                $transaction->markAsFailed($e->getMessage());
            }
        }

        return false;
    }

    /**
     * Match unmatched transactions to PPPoE users
     */
    public function matchUnmatchedTransactions(): array
    {
        $log = PaymentCheckLog::startCheck('automatic', $this->config['shortcode'] ?? null, $this->usingLandlordPaybill);

        $results = [
            'transactions_found' => 0,
            'transactions_matched' => 0,
            'users_activated' => 0,
            'users_disconnected' => 0,
            'details' => [],
        ];

        try {
            $transactions = MpesaTransaction::unmatched()
                ->byShortcode($this->config['shortcode'] ?? config('mpesa.shortcode'))
                ->recent(48)
                ->get();

            $results['transactions_found'] = $transactions->count();

            foreach ($transactions as $transaction) {
                $matched = $this->tryMatchTransaction($transaction);
                if ($matched) {
                    $results['transactions_matched']++;
                    $results['users_activated']++;
                }
            }

            $log->complete($results);
        } catch (\Exception $e) {
            Log::error('TenantPaybillService: Match transactions failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
            $log->fail($e->getMessage());
        }

        return $results;
    }

    public function checkAndDisconnectOverdueUsers(): array
    {
        $results = [
            'checked' => 0,
            'disconnected' => 0,
            'grace_period' => 0,
            'details' => [],
        ];

        $overdueUsers = PppoeUser::where('status', 'active')
            ->where('payment_status', '!=', 'paid')
            ->where(function ($q) {
                $q->where('next_payment_due', '<', now())
                    ->orWhereNull('next_payment_due');
            })
            ->where('in_grace_period', false)
            ->get();

        $results['checked'] = $overdueUsers->count();

        foreach ($overdueUsers as $user) {
            $gracePeriodDays = config('billing.grace_period_days', 3);

            if (!$user->grace_period_ends || $user->grace_period_ends < now()) {
                $user->update([
                    'in_grace_period' => true,
                    'grace_period_ends' => now()->addDays($gracePeriodDays),
                ]);
                $results['grace_period']++;

                event(new PppoeUserPaymentStatusChanged($this->tenantId, $user->id, 'grace_period', 'grace_started'));
                event(new PppoeGracePeriodStarted(
                    $this->tenantId,
                    $user->id,
                    'grace_period',
                    optional($user->grace_period_ends)->toIso8601String(),
                    'tenant_paybill_service'
                ));
            }
        }

        $expiredGraceUsers = PppoeUser::where('status', 'active')
            ->where('in_grace_period', true)
            ->where('grace_period_ends', '<', now())
            ->get();

        foreach ($expiredGraceUsers as $user) {
            $user->suspendForNonPayment();
            $user->save();

            DisconnectPppoeUserJob::dispatch($user->id, $this->tenantId, 'Payment overdue - grace period ended');
            $results['disconnected']++;
        }

        return $results;
    }

    /**
     * Get payment instructions for user
     */
    public function getPaymentInstructions(PppoeUser $user): array
    {
        $shortcode = $this->config['shortcode'] ?? config('mpesa.shortcode');
        $accountNumber = $user->account_number ?: $user->username;
        $amount = $user->package?->price ?? $user->amount_due ?? 0;

        return [
            'paybill_number' => $shortcode,
            'account_number' => $accountNumber,
            'amount' => $amount,
            'instructions' => [
                "1. Go to M-Pesa menu on your phone",
                "2. Select 'Lipa na M-Pesa'",
                "3. Select 'Pay Bill'",
                "4. Enter Business Number: {$shortcode}",
                "5. Enter Account Number: {$accountNumber}",
                "6. Enter Amount: KES {$amount}",
                "7. Enter your M-Pesa PIN",
                "8. Confirm the transaction",
            ],
            'is_landlord_paybill' => $this->usingLandlordPaybill,
        ];
    }

    protected function validationResponse(string $code, string $desc): array
    {
        return ['ResultCode' => $code, 'ResultDesc' => $desc];
    }

    protected function confirmationResponse(string $code, string $desc): array
    {
        return ['ResultCode' => $code, 'ResultDesc' => $desc];
    }

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
}
