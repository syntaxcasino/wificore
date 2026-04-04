<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use App\Models\Router;
use App\Models\RouterService;
use App\Jobs\DeployRouterServiceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixHapliteDeployment extends Command
{
    protected $signature = 'router:fix-haplite-deployment 
                            {--check : Only check status without fixing}
                            {--force : Force redeployment even if deployed}';

    protected $description = 'Fix haplite router service deployment';

    public function handle(): int
    {
        $routerId = '64a8f788-ae30-4cdf-a92c-31e1ff8c6d36';
        $tenantId = 'ff43d87e-3118-40b3-bb87-f14defda6655';
        
        $this->info("Checking haplite router deployment status...");
        
        // Get tenant
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return 1;
        }
        
        // Set tenant context
        DB::statement("SET search_path TO '{$tenant->schema_name}', public");
        
        // Check router
        $router = Router::find($routerId);
        if (!$router) {
            $this->error("Router not found: {$routerId}");
            return 1;
        }
        
        $this->info("Router: {$router->name} (Status: {$router->status}, Model: {$router->model})");
        
        // Check services
        $services = RouterService::where('router_id', $routerId)->get();
        
        if ($services->isEmpty()) {
            $this->warn("No services configured for this router!");
            $this->info("The router needs a service (PPPoE/Hotspot) to be configured first.");
            $this->info("Use the web interface to configure a service on this router.");
            return 1;
        }
        
        foreach ($services as $service) {
            $this->info("Service: {$service->service_type} | Status: {$service->deployment_status} | Interface: " . ($service->interface_name ?? 'N/A'));
            
            if ($this->option('check')) {
                continue;
            }
            
            // Check if deployment needed
            $needsDeployment = in_array($service->deployment_status, [
                RouterService::DEPLOYMENT_PENDING,
                RouterService::DEPLOYMENT_FAILED,
                null
            ]);
            
            if ($needsDeployment || $this->option('force')) {
                $this->info("Dispatching deployment job for service {$service->id}...");
                
                // Reset status to pending
                $service->update(['deployment_status' => RouterService::DEPLOYMENT_PENDING]);
                
                // Dispatch job
                DeployRouterServiceJob::dispatch($service->id, $tenantId)
                    ->onQueue('router-provisioning');
                
                $this->info("✓ Deployment job dispatched successfully!");
                $this->info("Monitor logs: docker compose logs wificore-backend -f | grep -i 'deploy'");
            } else {
                $this->info("Service already deployed (use --force to redeploy)");
            }
        }
        
        return 0;
    }
}
