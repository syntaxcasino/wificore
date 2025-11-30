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

            $package = \App\Models\Package::findOrFail($validated['package_id']);
            $amount = $package->price;
            
            // Auto-detect router_id if not provided
            $routerId = $validated['router_id'] ?? $this->detectRouterFromRequest($request);

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

            $payment = Payment::create([
                'phone_number' => $validated['phone_number'],
                'amount' => $amount,
                'package_id' => $validated['package_id'],
                'router_id' => $routerId,
                'status' => 'pending',
                'mac_address' => $validated['mac_address'],
                'transaction_id' => $checkoutRequestId,
            ]);

            $this->logToSystemAndFile('Transaction initiated', [
                'transaction_id' => $checkoutRequestId,
                'phone_number' => $validated['phone_number'],
                'amount' => $amount,
                'package_id' => $validated['package_id'],
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

            file_put_contents(
                storage_path('logs/mpesa_raw_callback.log'),
                now() . ' ~ ' . json_encode($callbackData, JSON_PRETTY_PRINT) . "\n---\n",
                FILE_APPEND
            );

            $this->logToSystemAndFile('M-Pesa Callback Received', ['raw_callback_data' => $callbackData], 'info');

            $processed = $this->mpesaService->processCallback($callbackData);

            $this->logToSystemAndFile('Callback Processing Result', ['processed_result' => $processed, 'data' => $processed['data'] ?? []], 'info');

            $checkoutRequestId = $callbackData['Body']['stkCallback']['CheckoutRequestID'] ?? null;
            $resultCode = (int) ($callbackData['Body']['stkCallback']['ResultCode'] ?? -1);
            $status = $resultCode === 0 ? 'completed' : 'failed';

            if ($checkoutRequestId) {
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
                        CreateHotspotUserJob::dispatch($payment, $payment->package)
                            ->onQueue('hotspot-provisioning');
                        
                        $this->logToSystemAndFile('Hotspot User Creation Job Dispatched', [
                            'payment_id' => $payment->id,
                            'phone_number' => $payment->phone_number,
                            'package_id' => $payment->package_id,
                        ], 'info');
                        
                        // EVENT-BASED: Handle subscription reconnection if user was disconnected
                        $subscription = $payment->subscription;
                        if ($subscription && $subscription->isDisconnected()) {
                            ReconnectSubscriptionJob::dispatch($payment, $subscription)
                                ->onQueue('subscription-reconnection');
                            
                            $this->logToSystemAndFile('Subscription Reconnection Job Dispatched', [
                                'payment_id' => $payment->id,
                                'subscription_id' => $subscription->id,
                                'user_id' => $subscription->user_id,
                            ], 'info');
                        }
                        
                        // Dispatch payment processing job for voucher creation (legacy)
                        ProcessPaymentJob::dispatch($payment)
                            ->onQueue('payments')
                            ->delay(now()->addSeconds(2));
                    }

                    $this->logToSystemAndFile("Payment $status", [
                        'transaction_id' => $checkoutRequestId,
                        'result_code' => $resultCode,
                        'message' => $callbackData['Body']['stkCallback']['ResultDesc'] ?? '',
                        'amount' => $processed['data']['amount'] ?? null,
                        'mpesa_receipt' => $processed['data']['mpesa_receipt'] ?? null,
                        'phone_number' => $processed['data']['phone_number'] ?? null,
                        'status' => $status,
                    ], $status === 'completed' ? 'info' : 'warning');
                } else {
                    $this->logToSystemAndFile('Payment Not Found', ['transaction_id' => $checkoutRequestId], 'warning');
                }
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
