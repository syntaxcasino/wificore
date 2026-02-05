<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\MpesaC2BService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * M-Pesa C2B (Paybill) Controller
 * 
 * Handles public callbacks from Safaricom for Paybill payments.
 * These endpoints must be publicly accessible (no auth middleware).
 */
class MpesaC2BController extends Controller
{
    protected MpesaC2BService $c2bService;

    public function __construct(MpesaC2BService $c2bService)
    {
        $this->c2bService = $c2bService;
    }

    /**
     * Validation callback from Safaricom
     * Called before payment is completed to validate account
     * 
     * POST /api/mpesa/c2b/validation/{tenantId}
     */
    public function validation(Request $request, string $tenantId): JsonResponse
    {
        Log::info('M-Pesa C2B Validation received', [
            'tenant_id' => $tenantId,
            'payload' => $request->all(),
            'ip' => $request->ip(),
        ]);

        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->is_active) {
            Log::error('M-Pesa C2B: Invalid tenant for validation', ['tenant_id' => $tenantId]);
            return response()->json([
                'ResultCode' => 'C2B00011',
                'ResultDesc' => 'Invalid tenant',
            ]);
        }

        $this->c2bService->setTenant($tenant);
        $result = $this->c2bService->handleValidation($request->all());

        return response()->json($result);
    }

    /**
     * Confirmation callback from Safaricom
     * Called after payment is completed
     * 
     * POST /api/mpesa/c2b/confirmation/{tenantId}
     */
    public function confirmation(Request $request, string $tenantId): JsonResponse
    {
        Log::info('M-Pesa C2B Confirmation received', [
            'tenant_id' => $tenantId,
            'payload' => $request->all(),
            'ip' => $request->ip(),
        ]);

        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->is_active) {
            Log::error('M-Pesa C2B: Invalid tenant for confirmation', ['tenant_id' => $tenantId]);
            return response()->json([
                'ResultCode' => '1',
                'ResultDesc' => 'Invalid tenant',
            ]);
        }

        $this->c2bService->setTenant($tenant);
        $result = $this->c2bService->handleConfirmation($request->all());

        return response()->json($result);
    }

    /**
     * Register C2B URLs with Safaricom
     * Must be called by tenant admin to enable Paybill payments
     * 
     * POST /api/mpesa/c2b/register-urls
     */
    public function registerUrls(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'validation_url' => 'nullable|url',
            'confirmation_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant = Tenant::find($request->user()->tenant_id);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        // Build URLs if not provided
        $baseUrl = config('app.url');
        $validationUrl = $request->validation_url ?? "{$baseUrl}/api/mpesa/c2b/validation/{$tenant->id}";
        $confirmationUrl = $request->confirmation_url ?? "{$baseUrl}/api/mpesa/c2b/confirmation/{$tenant->id}";

        $this->c2bService->setTenant($tenant);
        $result = $this->c2bService->registerUrls($validationUrl, $confirmationUrl);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Save M-Pesa credentials for tenant
     * 
     * POST /api/mpesa/settings
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'consumer_key' => 'required|string|min:10',
            'consumer_secret' => 'required|string|min:10',
            'shortcode' => 'required|string|min:5|max:10',
            'passkey' => 'required|string|min:10',
            'env' => 'required|in:sandbox,production',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant = Tenant::find($request->user()->tenant_id);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $settings = $tenant->settings ?? [];
        $settings['mpesa'] = [
            'consumer_key' => $request->consumer_key,
            'consumer_secret' => $request->consumer_secret,
            'shortcode' => $request->shortcode,
            'passkey' => $request->passkey,
            'env' => $request->env,
            'updated_at' => now()->toISOString(),
        ];

        $tenant->update(['settings' => $settings]);

        Log::info('M-Pesa settings saved', [
            'tenant_id' => $tenant->id,
            'shortcode' => $request->shortcode,
            'env' => $request->env,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'M-Pesa settings saved successfully',
        ]);
    }

    /**
     * Get M-Pesa settings for tenant (masked)
     * 
     * GET /api/mpesa/settings
     */
    public function getSettings(Request $request): JsonResponse
    {
        $tenant = Tenant::find($request->user()->tenant_id);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $settings = $tenant->settings['mpesa'] ?? [];

        // Mask sensitive data
        $masked = [
            'consumer_key' => isset($settings['consumer_key']) 
                ? substr($settings['consumer_key'], 0, 6) . '****' 
                : null,
            'consumer_secret' => isset($settings['consumer_secret']) 
                ? '****' . substr($settings['consumer_secret'], -4) 
                : null,
            'shortcode' => $settings['shortcode'] ?? null,
            'passkey' => isset($settings['passkey']) ? '********' : null,
            'env' => $settings['env'] ?? 'sandbox',
            'validation_url' => $settings['validation_url'] ?? null,
            'confirmation_url' => $settings['confirmation_url'] ?? null,
            'urls_registered_at' => $settings['urls_registered_at'] ?? null,
            'updated_at' => $settings['updated_at'] ?? null,
        ];

        return response()->json([
            'success' => true,
            'data' => $masked,
            'is_configured' => !empty($settings['shortcode']),
            'urls_registered' => !empty($settings['urls_registered_at']),
        ]);
    }

    /**
     * Test M-Pesa connection
     * 
     * POST /api/mpesa/test-connection
     */
    public function testConnection(Request $request): JsonResponse
    {
        $tenant = Tenant::find($request->user()->tenant_id);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $this->c2bService->setTenant($tenant);
        $token = $this->c2bService->getAccessToken();

        if ($token) {
            return response()->json([
                'success' => true,
                'message' => 'M-Pesa connection successful',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to connect to M-Pesa. Please check your credentials.',
        ], 400);
    }
}
