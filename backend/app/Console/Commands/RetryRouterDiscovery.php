<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverRouterInterfacesJob;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryRouterDiscovery extends Command
{
    protected $signature = 'routers:retry-discovery 
                            {--router= : Specific router ID to retry}
                            {--tenant= : Specific tenant ID to process}
                            {--all : Retry all pending routers}';

    protected $description = 'Retry interface discovery for routers stuck in pending status';

    public function handle(): int
    {
        $routerId = $this->option('router');
        $tenantId = $this->option('tenant');
        $all = $this->option('all');

        if (!$routerId && !$tenantId && !$all) {
            $this->error('Please specify --router, --tenant, or --all');
            return 1;
        }

        $tenants = $tenantId 
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();

        $totalDispatched = 0;

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} ({$tenant->id})");
            
            // Switch to tenant schema
            DB::purge('tenant');
            config(['database.connections.tenant.schema' => $tenant->schema_name]);
            DB::reconnect('tenant');

            // Find routers to retry
            $query = Router::on('tenant')
                ->where(function ($q) {
                    $q->where('status', '!=', 'online')
                      ->orWhereNull('status');
                })
                ->whereNotNull('vpn_ip');

            if ($routerId) {
                $query->where('id', $routerId);
            }

            $routers = $query->get();

            if ($routers->isEmpty()) {
                $this->info("  No routers need discovery retry");
                continue;
            }

            foreach ($routers as $router) {
                $this->info("  Dispatching discovery for router: {$router->name} ({$router->id})");
                $this->info("    Status: {$router->status}, VPN IP: {$router->vpn_ip}");
                
                // Clear any existing discovery locks
                $lockKey = "router_discovery_lock_{$router->id}";
                \Illuminate\Support\Facades\Cache::forget($lockKey);
                
                $dispatchKey = "discovery_dispatch_{$router->id}";
                \Illuminate\Support\Facades\Cache::forget($dispatchKey);

                // Dispatch the job
                dispatch(new DiscoverRouterInterfacesJob($tenant->id, $router->id))
                    ->onQueue('router-provisioning');
                
                $totalDispatched++;
                $this->info("    ✓ Discovery job dispatched");
            }
        }

        $this->info("\n✓ Total discovery jobs dispatched: {$totalDispatched}");
        return 0;
    }
}
