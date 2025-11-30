<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Exception;

/**
 * TenantSchemaManager Service
 * 
 * Manages tenant schema lifecycle: creation, migration, seeding, backup, and deletion.
 */
class TenantSchemaManager
{
    /**
     * System schema name
     */
    protected string $systemSchema = 'public';
    
    /**
     * TenantContext service
     */
    protected TenantContext $tenantContext;
    
    public function __construct(TenantContext $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }
    
    /**
     * Create a new tenant schema
     * 
     * @param Tenant $tenant
     * @return bool
     * @throws Exception
     */
    public function createSchema(Tenant $tenant): bool
    {
        try {
            $schemaName = $tenant->schema_name;
            
            // Validate schema name
            if (!$this->isValidSchemaName($schemaName)) {
                throw new Exception("Invalid schema name: {$schemaName}");
            }
            
            // Check if schema already exists
            if ($this->schemaExists($schemaName)) {
                Log::warning("Schema already exists: {$schemaName}");
                return true;
            }
            
            Log::info("Creating schema for tenant: {$tenant->name} ({$schemaName})");
            
            // Create the schema
            DB::statement("CREATE SCHEMA IF NOT EXISTS {$schemaName}");
            
            // Grant permissions to database user
            $dbUser = config('database.connections.pgsql.username');
            DB::statement("GRANT ALL ON SCHEMA {$schemaName} TO {$dbUser}");
            DB::statement("GRANT ALL ON ALL TABLES IN SCHEMA {$schemaName} TO {$dbUser}");
            DB::statement("GRANT ALL ON ALL SEQUENCES IN SCHEMA {$schemaName} TO {$dbUser}");
            
            // Set default privileges for future objects
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schemaName} GRANT ALL ON TABLES TO {$dbUser}");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schemaName} GRANT ALL ON SEQUENCES TO {$dbUser}");
            
            // Update tenant record
            $tenant->update([
                'schema_created' => true,
                'schema_created_at' => now()
            ]);
            
            Log::info("Schema created successfully: {$schemaName}");
            
            // Run migrations if auto-migrate is enabled
            if (config('multitenancy.auto_migrate_schema', true)) {
                $this->runMigrations($tenant);
            }
            
