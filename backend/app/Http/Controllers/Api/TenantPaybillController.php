<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantPaybillSetting;
use App\Models\MpesaTransaction;
use App\Models\PaymentCheckLog;
use App\Models\PppoeUser;
use App\Services\TenantPaybillService;
use App\Services\TenantContext;
use App\Events\PaybillSettingsUpdated;
use App\Jobs\CheckPppoePaymentsJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Tenant Paybill Controller
 * 
 * Manages tenant's MPesa Paybill settings with landlord fallback.
 * All operations are tenant-isolated and broadcast real-time updates.
 */
class TenantPaybillController extends Controller
{
    protected TenantPaybillService $paybillService;
    protected TenantContext $tenantContext;

    public function __construct(TenantPaybillService $paybillService, TenantContext $tenantContext)
    {
        $this->paybillService = $paybillService;
        $this->tenantContext = $tenantContext;
    }

    /**
     * Get current Paybill settings (masked)
     * 
     * GET /api/billing/paybill/settings
     */
    public function getSettings(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $settings = TenantPaybillSetting::first();
            
            // Get landlord config info from DB (with .env fallback)
            $systemSettings = \App\Models\SystemPaymentSetting::getActive();
            $landlordShortcode = $systemSettings?->shortcode ?? config('mpesa.shortcode');
            $hasLandlordPaybill = !empty($landlordShortcode);

            if ($settings) {
                return response()->json([
                    'success' => true,
                    'data' => $settings->getMaskedCredentials(),
                    'has_own_paybill' => $settings->hasOwnPaybill(),
                    'using_landlord_paybill' => $settings->use_landlord_paybill || !$settings->hasOwnPaybill(),
                    'landlord_paybill_available' => $hasLandlordPaybill,
                    'landlord_shortcode' => $hasLandlordPaybill ? $landlordShortcode : null,
                    'tenant_id' => $tenantId,
                ]);
            }

            // No settings exist yet - return defaults
            return response()->json([
                'success' => true,
                'data' => null,
                'has_own_paybill' => false,
                'using_landlord_paybill' => true,
                'landlord_paybill_available' => $hasLandlordPaybill,
                'landlord_shortcode' => $hasLandlordPaybill ? $landlordShortcode : null,
                'tenant_id' => $tenantId,
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Failed to get settings', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load Paybill settings',
            ], 500);
        }
    }

    /**
     * Save or update Paybill settings
     * 
     * POST /api/billing/paybill/settings
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'business_shortcode' => 'nullable|string|min:5|max:20',
            'consumer_key' => 'nullable|string|min:10',
            'consumer_secret' => 'nullable|string|min:10',
            'passkey' => 'nullable|string|min:10',
            'account_reference' => 'nullable|string|max:50',
            'environment' => 'required|in:sandbox,production',
            'use_landlord_paybill' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $settings = TenantPaybillSetting::first();
            
            $data = [
                'business_shortcode' => $request->business_shortcode,
                'consumer_key' => $request->consumer_key,
                'consumer_secret' => $request->consumer_secret,
                'passkey' => $request->passkey,
                'account_reference' => $request->account_reference,
                'environment' => $request->environment,
                'use_landlord_paybill' => $request->use_landlord_paybill,
                'updated_by' => $request->user()->id,
            ];

            if ($settings) {
                // Only update credentials if provided (don't clear existing)
                if (empty($request->consumer_key)) {
                    unset($data['consumer_key']);
                }
                if (empty($request->consumer_secret)) {
                    unset($data['consumer_secret']);
                }
                if (empty($request->passkey)) {
                    unset($data['passkey']);
                }
                
                $settings->update($data);
            } else {
                $data['created_by'] = $request->user()->id;
                $settings = TenantPaybillSetting::create($data);
            }

            Log::info('TenantPaybillController: Settings saved', [
                'tenant_id' => $tenantId,
                'shortcode' => $request->business_shortcode,
                'use_landlord' => $request->use_landlord_paybill,
            ]);

            // Broadcast settings update
            event(new PaybillSettingsUpdated($tenantId, $settings->getMaskedCredentials()));

            return response()->json([
                'success' => true,
                'message' => 'Paybill settings saved successfully',
                'data' => $settings->getMaskedCredentials(),
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Failed to save settings', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save Paybill settings',
            ], 500);
        }
    }

    /**
     * Test MPesa connection
     * 
     * POST /api/billing/paybill/test
     */
    public function testConnection(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $this->paybillService->setTenantId($tenantId);
            $this->paybillService->initialize();
            
            $token = $this->paybillService->getAccessToken();

            if ($token) {
                return response()->json([
                    'success' => true,
                    'message' => 'MPesa connection successful',
                    'using_landlord_paybill' => $this->paybillService->isUsingLandlordPaybill(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to MPesa. Please check your credentials.',
            ], 400);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Connection test failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register C2B URLs with Safaricom
     * 
     * POST /api/billing/paybill/register-urls
     */
    public function registerUrls(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $this->paybillService->setTenantId($tenantId);
            $this->paybillService->initialize();

            if ($this->paybillService->isUsingLandlordPaybill()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot register URLs when using landlord Paybill. URLs are managed by the system administrator.',
                ], 400);
            }

            $result = $this->paybillService->registerUrls();

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: URL registration failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'URL registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate tenant's own Paybill
     * 
     * POST /api/billing/paybill/activate
     */
    public function activate(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $settings = TenantPaybillSetting::first();

            if (!$settings || !$settings->hasCompleteCredentials()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please complete your Paybill credentials first',
                ], 400);
            }

            $settings->update([
                'is_active' => true,
                'use_landlord_paybill' => false,
                'updated_by' => $request->user()->id,
            ]);

            Log::info('TenantPaybillController: Paybill activated', [
                'tenant_id' => $tenantId,
                'shortcode' => $settings->business_shortcode,
            ]);

            event(new PaybillSettingsUpdated($tenantId, $settings->getMaskedCredentials()));

            return response()->json([
                'success' => true,
                'message' => 'Your Paybill has been activated',
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Activation failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Activation failed',
            ], 500);
        }
    }

    /**
     * Switch to landlord Paybill
     * 
     * POST /api/billing/paybill/use-landlord
     */
    public function useLandlordPaybill(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            // Check landlord paybill from DB (with .env fallback)
            $systemSettings = \App\Models\SystemPaymentSetting::getActive();
            $landlordShortcode = $systemSettings?->shortcode ?? config('mpesa.shortcode');
            
            if (empty($landlordShortcode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Landlord Paybill is not configured',
                ], 400);
            }

            $settings = TenantPaybillSetting::first();

            if ($settings) {
                $settings->update([
                    'use_landlord_paybill' => true,
                    'is_active' => false,
                    'updated_by' => $request->user()->id,
                ]);
            } else {
                TenantPaybillSetting::create([
                    'use_landlord_paybill' => true,
                    'environment' => config('mpesa.env', 'sandbox'),
                    'created_by' => $request->user()->id,
                ]);
            }

            Log::info('TenantPaybillController: Switched to landlord Paybill', [
                'tenant_id' => $tenantId,
            ]);

            $settings = TenantPaybillSetting::first();
            event(new PaybillSettingsUpdated($tenantId, $settings->getMaskedCredentials()));

            return response()->json([
                'success' => true,
                'message' => 'Now using landlord Paybill',
                'landlord_shortcode' => $landlordShortcode,
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Switch to landlord failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to switch to landlord Paybill',
            ], 500);
        }
    }

    /**
     * Get payment instructions for a user
     * 
     * GET /api/billing/paybill/instructions/{userId}
     */
    public function getPaymentInstructions(Request $request, string $userId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            // OPTIMIZED: Select only needed columns
            $user = PppoeUser::query()
                ->select(['id', 'username', 'account_number'])
                ->findOrFail($userId);

            $this->paybillService->setTenantId($tenantId);
            $this->paybillService->initialize();

            $instructions = $this->paybillService->getPaymentInstructions($user);

            return response()->json([
                'success' => true,
                'data' => $instructions,
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Failed to get instructions', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get payment instructions',
            ], 500);
        }
    }

    /**
     * Get transaction history
     * 
     * GET /api/billing/paybill/transactions
     */
    public function getTransactions(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $transactions = MpesaTransaction::with('pppoeUser:id,username,account_number')
                ->orderByDesc('transaction_time')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $transactions,
                'tenant_id' => $tenantId,
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Failed to get transactions', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load transactions',
            ], 500);
        }
    }

    /**
     * Get payment check logs
     * 
     * GET /api/billing/paybill/logs
     */
    public function getCheckLogs(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $logs = PaymentCheckLog::orderByDesc('started_at')
                ->limit(50)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $logs,
                'tenant_id' => $tenantId,
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Failed to get logs', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load check logs',
            ], 500);
        }
    }

    /**
     * Manually trigger payment check
     * 
     * POST /api/billing/paybill/check-payments
     */
    public function triggerPaymentCheck(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            CheckPppoePaymentsJob::dispatch($tenantId);

            return response()->json([
                'success' => true,
                'message' => 'Payment check queued',
            ]);

        } catch (\Exception $e) {
            Log::error('TenantPaybillController: Failed to queue payment check', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to queue payment check',
            ], 500);
        }
    }

    /**
     * Handle validation callback from Safaricom (public endpoint)
     * 
     * POST /api/mpesa/paybill/validation/{tenantId}
     */
    public function handleValidation(Request $request, string $tenantId): JsonResponse
    {
        Log::info('TenantPaybillController: Validation callback', [
            'tenant_id' => $tenantId,
            'payload' => $request->all(),
            'ip' => $request->ip(),
        ]);

        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->is_active) {
            return response()->json([
                'ResultCode' => 'C2B00011',
                'ResultDesc' => 'Invalid tenant',
            ]);
        }

        $this->tenantContext->setTenant($tenant);

        try {
            $this->paybillService->setTenantId($tenantId);
            $this->paybillService->initialize();
            
            $result = $this->paybillService->handleValidation($request->all());
            return response()->json($result);
        } finally {
            $this->tenantContext->reset();
        }
    }

    /**
     * Handle confirmation callback from Safaricom (public endpoint)
     * 
     * POST /api/mpesa/paybill/confirmation/{tenantId}
     */
    public function handleConfirmation(Request $request, string $tenantId): JsonResponse
    {
        Log::info('TenantPaybillController: Confirmation callback', [
            'tenant_id' => $tenantId,
            'payload' => $request->all(),
            'ip' => $request->ip(),
        ]);

        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->is_active) {
            return response()->json([
                'ResultCode' => '1',
                'ResultDesc' => 'Invalid tenant',
            ]);
        }

        $this->tenantContext->setTenant($tenant);

        try {
            $this->paybillService->setTenantId($tenantId);
            $this->paybillService->initialize();
            
            $result = $this->paybillService->handleConfirmation($request->all());
            return response()->json($result);
        } finally {
            $this->tenantContext->reset();
        }
    }
}
