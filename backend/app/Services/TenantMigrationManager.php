<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TenantMigrationManager
{
    private const LOCK_TIMEOUT_SQLSTATE = '55P03';

    /**
     * Run all tenant migrations for a specific tenant.
     * Each migration runs in its own transaction to prevent cascade failures.
     */
    public function runMigrationsForTenant(Tenant $tenant): bool
    {
        // Acquire a distributed lock to prevent multiple workers from migrating
        // the same tenant schema concurrently (race → duplicate CREATE TABLE).
        $lockKey = "tenant_migration_lock:{$tenant->id}";
        $lock = Cache::lock($lockKey, 300); // 5 minute TTL

        if (!$lock->get()) {
            Log::info("Another worker is already migrating tenant {$tenant->name}, skipping", [
                'tenant_id' => $tenant->id,
            ]);
            return true; // Not an error — another worker is handling it
        }

        try {
            // Get migration files and already-executed list before opening any transaction,
            // since these queries target the public schema and don't need tenant search_path.
            $migrationFiles = $this->getTenantMigrationFiles();
            Log::info("Found " . count($migrationFiles) . " migration files", ['files' => array_map('basename', $migrationFiles)]);

            $executedMigrations = $this->getExecutedMigrations($tenant);
            Log::info("Found " . count($executedMigrations) . " executed migrations", ['executed' => $executedMigrations]);

            $batch = $this->getNextBatchNumber($tenant);
            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;

            foreach ($migrationFiles as $migrationFile) {
                $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);

                if (in_array($migrationName, $executedMigrations)) {
                    $skippedCount++;
                    continue;
                }

                // If a migration is repeatedly failing due to lock contention, back off
                // for a bit instead of hammering the same DDL over and over.
                if ($this->isInCooldown($tenant, $migrationName)) {
                    $skippedCount++;
                    continue;
                }

                // Run each migration in its own transaction.
                // This prevents one failed migration from aborting the entire batch.
                $result = $this->runSingleMigration($migrationFile, $migrationName, $tenant, $batch);
                
                if ($result === 'success') {
                    $successCount++;
                    Log::info("Executed tenant migration: {$migrationName} for tenant: {$tenant->name}");
                } elseif ($result === 'already_exists') {
                    $successCount++;
                    Log::warning("Migration object already exists, marked as executed: {$migrationName}", [
                        'tenant_id' => $tenant->id,
                    ]);
                } else {
                    $failedCount++;
                    Log::error("Migration failed and will be retried later: {$migrationName}", [
                        'tenant_id' => $tenant->id,
                    ]);
                    // Continue with next migration - don't let one failure stop the batch
                }
            }

            Log::info("Migration batch completed for tenant: {$tenant->name}", [
                'success' => $successCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
            ]);

            // Return true if we processed at least one migration successfully or skipped already executed ones
            // Return false only if there were failures and no successes
            return $failedCount === 0 || $successCount > 0;

        } catch (\Exception $e) {
            Log::error("Failed to run tenant migrations for {$tenant->name}: " . $e->getMessage());
            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Run a single migration in its own transaction.
     * Returns: 'success', 'already_exists', or 'failed'
     */
    private function runSingleMigration(string $migrationFile, string $migrationName, Tenant $tenant, int $batch): string
    {
        try {
            // Include the migration once so we can honor its `withinTransaction` setting.
            $migration = include $migrationFile;
            $withinTransaction = $this->shouldRunWithinTransaction($migration);

            if ($withinTransaction) {
                return DB::transaction(function () use ($migration, $migrationName, $tenant, $batch) {
                    // Ensure all queries use the write PDO during this transaction.
                    // This keeps SET LOCAL search_path effective under PgBouncer.
                    $conn = DB::connection();
                    $conn->useWriteConnectionWhenReading(true);

                    try {
                        // Set search_path for this transaction
                        DB::statement("SET LOCAL search_path TO {$tenant->schema_name}, public");

                        Log::info("Running migration: {$migrationName}");

                        $migration->up();

                        // Record the migration
                        $this->recordMigration($tenant, $migrationName, $batch);

                        return 'success';
                    } finally {
                        $conn->useWriteConnectionWhenReading(false);
                    }
                });
            }

            // Some migrations (notably Postgres ALTER TABLE) must NOT be wrapped in a
            // transaction in order to avoid holding ACCESS EXCLUSIVE locks for the
            // full migration duration.
            // Note: we must reset search_path because this is session-scoped.
            $conn = DB::connection();
            $conn->useWriteConnectionWhenReading(true);
            $this->setTenantSearchPath($tenant);
            try {
                Log::info("Running migration: {$migrationName} (no transaction)");
                $migration->up();
                $this->recordMigration($tenant, $migrationName, $batch);
                return 'success';
            } finally {
                $this->resetSearchPath();
                $conn->useWriteConnectionWhenReading(false);
            }
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Table/type already exists from a prior partial migration run.
            // This is safe to ignore — the DDL succeeded previously but the
            // migration record was not written (transaction rolled back).
            if (str_contains($e->getMessage(), 'pg_type_typname_nsp_index') ||
                str_contains($e->getMessage(), 'already exists')) {
                
                // Try to record this migration as executed even though objects exist
                try {
                    $this->recordMigration($tenant, $migrationName, $batch);
                } catch (\Exception $recordEx) {
                    Log::warning("Could not record already-existing migration: {$migrationName}", [
                        'tenant_id' => $tenant->id,
                        'error' => $recordEx->getMessage(),
                    ]);
                }
                
                return 'already_exists';
            }
            
            Log::error("Migration failed with constraint violation: {$migrationName}", [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
            
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isLockTimeout($e)) {
                $this->startCooldown($tenant, $migrationName);
            }

            // Handle "already exists" errors for broader safety
            if (str_contains($e->getMessage(), 'already exists') ||
                str_contains($e->getMessage(), 'pg_type_typname_nsp_index')) {
                
                Log::warning("Migration object already exists: {$migrationName}", [
                    'tenant_id' => $tenant->id,
                ]);
                
                // Try to record this migration as executed
                try {
                    $this->recordMigration($tenant, $migrationName, $batch);
                } catch (\Exception $recordEx) {
                    Log::warning("Could not record already-existing migration: {$migrationName}", [
                        'tenant_id' => $tenant->id,
                        'error' => $recordEx->getMessage(),
                    ]);
                }
                
                return 'already_exists';
            }
            
            Log::error("Migration failed with query exception: {$migrationName}", [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
            
        } catch (\Exception $e) {
            Log::error("Migration failed: {$migrationName}", [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 'failed';
        } catch (\Throwable $e) {
            // Prevent worker crashes on non-Exception throwables (e.g. FatalError).
            Log::error("Migration crashed: {$migrationName}", [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'type' => get_class($e),
            ]);
            return 'failed';
        }
    }

    private function shouldRunWithinTransaction(mixed $migration): bool
    {
        // Laravel's base Migration defines `$withinTransaction = true;`.
        // If a migration explicitly sets it to false, we must respect it.
        try {
            if (is_object($migration) && property_exists($migration, 'withinTransaction')) {
                // In some Laravel versions this property isn't typed/public, so be defensive.
                return (bool) $migration->withinTransaction;
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return true;
    }

    private function isLockTimeout(\Illuminate\Database\QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        if ($sqlState === self::LOCK_TIMEOUT_SQLSTATE) {
            return true;
        }

        return str_contains($e->getMessage(), self::LOCK_TIMEOUT_SQLSTATE)
            || str_contains($e->getMessage(), 'canceling statement due to lock timeout');
    }

    private function cooldownKey(Tenant $tenant, string $migrationName): string
    {
        return "tenant_migration_cooldown:{$tenant->id}:{$migrationName}";
    }

    private function isInCooldown(Tenant $tenant, string $migrationName): bool
    {
        return Cache::has($this->cooldownKey($tenant, $migrationName));
    }

    private function startCooldown(Tenant $tenant, string $migrationName): void
    {
        Cache::put($this->cooldownKey($tenant, $migrationName), true, now()->addMinutes(5));
    }

    private function setTenantSearchPath(Tenant $tenant): void
    {
        // schema_name is generated/validated elsewhere; keep quoting minimal but safe.
        $schema = str_replace('"', '""', (string) $tenant->schema_name);
        DB::statement("SET search_path TO \"{$schema}\", public");
    }

    private function resetSearchPath(): void
    {
        DB::statement("SET search_path TO public");
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
                $tenant->schema_name = self::generateSecureSchemaName($tenant->slug, (string) $tenant->id);
                $tenant->saveQuietly(); // Save without triggering events
                
                Log::info("Schema name regenerated", [
                    'tenant_id' => $tenant->id,
                    'new_schema_name' => $tenant->schema_name
                ]);
            }
            
            Log::info("Creating schema for tenant", [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name,
                'tenant_name' => $tenant->name
            ]);
            
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
            
            Log::info("Schema created and permissions granted, running migrations", [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name
            ]);
            
            // Run migrations
            $success = $this->runMigrationsForTenant($tenant);
            
            if ($success) {
                // Update tenant record
                $tenant->update([
                    'schema_created' => true,
                    'schema_created_at' => now()
                ]);
                
                Log::info("Successfully set up schema for tenant: {$tenant->name}", [
                    'tenant_id' => $tenant->id,
                    'schema_name' => $tenant->schema_name
                ]);
            } else {
                Log::error("Migration execution failed for tenant", [
                    'tenant_id' => $tenant->id,
                    'schema_name' => $tenant->schema_name,
                    'tenant_name' => $tenant->name
                ]);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            Log::error("Failed to setup tenant schema", [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'schema_name' => $tenant->schema_name ?? 'not_set',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Seed tenant schema with test data
     */
    public function seedTenantSchema(Tenant $tenant, bool $withTestData = false): bool
    {
        try {
            DB::transaction(function () use ($tenant, $withTestData) {
                DB::statement("SET LOCAL search_path TO {$tenant->schema_name}, public");

                if ($withTestData) {
                    $this->seedTestData($tenant);
                } else {
                    $this->seedBasicData($tenant);
                }
            });

            Log::info("Successfully seeded tenant schema: {$tenant->name}");
            return true;

        } catch (\Exception $e) {
            Log::error("Failed to seed tenant schema for {$tenant->name}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate secure schema name
     */
    public static function generateSecureSchemaName(string $tenantSlug, ?string $uniqueSeed = null): string
    {
        // Create a hash-based schema name that's hard to guess
        // Include a per-tenant unique seed (typically tenant UUID) to avoid collisions
        // when a tenant is deleted and recreated with the same slug.
        $seed = $uniqueSeed ?: $tenantSlug;
        $hash = hash('sha256', $tenantSlug . '|' . $seed . '|' . config('app.key') . '|tenant_schema_salt');
        
        // Take first 12 characters and prefix with 'ts_' (tenant schema)
        $schemaName = 'ts_' . substr($hash, 0, 12);
        
        // Ensure it's a valid PostgreSQL identifier (lowercase, no hyphens)
        return strtolower($schemaName);
    }
    
    /**
     * Check whether any tenant migrations are pending for the given tenant.
     * Runs entirely against the public schema (no search_path change needed).
     */
    public function hasPendingMigrations(Tenant $tenant): bool
    {
        try {
            $migrationFiles      = $this->getTenantMigrationFiles();
            $executedMigrations  = $this->getExecutedMigrations($tenant);

            foreach ($migrationFiles as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (!in_array($name, $executedMigrations)) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::warning("Could not determine pending migrations for tenant {$tenant->id}: " . $e->getMessage());
            return false;
        }
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
     * Record migration execution
     */
    private function recordMigration(Tenant $tenant, string $migrationName, int $batch): void
    {
        try {
            DB::table('tenant_schema_migrations')->insert([
                'id' => Str::uuid(),
                'tenant_id' => $tenant->id,
                'migration' => $migrationName,
                'batch' => $batch,
                'executed_at' => now()
            ]);
        } catch (\Exception $e) {
            // If we can't record the migration due to transaction abort, 
            // it will be retried on the next run
            if (str_contains($e->getMessage(), 'current transaction is aborted')) {
                Log::warning("Cannot record migration due to transaction abort: {$migrationName}", [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                return;
            }
            throw $e;
        }
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