            // Seed data if auto-seed is enabled
            if (config('multitenancy.auto_seed_schema', false)) {
                $this->seedData($tenant);
            }
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to create schema for tenant {$tenant->name}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Drop a tenant schema
     * 
     * @param Tenant $tenant
     * @param bool $cascade
     * @return bool
     * @throws Exception
     */
    public function dropSchema(Tenant $tenant, bool $cascade = true): bool
    {
        try {
            $schemaName = $tenant->schema_name;
            
            if (!$this->schemaExists($schemaName)) {
                Log::warning("Schema does not exist: {$schemaName}");
                return true;
            }
            
            Log::warning("Dropping schema: {$schemaName}");
            
            $cascadeClause = $cascade ? 'CASCADE' : 'RESTRICT';
            DB::statement("DROP SCHEMA IF EXISTS {$schemaName} {$cascadeClause}");
            
            // Update tenant record
            $tenant->update([
                'schema_created' => false,
                'schema_created_at' => null
            ]);
            
            Log::info("Schema dropped successfully: {$schemaName}");
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to drop schema {$schemaName}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Run migrations for tenant schema
     * 
     * @param Tenant $tenant
     * @return bool
     * @throws Exception
     */
    public function runMigrations(Tenant $tenant): bool
    {
        try {
            $schemaName = $tenant->schema_name;
            
            Log::info("Running migrations for tenant: {$tenant->name} ({$schemaName})");
            
            // Run in tenant context
            return $this->tenantContext->runInTenantContext($tenant, function() use ($schemaName) {
                // Check if tenant migrations directory exists
                $migrationPath = database_path('migrations/tenant');
                
                if (File::exists($migrationPath)) {
                    Artisan::call('migrate', [
                        '--path' => 'database/migrations/tenant',
                        '--force' => true,
                    ]);
                    
                    Log::info("Migrations completed for schema: {$schemaName}");
                    return true;
                } else {
                    Log::warning("Tenant migrations directory not found: {$migrationPath}");
                    return false;
                }
            });
        } catch (Exception $e) {
            Log::error("Failed to run migrations for tenant {$tenant->name}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Seed data for tenant schema
     * 
     * @param Tenant $tenant
     * @return bool
     * @throws Exception
     */
    public function seedData(Tenant $tenant): bool
    {
        try {
            Log::info("Seeding data for tenant: {$tenant->name}");
            
            return $this->tenantContext->runInTenantContext($tenant, function() {
                Artisan::call('db:seed', [
                    '--class' => 'TenantSeeder',
                    '--force' => true,
                ]);
                
                return true;
            });
        } catch (Exception $e) {
            Log::error("Failed to seed data for tenant {$tenant->name}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Backup tenant schema
     * 
     * @param Tenant $tenant
     * @return string Path to backup file
     * @throws Exception
     */
    public function backupSchema(Tenant $tenant): string
    {
        try {
            $schemaName = $tenant->schema_name;
            $timestamp = now()->format('Y-m-d_His');
            $backupFile = storage_path("backups/tenant_{$tenant->slug}_{$timestamp}.sql");
            
            // Ensure backup directory exists
            File::ensureDirectoryExists(dirname($backupFile));
            
            Log::info("Backing up schema: {$schemaName} to {$backupFile}");
            
            $dbConfig = config('database.connections.pgsql');
            $command = sprintf(
                'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -n %s %s > %s',
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['port']),
                escapeshellarg($dbConfig['username']),
                escapeshellarg($schemaName),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($backupFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception("Backup command failed with code: {$returnCode}");
            }
            
            Log::info("Backup completed: {$backupFile}");
            
            return $backupFile;
        } catch (Exception $e) {
            Log::error("Failed to backup schema for tenant {$tenant->name}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Restore tenant schema from backup
     * 
     * @param Tenant $tenant
     * @param string $backupFile
     * @return bool
     * @throws Exception
     */
    public function restoreSchema(Tenant $tenant, string $backupFile): bool
    {
        try {
            if (!File::exists($backupFile)) {
                throw new Exception("Backup file not found: {$backupFile}");
            }
            
            $schemaName = $tenant->schema_name;
            
            Log::info("Restoring schema: {$schemaName} from {$backupFile}");
            
            $dbConfig = config('database.connections.pgsql');
            $command = sprintf(
                'PGPASSWORD=%s psql -h %s -p %s -U %s %s < %s',
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['port']),
                escapeshellarg($dbConfig['username']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($backupFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception("Restore command failed with code: {$returnCode}");
            }
            
            Log::info("Restore completed: {$schemaName}");
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to restore schema for tenant {$tenant->name}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if schema exists
     * 
     * @param string $schemaName
     * @return bool
     */
    public function schemaExists(string $schemaName): bool
    {
        $result = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
            [$schemaName]
        );
        
        return $result->exists ?? false;
    }
    
    /**
     * Get schema size in bytes
     * 
     * @param string $schemaName
     * @return int
     */
    public function getSchemaSize(string $schemaName): int
    {
        $result = DB::selectOne("
            SELECT SUM(pg_total_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename)))::bigint as size
            FROM pg_tables
            WHERE schemaname = ?
        ", [$schemaName]);
        
        return $result->size ?? 0;
    }
    
    /**
     * Get list of tables in schema
     * 
     * @param string $schemaName
     * @return array
     */
    public function getSchemaTableslist(string $schemaName): array
    {
        $results = DB::select("
            SELECT tablename
            FROM pg_tables
            WHERE schemaname = ?
            ORDER BY tablename
        ", [$schemaName]);
        
        return array_column($results, 'tablename');
    }
    
    /**
     * Validate schema name
     * 
     * @param string $schemaName
     * @return bool
     */
    protected function isValidSchemaName(string $schemaName): bool
    {
        // Schema name must be alphanumeric with underscores, max 63 chars
        return preg_match('/^[a-z0-9_]{1,63}$/', $schemaName) === 1;
    }
    
    /**
     * Clone tenant schema
     * 
     * @param Tenant $sourceTenant
     * @param Tenant $targetTenant
     * @return bool
     * @throws Exception
     */
    public function cloneSchema(Tenant $sourceTenant, Tenant $targetTenant): bool
    {
        try {
            $sourceSchema = $sourceTenant->schema_name;
            $targetSchema = $targetTenant->schema_name;
            
            Log::info("Cloning schema from {$sourceSchema} to {$targetSchema}");
            
            // Create target schema
            $this->createSchema($targetTenant);
            
            // Copy all tables and data
            $tables = $this->getSchemaTablesList($sourceSchema);
            
            foreach ($tables as $table) {
                DB::statement("
                    CREATE TABLE {$targetSchema}.{$table} 
                    (LIKE {$sourceSchema}.{$table} INCLUDING ALL)
                ");
                
                DB::statement("
                    INSERT INTO {$targetSchema}.{$table}
                    SELECT * FROM {$sourceSchema}.{$table}
                ");
            }
            
            Log::info("Schema cloned successfully");
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to clone schema: " . $e->getMessage());
            throw $e;
        }
    }
}
