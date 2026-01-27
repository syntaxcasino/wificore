<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\CacheService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CacheRoutersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('cache');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // If no tenant ID, dispatch for all active tenants
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)
                ->where('schema_created', true)
                ->whereNotNull('schema_name')
                ->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched router caching jobs for " . $tenants->count() . " tenants");
            return;
        }

        // Execute within tenant context
        $this->executeInTenantContext(function() {
            $this->cacheRoutersForTenant();
        });
    }

    /**
     * Cache routers for the current tenant
     */
    protected function cacheRoutersForTenant(): void
    {
        try {
            // Fetch all routers for this tenant
            $routers = Router::select('id', 'name', 'ip_address', 'vpn_ip', 'status', 'port', 'username')
                ->addSelect('created_at')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
            
            // Cache each router individually using helper method
            foreach ($routers as $router) {
                CacheService::cacheRouter($this->tenantId, $router['id'], $router, CacheService::TTL_MEDIUM);
            }
            
            // Cache the router list for this tenant using helper method
            CacheService::cacheRouterList($this->tenantId, $routers, CacheService::TTL_MEDIUM);
            
            Log::info("Cached routers for tenant", [
                'tenant_id' => $this->tenantId,
                'router_count' => count($routers),
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to cache routers for tenant", [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("CacheRoutersJob failed permanently", [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
