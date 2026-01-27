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

    public function handle()
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
                // Set tenant context
                $tenantContext = app(TenantContext::class);
                $tenantContext->setTenant($tenant);
                
                // Switch to tenant schema
                DB::statement("SET search_path TO {$tenant->schema_name}");
                
                // Get rotation service
                $rotationService = app(SshKeyRotationService::class);
                
                // Get routers needing rotation
                $routers = $force 
                    ? DB::table('routers')->whereNotNull('ssh_key')->get()
                    : $rotationService->getRoutersNeedingRotation();
                
                if ($routers->isEmpty()) {
                    $this->info("  No routers need key rotation for tenant {$tenant->name}");
                    continue;
                }
                
                $this->info("  Found {$routers->count()} router(s) needing key rotation");
                
                foreach ($routers as $routerData) {
                    // Load full router model
                    $router = \App\Models\Router::find($routerData->id);
                    
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
