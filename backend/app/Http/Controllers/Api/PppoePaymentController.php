<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoePayment;
use App\Models\PppoeUser;
use App\Services\PppoeBillingLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PppoePaymentController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $payments = PppoePayment::with(['pppoeUser', 'verifiedBy'])
            ->orderBy('payment_date', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $payments,
            'tenant_id' => $tenantId,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'pppoe_user_id' => 'required|uuid|exists:pppoe_users,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:paybill,manual,mpesa,bank,cash',
            'payment_reference' => 'nullable|string|max:100',
            'transaction_id' => 'nullable|string|max:100',
            'payment_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $pppoeUser = PppoeUser::findOrFail($request->pppoe_user_id);

            DB::beginTransaction();

            // Create payment record
            $payment = PppoePayment::create([
                'pppoe_user_id' => $pppoeUser->id,
                'account_number' => $pppoeUser->account_number,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference,
                'transaction_id' => $request->transaction_id,
                'status' => 'pending',
                'payment_date' => $request->payment_date,
                'period_start' => now(),
                'period_end' => now()->addDays(30),
                'notes' => $request->notes,
            ]);

            // Auto-verify MPesa, Paybill, and Bank payments
            // Only cash payments require manual verification
            if (in_array($request->payment_method, ['mpesa', 'paybill', 'bank', 'manual'])) {
                $payment->markAsCompleted($request->user()->id);
                
                // Update user payment status
                $this->activateUserAfterPayment($pppoeUser, $payment, $tenantId);
                
                Log::info('Payment auto-verified', [
                    'payment_id' => $payment->id,
                    'payment_method' => $request->payment_method,
                    'user_id' => $pppoeUser->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => $payment->load(['pppoeUser', 'verifiedBy']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to record payment', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'pppoe_user_id' => $request->pppoe_user_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function verify(Request $request, string $id)
    {
        $tenantId = $request->user()->tenant_id;

        $payment = PppoePayment::findOrFail($id);

        if ($payment->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Payment already verified',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $payment->markAsCompleted($request->user()->id);
            
            // Activate user after payment verification
            $this->activateUserAfterPayment($payment->pppoeUser, $payment, $tenantId);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment verified and user activated',
                'data' => $payment->load(['pppoeUser', 'verifiedBy']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to verify payment', [
                'tenant_id' => $tenantId,
                'payment_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function activateUserAfterPayment(PppoeUser $user, PppoePayment $payment, string $tenantId): void
    {
        app(PppoeBillingLifecycleService::class)
            ->handleSuccessfulPayment($user, $payment, $tenantId, 'pppoe_payment_controller');
    }

    public function getPendingPayments(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $pendingPayments = PppoePayment::with(['pppoeUser'])
            ->where('status', 'pending')
            ->orderBy('payment_date', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $pendingPayments,
            'tenant_id' => $tenantId,
        ]);
    }

    public function getUserPayments(Request $request, string $userId)
    {
        $tenantId = $request->user()->tenant_id;

        $payments = PppoePayment::where('pppoe_user_id', $userId)
            ->with(['verifiedBy'])
            ->orderBy('payment_date', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $payments,
            'tenant_id' => $tenantId,
        ]);
    }
}
