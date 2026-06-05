<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\MikroTik\SshKeyRotationService;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RotateRouterSshKeys extends Command
{
    protected $signature = 'routers:rotate-ssh-keys 
                            {--tenant= : Specific tenant ID to rotate keys for}
                            {--force : Force rotation even if not due}';

    protected $description = 'Rotate SSH keys for routers that are due for rotation (90+ days old)';

    public function handle(SshKeyRotationService $rotationService)
    {
        $this->info('Starting SSH key rotation for routers...');
        
        $tenantId = $this->option('tenant');
        $force = $this->option('force');
        
        // Get tenants to process
        $tenants = $tenantId 
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();
        
        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');
            return 1;
        }
        
        $totalRotated = 0;
        $totalFailed = 0;
        
        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} (ID: {$tenant->id})");

            try {
                // CRITICAL: Use DB::transaction with recordsHaveBeenModified for PgBouncer compatibility
                // SET LOCAL search_path only persists within a transaction under pool_mode=transaction
                // We need to get the router list first, then process within transaction
                $routers = DB::transaction(function () use ($tenant, $force, $rotationService) {
                    DB::connection()->recordsHaveBeenModified();

                    // Set tenant context within transaction
                    $tenantContext = app(TenantContext::class);
                    $tenantContext->setTenantById((string) $tenant->id);

                    // Get routers needing rotation
                    return $force
                        ? \App\Models\Router::whereNotNull('ssh_key')->get()
                        : $rotationService->getRoutersNeedingRotation();
                });

                if ($routers->isEmpty()) {
                    $this->info("  No routers need key rotation for tenant {$tenant->name}");
                    continue;
                }

                $this->info("  Found {$routers->count()} router(s) needing key rotation");

                foreach ($routers as $routerData) {
                    // Load full router model within transaction
                    $router = DB::transaction(function () use ($tenant, $routerData) {
                        DB::connection()->recordsHaveBeenModified();
                        $tenantContext = app(TenantContext::class);
                        $tenantContext->setTenantById((string) $tenant->id);
                        return \App\Models\Router::find($routerData->id);
                    });

                    if (!$router) {
                        $this->warn("  Router {$routerData->id} not found, skipping");
                        continue;
                    }

                    try {
                        $this->info("  Rotating key for router: {$router->name} (ID: {$router->id})");

                        $result = $rotationService->rotateKey($router);

                        $this->info("  ✓ Key rotated successfully for {$router->name}");
                        $this->line("    Fingerprint: {$result['fingerprint']}");
                        $this->line("    Duration: {$result['duration']}");

                        $totalRotated++;

                    } catch (\Exception $e) {
                        $this->error("  ✗ Failed to rotate key for {$router->name}: {$e->getMessage()}");

                        Log::error('SSH key rotation failed in command', [
                            'router_id' => $router->id,
                            'router_name' => $router->name,
                            'tenant_id' => $tenant->id,
                            'error' => $e->getMessage()
                        ]);

                        $totalFailed++;
                    }
                }

            } catch (\Exception $e) {
                $this->error("Failed to process tenant {$tenant->name}: {$e->getMessage()}");

                Log::error('Tenant processing failed in SSH key rotation', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Summary
        $this->newLine();
        $this->info('SSH Key Rotation Summary:');
        $this->line("  Total rotated: {$totalRotated}");
        $this->line("  Total failed: {$totalFailed}");
        
        return $totalFailed > 0 ? 1 : 0;
    }
}
