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

class PaymentController extends Controller
{
 
  protected $mpesaService;

    public function __construct(MpesaService $mpesaService)
    {
        $this->mpesaService = $mpesaService;
    }


    public function initiateSTK(Request $request)
{
    try {
        $data = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'phone_number' => 'required|string|regex:/^(\+?2547\d{8})$/',
            'mac_address' => 'required|string'
        ]);

        $package = Package::find($data['package_id']);

        if (!$package) {
            return response()->json(['error' => 'Package not found'], 404);
        }

       
        $response = $this->mpesaService->initiateSTKPush($data['phone_number'], $package->price);

             if ($response['success']) {
        Payment::create([
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
                'transaction_id' => $response['data']['CheckoutRequestID'],
                'mac_address' => $data['mac_address'],
                'phone_number' => $data['phone_number'],
                'amount' => $package->price,
                'mpesa_transaction_id' => $response['data']['CheckoutRequestID'],
            ]),
        ]);

        // Successfully queued payment initiation
        \Log::info('Payment initiation successful for: ' . $data['phone_number']);

        return response()->json([
            'success' => true,
            'message' => 'Payment initiated successfully',
            'transaction_id' => $response['data']['CheckoutRequestID'],
        ], 200);


    }       
    return response()->json([
            'success' => false,
            'message' => $response['message'] ?? 'Failed to initiate STK Push'
        ], 400);


    } catch (\Throwable $e) {
        \Log::error('Payment initiation failed: ' . $e->getMessage(), ['exception' => $e]);
        return response()->json([
            'success' => false,
            'message' => 'Payment initiation failed.',
            'error' => $e->getMessage()
        ], 500);
    }
}



  public function callback(Request $request)
    {
        $callbackData = $request->all();

        // Process the callback
        $processed = $this->mpesaService->processCallback($callbackData);

        if ($processed) {
            // Update transaction status based on callback
            if (isset($callbackData['Body']['stkCallback']['CheckoutRequestID'])) {
                $checkoutRequestId = $callbackData['Body']['stkCallback']['CheckoutRequestID'];

                $transaction = Transaction::where('checkout_request_id', $checkoutRequestId)->first();

                if ($transaction) {
                    $resultCode = $callbackData['Body']['stkCallback']['ResultCode'] ?? 1;

                    $transaction->update([
                        'status' => $resultCode == 0 ? 'completed' : 'failed',
                        'callback_response' => $callbackData
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Callback processed successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Callback processing failed'
        ], 400);
    }


  private function generateVoucher()
    {
        return strtoupper(substr(md5(uniqid()), 0, 8));
    }
}