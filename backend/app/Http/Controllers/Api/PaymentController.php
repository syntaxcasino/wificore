<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Voucher;
use App\Models\SystemLog;
use App\Models\HotspotUser;
use App\Models\HotspotCredential;
use App\Models\RadiusSession;
use App\Models\Package;
use App\Models\RouterTenantMap;
use App\Services\MpesaService;
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
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            
            if (!$checkoutRequestId) {
                return response()->json(['success' => false, 'message' => 'Invalid callback data']);
            }

            // 1. Find the transaction mapping in public schema
            $mapping = \App\Models\MpesaTransactionMap::where('checkout_request_id', $checkoutRequestId)->first();
            
            if (!$mapping) {
                Log::error("M-Pesa Callback: No transaction map found for CheckoutRequestID: $checkoutRequestId");
                return response()->json(['success' => false, 'message' => 'Transaction not found']);
            }

            // 2. Switch to Tenant Context (include public for shared tables)
            $tenant = \App\Models\Tenant::find($mapping->tenant_id);
            if (!$tenant || !$tenant->is_active) {
                Log::error("M-Pesa Callback: Tenant not found or inactive for ID: {$mapping->tenant_id}");
                return response()->json(['success' => false, 'message' => 'Tenant not found or inactive']);
            }

            $this->logToSystemAndFile('M-Pesa Callback Received', ['raw_callback_data' => $callbackData, 'tenant' => $tenant->slug], 'info');

            $processed = $this->mpesaService->processCallback($callbackData);
            
            $resultCode = (int) ($callbackData['Body']['stkCallback']['ResultCode'] ?? -1);
            $status = $resultCode === 0 ? 'completed' : 'failed';

            $payment = $this->runInTenantContext($tenant, function () use ($checkoutRequestId) {
                return Payment::with('package')->where('transaction_id', $checkoutRequestId)->first();
            });

            if ($payment) {
                // IDEMPOTENCY GUARD: Skip if already processed
                if (in_array($payment->status, ['completed', 'failed'])) {
                    Log::info('M-Pesa Callback: Payment already processed (duplicate callback)', [
                        'transaction_id' => $checkoutRequestId,
                        'existing_status' => $payment->status,
                        'tenant' => $tenant->slug,
                    ]);
                    return response()->json(['success' => true, 'message' => 'Already processed']);
                }

                $paymentContext = $this->runInTenantContext($tenant, function () use ($checkoutRequestId, $status, $callbackData, $processed) {
                    $tenantPayment = Payment::with('package', 'subscription')
                        ->where('transaction_id', $checkoutRequestId)
                        ->first();

                    if (!$tenantPayment) {
                        return null;
                    }

                    $tenantPayment->update([
                        'status' => $status,
                        'callback_response' => $callbackData,
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
                        ->onQueue('hotspot-provisioning');

                    // EVENT-BASED: Handle subscription reconnection (for PPPoE/generic subscriptions only)
                    if (($paymentContext['subscription_disconnected'] ?? false) && !empty($paymentContext['subscription_id'])) {
                        ReconnectSubscriptionJob::dispatch($paymentContext['payment_id'], $paymentContext['subscription_id'], $tenant->id)
                            ->onQueue('subscription-reconnection');
                    }

                    // Note: ProcessPaymentJob is NOT dispatched here because CreateHotspotUserJob
                    // already handles complete hotspot provisioning. ProcessPaymentJob is for
                    // generic subscription provisioning (non-hotspot payments).

                    // CACHE BUST: Clear tenant dashboard cache so revenue shows immediately
                    TenantDashboardController::bustDashboardCache($tenant->id);
                }

                $this->logToSystemAndFile("Payment $status", [
                    'transaction_id' => $checkoutRequestId,
                    'tenant' => $tenant->slug,
                    'result_code' => $resultCode,
                    'status' => $status,
                ], $status === 'completed' ? 'info' : 'warning');
            } else {
                $this->logToSystemAndFile('Payment Not Found', ['transaction_id' => $checkoutRequestId, 'tenant' => $tenant->slug], 'warning');
            }

            return response()->json(['success' => true, 'message' => 'Callback processed successfully']);

        } catch (\Exception $e) {
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
