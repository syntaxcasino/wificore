<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\MpesaService;
use App\Services\MikrotikService;
use App\Models\Payment;
use App\Models\UserSession;
use App\Models\SystemLog;
use App\Models\Package;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Broadcast;
use App\Events\PaymentProcessed;

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
            $data = $request->validate([
                'package_id' => 'required|exists:packages,id',
                'phone_number' => 'required|string|regex:/^(\+?2547\d{8})$/',
                'mac_address' => 'required|string|regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'
            ]);

            $package = Package::find($data['package_id']);
            if (!$package) {
                Log::error('Package not found', ['package_id' => $data['package_id']]);
                return response()->json(['error' => 'Package not found'], 404);
            }

            $response = $this->mpesaService->initiateSTKPush(
                $data['phone_number'],
                $package->price,
                'WIFI-' . time(),
                'WiFi Package Purchase'
            );

            if (!$response['success']) {
                Log::error('STK Push initiation failed', [
                    'phone' => $data['phone_number'],
                    'response' => $response
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $response['message'] ?? 'Failed to initiate STK Push'
                ], 400);
            }

            $payment = Payment::create([
                'phone_number' => $data['phone_number'],
                'amount' => $package->price,
                'package_id' => $data['package_id'],
                'status' => 'pending',
                'mac_address' => $data['mac_address'],
                'transaction_id' => $response['data']['CheckoutRequestID'],
            ]);

            SystemLog::create([
                'action' => 'Transaction created',
                'details' => json_encode([
                    'payment_id' => $payment->id,
                    'mac_address' => $data['mac_address'],
                    'phone_number' => $data['phone_number'],
                    'amount' => $package->price,
                    'mpesa_transaction_id' => $response['data']['CheckoutRequestID'],
                ]),
            ]);

            Log::info('Payment initiation successful', [
                'phone' => $data['phone_number'],
                'transaction_id' => $response['data']['CheckoutRequestID']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'transaction_id' => $response['data']['CheckoutRequestID'],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Payment initiation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Payment initiation failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function callback(Request $request)
    {
        Log::info('M-Pesa Callback Received', ['data' => $request->all()]);

        try {
            $callbackData = $request->all();

            if (!isset($callbackData['Body']['stkCallback']['CheckoutRequestID'])) {
                Log::error('Invalid callback data', ['data' => $callbackData]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid callback data'
                ], 400);
            }

            $processed = $this->mpesaService->processCallback($callbackData);
            $checkoutRequestId = $callbackData['Body']['stkCallback']['CheckoutRequestID'];

            $payment = Payment::where('transaction_id', $checkoutRequestId)->first();
            if (!$payment) {
                Log::error('Payment not found', ['checkout_request_id' => $checkoutRequestId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            $resultCode = $callbackData['Body']['stkCallback']['ResultCode'] ?? 1;
            $status = $resultCode == 0 ? 'completed' : 'failed';
            $payment->update([
                'status' => $status,
                'callback_response' => json_encode($callbackData)
            ]);

            SystemLog::create([
                'action' => 'Payment ' . $status,
                'details' => json_encode([
                    'payment_id' => $payment->id,
                    'checkout_request_id' => $checkoutRequestId,
                    'status' => $status,
                    'callback_data' => $callbackData
                ]),
            ]);

            if ($status === 'completed') {
                $package = Package::find($payment->package_id);
                $voucher = $this->generateVoucher();

                $session = UserSession::create([
                    'payment_id' => $payment->id,
                    'voucher' => $voucher,
                    'mac_address' => $payment->mac_address,
                    'start_time' => now(),
                    'end_time' => now()->addHours($package->duration_hours),
                    'status' => 'active',
                ]);

                try {
                    $this->mikrotikService->createSession(
                        $voucher,
                        $payment->mac_address,
                        $package->mikrotik_profile,
                        $package->duration_hours
                    );

                    $this->mikrotikService->authenticateUser($voucher);

                    SystemLog::create([
                        'action' => 'Session created',
                        'details' => json_encode([
                            'session_id' => $session->id,
                            'voucher' => $voucher,
                            'mac_address' => $payment->mac_address,
                            'profile' => $package->mikrotik_profile,
                        ]),
                    ]);

                    // Broadcast event to frontend
                    Broadcast::event(new PaymentProcessed($payment->id, $status, $voucher));

                } catch (\Exception $e) {
                    Log::error('Mikrotik session creation failed', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage()
                    ]);
                    $session->update(['status' => 'failed']);
                    $payment->update(['status' => 'failed']);
                    Broadcast::event(new PaymentProcessed($payment->id, 'failed', null));
                }
            } else {
                // Broadcast failed payment to frontend
                Broadcast::event(new PaymentProcessed($payment->id, $status, null));
            }

            return response()->json([
                'success' => true,
                'message' => 'Callback processed successfully'
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Callback processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Callback processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function generateVoucher()
    {
        return strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
    }
}