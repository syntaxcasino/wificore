<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\PasswordEncryptionService;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ValidateRouterPasswords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'router:validate-passwords 
                            {--tenant= : Specific tenant ID to validate}
                            {--fix : Attempt to fix issues by prompting for passwords}
                            {--report : Generate detailed report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate that all router passwords can be decrypted with current APP_KEY';

    /**
     * Execute the console command.
     */
    public function handle(TenantContext $tenantContext): int
    {
        $this->info('🔐 Router Password Validation Tool');
        $this->newLine();
        
        // First, validate APP_KEY configuration
        $this->info('Checking APP_KEY configuration...');
        $appKeyValidation = PasswordEncryptionService::validateAppKey();
        
        if (!$appKeyValidation['valid']) {
            $this->error('❌ APP_KEY validation failed!');
            foreach ($appKeyValidation['issues'] as $issue) {
                $this->error('  - ' . $issue);
            }
            $this->newLine();
            $this->warn('Please fix APP_KEY issues before proceeding.');
            return 1;
        }
        
        $this->info('✅ APP_KEY is properly configured');
        $this->table(
            ['Property', 'Value'],
            collect($appKeyValidation['info'])->map(fn($v, $k) => [$k, $v])->values()->toArray()
        );
        $this->newLine();
        
        // Get tenants to validate
        $tenantId = $this->option('tenant');
        
        if ($tenantId) {
            $tenants = Tenant::where('id', $tenantId)->get();
            if ($tenants->isEmpty()) {
                $this->error("Tenant with ID {$tenantId} not found");
                return 1;
            }
        } else {
            $tenants = Tenant::where('is_active', true)
                ->where('schema_created', true)
                ->get();
        }
        
        $this->info("Validating passwords for {$tenants->count()} tenant(s)...");
        $this->newLine();
        
        $allFailedRouters = [];
        $totalRouters = 0;
        
        foreach ($tenants as $tenant) {
            $this->info("Tenant: {$tenant->name} (ID: {$tenant->id})");
            
            // Switch to tenant schema
            $tenantContext->setTenant($tenant);
            DB::statement("SET search_path TO {$tenant->schema_name}");
            
            try {
                $failedRouters = PasswordEncryptionService::validateAllPasswords($tenant->id);
                $routerCount = Router::count();
                $totalRouters += $routerCount;
                
                if (empty($failedRouters)) {
                    $this->info("  ✅ All {$routerCount} router passwords validated successfully");
                } else {
                    $this->error("  ❌ {count($failedRouters)} out of {$routerCount} router passwords failed validation");
                    
                    foreach ($failedRouters as $failed) {
                        $allFailedRouters[] = array_merge($failed, [
                            'tenant_id' => $tenant->id,
                            'tenant_name' => $tenant->name
                        ]);
                        $this->warn("    - Router: {$failed['name']} (ID: {$failed['id']})");
                    }
                }
                
            } catch (\Exception $e) {
                $this->error("  ❌ Error validating tenant: " . $e->getMessage());
            }
            
            $this->newLine();
        }
        
        // Reset to public schema
        DB::statement("SET search_path TO public");
        
        // Summary
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('VALIDATION SUMMARY');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("Total Tenants: {$tenants->count()}");
        $this->info("Total Routers: {$totalRouters}");
        $this->info("Failed Routers: " . count($allFailedRouters));
        $this->newLine();
        
        if (!empty($allFailedRouters)) {
            $this->error('⚠️  ROUTERS WITH DECRYPTION ISSUES:');
            $this->table(
                ['Tenant', 'Router ID', 'Router Name'],
                collect($allFailedRouters)->map(fn($r) => [
                    $r['tenant_name'],
                    $r['id'],
                    $r['name']
                ])->toArray()
            );
            
            $this->newLine();
            $this->warn('POSSIBLE CAUSES:');
            $this->warn('1. APP_KEY was changed after routers were created');
            $this->warn('2. Database was migrated from another environment with different APP_KEY');
            $this->warn('3. Encryption data corruption');
            
            $this->newLine();
            $this->info('SOLUTIONS:');
            $this->info('1. Restore the original APP_KEY from backup');
            $this->info('2. Re-enter passwords for affected routers via UI or API');
            $this->info('3. Use --fix option to manually re-encrypt passwords (requires knowing plain passwords)');
            
            if ($this->option('fix')) {
                $this->newLine();
                if ($this->confirm('Do you want to attempt to fix these routers?')) {
                    $this->fixRouterPasswords($allFailedRouters, $tenantContext);
                }
            }
            
            return 1;
        }
        
        $this->info('✅ All router passwords validated successfully!');
        return 0;
    }
    
    /**
     * Attempt to fix router passwords by prompting for plain text passwords
     */
    protected function fixRouterPasswords(array $failedRouters, TenantContext $tenantContext): void
    {
        $this->warn('⚠️  You will be prompted to enter the plain text password for each router.');
        $this->warn('Make sure you have the correct passwords before proceeding.');
        $this->newLine();
        
        foreach ($failedRouters as $failed) {
            $this->info("Router: {$failed['name']} (Tenant: {$failed['tenant_name']})");
            
            if (!$this->confirm('Do you want to fix this router?', true)) {
                continue;
            }
            
            $password = $this->secret('Enter the plain text password for this router');
            
            if (empty($password)) {
                $this->warn('Skipping - no password provided');
                continue;
            }
            
            try {
                // Switch to tenant schema
                $tenant = Tenant::find($failed['tenant_id']);
                $tenantContext->setTenant($tenant);
                DB::statement("SET search_path TO {$tenant->schema_name}");
                
                $router = Router::find($failed['id']);
                
                if (!$router) {
                    $this->error('Router not found');
                    continue;
                }
                
                if (PasswordEncryptionService::reEncryptPassword($router, $password)) {
                    $this->info('✅ Password re-encrypted successfully');
                } else {
                    $this->error('❌ Failed to re-encrypt password');
                }
                
            } catch (\Exception $e) {
                $this->error('Error: ' . $e->getMessage());
            }
            
            $this->newLine();
        }
        
        // Reset to public schema
        DB::statement("SET search_path TO public");
    }
}
