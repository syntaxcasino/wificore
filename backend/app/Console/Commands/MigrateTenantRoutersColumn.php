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
    protected $signature = 'tenant:migrate-routers-column {--tenant= : Specific tenant ID to migrate} {--indexes : Create indexes instead of adding column}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add last_checked column or create indexes on routers table in all tenant schemas';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $createIndexes = $this->option('indexes');
        
        if ($createIndexes) {
            $this->info('🔧 Creating router table indexes in tenant schemas...');
        } else {
            $this->info('🔧 Adding last_checked column to routers tables in tenant schemas...');
        }
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

                if ($createIndexes) {
                    // Create indexes
                    $this->createIndexes($tenant, $schemaName);
                } else {
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
            if ($createIndexes) {
                $this->info('✅ Index creation completed successfully!');
            } else {
                $this->info('✅ Migration completed successfully!');
            }
            return Command::SUCCESS;
        } else {
            $this->warn('⚠️  Some errors occurred. Please check the logs.');
            return Command::FAILURE;
        }
    }

    /**
     * Create indexes for router tables in tenant schema
     */
    private function createIndexes(Tenant $tenant, string $schemaName): void
    {
        $createdIndexes = 0;
        
        // Create index on routers.status
        if ($this->tableExists($tenant, 'routers')) {
            if (!$this->indexExists($tenant, 'routers', 'status')) {
                DB::statement("CREATE INDEX routers_status_index ON routers(status)");
                $this->info("  ✅ Created routers_status_index");
                $createdIndexes++;
            } else {
                $this->info("  ✅ routers_status_index already exists");
            }
        }

        // Create indexes on router_services
        if ($this->tableExists($tenant, 'router_services')) {
            if (!$this->indexExists($tenant, 'router_services', 'router_id')) {
                DB::statement("CREATE INDEX router_services_router_id_index ON router_services(router_id)");
                $this->info("  ✅ Created router_services_router_id_index");
                $createdIndexes++;
            } else {
                $this->info("  ✅ router_services_router_id_index already exists");
            }
            
            if (!$this->indexExists($tenant, 'router_services', 'service_type')) {
                DB::statement("CREATE INDEX router_services_service_type_index ON router_services(service_type)");
                $this->info("  ✅ Created router_services_service_type_index");
                $createdIndexes++;
            } else {
                $this->info("  ✅ router_services_service_type_index already exists");
            }
        }

        // Create indexes on access_points
        if ($this->tableExists($tenant, 'access_points')) {
            if (!$this->indexExists($tenant, 'access_points', 'router_id')) {
                DB::statement("CREATE INDEX access_points_router_id_index ON access_points(router_id)");
                $this->info("  ✅ Created access_points_router_id_index");
                $createdIndexes++;
            } else {
                $this->info("  ✅ access_points_router_id_index already exists");
            }
            
            if (!$this->indexExists($tenant, 'access_points', 'status')) {
                DB::statement("CREATE INDEX access_points_status_index ON access_points(status)");
                $this->info("  ✅ Created access_points_status_index");
                $createdIndexes++;
            } else {
                $this->info("  ✅ access_points_status_index already exists");
            }
        }

        if ($createdIndexes > 0) {
            $this->info("  📊 Created {$createdIndexes} new indexes");
        } else {
            $this->info("  📊 All indexes already exist");
        }
    }

    /**
     * Check if table exists in tenant schema
     */
    private function tableExists(Tenant $tenant, string $tableName): bool
    {
        $result = DB::select("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = ?
                AND table_name = ?
            ) as exists
        ", [$tenant->schema_name, $tableName]);
        
        return $result[0]->exists;
    }

    /**
     * Check if index exists for a specific column in tenant schema
     */
    private function indexExists(Tenant $tenant, string $tableName, string $column): bool
    {
        $result = DB::select("
            SELECT EXISTS (
                SELECT FROM pg_indexes 
                WHERE schemaname = ?
                AND tablename = ?
                AND indexdef LIKE ?
            ) as exists
        ", [$tenant->schema_name, $tableName, "%({$column})%"]);
        
        return $result[0]->exists;
    }
}
