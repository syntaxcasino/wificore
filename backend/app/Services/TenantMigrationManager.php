<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TenantMigrationManager
{
    /**
     * Run all tenant migrations for a specific tenant
     */
    public function runMigrationsForTenant(Tenant $tenant): bool
    {
        try {
            // Set search path to tenant schema
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            
            // Get all tenant migration files
            $migrationFiles = $this->getTenantMigrationFiles();
            
            // Get already executed migrations for this tenant
            $executedMigrations = $this->getExecutedMigrations($tenant);
            
            $batch = $this->getNextBatchNumber($tenant);
            
            foreach ($migrationFiles as $migrationFile) {
                $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);
                
                // Skip if already executed
                if (in_array($migrationName, $executedMigrations)) {
                    continue;
                }
                
                // Run the migration
                $this->executeMigration($migrationFile, $tenant, $batch);
                
                // Record the migration
                $this->recordMigration($tenant, $migrationName, $batch);
                
                Log::info("Executed tenant migration: {$migrationName} for tenant: {$tenant->name}");
            }
            
            // Reset search path
            DB::statement("SET search_path TO public");
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to run tenant migrations for {$tenant->name}: " . $e->getMessage());
            
            // Reset search path on error
            DB::statement("SET search_path TO public");
            
            return false;
        }
    }
    
    /**
     * Create tenant schema and run initial setup
     */
    public function setupTenantSchema(Tenant $tenant): bool
    {
        try {
            // Ensure schema name is valid (no hyphens, only underscores and alphanumeric)
            if (empty($tenant->schema_name) || preg_match('/-/', $tenant->schema_name)) {
                Log::warning("Invalid schema name detected, regenerating", [
                    'tenant_id' => $tenant->id,
                    'old_schema_name' => $tenant->schema_name,
                    'slug' => $tenant->slug
                ]);
                
                // Generate new secure schema name
                $tenant->schema_name = self::generateSecureSchemaName($tenant->slug);
                $tenant->saveQuietly(); // Save without triggering events
                
                Log::info("Schema name regenerated", [
                    'tenant_id' => $tenant->id,
                    'new_schema_name' => $tenant->schema_name
                ]);
            }
            
            // Create the schema
            DB::statement("CREATE SCHEMA IF NOT EXISTS {$tenant->schema_name}");
            
            // Grant permissions
            $dbUser = config('database.connections.pgsql.username');
            DB::statement("GRANT ALL ON SCHEMA {$tenant->schema_name} TO {$dbUser}");
            DB::statement("GRANT ALL ON ALL TABLES IN SCHEMA {$tenant->schema_name} TO {$dbUser}");
            DB::statement("GRANT ALL ON ALL SEQUENCES IN SCHEMA {$tenant->schema_name} TO {$dbUser}");
            
            // Set default privileges for future objects
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$tenant->schema_name} GRANT ALL ON TABLES TO {$dbUser}");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$tenant->schema_name} GRANT ALL ON SEQUENCES TO {$dbUser}");
            
            // Run migrations
            $success = $this->runMigrationsForTenant($tenant);
            
            if ($success) {
                // Update tenant record
                $tenant->update([
                    'schema_created' => true,
                    'schema_created_at' => now()
                ]);
                
                Log::info("Successfully set up schema for tenant: {$tenant->name}");
            }
            
            return $success;
            
        } catch (\Exception $e) {
            Log::error("Failed to setup tenant schema for {$tenant->name}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Seed tenant schema with test data
     */
    public function seedTenantSchema(Tenant $tenant, bool $withTestData = false): bool
    {
        try {
            // Set search path to tenant schema
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            
            if ($withTestData) {
                $this->seedTestData($tenant);
            } else {
                $this->seedBasicData($tenant);
            }
            
            // Reset search path
            DB::statement("SET search_path TO public");
            
            Log::info("Successfully seeded tenant schema: {$tenant->name}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to seed tenant schema for {$tenant->name}: " . $e->getMessage());
            
            // Reset search path on error
            DB::statement("SET search_path TO public");
            
            return false;
        }
    }
    
    /**
     * Generate secure schema name
     */
    public static function generateSecureSchemaName(string $tenantSlug): string
    {
        // Create a hash-based schema name that's hard to guess
        $hash = hash('sha256', $tenantSlug . config('app.key') . 'tenant_schema_salt');
        
        // Take first 12 characters and prefix with 'ts_' (tenant schema)
        $schemaName = 'ts_' . substr($hash, 0, 12);
        
        // Ensure it's a valid PostgreSQL identifier (lowercase, no hyphens)
        return strtolower($schemaName);
    }
    
    /**
     * Get all tenant migration files
     */
    private function getTenantMigrationFiles(): array
    {
        $migrationPath = database_path('migrations/tenant');
        
        if (!File::exists($migrationPath)) {
            return [];
        }
        
        $files = File::files($migrationPath);
        
        // Sort by filename (which includes timestamp)
        usort($files, function ($a, $b) {
            return strcmp($a->getFilename(), $b->getFilename());
        });
        
        return array_map(function ($file) {
            return $file->getPathname();
        }, $files);
    }
    
    /**
     * Get executed migrations for tenant
     */
    private function getExecutedMigrations(Tenant $tenant): array
    {
        // Check if tenant_schema_migrations table exists in public schema
        $tableExists = DB::select("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = 'tenant_schema_migrations'
            )
        ");
        
        if (!$tableExists[0]->exists) {
            // Create the table if it doesn't exist
            DB::statement("
                CREATE TABLE IF NOT EXISTS public.tenant_schema_migrations (
                    id UUID PRIMARY KEY,
                    tenant_id UUID NOT NULL,
                    migration VARCHAR(255) NOT NULL,
                    batch INTEGER NOT NULL,
                    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
        
        return DB::table('tenant_schema_migrations')
            ->where('tenant_id', $tenant->id)
            ->pluck('migration')
            ->toArray();
    }
    
    /**
     * Get next batch number
     */
    private function getNextBatchNumber(Tenant $tenant): int
    {
        $lastBatch = DB::table('tenant_schema_migrations')
            ->where('tenant_id', $tenant->id)
            ->max('batch');
            
        return ($lastBatch ?? 0) + 1;
    }
    
    /**
     * Execute a migration file
     */
    private function executeMigration(string $migrationFile, Tenant $tenant, int $batch): void
    {
        // Include the migration file
        $migration = include $migrationFile;
        
        // Execute the up method
        $migration->up();
    }
    
    /**
     * Record migration execution
     */
    private function recordMigration(Tenant $tenant, string $migrationName, int $batch): void
    {
        DB::table('tenant_schema_migrations')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $tenant->id,
            'migration' => $migrationName,
            'batch' => $batch,
            'executed_at' => now()
        ]);
    }
    
    /**
     * Seed basic data (required for all tenants)
     */
    private function seedBasicData(Tenant $tenant): void
    {
        // Add basic RADIUS groups if not already present
        $existingGroup = DB::table('radgroupcheck')
            ->where('groupname', 'hotspot_users')
            ->exists();
            
        if (!$existingGroup) {
            DB::table('radgroupcheck')->insert([
                [
                    'groupname' => 'hotspot_users',
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => 'Accept',
                    'created_at' => now(),
                ],
            ]);
            
            DB::table('radgroupreply')->insert([
                [
                    'groupname' => 'hotspot_users',
                    'attribute' => 'Service-Type',
                    'op' => ':=',
                    'value' => 'Framed-User',
                    'created_at' => now(),
                ],
            ]);
        }
    }
    
    /**
     * Seed test data (for development/testing)
     */
    private function seedTestData(Tenant $tenant): void
    {
        $this->seedBasicData($tenant);
        
        // Add additional test data here if needed
        Log::info("Test data seeded for tenant: {$tenant->name}");
    }
    
    /**
     * Drop tenant schema
     */
    public function dropTenantSchema(Tenant $tenant): bool
    {
        try {
            $schemaName = $tenant->schema_name;
            
            // Check if schema exists
            $schemaExists = DB::select("
                SELECT EXISTS (
                    SELECT FROM information_schema.schemata 
                    WHERE schema_name = ?
                )
            ", [$schemaName]);
            
            if (!$schemaExists[0]->exists) {
                Log::warning("Schema does not exist: {$schemaName}");
                return true;
            }
            
            Log::info("Dropping schema for tenant: {$tenant->name} ({$schemaName})");
            
            // Drop the schema
            DB::statement("DROP SCHEMA IF EXISTS {$schemaName} CASCADE");
            
            // Remove migration records
            DB::table('tenant_schema_migrations')
                ->where('tenant_id', $tenant->id)
                ->delete();
            
            // Update tenant record
            $tenant->update([
                'schema_created' => false,
                'schema_created_at' => null
            ]);
            
            Log::info("Schema dropped successfully: {$schemaName}");
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to drop schema for tenant {$tenant->name}: " . $e->getMessage());
            throw $e;
        }
    }
}
