<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantInvoiceNotification;
use App\Notifications\TenantPaymentReceiptNotification;
use App\Services\SaasBillingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LandlordBillingController extends Controller
{
    protected SaasBillingService $billingService;

    public function __construct(SaasBillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Get current SaaS billing configuration
     */
    public function getConfiguration(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'configuration' => [
                'default_paybill' => config('saas.default_paybill'),
                'default_paybill_name' => config('saas.default_paybill_name'),
                'pppoe_rate' => config('saas.pppoe_rate'),
                'hotspot_revenue_pct' => config('saas.hotspot_revenue_pct'),
                'resource_factors' => config('saas.resource_factors'),
                'minimum_subscription' => config('saas.minimum_subscription'),
                'plans' => config('saas.plans'),
                'enforcement' => config('saas.enforcement'),
            ],
        ]);
    }

    /**
     * Update default paybill configuration
     * Persists to system_payment_settings DB table (replaces .env approach)
     */
    public function updateDefaultPaybill(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'paybill' => 'required|string|max:20',
            'paybill_name' => 'nullable|string|max:100',
            'consumer_key' => 'nullable|string|min:10',
            'consumer_secret' => 'nullable|string|min:10',
            'passkey' => 'nullable|string|min:10',
            'environment' => 'nullable|in:sandbox,production',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $settings = \App\Models\SystemPaymentSetting::first();

            $data = [
                'default_paybill_number' => $request->paybill,
                'shortcode' => $request->paybill,
                'environment' => $request->environment ?? 'sandbox',
                'is_active' => true,
                'updated_by' => auth()->id(),
            ];

            // Only update credentials if provided (don't clear existing)
            if ($request->filled('consumer_key')) {
                $data['consumer_key'] = $request->consumer_key;
            }
            if ($request->filled('consumer_secret')) {
                $data['consumer_secret'] = $request->consumer_secret;
            }
            if ($request->filled('passkey')) {
                $data['passkey'] = $request->passkey;
            }

            if ($settings) {
                $settings->update($data);
            } else {
                $settings = \App\Models\SystemPaymentSetting::create($data);
            }

            // Clear cached system settings
            \App\Services\PaymentConfigService::clearSystemSettingsCache();

            Log::info('Landlord updated default paybill (DB-persisted)', [
                'shortcode' => $request->paybill,
                'environment' => $request->environment,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Default paybill configuration saved to database',
                'data' => $settings->getMaskedCredentials(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update default paybill', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save paybill configuration',
            ], 500);
        }
    }

    /**
     * Update billing rates
     */
    public function updateBillingRates(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pppoe_rate' => 'nullable|numeric|min:0',
            'hotspot_revenue_pct' => 'nullable|numeric|min:0|max:100',
            'router_rate' => 'nullable|numeric|min:0',
            'minimum_subscription' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        Log::info('Landlord updated billing rates', [
            'pppoe_rate' => $request->pppoe_rate,
            'hotspot_revenue_pct' => $request->hotspot_revenue_pct,
            'router_rate' => $request->router_rate,
            'minimum_subscription' => $request->minimum_subscription,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Billing rates updated. Note: Requires environment variable update for persistence.',
            'rates' => [
                'pppoe_rate' => $request->pppoe_rate ?? config('saas.pppoe_rate'),
                'hotspot_revenue_pct' => $request->hotspot_revenue_pct ?? config('saas.hotspot_revenue_pct'),
                'router_rate' => $request->router_rate ?? config('saas.resource_factors.router_rate'),
                'minimum_subscription' => $request->minimum_subscription ?? config('saas.minimum_subscription'),
            ],
        ]);
    }

    /**
     * Get aggregate tenant metrics (privacy-safe)
     */
    public function getAggregateMetrics(): JsonResponse
    {
        $metrics = $this->billingService->getAggregateTenantMetrics();

        return response()->json([
            'success' => true,
            'metrics' => $metrics,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get per-tenant counts (no sensitive data)
     */
    public function getTenantCounts(): JsonResponse
    {
        $counts = $this->billingService->getTenantCounts();

        return response()->json([
            'success' => true,
            'tenant_counts' => $counts,
            'total_tenants' => count($counts),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Set tenant-specific billing rates
     */
    public function setTenantRates(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::whereRaw('is_landlord = false')->findOrFail($tenantId);

        $validator = Validator::make($request->all(), [
            'pppoe_rate' => 'nullable|numeric|min:0',
            'hotspot_revenue_pct' => 'nullable|numeric|min:0|max:100',
            'router_rate' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant->pppoe_rate = $request->pppoe_rate;
        $tenant->hotspot_revenue_pct = $request->hotspot_revenue_pct;
        $tenant->router_rate = $request->router_rate;
        $tenant->save();

        Log::info('Landlord set tenant-specific billing rates', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'pppoe_rate' => $request->pppoe_rate,
            'hotspot_revenue_pct' => $request->hotspot_revenue_pct,
            'router_rate' => $request->router_rate,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant billing rates updated',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'pppoe_rate' => $tenant->pppoe_rate,
                'hotspot_revenue_pct' => $tenant->hotspot_revenue_pct,
                'router_rate' => $tenant->router_rate,
            ],
        ]);
    }

    /**
     * Apply landlord override to prevent tenant disconnection
     */
    public function applyOverride(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::whereRaw('is_landlord = false')->findOrFail($tenantId);

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'until' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $until = $request->until ? Carbon::parse($request->until) : null;
        $success = $this->billingService->applyLandlordOverride($tenant, $request->reason, $until);

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply landlord override',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Landlord override applied successfully',
            'override' => [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'reason' => $request->reason,
                'until' => $until?->toIso8601String(),
                'indefinite' => is_null($until),
            ],
        ]);
    }

    /**
     * Remove landlord override from tenant
     */
    public function removeOverride(string $tenantId): JsonResponse
    {
        $tenant = Tenant::whereRaw('is_landlord = false')->findOrFail($tenantId);

        $success = $this->billingService->removeLandlordOverride($tenant);

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove landlord override',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Landlord override removed',
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Get tenants with active overrides
     */
    public function getOverriddenTenants(): JsonResponse
    {
        $tenants = Tenant::whereRaw('is_landlord = false')
            ->whereRaw('landlord_override = true')
            ->get(['id', 'name', 'slug', 'landlord_override_reason', 'landlord_override_until', 'subscription_ends_at']);

        return response()->json([
            'success' => true,
            'overridden_tenants' => $tenants->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'reason' => $tenant->landlord_override_reason,
                    'until' => $tenant->landlord_override_until,
                    'subscription_ends_at' => $tenant->subscription_ends_at,
                    'override_active' => $this->billingService->hasActiveOverride($tenant),
                ];
            }),
            'count' => $tenants->count(),
        ]);
    }

    /**
     * Reactivate a suspended tenant
     */
    public function reactivateTenant(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::whereRaw('is_landlord = false')->findOrFail($tenantId);

        $validator = Validator::make($request->all(), [
            'extend_days' => 'nullable|integer|min:1|max:365',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Reactivate tenant
        $tenant->is_active = true;
        $tenant->suspended_at = null;
        $tenant->suspension_reason = null;
        $tenant->subscription_status = 'active';

        // Extend subscription if requested
        $extendDays = $request->extend_days ?? 30;
        $this->billingService->extendSubscription($tenant, $extendDays);

        Log::info('Landlord reactivated tenant', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'extend_days' => $extendDays,
            'reason' => $request->reason,
            'reactivated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant reactivated successfully',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'is_active' => $tenant->is_active,
                'subscription_ends_at' => $tenant->subscription_ends_at,
            ],
        ]);
    }

    /**
     * Calculate subscription cost for a tenant
     */
    public function calculateTenantSubscription(string $tenantId): JsonResponse
    {
        $tenant = Tenant::whereRaw('is_landlord = false')->findOrFail($tenantId);

        $subscriptionCost = $this->billingService->calculateSubscriptionCost($tenant);

        return response()->json([
            'success' => true,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'subscription_cost' => $subscriptionCost,
        ]);
    }

    /**
     * Generate and send invoice to tenant
     */
    public function generateInvoice(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::whereRaw('is_landlord = false')->findOrFail($tenantId);

        $validator = Validator::make($request->all(), [
            'due_days' => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Calculate subscription cost
        $invoice = $this->billingService->calculateSubscriptionCost($tenant);
        $invoice['due_date'] = now()->addDays($request->due_days ?? 7)->toIso8601String();

        // Generate invoice number
        $invoiceNumber = 'INV-' . strtoupper(Str::random(8)) . '-' . now()->format('Ymd');

        // Update tenant last invoice
        $tenant->last_invoice_at = now();
        $tenant->last_invoice_amount = $invoice['total'];
        $tenant->save();

        // Send invoice notification to tenant admins
        $admins = User::where('tenant_id', $tenant->id)
            ->where('role', User::ROLE_ADMIN)
            ->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new TenantInvoiceNotification($tenant, $invoice, $invoiceNumber));
        }

        Log::info('Landlord generated invoice for tenant', [
            'tenant_id' => $tenant->id,
            'invoice_number' => $invoiceNumber,
            'amount' => $invoice['total'],
            'generated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Invoice generated and sent to tenant admins',
            'invoice' => [
                'number' => $invoiceNumber,
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'amount' => $invoice['total'],
                'due_date' => $invoice['due_date'],
                'breakdown' => $invoice['breakdown'],
                'usage' => $invoice['usage'],
            ],
        ]);
    }

    /**
     * Record payment and send receipt
     */
    public function recordPayment(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::whereRaw('is_landlord = false')->findOrFail($tenantId);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'transaction_id' => 'required|string|max:100',
            'payment_method' => 'nullable|string|in:mpesa,bank,cash,other',
            'extend_days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Record payment
        $this->billingService->recordPayment(
            $tenant,
            $request->amount,
            $request->transaction_id,
            $request->payment_method ?? 'mpesa'
        );

        // Extend subscription
        $extendDays = $request->extend_days ?? 30;
        $this->billingService->extendSubscription($tenant, $extendDays);

        // If tenant was suspended, reactivate
        if ($tenant->suspended_at) {
            $tenant->is_active = true;
            $tenant->suspended_at = null;
            $tenant->suspension_reason = null;
            $tenant->subscription_status = 'active';
            $tenant->save();
        }

        // Generate receipt number
        $receiptNumber = 'RCT-' . strtoupper(Str::random(8)) . '-' . now()->format('Ymd');

        // Prepare payment data for notification
        $payment = [
            'amount' => $request->amount,
            'transaction_id' => $request->transaction_id,
            'payment_method' => $request->payment_method ?? 'mpesa',
            'paid_at' => now()->toIso8601String(),
            'new_expiry_date' => $tenant->fresh()->subscription_ends_at,
        ];

        // Send receipt notification
        $admins = User::where('tenant_id', $tenant->id)
            ->where('role', User::ROLE_ADMIN)
            ->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new TenantPaymentReceiptNotification($tenant, $payment, $receiptNumber));
        }

        Log::info('Landlord recorded payment for tenant', [
            'tenant_id' => $tenant->id,
            'receipt_number' => $receiptNumber,
            'amount' => $request->amount,
            'transaction_id' => $request->transaction_id,
            'recorded_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded and receipt sent to tenant',
            'receipt' => [
                'number' => $receiptNumber,
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'amount' => $request->amount,
                'transaction_id' => $request->transaction_id,
                'new_expiry_date' => $tenant->fresh()->subscription_ends_at,
            ],
        ]);
    }

    /**
     * Get tenants expiring soon
     */
    public function getExpiringSoon(): JsonResponse
    {
        $warningDays = config('saas.enforcement.warning_days', 5);

        $tenants = Tenant::whereRaw('is_landlord = false')
            ->whereRaw('is_active = true')
            ->whereNull('suspended_at')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '>', now())
            ->where('subscription_ends_at', '<=', now()->addDays($warningDays))
            ->get(['id', 'name', 'slug', 'subscription_ends_at', 'subscription_warning_sent_at', 'landlord_override']);

        return response()->json([
            'success' => true,
            'expiring_tenants' => $tenants->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'subscription_ends_at' => $tenant->subscription_ends_at,
                    'days_remaining' => $this->billingService->getDaysUntilExpiry($tenant),
                    'warning_sent' => !is_null($tenant->subscription_warning_sent_at),
                    'has_override' => $tenant->landlord_override,
                ];
            }),
            'count' => $tenants->count(),
            'warning_threshold_days' => $warningDays,
        ]);
    }

    /**
     * Get suspended tenants
     */
    public function getSuspendedTenants(): JsonResponse
    {
        $tenants = Tenant::whereRaw('is_landlord = false')
            ->whereNotNull('suspended_at')
            ->get(['id', 'name', 'slug', 'suspended_at', 'suspension_reason', 'subscription_ends_at']);

        return response()->json([
            'success' => true,
            'suspended_tenants' => $tenants,
            'count' => $tenants->count(),
        ]);
    }
}
