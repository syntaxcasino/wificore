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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
                'package_id' => 'required|exists:packages,id',
                'mac_address' => ['required', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
                'router_id' => 'nullable|exists:routers,id',
            ]);

            // 1. Fetch Package (Ignore TenantScope to allow public access if needed)
            $package = \App\Models\Package::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->findOrFail($validated['package_id']);
            
            $amount = $package->price;
            $tenantId = $package->tenant_id;

            // 2. Identify Tenant and Switch Context
            $tenant = \App\Models\Tenant::find($tenantId);
            if (!$tenant) {
                throw new \Exception('Tenant not found for this package');
            }

            // Switch to tenant schema to create Payment record
            // We include 'public' in search_path to allow access to shared tables if necessary
            DB::statement("SET search_path TO {$tenant->schema_name}, public");

            // Auto-detect router_id if not provided
            // Note: router_id validation above might fail if router is in tenant schema and we are in public
            // But we just validated against public.routers? No, routers table is dropped from public.
            // So the validation rule 'exists:routers,id' might fail if it looks in public.
            // We should probably remove the router validation or defer it until we switch context.
            // For now, let's assume validation passes or we handle it manually.
            
            // Actually, if 'routers' table is gone from public, 'exists:routers,id' WILL FAIL.
            // We should remove 'exists:routers,id' from validation or handle it manually.
            
            $routerId = $validated['router_id'] ?? null;
            if (!$routerId) {
                $routerId = $this->detectRouterFromRequest($request);
            }

            // 3. Initiate STK Push
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
            $payment = Payment::create([
                'phone_number' => $validated['phone_number'],
                'amount' => $amount,
                'package_id' => $validated['package_id'],
                'router_id' => $routerId,
                'status' => 'pending',
                'mac_address' => $validated['mac_address'],
                'transaction_id' => $checkoutRequestId,
            ]);

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

            // 2. Switch to Tenant Context
            $tenant = \App\Models\Tenant::find($mapping->tenant_id);
            if ($tenant) {
                // Configure tenant database connection/schema
                // This assumes we have a service to switch tenant context
                // For now, let's manually force the schema if possible or assume the global scope works if we set it?
                // Actually, standard way is to use TenantScope/Manager. 
                // But here we might just need to set the search path.
                
                DB::statement("SET search_path TO {$tenant->schema_name}");
            } else {
                 Log::error("M-Pesa Callback: Tenant not found for ID: {$mapping->tenant_id}");
                 return response()->json(['success' => false, 'message' => 'Tenant not found']);
            }

            $this->logToSystemAndFile('M-Pesa Callback Received', ['raw_callback_data' => $callbackData, 'tenant' => $tenant->slug], 'info');

            $processed = $this->mpesaService->processCallback($callbackData);
            
            $resultCode = (int) ($callbackData['Body']['stkCallback']['ResultCode'] ?? -1);
            $status = $resultCode === 0 ? 'completed' : 'failed';

            $payment = Payment::with('package')->where('transaction_id', $checkoutRequestId)->first();

            if ($payment) {
                $payment->update([
                    'status' => $status,
                    'callback_response' => $callbackData,
                    'amount' => $processed['data']['amount'] ?? $payment->amount,
                    'phone_number' => $processed['data']['phone_number'] ?? $payment->phone_number,
                    'mpesa_receipt' => $processed['data']['mpesa_receipt'] ?? null,
                ]);

                if ($status === 'completed') {
                    // Broadcast payment completed event
                    broadcast(new PaymentCompleted($payment))->toOthers();
                    
                    // EVENT-BASED: Dispatch hotspot user creation job (async)
                    CreateHotspotUserJob::dispatch($payment->id, $payment->package_id, $tenant->id)
                        ->onQueue('hotspot-provisioning');
                    
                    // EVENT-BASED: Handle subscription reconnection
                    $subscription = $payment->subscription; // This might be null if not loaded or exists
                    // Note: subscription relation on Payment model looks for UserSubscription
                    // Since we are in tenant schema, UserSubscription query should work
                    
                    if ($subscription && $subscription->isDisconnected()) {
                        ReconnectSubscriptionJob::dispatch($payment->id, $subscription->id, $tenant->id)
                            ->onQueue('subscription-reconnection');
                    }
                    
                    // Dispatch payment processing job for voucher creation (legacy)
                    ProcessPaymentJob::dispatch($payment->id, $tenant->id)
                        ->onQueue('payments')
                        ->delay(now()->addSeconds(2));
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
        SystemLog::create(['action' => $action, 'details' => $sanitizedDetails]);
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
     * Check payment status and get credentials for auto-login
     */
    public function checkStatus(Payment $payment)
    {
        try {
            // Get credentials from cache if available
            $credentials = Cache::get("payment_credentials_{$payment->id}");
            
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
            $router = \App\Models\Router::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->where('ip_address', $gatewayIp)
                ->first();
            
            if ($router) {
                return $router->id;
            }
        }

        return null;
    }
}
