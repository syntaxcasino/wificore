<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Payment;
use App\Models\User;
use App\Services\UserProvisioningService;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PurchaseController extends Controller
{
    protected $provisioningService;

    public function __construct(UserProvisioningService $provisioningService)
    {
        $this->provisioningService = $provisioningService;
    }

    /**
     * Purchase package using account balance
     */
    public function purchaseWithBalance(Request $request)
    {
        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'mac_address' => 'required|string|max:17',
        ]);

        try {
            $user = $request->user();
            $package = Package::findOrFail($request->package_id);

            // Check if user has sufficient balance
            if (!$user->hasSufficientBalance($package->price)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient account balance',
                    'required' => $package->price,
                    'available' => $user->account_balance,
                ], 400);
            }

            // Create payment record
            $payment = Payment::create([
                'user_id' => $user->id,
                'mac_address' => $request->mac_address,
                'phone_number' => $user->phone_number,
                'package_id' => $package->id,
                'router_id' => $request->router_id ?? null,
                'amount' => $package->price,
                'transaction_id' => 'BAL_' . Str::upper(Str::random(10)),
                'status' => 'completed',
                'payment_method' => 'account_balance',
            ]);

            // Deduct from balance
            $user->deductBalance($package->price);

            // Provision user
            $result = $this->provisioningService->processPayment($payment);

            return response()->json([
                'success' => true,
                'message' => 'Package purchased successfully',
                'subscription' => $result['subscription'],
                'credentials' => $result['credentials'],
                'new_balance' => $user->fresh()->account_balance,
            ]);

        } catch (\Exception $e) {
            \Log::error('Purchase failed', [
                'user_id' => $request->user()->id,
                'package_id' => $request->package_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Purchase failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's active subscription
     */
    public function getMySubscription(Request $request)
    {
        $user = $request->user();
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'subscription' => [
                'id' => $subscription->id,
                'package' => $subscription->package->name,
                'start_time' => $subscription->start_time,
                'end_time' => $subscription->end_time,
                'status' => $subscription->status,
                'remaining_minutes' => $subscription->getRemainingMinutes(),
                'data_used_mb' => $subscription->data_used_mb,
                'time_used_minutes' => $subscription->time_used_minutes,
                'credentials' => [
                    'username' => $subscription->mikrotik_username,
                    'password' => $subscription->mikrotik_password,
                ],
            ],
        ]);
    }

    /**
     * Get user's subscription history
     */
    public function getMyHistory(Request $request)
    {
        $user = $request->user();
        $subscriptions = $user->subscriptions()
            ->with('package')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * Get user's usage statistics
     */
    public function getMyUsage(Request $request)
    {
        $user = $request->user();
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found',
            ], 404);
        }

        // Get RADIUS accounting data
        $radiusData = \DB::table('radacct')
            ->where('username', $subscription->mikrotik_username)
            ->where('acctstoptime', null)
            ->first();

        $dataUsedMB = 0;
        $timeUsedMinutes = 0;

        if ($radiusData) {
            $dataUsedMB = ($radiusData->acctinputoctets + $radiusData->acctoutputoctets) / (1024 * 1024);
            $timeUsedMinutes = $radiusData->acctsessiontime / 60;
        }

        return response()->json([
            'success' => true,
            'usage' => [
                'data_used_mb' => round($dataUsedMB, 2),
                'time_used_minutes' => round($timeUsedMinutes, 2),
                'session_start' => $radiusData->acctstarttime ?? null,
                'package' => $subscription->package->name,
                'expires_at' => $subscription->end_time,
                'remaining_minutes' => $subscription->getRemainingMinutes(),
            ],
        ]);
    }

    /**
     * Top up account balance
     */
    public function topUpBalance(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'phone_number' => 'required|string|regex:/^\+254[0-9]{9}$/',
        ]);
    
        try {
            $user = $request->user();
            
            // Initiate M-Pesa STK Push
            $mpesaService = app(MpesaService::class);
            $stkResponse = $mpesaService->initiateSTKPush(
                $request->phone_number,
                $request->amount
            );
    
            if (!$stkResponse['success']) {
                throw new \Exception($stkResponse['message']);
            }
    
            // Create payment record
            $payment = Payment::create([
                'user_id' => $user->id,
                'mac_address' => '00:00:00:00:00:00',
                'phone_number' => $request->phone_number,
                'package_id' => null,
                'amount' => $request->amount,
                'transaction_id' => $stkResponse['data']['CheckoutRequestID'],
                'status' => 'pending',
                'payment_method' => 'mpesa',
            ]);
    
            return response()->json([
                'success' => true,
                'message' => 'Top-up initiated. Complete payment on your phone.',
                'checkout_request_id' => $stkResponse['data']['CheckoutRequestID'],
                'payment_id' => $payment->id,
            ]);
    
        } catch (\Exception $e) {
            Log::error('Top-up failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Top-up failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
