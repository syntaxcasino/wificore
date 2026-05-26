<?php

namespace App\Http\Controllers\Api;

use App\Helpers\PackageExpiryHelper;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PppoePayment;
use App\Models\PppoeTimedVoucher;
use App\Models\PppoeUser;
use App\Models\Voucher;
use App\Models\SystemLog;
use App\Models\HotspotUser;
use App\Models\HotspotCredential;
use App\Models\RadiusSession;
use App\Models\Package;
use App\Models\RouterTenantMap;
use App\Services\MpesaService;
use App\Services\PppoeBillingLifecycleService;
use App\Services\MikrotikSessionService;
use App\Services\UserProvisioningService;
use App\Jobs\ProcessPaymentJob;
use App\Jobs\ProvisionUserInMikroTikJob;
use App\Jobs\SendCredentialsSMSJob;
use App\Jobs\CreateHotspotUserJob;
use App\Jobs\ReconnectSubscriptionJob;
use App\Services\SubscriptionManager;
use App\Events\PaymentCompleted;
use App\Events\HotspotUserCreated;
use App\Events\PppoePortalPaymentUpdated;
use App\Services\TenantContext;
use App\Services\PaymentTraceLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    protected $mpesaService;
    protected $mikrotikService;
    protected $provisioningService;

    public function __construct(
        MpesaService $mpesaService, 
        MikrotikSessionService $mikrotikService,
        UserProvisioningService $provisioningService
    ) {
        $this->mpesaService = $mpesaService;
        $this->mikrotikService = $mikrotikService;
        $this->provisioningService = $provisioningService;
    }

    public function initiateSTK(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone_number' => ['required', 'regex:/^\+254[0-9]{9}$/'],
                'package_id' => 'required|string',
                'mac_address' => ['required', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
                'router_id' => 'nullable|string',
            ]);

            // 1. Identify tenant from router_id via router_tenant_map
            $routerIdParam = $validated['router_id'] ?? null;
            $tenantId = null;

            if ($routerIdParam) {
                $tenantId = RouterTenantMap::findTenantByRouterId($routerIdParam);
            }
            if (!$tenantId) {
                // Fallback: detect from request headers/IP
                $detectedRouterId = $this->detectRouterFromRequest($request);
                if ($detectedRouterId) {
                    $tenantId = RouterTenantMap::findTenantByRouterId($detectedRouterId);
                }
            }
            if (!$tenantId) {
                throw new \Exception('Unable to identify tenant. Please connect to a hotspot network.');
            }

            // 2. Validate tenant and set schema context
            $tenant = \App\Models\Tenant::find($tenantId);
            if (!$tenant || !$tenant->is_active) {
                throw new \Exception('Tenant not found or inactive');
            }

            // Check tenant subscription is valid before processing payment
            if ($tenant->isSubscriptionExpired() && !$tenant->isOnTrial()) {
                throw new \Exception('Service temporarily unavailable. Please contact your provider.');
            }

            $tenantScoped = $this->runInTenantContext($tenant, function () use ($validated, $request) {
                // 3. Fetch Package from tenant schema (now in tenant context)
                $package = Package::findOrFail($validated['package_id']);
                $amount = $package->price;

                // Auto-detect router_id if not provided
                $routerId = $validated['router_id'] ?? null;
                if (!$routerId) {
                    $routerId = $this->detectRouterFromRequest($request);
                }

                // Validate router exists in tenant schema
                if ($routerId) {
                    $routerExists = \App\Models\Router::find($routerId);
                    if (!$routerExists) {
                        $routerId = null; // Silently clear invalid router
                    }
                }

                // IDEMPOTENCY CHECK: Prevent duplicate payments from double-clicks
                $recentPendingPayment = Payment::where('phone_number', $validated['phone_number'])
                    ->where('package_id', $validated['package_id'])
                    ->where('status', 'pending')
                    ->where('created_at', '>', now()->subMinutes(2))
                    ->first();

                return [
                    'amount' => $amount,
                    'router_id' => $routerId,
                    'recent_pending_payment' => $recentPendingPayment,
                ];
            });

            $amount = $tenantScoped['amount'];
            $routerId = $tenantScoped['router_id'];
            $recentPendingPayment = $tenantScoped['recent_pending_payment'];

            if ($recentPendingPayment) {
                Log::info('Duplicate payment attempt blocked - recent pending payment exists', [
                    'phone_number' => $validated['phone_number'],
                    'package_id' => $validated['package_id'],
                    'existing_payment_id' => $recentPendingPayment->id,
                    'existing_transaction_id' => $recentPendingPayment->transaction_id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment already initiated - please complete the payment on your phone',
                    'transaction_id' => $recentPendingPayment->transaction_id,
                    'payment_id' => $recentPendingPayment->id,
                    'data' => [
                        'CheckoutRequestID' => $recentPendingPayment->transaction_id,
                        'payment_id' => $recentPendingPayment->id,
                    ],
                    'ResultCode' => 0
                ]);
            }

            // 3. Set tenant payment context for correct Paybill credentials
            $this->mpesaService->setTenantPaymentContext($tenantId);

            // 4. Initiate STK Push with tenant-resolved credentials
            $stkResponse = $this->mpesaService->initiateSTKPush(
                $validated['phone_number'],
                $amount
            );

            if (!($stkResponse['success'] ?? false)) {
                throw new \Exception($stkResponse['message'] ?? 'M-Pesa STK Push failed');
            }

            $checkoutRequestId = $stkResponse['data']['CheckoutRequestID'] ?? null;
            if (!$checkoutRequestId) {
                throw new \Exception('Missing CheckoutRequestID from M-Pesa response');
            }

            // 4. Create Payment in Tenant Schema
            $payment = $this->runInTenantContext($tenant, function () use ($validated, $amount, $routerId, $checkoutRequestId) {
                return Payment::create([
                    'phone_number' => $validated['phone_number'],
                    'amount' => $amount,
                    'package_id' => $validated['package_id'],
                    'router_id' => $routerId,
                    'status' => 'pending',
                    'mac_address' => $validated['mac_address'],
                    'transaction_id' => $checkoutRequestId,
                ]);
            });

            // 5. Create Mapping in Public Schema
            // MpesaTransactionMap is in public schema. Since 'public' is in search_path, it should be fine.
            \App\Models\MpesaTransactionMap::create([
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $stkResponse['data']['MerchantRequestID'] ?? null,
                'tenant_id' => $tenantId,
                'payment_type' => 'hotspot',
                'related_id' => $payment->id,
            ]);

            $this->logToSystemAndFile('Transaction initiated', [
                'transaction_id' => $checkoutRequestId,
                'phone_number' => $validated['phone_number'],
                'amount' => $amount,
                'package_id' => $validated['package_id'],
                'tenant_id' => $tenantId,
            ], 'info');

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'transaction_id' => $checkoutRequestId,
                'payment_id' => $payment->id,
                'data' => [
                    'CheckoutRequestID' => $checkoutRequestId,
                    'payment_id' => $payment->id,
                ],
                'ResultCode' => 0
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logToSystemAndFile('STK Push initiation failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ], 'error');

            return response()->json([
                'success' => false,
                'message' => 'Payment initiation failed',
                'error' => $e->getMessage(),
                'ResultCode' => 9999
            ], 500);
        }
    }

    public function callback(Request $request)
    {
        try {
            $callbackData = $request->all();
            
            // Log raw callback
            file_put_contents(
                storage_path('logs/mpesa_raw_callback.log'),
                now() . ' ~ ' . json_encode($callbackData, JSON_PRETTY_PRINT) . "\n---\n",
                FILE_APPEND
            );

            $checkoutRequestId = $callbackData['Body']['stkCallback']['CheckoutRequestID'] ?? null;
            $traceId = 'mpesa:' . Str::uuid()->toString();

            if (!$checkoutRequestId) {
                $this->logPaymentTrace('callback.invalid', [
                    'trace_id' => $traceId,
                    'raw_callback' => $callbackData,
                ], 'error');
                return response()->json(['success' => false, 'message' => 'Invalid callback data']);
            }

            $resultCode = (int) ($callbackData['Body']['stkCallback']['ResultCode'] ?? -1);
            $status = $resultCode === 0 ? 'completed' : 'failed';
            $this->logPaymentTrace('callback.received', [
                'trace_id' => $traceId,
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
                'status' => $status,
            ]);

            // 1. Find the transaction mapping in public schema
            $mapping = \App\Models\MpesaTransactionMap::where('checkout_request_id', $checkoutRequestId)->first();

            if (!$mapping) {
                $this->logPaymentTrace('callback.mapping_missing', [
                    'trace_id' => $traceId,
                    'checkout_request_id' => $checkoutRequestId,
                ], 'error');
                Log::error("M-Pesa Callback: No transaction map found for CheckoutRequestID: $checkoutRequestId");
                return response()->json(['success' => false, 'message' => 'Transaction not found']);
            }

            // 2. Switch to Tenant Context (include public for shared tables)
            $tenant = \App\Models\Tenant::find($mapping->tenant_id);
            if (!$tenant || !$tenant->is_active) {
                $this->logPaymentTrace('callback.tenant_invalid', [
                    'trace_id' => $traceId,
                    'checkout_request_id' => $checkoutRequestId,
                    'tenant_id' => $mapping->tenant_id,
                ], 'error');
                Log::error("M-Pesa Callback: Tenant not found or inactive for ID: {$mapping->tenant_id}");
                return response()->json(['success' => false, 'message' => 'Tenant not found or inactive']);
            }

            $this->logPaymentTrace('callback.tenant_resolved', [
                'trace_id' => $traceId,
                'checkout_request_id' => $checkoutRequestId,
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'payment_type' => $mapping->payment_type ?? null,
                'related_id' => $mapping->related_id ?? null,
            ]);

            $this->logToSystemAndFile('M-Pesa Callback Received', ['raw_callback_data' => $callbackData, 'tenant' => $tenant->slug], 'info');

            $processed = $this->mpesaService->processCallback($callbackData);

            // ---------------------------------------------------------------
            // PPPoE Portal payment path (payment_type = 'pppoe')
            // ---------------------------------------------------------------
            if (($mapping->payment_type ?? '') === 'pppoe') {
                $this->logPaymentTrace('callback.branch.pppoe', [
                    'trace_id' => $traceId,
                    'checkout_request_id' => $checkoutRequestId,
                    'tenant_id' => $tenant->id,
                    'related_id' => $mapping->related_id,
                    'status' => $status,
                ]);

                $this->runInTenantContext($tenant, function () use ($mapping, $status, $processed, $callbackData, $tenant, $traceId, $checkoutRequestId, $resultCode) {
                    $pppoePayment = PppoePayment::find($mapping->related_id);

                    if (!$pppoePayment) {
                        Log::error('M-Pesa PPPoE Callback: PppoePayment not found', [
                            'related_id' => $mapping->related_id,
                            'tenant'     => $tenant->slug,
                        ]);
                        return;
                    }

                    // Idempotency guard
                    if (in_array($pppoePayment->status, ['completed', 'failed'])) {
                        return;
                    }

                    $mpesaReceipt = $processed['data']['mpesa_receipt'] ?? null;
                    $mpesaPhoneNumber = $processed['data']['phone_number'] ?? null;

                    // Resolve billing period from the current active expiry so renewals extend instead of reset
                    $paymentDate = now();
                    $pppoeUser = PppoeUser::with('package:id,name,duration,validity,price')->find($pppoePayment->pppoe_user_id);
                    $currentExpiry = $pppoeUser?->expires_at ? Carbon::parse($pppoeUser->expires_at) : null;
                    $periodStart = PackageExpiryHelper::resolveRenewalBaseTime($paymentDate, $currentExpiry);
                    $periodEnd = $pppoeUser?->package
                        ? PackageExpiryHelper::calculateRenewalExpiresAt($pppoeUser->package, $paymentDate, $currentExpiry)
                        : $periodStart->copy()->addDays(30);

                    $pppoePayment->update([
                        'status'             => $status,
                        'verified_at'        => $status === 'completed' ? now() : null,
                        'payment_date'       => $status === 'completed' ? now() : $pppoePayment->payment_date,
                        'period_start'       => $periodStart,
                        'period_end'         => $periodEnd,
                        'payment_reference'  => $mpesaReceipt ?? $pppoePayment->payment_reference,
                        'metadata'           => array_merge((array) ($pppoePayment->metadata ?? []), [
                            'callback_response' => $callbackData,
                            'mpesa_receipt'     => $mpesaReceipt,
                            'mpesa_phone_number' => $mpesaPhoneNumber,
                            'processing_trace'  => [
                                'trace_id' => $traceId,
                                'checkout_request_id' => $checkoutRequestId,
                                'tenant_id' => $tenant->id,
                                'result_code' => $resultCode,
                                'status' => $status,
                            ],
                        ]),
                    ]);

                    Cache::forget('mpesa_stk_limit:' . $pppoePayment->pppoe_user_id);

                    if ($status === 'completed' && isset($pppoeUser)) {
                        if ($pppoeUser) {
                            app(PppoeBillingLifecycleService::class)
                                ->handleSuccessfulPayment($pppoeUser, $pppoePayment, (string) $tenant->id, 'mpesa_portal');

                            $pppoeUser->refresh();
                        }
                    }

                    event(new PppoePortalPaymentUpdated(
                        transactionId: (string) $pppoePayment->transaction_id,
                        status: (string) $pppoePayment->status,
                        tenantId: (string) $tenant->id,
                        payment: $pppoePayment->fresh(),
                        user: isset($pppoeUser) && $pppoeUser ? $pppoeUser : PppoeUser::find($pppoePayment->pppoe_user_id),
                    ));

                    $this->logPaymentTrace('callback.pppoe.' . $status, [
                        'trace_id' => $traceId,
                        'transaction_id' => $checkoutRequestId,
                        'tenant' => $tenant->slug,
                        'result_code' => $resultCode,
                        'status' => $status,
                        'payment_id' => $pppoePayment->id,
                    ], $status === 'completed' ? 'info' : 'warning');
                });

                return response()->json(['success' => true, 'message' => 'Callback processed successfully']);
            }

            // ---------------------------------------------------------------
            // PPPoE Timed Voucher path (payment_type = 'pppoe_timed_voucher')
            // ---------------------------------------------------------------
            if (($mapping->payment_type ?? '') === 'pppoe_timed_voucher') {
                $this->logPaymentTrace('callback.branch.pppoe_timed_voucher', [
                    'trace_id' => $traceId,
                    'checkout_request_id' => $checkoutRequestId,
                    'tenant_id' => $tenant->id,
                    'related_id' => $mapping->related_id,
                    'status' => $status,
                ]);

                $this->runInTenantContext($tenant, function () use ($mapping, $status, $processed, $callbackData, $traceId, $checkoutRequestId, $tenant, $resultCode) {
                    $timedVoucher = PppoeTimedVoucher::find($mapping->related_id);

                    if (!$timedVoucher) {
                        Log::error('M-Pesa Timed Voucher Callback: PppoeTimedVoucher not found', [
                            'related_id' => $mapping->related_id,
                        ]);
                        return;
                    }

                    if (!in_array($timedVoucher->status, ['pending_payment'])) {
                        return;
                    }

                    $mpesaReceipt = $processed['data']['mpesa_receipt'] ?? null;

                    if ($status === 'completed') {
                        $timedVoucher->payment_reference = $mpesaReceipt ?? $timedVoucher->transaction_id;
                        $timedVoucher->amount_paid       = $timedVoucher->price;
                        $timedVoucher->activate();

                        // Set RADIUS Session-Timeout so the user gets kicked automatically when voucher expires
                        $pppoeUser = PppoeUser::find($timedVoucher->pppoe_user_id);
                        if ($pppoeUser && $timedVoucher->expires_at) {
                            $sessionTimeout = max(60, (int) now()->diffInSeconds($timedVoucher->expires_at, false));
                            DB::table('radreply')->upsert(
                                [['username' => $pppoeUser->username, 'attribute' => 'Session-Timeout', 'op' => ':=', 'value' => (string) $sessionTimeout, 'created_at' => now(), 'updated_at' => now()]],
                                ['username', 'attribute'],
                                ['op', 'value', 'updated_at']
                            );

                            $versionKey = 'pppoe_portal_dashboard_version:' . $pppoeUser->id;
                            Cache::forever($versionKey, ((int) Cache::get($versionKey, 1)) + 1);
                        }

                        Log::info('PPPoE timed voucher activated', [
                            'voucher_id'     => $timedVoucher->id,
                            'duration_label' => $timedVoucher->duration_label,
                            'expires_at'     => $timedVoucher->expires_at,
                        ]);
                    } else {
                        $timedVoucher->status = 'cancelled';
                        $timedVoucher->save();
                    }

                    Cache::forget('timed_voucher_limit:' . $timedVoucher->pppoe_user_id);
                });

                $this->logPaymentTrace('callback.pppoe_timed_voucher.' . $status, [
                    'trace_id' => $traceId,
                    'transaction_id' => $checkoutRequestId,
                    'tenant' => $tenant->slug,
                    'result_code' => $resultCode,
                    'status' => $status,
                    'voucher_id' => $timedVoucher->id,
                ], $status === 'completed' ? 'info' : 'warning');

                return response()->json(['success' => true, 'message' => 'Callback processed successfully']);
            }

            // ---------------------------------------------------------------
            // Hotspot Payment path (legacy)
            // ---------------------------------------------------------------
            $this->logPaymentTrace('callback.branch.hotspot', [
                'trace_id' => $traceId,
                'checkout_request_id' => $checkoutRequestId,
                'tenant_id' => $tenant->id,
                'status' => $status,
            ]);

            $payment = $this->runInTenantContext($tenant, function () use ($checkoutRequestId) {
                return Payment::with('package')->where('transaction_id', $checkoutRequestId)->first();
            });

            if ($payment) {
                // IDEMPOTENCY GUARD: Skip if already processed
                if (in_array($payment->status, ['completed', 'failed'])) {
                    $this->logPaymentTrace('callback.hotspot.duplicate', [
                        'trace_id' => $traceId,
                        'transaction_id' => $checkoutRequestId,
                        'existing_status' => $payment->status,
                        'tenant' => $tenant->slug,
                    ], 'warning');
                    Log::info('M-Pesa Callback: Payment already processed (duplicate callback)', [
                        'transaction_id' => $checkoutRequestId,
                        'existing_status' => $payment->status,
                        'tenant' => $tenant->slug,
                    ]);
                    return response()->json(['success' => true, 'message' => 'Already processed']);
                }

                $paymentContext = $this->runInTenantContext($tenant, function () use ($checkoutRequestId, $status, $callbackData, $processed, $traceId, $resultCode, $tenant) {
                    $tenantPayment = Payment::with('package', 'subscription')
                        ->where('transaction_id', $checkoutRequestId)
                        ->first();

                    if (!$tenantPayment) {
                        return null;
                    }

                    $tenantPayment->update([
                        'status' => $status,
                        'callback_response' => array_merge((array) $callbackData, [
                            '_trace' => [
                                'trace_id' => $traceId,
                                'checkout_request_id' => $checkoutRequestId,
                                'tenant_id' => $tenant->id,
                                'status' => $status,
                                'result_code' => $resultCode,
                            ],
                        ]),
                        'amount' => $processed['data']['amount'] ?? $tenantPayment->amount,
                        'phone_number' => $processed['data']['phone_number'] ?? $tenantPayment->phone_number,
                        'mpesa_receipt' => $processed['data']['mpesa_receipt'] ?? null,
                    ]);

                    return [
                        'payment_id' => $tenantPayment->id,
                        'package_id' => $tenantPayment->package_id,
                        'subscription_id' => $tenantPayment->subscription?->id,
                        'subscription_disconnected' => $tenantPayment->subscription?->isDisconnected() ?? false,
                    ];
                });

                if ($status === 'completed') {
                    // Broadcast payment completed event
                    broadcast(new PaymentCompleted($payment))->toOthers();

                    // EVENT-BASED: Dispatch hotspot user creation job (async)
                    // This handles all hotspot provisioning: HotspotUser, RADIUS entries, credentials
                    CreateHotspotUserJob::dispatch($paymentContext['payment_id'], $paymentContext['package_id'], $tenant->id)
                        ->onQueue('hotspot-provisioning')
                        ->afterCommit();

                    // EVENT-BASED: Handle subscription reconnection (for PPPoE/generic subscriptions only)
                    if (($paymentContext['subscription_disconnected'] ?? false) && !empty($paymentContext['subscription_id'])) {
                        ReconnectSubscriptionJob::dispatch($paymentContext['payment_id'], $paymentContext['subscription_id'], $tenant->id)
                            ->onQueue('subscription-reconnection')
                            ->afterCommit();
                    }

                    // Note: ProcessPaymentJob is NOT dispatched here because CreateHotspotUserJob
                    // already handles complete hotspot provisioning. ProcessPaymentJob is for
                    // generic subscription provisioning (non-hotspot payments).

                    // CACHE BUST: keep tenant revenue widgets and payments list fresh immediately
                    TenantDashboardController::bustDashboardCache((string) $tenant->id);
                    TenantDashboardController::bustEntityCache((string) $tenant->id, 'payments');

                    $hotspotUser = HotspotUser::query()
                        ->select(['id', 'phone_number', 'username'])
                        ->where('phone_number', $tenantPayment->phone_number)
                        ->orWhere('username', $tenantPayment->phone_number)
                        ->first();

                    if ($hotspotUser) {
                        Cache::forget('payment_status:' . $hotspotUser->id . ':' . md5((string) $checkoutRequestId));
                    }
                }

                $this->logPaymentTrace('callback.hotspot.' . $status, [
                    'trace_id' => $traceId,
                    'transaction_id' => $checkoutRequestId,
                    'tenant' => $tenant->slug,
                    'result_code' => $resultCode,
                    'status' => $status,
                    'payment_id' => $paymentContext['payment_id'] ?? null,
                    'subscription_id' => $paymentContext['subscription_id'] ?? null,
                ], $status === 'completed' ? 'info' : 'warning');
            } else {
                $this->logPaymentTrace('callback.hotspot.not_found', [
                    'trace_id' => $traceId,
                    'transaction_id' => $checkoutRequestId,
                    'tenant' => $tenant->slug,
                    'result_code' => $resultCode,
                ], 'warning');
                $this->logToSystemAndFile('Payment Not Found', ['transaction_id' => $checkoutRequestId, 'tenant' => $tenant->slug], 'warning');
            }

            return response()->json(['success' => true, 'message' => 'Callback processed successfully']);

        } catch (\Exception $e) {
            $this->logPaymentTrace('callback.exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'error');
            $this->logToSystemAndFile('Callback Processing Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');

            return response()->json(['success' => false, 'message' => 'Error processing callback'], 500);
        }
    }

    protected function processSuccessfulPayment(Payment $payment)
    {
        DB::transaction(function () use ($payment) {
            try {
                $voucherCode = $this->generateVoucherCode();
                $durationHours = $payment->package->duration_hours;

                $voucher = Voucher::create([
                    'code' => $voucherCode,
                    'mac_address' => $payment->mac_address,
                    'payment_id' => $payment->id,
                    'package_id' => $payment->package_id,
                    'duration_hours' => $durationHours,
                    'expires_at' => now()->addHours($durationHours),
                ]);

                $result = $this->mikrotikService->createSession(
                    $voucherCode,
                    $payment->mac_address,
                    config('mikrotik.default_profile', 'default'),
                    $durationHours
                );

                $voucher->update([
                    'mikrotik_response' => $result,
                    'status' => $result['success'] ? 'active' : 'failed'
                ]);

                if (!$result['success']) {
                    throw new \Exception('Mikrotik session creation failed: ' . ($result['message'] ?? 'Unknown error'));
                }

                $this->logToSystemAndFile('Voucher created and Mikrotik session configured', [
                    'voucher_id' => $voucher->id,
                    'payment_id' => $payment->id,
                    'mac_address' => $payment->mac_address,
                    'duration_hours' => $durationHours,
                    'voucher_code' => $voucherCode
                ], 'info');

            } catch (\Exception $e) {
                $this->logToSystemAndFile('Payment processing failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ], 'error');
                throw $e;
            }
        });
    }

    protected function generateVoucherCode(): string
    {
        $prefix = config('mikrotik.voucher_prefix', '');
        $suffix = config('mikrotik.voucher_suffix', '');
        $length = config('mikrotik.voucher_length', 8);

        do {
            $random = Str::upper(Str::random($length));
            $code = $prefix . $random . $suffix;
        } while (Voucher::where('code', $code)->exists());

        return $code;
    }

    protected function logToSystemAndFile(string $action, array $details, string $logLevel = 'info'): void
    {
        $sanitizedDetails = $this->sanitizeLogData($details);
        try {
            SystemLog::create(['action' => $action, 'details' => $sanitizedDetails]);
        } catch (\Throwable $e) {
            Log::warning('SystemLog::create failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
        Log::$logLevel($action, $sanitizedDetails);
    }

    protected function logPaymentTrace(string $stage, array $details, string $logLevel = 'info'): void
    {
        $logger = app(PaymentTraceLogger::class);
        $sanitizedDetails = $logger->sanitizeLogData($details);
        $logger->log($stage, $sanitizedDetails, $logLevel);

        try {
            SystemLog::create(['action' => 'Payment Trace: ' . $stage, 'details' => $sanitizedDetails]);
        } catch (\Throwable $e) {
            Log::warning('SystemLog::create failed', [
                'action' => 'Payment Trace: ' . $stage,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['password', 'passkey', 'secret', 'auth', 'token'];
        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '*****';
            }
        });
        return $data;
    }

    /**
     * SSE stream for a specific payment's completion status.
     * Used by the hotspot captive portal (unauthenticated guest).
     *
     * Subscribes to the Redis payments channel for the payment's tenant and
     * emits a single "payment.result" SSE event when PaymentCompleted arrives,
     * then closes the stream. Max timeout: 90 seconds.
     */
    public function streamStatus(Request $request, string $paymentId): StreamedResponse
    {
        $payment = Payment::find($paymentId);

        if (!$payment) {
            abort(404, 'Payment not found');
        }

        // If already terminal, stream result immediately without waiting
        $alreadyDone = in_array($payment->status, ['completed', 'failed']);

        return new StreamedResponse(function () use ($payment, $paymentId, $alreadyDone) {
            // Disable output buffering
            while (ob_get_level()) ob_end_flush();

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');

            $sendEvent = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data) . "\n\n";
                flush();
            };

            // Send initial heartbeat so the browser knows the connection is open
            $sendEvent('connected', ['payment_id' => $payment->id, 'status' => $payment->status]);

            if ($alreadyDone) {
                $credentials = Cache::get("payment_credentials_{$payment->id}");
                $sendEvent('payment.result', [
                    'payment_id' => $payment->id,
                    'status'     => $payment->status,
                    'credentials' => $credentials,
                    'auto_login'  => $credentials !== null,
                ]);
                $sendEvent('done', []);
                return;
            }

            // Determine the Redis SSE channel for this payment's tenant
            // PublishEventToSse publishes PaymentCompleted to sse:tenant.{tenantId}.payments
            $tenantId = $payment->tenant_id;
            $sseChannel = "sse:tenant.{$tenantId}.payments";

            $deadline = time() + 90;

            $redis = Redis::connection('sse');

            try {
                $redis->subscribe([$sseChannel], function ($message) use ($payment, $sendEvent, $deadline, $redis) {
                    if (time() >= $deadline) {
                        $sendEvent('timeout', ['payment_id' => $payment->id]);
                        $redis->disconnect();
                        return;
                    }

                    $decoded = json_decode($message, true);
                    if (!$decoded) {
                        return;
                    }

                    // Only care about PaymentCompleted for this specific payment
                    if (($decoded['event'] ?? '') !== 'PaymentCompleted') {
                        return;
                    }

                    $data = $decoded['data'] ?? [];
                    if (($data['payment']['id'] ?? null) != $payment->id) {
                        return;
                    }

                    // Fetch credentials (set by CreateHotspotUserJob)
                    $credentials = Cache::get("payment_credentials_{$payment->id}");

                    $sendEvent('payment.result', [
                        'payment_id'  => $payment->id,
                        'status'      => $data['payment']['status'] ?? 'completed',
                        'credentials' => $credentials,
                        'auto_login'  => $credentials !== null,
                    ]);
                    $sendEvent('done', []);

                    $redis->disconnect();
                });
            } finally {
                $redis->disconnect();
            }
        }, 200, [
            'Content-Type'     => 'text/event-stream',
            'Cache-Control'    => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Check payment status and get credentials for auto-login
     */
    public function checkStatus(Request $request, Payment $payment)
    {
        try {
            // Get credentials from cache (tenant-scoped key, with legacy fallback)
            $tenantId = $request->user()?->tenant_id ?? null;
            $credentials = null;
            if ($tenantId) {
                $credentials = Cache::get("tenant_{$tenantId}_payment_credentials_{$payment->id}");
            }
            // Legacy fallback for old cache keys
            if (!$credentials) {
                $credentials = Cache::get("payment_credentials_{$payment->id}");
            }
            
            return response()->json([
                'success' => true,
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'phone_number' => $payment->phone_number,
                    'created_at' => $payment->created_at,
                ],
                'credentials' => $credentials,
                'auto_login' => $credentials !== null,
            ]);
        } catch (\Exception $e) {
            Log::error('Payment status check error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error checking payment status',
            ], 500);
        }
    }

    /**
     * DEPRECATED: Replaced by CreateHotspotUserJob (event-based)
     * This method is no longer used - all provisioning is now async via jobs
     * Kept for reference only - will be removed in future version
     */
    // public function createHotspotUserSync(Payment $payment, Package $package): array { ... }

    /**
     * Detect router from request headers and IP
     */
    private function detectRouterFromRequest(Request $request)
    {
        // Check for router IP in headers (MikroTik hotspot sends these)
        $gatewayIp = $request->header('X-Gateway-IP') 
                  ?? $request->header('X-Router-IP')
                  ?? $request->ip();

        if ($gatewayIp) {
            // Use router_tenant_map (public schema) for cross-schema lookup
            $map = RouterTenantMap::where('ip_address', $gatewayIp)->first();
            if ($map) {
                return $map->router_id;
            }
        }

        return null;
    }

    private function runInTenantContext(\App\Models\Tenant $tenant, callable $callback)
    {
        $context = app(TenantContext::class);

        return DB::transaction(function () use ($context, $tenant, $callback) {
            DB::connection()->recordsHaveBeenModified();
            return $context->runInTenantContext($tenant, $callback);
        });
    }
}
