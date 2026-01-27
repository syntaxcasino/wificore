<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantMigrationManager;
use Illuminate\Console\Command;

class MigrateTenantIndexes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:migrate-indexes {--tenant= : Specific tenant ID to migrate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pending tenant migrations across tenant schemas';

    /**
     * Execute the console command.
     */
    public function handle(TenantMigrationManager $migrationManager)
    {
        $this->info('🔧 Running pending tenant migrations across tenant schemas...');
        $this->newLine();

        // Get tenants to process
        $query = Tenant::query();
        if ($this->option('tenant')) {
            $query->where('id', $this->option('tenant'));
        }
        
        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found to process.');
            return Command::FAILURE;
        }

        $success = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} (Schema: {$tenant->schema_name})");

            try {
                if ($migrationManager->runMigrationsForTenant($tenant)) {
                    $this->info("  ✅ Tenant migrations executed successfully");
                    $success++;
                } else {
                    $this->error("  ❌ Tenant migration execution failed");
                    $errors++;
                }

            } catch (\Exception $e) {
                $this->error("  ❌ Error: " . $e->getMessage());
                $errors++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('📊 Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Tenants', $tenants->count()],
                ['Successfully Updated', $success],
                ['Errors', $errors],
            ]
        );

        if ($errors === 0) {
            $this->info('✅ Index migration completed successfully!');
            return Command::SUCCESS;
        } else {
            $this->warn('⚠️  Some errors occurred. Please check the error messages above.');
            return Command::FAILURE;
        }
    }
}
