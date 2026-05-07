<?php

namespace App\Jobs;

use App\Services\StaleSafeCacheService;
use App\Services\TenantContext;
use App\Models\Tenant;
use App\Models\Router;
use App\Models\Package;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cache Warm-Up Job
 * Pre-populates cache with frequently accessed data to improve response times
 * Runs on deployment or scheduled basis
 */
class CacheWarmUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    private TenantContext $tenantContext;

    public function __construct()
    {
        $this->tenantContext = app(TenantContext::class);
    }

    public function handle(): void
    {
        Log::info('Starting cache warm-up job');
        $startTime = microtime(true);
        $stats = [
            'tenants_processed' => 0,
            'routers_cached' => 0,
            'packages_cached' => 0,
            'errors' => 0,
        ];

        try {
            // Process each tenant
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                try {
                    $this->warmTenantCache($tenant);
                    $stats['tenants_processed']++;
                } catch (\Exception $e) {
                    Log::warning("Failed to warm cache for tenant {$tenant->id}", [
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                }
            }

            // Warm global cache
            $this->warmGlobalCache();

            $duration = round(microtime(true) - $startTime, 2);
            Log::info('Cache warm-up completed', [
                'duration_seconds' => $duration,
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Cache warm-up job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function warmTenantCache(Tenant $tenant): void
    {
        // Set tenant context
        $this->tenantContext->setTenant($tenant);

        // Cache router list (lightweight - just basic info)
        try {
            $routers = Router::select(['id', 'name', 'ip_address', 'status', 'device_type'])
                ->limit(100)
                ->get()
                ->toArray();

            StaleSafeCacheService::cachePaginatedList(
                'routers',
                $tenant->id,
                ['page' => 1, 'per_page' => 25],
                $routers
            );
        } catch (\Exception $e) {
            Log::warning("Failed to cache routers for tenant {$tenant->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        // Cache packages
        try {
            $packages = Package::where('is_active', true)
                ->select(['id', 'name', 'type', 'price', 'speed_limit', 'data_cap'])
                ->get()
                ->toArray();

            StaleSafeCacheService::put(
                "packages:{$tenant->id}",
                $packages,
                StaleSafeCacheService::TTL_STANDARD
            );
        } catch (\Exception $e) {
            Log::warning("Failed to cache packages for tenant {$tenant->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        // Clear tenant context
        $this->tenantContext->clear();
    }

    private function warmGlobalCache(): void
    {
        // Cache tenant list for public pages
        try {
            $activeTenants = Tenant::where('is_active', true)
                ->where('is_suspended', false)
                ->select(['id', 'name', 'subdomain', 'subscription_ends_at'])
                ->get()
                ->toArray();

            StaleSafeCacheService::put(
                'public:active_tenants',
                $activeTenants,
                StaleSafeCacheService::TTL_STANDARD
            );
        } catch (\Exception $e) {
            Log::warning('Failed to cache active tenants', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
