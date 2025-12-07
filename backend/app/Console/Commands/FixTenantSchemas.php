<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantMigrationManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTenantSchemas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:fix-schemas {--force : Force schema recreation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix tenant schema names and create missing schemas';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 Starting tenant schema fix...');
        $this->newLine();

        $tenants = Tenant::all();
        $fixed = 0;
        $created = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} (ID: {$tenant->id})");

            try {
                // Step 1: Fix schema name if it contains hyphens
                $oldSchemaName = $tenant->schema_name;
                if (str_contains($oldSchemaName, '-')) {
                    // Generate new secure schema name
                    $newSchemaName = TenantMigrationManager::generateSecureSchemaName($tenant->slug);
                    
                    $this->warn("  ❌ Invalid schema name: {$oldSchemaName}");
                    $this->info("  ✅ New schema name: {$newSchemaName}");
                    
                    // Update tenant record
                    $tenant->schema_name = $newSchemaName;
                    $tenant->save();
                    
                    // Update radius_user_schema_mapping
                    DB::table('radius_user_schema_mapping')
                        ->where('schema_name', $oldSchemaName)
                        ->update(['schema_name' => $newSchemaName]);
                    
                    $this->info("  ✅ Updated schema name and mappings");
                    $fixed++;
                }

                // Step 2: Check if schema exists
                $schemaExists = DB::select("
                    SELECT EXISTS (
                        SELECT FROM information_schema.schemata 
                        WHERE schema_name = ?
                    )
                ", [$tenant->schema_name]);

                if (!$schemaExists[0]->exists || $this->option('force')) {
                    $this->warn("  📦 Schema does not exist, creating...");
                    
                    // Create schema and run migrations
                    $migrationManager = app(TenantMigrationManager::class);
                    
                    if ($migrationManager->setupTenantSchema($tenant)) {
                        $this->info("  ✅ Schema created and migrations run successfully");
                        $created++;
                        
                        // Step 3: Migrate existing RADIUS data if any
                        $this->migrateExistingRadiusData($tenant);
                    } else {
                        $this->error("  ❌ Failed to create schema");
                        $errors++;
                    }
                } else {
                    $this->info("  ✅ Schema already exists");
                }

                $this->newLine();

            } catch (\Exception $e) {
                $this->error("  ❌ Error: " . $e->getMessage());
                $errors++;
                $this->newLine();
            }
        }

        // Summary
        $this->newLine();
        $this->info('📊 Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Tenants', $tenants->count()],
                ['Schema Names Fixed', $fixed],
                ['Schemas Created', $created],
                ['Errors', $errors],
            ]
        );

        if ($errors === 0) {
            $this->info('✅ All tenant schemas fixed successfully!');
            return Command::SUCCESS;
        } else {
            $this->warn('⚠️  Some errors occurred. Please check the logs.');
            return Command::FAILURE;
        }
    }

    /**
     * Migrate existing RADIUS data from public schema to tenant schema
     */
    private function migrateExistingRadiusData(Tenant $tenant)
    {
        try {
            // Get all users for this tenant
            $users = DB::table('users')
                ->where('tenant_id', $tenant->id)
                ->get();

            if ($users->isEmpty()) {
                return;
            }

            $this->info("  📋 Migrating RADIUS data for {$users->count()} users...");

            // Switch to tenant schema
            DB::statement("SET search_path TO {$tenant->schema_name}, public");

            foreach ($users as $user) {
                // Check if user has RADIUS entry in public schema
                $publicRadcheck = DB::table('public.radcheck')
                    ->where('username', $user->username)
                    ->first();

                if ($publicRadcheck) {
                    // Copy to tenant schema
                    DB::table('radcheck')->updateOrInsert(
                        [
                            'username' => $publicRadcheck->username,
                            'attribute' => $publicRadcheck->attribute,
                        ],
                        [
                            'op' => $publicRadcheck->op,
                            'value' => $publicRadcheck->value,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    // Add to radreply
                    DB::table('radreply')->updateOrInsert(
                        [
                            'username' => $user->username,
                            'attribute' => 'Tenant-ID',
                        ],
                        [
                            'op' => ':=',
                            'value' => $tenant->schema_name,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $this->info("    ✅ Migrated RADIUS data for user: {$user->username}");
                }
            }

            // Reset search path
            DB::statement("SET search_path TO public");

            $this->info("  ✅ RADIUS data migration completed");

        } catch (\Exception $e) {
            $this->error("  ❌ Failed to migrate RADIUS data: " . $e->getMessage());
            DB::statement("SET search_path TO public");
        }
    }
}
