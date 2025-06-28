<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Voucher;
use App\Models\SystemLog;
use App\Models\Payment;
use App\Models\Voucher;
use App\Models\SystemLog;
use App\Services\MpesaService;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected $mpesaService;
    protected $mikrotikService;

    public function __construct(MpesaService $mpesaService, MikrotikService $mikrotikService)
    {
        $this->mpesaService = $mpesaService;
        $this->mikrotikService = $mikrotikService;
    }

    public function initiateSTK(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone_number' => ['required', 'regex:/^\+254[0-9]{9}$/'],
            $validated = $request->validate([
                'phone_number' => ['required', 'regex:/^\+254[0-9]{9}$/'],
                'package_id' => 'required|exists:packages,id',
                'mac_address' => ['required', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
                'mac_address' => ['required', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            ]);

            $package = \App\Models\Package::findOrFail($validated['package_id']);
            $amount = $package->price;

            $stkResponse = $this->mpesaService->initiateSTKPush(
                $validated['phone_number'],
                $amount
            $package = \App\Models\Package::findOrFail($validated['package_id']);
            $amount = $package->price;

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
                'phone_number' => $validated['phone_number'],
                'amount' => $amount,
                'package_id' => $validated['package_id'],
                'status' => 'pending',
                'mac_address' => $validated['mac_address'],
                'transaction_id' => $checkoutRequestId,
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
            // Raw callback logging
            $callbackData = $request->all();

            file_put_contents(
                storage_path('logs/mpesa_raw_callback.log'),
                now() . ' ~ ' . json_encode($callbackData, JSON_PRETTY_PRINT) . "\n---\n",
                FILE_APPEND
            );

            $this->logToSystemAndFile('M-Pesa Callback Received', ['raw_callback_data' => $callbackData], 'info');

            $processed = $this->mpesaService->processCallback($callbackData);

            $this->logToSystemAndFile('Callback Processing Result', ['processed_result' => $processed], 'info');

            $checkoutRequestId = $callbackData['Body']['stkCallback']['CheckoutRequestID'] ?? null;
            $resultCode = (int) ($callbackData['Body']['stkCallback']['ResultCode'] ?? -1);
            $status = $resultCode === 0 ? 'completed' : 'failed';

            if ($checkoutRequestId) {
                $payment = Payment::with('package')->where('transaction_id', $checkoutRequestId)->first();

                if ($payment) {
                    $payment->status = $status;
                    $payment->callback_response = $callbackData;
                    $payment->save();

                    if ($status === 'completed') {
                        $this->processSuccessfulPayment($payment);
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
}
