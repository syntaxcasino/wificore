<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class MigrateTenantRoutersColumn extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:migrate-routers-column {--tenant= : Specific tenant ID to migrate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add last_checked column to routers table in all tenant schemas';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 Adding last_checked column to routers tables in tenant schemas...');
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
        $skipped = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} (Schema: {$tenant->schema_name})");

            try {
                // Switch to tenant schema
                DB::statement("SET search_path TO {$tenant->schema_name}, public");

                // Check if column already exists
                $columnExists = DB::select("
                    SELECT EXISTS (
                        SELECT FROM information_schema.columns 
                        WHERE table_schema = ?
                        AND table_name = 'routers'
                        AND column_name = 'last_checked'
                    ) as exists
                ", [$tenant->schema_name]);

                if ($columnExists[0]->exists) {
                    $this->info("  ✅ Column already exists, skipping");
                    $skipped++;
                } else {
                    // Add the column
                    DB::statement("
                        ALTER TABLE {$tenant->schema_name}.routers 
                        ADD COLUMN last_checked TIMESTAMP NULL
                    ");
                    
                    $this->info("  ✅ Column added successfully");
                    $success++;
                }

                // Reset search path
                DB::statement("SET search_path TO public");

            } catch (\Exception $e) {
                $this->error("  ❌ Error: " . $e->getMessage());
                $errors++;
                
                // Reset search path on error
                try {
                    DB::statement("SET search_path TO public");
                } catch (\Exception $resetError) {
                    // Ignore reset errors
                }
            }

            $this->newLine();
        }

        // Summary
        $this->info('📊 Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Tenants', $tenants->count()],
                ['Successfully Added', $success],
                ['Already Exists', $skipped],
                ['Errors', $errors],
            ]
        );

        if ($errors === 0) {
            $this->info('✅ Migration completed successfully!');
            return Command::SUCCESS;
        } else {
            $this->warn('⚠️  Some errors occurred. Please check the logs.');
            return Command::FAILURE;
        }
    }
}
