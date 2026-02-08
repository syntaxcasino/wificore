<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\RouterProvisioningJob;
use App\Models\Router;
use App\Models\RouterTenantMap;
use App\Models\Tenant;
use App\Services\TenantContext;

class TestProvisioning extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:provisioning {routerId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test provisioning for a router';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $routerId = $this->argument('routerId');

        // Router is in tenant schema - find tenant via lookup table
        $tenantId = RouterTenantMap::findTenantByRouterId($routerId);
        if (!$tenantId) {
            $this->error("Router not found in router_tenant_map");
            return 1;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found");
            return 1;
        }

        // Set tenant context so Router::find works
        $tenantContext = app(TenantContext::class);
        $tenantContext->setTenant($tenant);

        $router = Router::find($routerId);
        if (!$router) {
            $this->error("Router not found in tenant schema");
            return 1;
        }
        
        RouterProvisioningJob::dispatch($router->id, $tenantId, [
            "service_type" => "hotspot",
            "hotspot_interfaces" => ["ether2"]
        ]);
        
        $this->info("Provisioning job dispatched for router {$routerId} (tenant: {$tenantId})");
        
        return 0;
    }
}
