<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\PppoeUser;
use App\Models\HotspotUser;
use App\Models\Router;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaasBillingService
{
    /**
     * Get the effective PPPoE rate for a tenant
     */
    public function getPppoeRate(Tenant $tenant): float
    {
        return $tenant->pppoe_rate ?? config('saas.pppoe_rate', 30.00);
    }

    /**
     * Get the effective hotspot revenue percentage for a tenant
     */
    public function getHotspotRevenuePct(Tenant $tenant): float
    {
        return $tenant->hotspot_revenue_pct ?? config('saas.hotspot_revenue_pct', 2.0);
    }

    /**
     * Get the effective router rate for a tenant
     */
    public function getRouterRate(Tenant $tenant): float
    {
        return $tenant->router_rate ?? config('saas.resource_factors.router_rate', 100.00);
    }

    /**
     * Get the default paybill (landlord's paybill)
     */
    public function getDefaultPaybill(): string
    {
        return config('saas.default_paybill', '');
    }

    /**
     * Get the effective paybill for a tenant
     */
    public function getTenantPaybill(Tenant $tenant): string
    {
        return $tenant->custom_paybill ?? $this->getDefaultPaybill();
    }

    /**
     * Calculate the subscription cost for a tenant based on usage
     */
    public function calculateSubscriptionCost(Tenant $tenant, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): array
    {
        $periodStart = $periodStart ?? Carbon::now()->startOfMonth();
        $periodEnd = $periodEnd ?? Carbon::now()->endOfMonth();

        // Get usage counts from tenant schema
        $usage = $this->getTenantUsage($tenant, $periodStart, $periodEnd);

        // Get rates
        $pppoeRate = $this->getPppoeRate($tenant);
        $hotspotPct = $this->getHotspotRevenuePct($tenant);
        $routerRate = $this->getRouterRate($tenant);

        // Calculate costs
        $pppoeCost = $usage['pppoe_users'] * $pppoeRate;
        $hotspotCost = $usage['hotspot_revenue'] * ($hotspotPct / 100);
        $routerCost = $usage['routers'] * $routerRate;

        // Base plan cost
        $baseCost = $this->getPlanBaseCost($tenant);

        // Total before minimum
        $subtotal = $baseCost + $pppoeCost + $hotspotCost + $routerCost;

        // Apply minimum subscription
        $minimumSubscription = config('saas.minimum_subscription', 500.00);
        $total = max($subtotal, $minimumSubscription);

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'usage' => $usage,
            'rates' => [
                'pppoe_rate' => $pppoeRate,
                'hotspot_revenue_pct' => $hotspotPct,
                'router_rate' => $routerRate,
            ],
            'breakdown' => [
                'base_cost' => $baseCost,
                'pppoe_cost' => $pppoeCost,
                'hotspot_cost' => $hotspotCost,
                'router_cost' => $routerCost,
            ],
            'subtotal' => $subtotal,
            'minimum_subscription' => $minimumSubscription,
            'total' => $total,
            'currency' => 'KES',
        ];
    }

    /**
     * Get tenant usage metrics for billing calculation
     */
    public function getTenantUsage(Tenant $tenant, Carbon $periodStart, Carbon $periodEnd): array
    {
        try {
            return $this->runInTenantContext($tenant, function () use ($periodStart, $periodEnd) {
                // Count active PPPoE users
                $pppoeUsers = DB::table('pppoe_users')
                    ->where('is_active', true)
                    ->count();

                // Count active hotspot users
                $hotspotUsers = DB::table('hotspot_users')
                    ->where('is_active', true)
                    ->count();

                // Calculate hotspot revenue for the period
                $hotspotRevenue = DB::table('payments')
                    ->whereBetween('created_at', [$periodStart, $periodEnd])
                    ->where('status', 'completed')
                    ->where('payment_type', 'hotspot')
                    ->sum('amount') ?? 0;

                // Count active routers
                $routers = DB::table('routers')
                    ->where('is_active', true)
                    ->count();

                return [
                    'pppoe_users' => $pppoeUsers,
                    'hotspot_users' => $hotspotUsers,
                    'hotspot_revenue' => (float) $hotspotRevenue,
                    'routers' => $routers,
                ];
            });
        } catch (\Exception $e) {
            Log::error('Failed to get tenant usage', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'pppoe_users' => 0,
                'hotspot_users' => 0,
                'hotspot_revenue' => 0.0,
                'routers' => 0,
            ];
        }
    }

    private function runInTenantContext(Tenant $tenant, callable $callback)
    {
        $context = app(TenantContext::class);

        return DB::transaction(function () use ($context, $tenant, $callback) {
            DB::connection()->recordsHaveBeenModified();
            return $context->runInTenantContext($tenant, $callback);
        });
    }

    /**
     * Get the base cost for a tenant's subscription plan
     */
    public function getPlanBaseCost(Tenant $tenant): float
    {
        $plan = $tenant->subscription_plan ?? 'starter';
        $plans = config('saas.plans', []);

        return $plans[$plan]['base_price'] ?? 0.0;
    }

    /**
     * Check if tenant subscription is expired
     */
    public function isSubscriptionExpired(Tenant $tenant): bool
    {
        if (!$tenant->subscription_ends_at) {
            return false;
        }

        return Carbon::parse($tenant->subscription_ends_at)->isPast();
    }

    /**
     * Check if tenant subscription expires within the warning period
     */
    public function isSubscriptionExpiringSoon(Tenant $tenant): bool
    {
        if (!$tenant->subscription_ends_at) {
            return false;
        }

        $warningDays = config('saas.enforcement.warning_days', 5);
        $expiresAt = Carbon::parse($tenant->subscription_ends_at);

        return $expiresAt->isFuture() && $expiresAt->diffInDays(now()) <= $warningDays;
    }

    /**
     * Get days until subscription expires
     */
    public function getDaysUntilExpiry(Tenant $tenant): ?int
    {
        if (!$tenant->subscription_ends_at) {
            return null;
        }

        $expiresAt = Carbon::parse($tenant->subscription_ends_at);
        
        if ($expiresAt->isPast()) {
            return 0;
        }

        return (int) now()->diffInDays($expiresAt);
    }

    /**
     * Check if landlord override is active for tenant
     */
    public function hasActiveOverride(Tenant $tenant): bool
    {
        if (!$tenant->landlord_override) {
            return false;
        }

        // Check if override has expired
        if ($tenant->landlord_override_until && Carbon::parse($tenant->landlord_override_until)->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Apply landlord override to prevent service disconnection
     */
    public function applyLandlordOverride(Tenant $tenant, string $reason, ?Carbon $until = null): bool
    {
        try {
            $tenant->landlord_override = true;
            $tenant->landlord_override_reason = $reason;
            $tenant->landlord_override_until = $until;
            $tenant->save();

            Log::info('Applied landlord override to tenant', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'reason' => $reason,
                'until' => $until?->toDateTimeString(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to apply landlord override', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove landlord override from tenant
     */
    public function removeLandlordOverride(Tenant $tenant): bool
    {
        try {
            $tenant->landlord_override = false;
            $tenant->landlord_override_reason = null;
            $tenant->landlord_override_until = null;
            $tenant->save();

            Log::info('Removed landlord override from tenant', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to remove landlord override', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Extend tenant subscription
     */
    public function extendSubscription(Tenant $tenant, int $days): bool
    {
        try {
            $currentEnd = $tenant->subscription_ends_at 
                ? Carbon::parse($tenant->subscription_ends_at) 
                : now();

            // If already expired, extend from today
            if ($currentEnd->isPast()) {
                $currentEnd = now();
            }

            $tenant->subscription_ends_at = $currentEnd->addDays($days);
            $tenant->subscription_status = 'active';
            $tenant->subscription_warning_sent_at = null; // Reset warning
            $tenant->save();

            Log::info('Extended tenant subscription', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'new_end_date' => $tenant->subscription_ends_at,
                'days_added' => $days,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to extend tenant subscription', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Record a subscription payment from tenant
     */
    public function recordPayment(Tenant $tenant, float $amount, string $transactionId, string $paymentMethod = 'mpesa'): bool
    {
        try {
            $tenant->last_payment_at = now();
            $tenant->save();

            Log::info('Recorded tenant subscription payment', [
                'tenant_id' => $tenant->id,
                'amount' => $amount,
                'transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to record tenant payment', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get aggregate metrics for landlord dashboard (privacy-safe)
     */
    public function getAggregateTenantMetrics(): array
    {
        $tenants = Tenant::where('is_landlord', false)->get();
        
        $totalPppoeUsers = 0;
        $totalHotspotUsers = 0;
        $totalRouters = 0;

        foreach ($tenants as $tenant) {
            if (!$tenant->schema_created || !$tenant->schema_name) {
                continue;
            }

            try {
                $usage = $this->getTenantUsage($tenant, now()->startOfMonth(), now());
                $totalPppoeUsers += $usage['pppoe_users'];
                $totalHotspotUsers += $usage['hotspot_users'];
                $totalRouters += $usage['routers'];
            } catch (\Exception $e) {
                Log::warning('Failed to get usage for tenant in aggregate metrics', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'total_tenants' => Tenant::where('is_landlord', false)->count(),
            'active_tenants' => Tenant::where('is_landlord', false)->where('is_active', true)->count(),
            'tenants_with_valid_subscriptions' => Tenant::where('is_landlord', false)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('subscription_ends_at')
                      ->orWhere('subscription_ends_at', '>', now());
                })
                ->count(),
            'tenants_expiring_soon' => Tenant::where('is_landlord', false)
                ->where('is_active', true)
                ->whereNotNull('subscription_ends_at')
                ->where('subscription_ends_at', '>', now())
                ->where('subscription_ends_at', '<=', now()->addDays(config('saas.enforcement.warning_days', 5)))
                ->count(),
            'suspended_tenants' => Tenant::where('is_landlord', false)
                ->whereNotNull('suspended_at')
                ->count(),
            'total_pppoe_users' => $totalPppoeUsers,
            'total_hotspot_users' => $totalHotspotUsers,
            'total_routers' => $totalRouters,
        ];
    }

    /**
     * Get per-tenant counts only (no sensitive data)
     */
    public function getTenantCounts(): array
    {
        $tenants = Tenant::where('is_landlord', false)
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'subscription_status', 'subscription_ends_at', 'schema_name', 'schema_created']);

        $counts = [];

        foreach ($tenants as $tenant) {
            if (!$tenant->schema_created || !$tenant->schema_name) {
                $counts[] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'subscription_status' => $tenant->subscription_status,
                    'pppoe_user_count' => 0,
                    'hotspot_user_count' => 0,
                    'router_count' => 0,
                ];
                continue;
            }

            try {
                $usage = $this->getTenantUsage($tenant, now()->startOfMonth(), now());
                $counts[] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'subscription_status' => $tenant->subscription_status,
                    'pppoe_user_count' => $usage['pppoe_users'],
                    'hotspot_user_count' => $usage['hotspot_users'],
                    'router_count' => $usage['routers'],
                ];
            } catch (\Exception $e) {
                $counts[] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'subscription_status' => $tenant->subscription_status,
                    'pppoe_user_count' => 0,
                    'hotspot_user_count' => 0,
                    'router_count' => 0,
                ];
            }
        }

        return $counts;
    }
}
