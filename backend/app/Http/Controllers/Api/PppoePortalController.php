<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoeUser;
use App\Models\Voucher;
use App\Models\Payment;
use App\Models\Router;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PPPoE Customer Portal Controller
 * 
 * Provides tenant-agnostic access for PPPoE users to:
 * - View their usage and account status
 * - Make M-Pesa payments
 * - Redeem vouchers
 * - Check session history
 */
class PppoePortalController extends Controller
{
    /**
     * Authenticate PPPoE user with account number and portal password
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_number' => 'required|string',
            'portal_password' => 'required|string',
        ]);

        // Find PPPoE user by account number (tenant-agnostic lookup)
        $pppoeUser = PppoeUser::query()
            ->where('account_number', $validated['account_number'])
            ->whereNotNull('portal_password') // Must have portal password set
            ->first();

        if (!$pppoeUser) {
            Log::warning('PPPoE portal login failed: User not found', [
                'account_number' => $validated['account_number'],
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid account number or password',
            ], 401);
        }

        // Verify portal password (NOT the PPPoE client password)
        if (!Hash::check($validated['portal_password'], $pppoeUser->portal_password)) {
            Log::warning('PPPoE portal login failed: Invalid password', [
                'account_number' => $validated['account_number'],
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid account number or password',
            ], 401);
        }

        // Check if account is active
        if ($pppoeUser->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is ' . $pppoeUser->status,
                'status' => $pppoeUser->status,
            ], 403);
        }

        // Create token for portal access
        $token = $pppoeUser->createPortalToken();

        Log::info('PPPoE portal login successful', [
            'account_number' => $pppoeUser->account_number,
            'tenant_id' => $pppoeUser->tenant_id,
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $pppoeUser->id,
                'account_number' => $pppoeUser->account_number,
                'username' => $pppoeUser->username,
                'full_name' => $pppoeUser->full_name,
                'email' => $pppoeUser->email,
                'phone' => $pppoeUser->phone,
                'package_name' => $pppoeUser->package?->name,
                'status' => $pppoeUser->status,
                'expiration_date' => $pppoeUser->expiration_date,
            ],
        ]);
    }

    /**
     * Get PPPoE user dashboard data (usage, balance, etc.)
     */
    public function dashboard(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Get current session info from RADIUS
        $currentSession = $this->getCurrentSession($pppoeUser);

        // Get usage statistics
        $usageStats = $this->getUsageStats($pppoeUser);

        // Get recent payments
        $recentPayments = Payment::query()
            ->where('pppoe_user_id', $pppoeUser->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'amount', 'status', 'payment_method', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $pppoeUser->id,
                    'account_number' => $pppoeUser->account_number,
                    'username' => $pppoeUser->username,
                    'full_name' => $pppoeUser->full_name,
                    'email' => $pppoeUser->email,
                    'phone' => $pppoeUser->phone,
                    'package' => [
                        'name' => $pppoeUser->package?->name,
                        'download_speed' => $pppoeUser->package?->download_speed,
                        'upload_speed' => $pppoeUser->package?->upload_speed,
                        'price' => $pppoeUser->package?->price,
                    ],
                    'status' => $pppoeUser->status,
                    'expiration_date' => $pppoeUser->expiration_date,
                    'balance' => $pppoeUser->balance ?? 0,
                ],
                'current_session' => $currentSession,
                'usage_stats' => $usageStats,
                'recent_payments' => $recentPayments,
            ],
        ]);
    }

    /**
     * Get session history for PPPoE user
     */
    public function sessionHistory(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'days' => 'integer|min:1|max:90|default:30',
        ]);

        $days = $validated['days'] ?? 30;
        $startDate = Carbon::now()->subDays($days);

        // Query RADIUS accounting data
        $sessions = DB::connection('radius')
            ->table('radacct')
            ->where('username', $pppoeUser->username)
            ->where('acctstarttime', '>=', $startDate)
            ->orderBy('acctstarttime', 'desc')
            ->get([
                'radacctid',
                'acctstarttime as start_time',
                'acctstoptime as stop_time',
                'acctsessiontime as duration_seconds',
                'acctinputoctets as download_bytes',
                'acctoutputoctets as upload_bytes',
                'nasipaddress as nas_ip',
                'framedipaddress as ip_address',
                'acctterminatecause as terminate_cause',
            ]);

        // Format sessions
        $formattedSessions = $sessions->map(function ($session) {
            return [
                'id' => $session->radacctid,
                'start_time' => $session->start_time,
                'stop_time' => $session->stop_time,
                'duration_formatted' => $this->formatDuration($session->duration_seconds),
                'duration_seconds' => $session->duration_seconds,
                'download_formatted' => $this->formatBytes($session->download_bytes),
                'download_bytes' => $session->download_bytes,
                'upload_formatted' => $this->formatBytes($session->upload_bytes),
                'upload_bytes' => $session->upload_bytes,
                'total_formatted' => $this->formatBytes($session->download_bytes + $session->upload_bytes),
                'ip_address' => $session->ip_address,
                'nas_ip' => $session->nas_ip,
                'status' => $session->stop_time ? 'disconnected' : 'active',
                'terminate_cause' => $session->terminate_cause,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => $formattedSessions,
                'total_sessions' => $sessions->count(),
                'period_days' => $days,
            ],
        ]);
    }

    /**
     * Initiate M-Pesa STK push for PPPoE user
     */
    public function initiateMpesaPayment(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'phone_number' => 'required|string|regex:/^254[0-9]{9}$/',
            'amount' => 'required|numeric|min:10|max:100000',
        ]);

        try {
            // Check for recent pending payments to prevent duplicates
            $recentPending = Payment::query()
                ->where('pppoe_user_id', $pppoeUser->id)
                ->where('status', 'pending')
                ->where('payment_method', 'mpesa')
                ->where('created_at', '>', Carbon::now()->subMinutes(2))
                ->first();

            if ($recentPending) {
                return response()->json([
                    'success' => false,
                    'message' => 'A payment request is already pending. Please wait.',
                    'pending_transaction' => $recentPending->transaction_id,
                ], 429);
            }

            // Initiate M-Pesa STK Push
            $mpesaService = app(MpesaService::class);
            $stkResponse = $mpesaService->initiateSTKPush(
                $validated['phone_number'],
                $validated['amount']
            );

            if (!$stkResponse['success']) {
                Log::error('M-Pesa STK Push failed', [
                    'account_number' => $pppoeUser->account_number,
                    'error' => $stkResponse['message'] ?? 'Unknown error',
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $stkResponse['message'] ?? 'Failed to initiate payment',
                ], 500);
            }

            // Create payment record
            $payment = Payment::create([
                'pppoe_user_id' => $pppoeUser->id,
                'tenant_id' => $pppoeUser->tenant_id,
                'user_id' => $pppoeUser->user_id, // Link to parent user if exists
                'amount' => $validated['amount'],
                'phone_number' => $validated['phone_number'],
                'transaction_id' => $stkResponse['data']['CheckoutRequestID'],
                'merchant_request_id' => $stkResponse['data']['MerchantRequestID'],
                'status' => 'pending',
                'payment_method' => 'mpesa',
                'payment_type' => 'pppoe_topup',
                'description' => 'PPPoE Account Top-up via Portal',
            ]);

            Log::info('M-Pesa STK Push initiated for PPPoE user', [
                'account_number' => $pppoeUser->account_number,
                'amount' => $validated['amount'],
                'transaction_id' => $payment->transaction_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment request sent to your phone. Please enter M-Pesa PIN.',
                'data' => [
                    'transaction_id' => $payment->transaction_id,
                    'merchant_request_id' => $payment->merchant_request_id,
                    'amount' => $validated['amount'],
                    'phone_number' => $this->maskPhoneNumber($validated['phone_number']),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error initiating M-Pesa payment', [
                'account_number' => $pppoeUser->account_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your payment request',
            ], 500);
        }
    }

    /**
     * Redeem voucher for PPPoE user
     */
    public function redeemVoucher(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'voucher_code' => 'required|string|min:5|max:20',
        ]);

        $voucherCode = strtoupper(trim($validated['voucher_code']));

        try {
            DB::beginTransaction();

            // Find voucher (tenant-scoped lookup for security)
            $voucher = Voucher::query()
                ->where('tenant_id', $pppoeUser->tenant_id)
                ->where('code', $voucherCode)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', Carbon::now());
                })
                ->lockForUpdate()
                ->first();

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired voucher code',
                ], 400);
            }

            // Check if voucher was already used
            if ($voucher->used_at || $voucher->used_by) {
                return response()->json([
                    'success' => false,
                    'message' => 'This voucher has already been redeemed',
                ], 400);
            }

            // Mark voucher as used
            $voucher->update([
                'status' => 'used',
                'used_at' => Carbon::now(),
                'used_by' => $pppoeUser->id,
                'used_by_type' => 'pppoe_user',
            ]);

            // Create payment record for the voucher redemption
            $payment = Payment::create([
                'pppoe_user_id' => $pppoeUser->id,
                'tenant_id' => $pppoeUser->tenant_id,
                'user_id' => $pppoeUser->user_id,
                'voucher_id' => $voucher->id,
                'amount' => $voucher->value,
                'status' => 'completed',
                'payment_method' => 'voucher',
                'payment_type' => 'pppoe_topup',
                'transaction_id' => 'VOUCHER-' . $voucher->code,
                'description' => 'Voucher redemption: ' . $voucher->code,
                'paid_at' => Carbon::now(),
            ]);

            // Update PPPoE user balance
            $pppoeUser->balance = ($pppoeUser->balance ?? 0) + $voucher->value;
            $pppoeUser->save();

            // Extend expiration if applicable
            if ($voucher->package_duration_days) {
                $currentExpiry = $pppoeUser->expiration_date ? Carbon::parse($pppoeUser->expiration_date) : Carbon::now();
                $newExpiry = $currentExpiry->addDays($voucher->package_duration_days);
                $pppoeUser->expiration_date = $newExpiry;
                $pppoeUser->save();
            }

            DB::commit();

            Log::info('Voucher redeemed successfully', [
                'account_number' => $pppoeUser->account_number,
                'voucher_code' => $voucher->code,
                'value' => $voucher->value,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Voucher redeemed successfully!',
                'data' => [
                    'voucher_value' => $voucher->value,
                    'new_balance' => $pppoeUser->balance,
                    'expiration_extended' => $voucher->package_duration_days ? true : false,
                    'new_expiration' => $pppoeUser->expiration_date,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error redeeming voucher', [
                'account_number' => $pppoeUser->account_number,
                'voucher_code' => $voucherCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while redeeming the voucher',
            ], 500);
        }
    }

    /**
     * Check payment status
     */
    public function checkPaymentStatus(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'transaction_id' => 'required|string',
        ]);

        $payment = Payment::query()
            ->where('pppoe_user_id', $pppoeUser->id)
            ->where('transaction_id', $validated['transaction_id'])
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $payment->transaction_id,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'created_at' => $payment->created_at,
                'paid_at' => $payment->paid_at,
            ],
        ]);
    }

    /**
     * Logout PPPoE user
     */
    public function logout(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if ($pppoeUser) {
            // Revoke all portal tokens
            $pppoeUser->revokePortalTokens();
            
            Log::info('PPPoE portal logout', [
                'account_number' => $pppoeUser->account_number,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    // ============== Helper Methods ==============

    private function getCurrentSession(PppoeUser $pppoeUser): ?array
    {
        $session = DB::connection('radius')
            ->table('radacct')
            ->where('username', $pppoeUser->username)
            ->whereNull('acctstoptime')
            ->orderBy('acctstarttime', 'desc')
            ->first();

        if (!$session) {
            return null;
        }

        return [
            'start_time' => $session->acctstarttime,
            'duration_formatted' => $this->formatDuration($session->acctsessiontime),
            'duration_seconds' => $session->acctsessiontime,
            'ip_address' => $session->framedipaddress,
            'nas_ip' => $session->nasipaddress,
            'download_formatted' => $this->formatBytes($session->acctinputoctets),
            'download_bytes' => $session->acctinputoctets,
            'upload_formatted' => $this->formatBytes($session->acctoutputoctets),
            'upload_bytes' => $session->acctoutputoctets,
        ];
    }

    private function getUsageStats(PppoeUser $pppoeUser): array
    {
        // Get last 30 days usage from RADIUS
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        
        $stats = DB::connection('radius')
            ->table('radacct')
            ->where('username', $pppoeUser->username)
            ->where('acctstarttime', '>=', $thirtyDaysAgo)
            ->select(
                DB::raw('COUNT(*) as total_sessions'),
                DB::raw('SUM(acctsessiontime) as total_duration'),
                DB::raw('SUM(acctinputoctets) as total_download'),
                DB::raw('SUM(acctoutputoctets) as total_upload')
            )
            ->first();

        return [
            'period_days' => 30,
            'total_sessions' => $stats->total_sessions ?? 0,
            'total_duration_formatted' => $this->formatDuration($stats->total_duration ?? 0),
            'total_duration_seconds' => $stats->total_duration ?? 0,
            'total_download_formatted' => $this->formatBytes($stats->total_download ?? 0),
            'total_download_bytes' => $stats->total_download ?? 0,
            'total_upload_formatted' => $this->formatBytes($stats->total_upload ?? 0),
            'total_upload_bytes' => $stats->total_upload ?? 0,
            'total_usage_formatted' => $this->formatBytes(($stats->total_download ?? 0) + ($stats->total_upload ?? 0)),
        ];
    }

    private function formatDuration(?int $seconds): string
    {
        if (!$seconds) return '0s';
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = [];
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0) $parts[] = $minutes . 'm';
        if ($secs > 0 || empty($parts)) $parts[] = $secs . 's';
        
        return implode(' ', $parts);
    }

    private function formatBytes(?int $bytes): string
    {
        if (!$bytes) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    private function maskPhoneNumber(string $phone): string
    {
        return substr($phone, 0, 6) . '****' . substr($phone, -3);
    }
}
