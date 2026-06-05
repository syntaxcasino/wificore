<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TenantContext Service
 * 
 * Manages the current tenant context and PostgreSQL search_path for schema-based multi-tenancy.
 * This service is the core of the multi-tenant architecture.
 */
class TenantContext
{
    /**
     * Current tenant instance
     */
    protected ?Tenant $tenant = null;
    
    /**
     * System schema name
     */
    protected string $systemSchema = 'public';
    
    /**
     * Original search path before tenant context was set
     */
    protected ?string $originalSearchPath = null;

    /**
     * Cached active tenant ID for fast re-entry checks
     */
    protected ?string $activeTenantId = null;

    /**
     * Cached active schema name for fast re-entry checks
     */
    protected ?string $activeSchemaName = null;

    /**
     * Cached active search path for fast re-entry checks
     */
    protected ?string $activeSearchPath = null;

    /**
     * Set tenant context by tenant object
     * 
     * @param Tenant|null $tenant
     * @return void
     */
    public function setTenant(?Tenant $tenant, bool $force = true): void
    {
        if (!$tenant) {
            $this->clearTenant();
            return;
        }
        
        $targetSearchPath = $tenant->schema_created && $tenant->schema_name
            ? $this->buildSearchPath($tenant->schema_name)
            : null;

        $this->tenant = $tenant;
        
        // Also set in app container for model events to access
        app()->instance('current_tenant', $tenant);
        
        // Only set search path if tenant has a schema
        if ($tenant->schema_created && $tenant->schema_name) {
            $this->setSearchPath($tenant->schema_name);
            
            Log::debug('Tenant context set', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'schema_name' => $tenant->schema_name,
            ]);
        }
    }
    
    /**
     * Set tenant context by tenant ID
     * 
     * @param string $tenantId
     * @return void
     * @throws \Exception if tenant not found
     */
    public function setTenantById(string $tenantId): void
    {
        $tenant = Tenant::find($tenantId);
        
        if (!$tenant) {
            throw new \Exception("Tenant not found: {$tenantId}");
        }
        
        $this->setTenant($tenant);
    }
    
    /**
     * Set tenant context by user
     * 
     * @param User $user
     * @return void
     * @throws \Exception if user has no tenant
     */
    public function setTenantByUser(User $user): void
    {
        if (!$user->tenant_id) {
            throw new \Exception("User does not belong to any tenant");
        }
        
        $this->setTenantById($user->tenant_id);
    }
    
    /**
     * Get current tenant
     * 
     * @return Tenant|null
     */
    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }
    
    /**
     * Get current tenant ID
     * 
     * @return string|null
     */
    public function getTenantId(): ?string
    {
        return $this->tenant?->id;
    }
    
    /**
     * Get current tenant schema name
     * 
     * @return string|null
     */
    public function getSchemaName(): ?string
    {
        return $this->tenant?->schema_name;
    }
    
    /**
     * Check if tenant context is set
     * 
     * @return bool
     */
    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }
    
    /**
     * Clear tenant context and reset to public schema
     * 
     * @return void
     */
    public function clearTenant(): void
    {
        $targetSearchPath = $this->originalSearchPath ?: $this->systemSchema;

        try {
            $this->setSearchPathStatement($targetSearchPath);
        } catch (\Throwable $e) {
            $isAbortedTransaction = DB::transactionLevel() > 0 && str_contains($e->getMessage(), 'current transaction is aborted');

            if ($isAbortedTransaction) {
                Log::debug('Skipped tenant search path reset because the current transaction is already aborted', [
                    'target_search_path' => $targetSearchPath,
                    'transaction_level' => DB::transactionLevel(),
                ]);
            } else {
                Log::warning('Failed to reset tenant search path during context clear', [
                    'target_search_path' => $targetSearchPath,
                    'transaction_level' => DB::transactionLevel(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->originalSearchPath = null;
        $this->activeTenantId = null;
        $this->activeSchemaName = null;
        $this->activeSearchPath = $targetSearchPath;

        $this->tenant = null;
        
        // Clear from app container
        if (app()->has('current_tenant')) {
            app()->forgetInstance('current_tenant');
        }
        
        Log::debug('Tenant context cleared');
    }
    
    /**
     * Set PostgreSQL search_path to tenant schema
     * 
     * @param string $schemaName
     * @return void
     */
    protected function setSearchPath(string $schemaName): void
    {
        // Validate schema name to prevent SQL injection
        if (!$this->isValidSchemaName($schemaName)) {
            throw new \Exception("Invalid schema name: {$schemaName}");
        }
        
        // Save original search path if not already saved.
        // MUST use the write PDO: DB::selectOne() routes to the read PDO
        // (a different physical connection to the read replica) and would
        // return that replica's search_path, not the write PDO's baseline.
        if (!$this->originalSearchPath) {
            // useWritePdo() is a Query Builder method, not a Connection method.
            // We use DB::table() to get a Query Builder, call useWritePdo(), then get the connection.
            $result = DB::connection()->getReadPdo() === DB::connection()->getPdo()
                ? DB::selectOne("SHOW search_path")
                : DB::table('tenants')->useWritePdo()->getConnection()->selectOne("SHOW search_path");
            $raw = $result->search_path ?? 'public';
            // PostgreSQL's default search_path is '"$user", public'. The "$user" pseudo-schema
            // is not a real schema name and SET LOCAL rejects it on an aborted transaction.
            // Normalize by keeping only simple alphanumeric/underscore schema tokens.
            $parts = array_filter(
                array_map('trim', explode(',', $raw)),
                fn (string $p) => preg_match('/^[a-z0-9_]{1,63}$/', $p) === 1
            );
            $this->originalSearchPath = implode(', ', $parts) ?: 'public';
        }

        // Use SET LOCAL so the search_path is scoped to the current transaction.
        // This is required for PgBouncer transaction pooling compatibility:
        // plain SET is session-level but PgBouncer rotates the backend connection
        // between statements in transaction mode, losing session-level settings.
        // Callers MUST: (1) wrap in DB::transaction(), and (2) call
        // DB::connection()->recordsHaveBeenModified() to force sticky-write mode
        // so all SELECT queries use the write PDO (the one inside the transaction).
        // The read PDO has no open transaction so SET LOCAL on it is immediately lost.
        $this->setSearchPathStatement("{$schemaName}, {$this->systemSchema}");
        $this->activeTenantId = $this->tenant?->id;
        $this->activeSchemaName = $schemaName;
        $this->activeSearchPath = "{$schemaName}, {$this->systemSchema}";
        
        Log::debug('Search path set', [
            'schema_name' => $schemaName,
            'original_path' => $this->originalSearchPath,
        ]);
    }
    
    /**
     * Validate schema name to prevent SQL injection
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
     * Run code in tenant context
     * 
     * Automatically sets tenant context, runs the callback, and clears context.
     * 
     * @param Tenant $tenant
     * @param callable $callback
     * @return mixed
     */
    public function runInTenantContext(Tenant $tenant, callable $callback)
    {
        $previousTenant = $this->tenant;

        // NOTE: Callers (SetTenantContext middleware, TenantAwareJob, RscFileCleanupService)
        // are responsible for wrapping in DB::transaction() so SET LOCAL search_path
        // persists across statements under PgBouncer transaction pooling.
        // Do NOT add DB::transaction() here — it would issue a nested SET LOCAL
        // clearTenant() call that resets search_path mid outer-transaction.
        try {
            $this->setTenant($tenant, false);
            return $callback();
        } finally {
            try {
                if ($previousTenant) {
                    if ($this->tenant?->id !== $previousTenant->id) {
                        $this->setTenant($previousTenant, true);
                    }
                } else {
                    if ($this->tenant !== null) {
                        $this->clearTenant();
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to restore tenant context after scoped operation', [
                    'tenant_id' => $tenant->id ?? null,
                    'previous_tenant_id' => $previousTenant?->id,
                    'transaction_level' => DB::transactionLevel(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
    
    /**
     * Run code in system context (public schema)
     * 
     * @param callable $callback
     * @return mixed
     */
    public function runInSystemContext(callable $callback)
    {
        $previousTenant = $this->tenant;

        try {
            $this->clearTenant();
            return $callback();
        } finally {
            if ($previousTenant) {
                if ($this->tenant?->id !== $previousTenant->id) {
                    $this->setTenant($previousTenant, true);
                }
            }
        }
    }
    
    /**
     * Get current search path
     * 
     * @return string
     */
    public function getCurrentSearchPath(): string
    {
        // useWritePdo() is a Query Builder method, not a Connection method.
        $result = DB::connection()->getReadPdo() === DB::connection()->getPdo()
            ? DB::selectOne("SHOW search_path")
            : DB::table('tenants')->useWritePdo()->getConnection()->selectOne("SHOW search_path");
        return $result->search_path ?? 'public';
    }
    
    /**
     * Check if currently in tenant context
     * 
     * @return bool
     */
    public function isInTenantContext(): bool
    {
        return $this->tenant !== null && $this->tenant->schema_created;
    }
    
    /**
     * Check if currently in system context
     * 
     * @return bool
     */
    public function isInSystemContext(): bool
    {
        return !$this->isInTenantContext();
    }

    /**
     * Reset tenant context (alias for clearTenant)
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->clearTenant();
    }

    /**
     * Apply search_path safely based on transaction state.
     *
     * SET LOCAL is only valid inside a transaction; outside transaction it throws
     * on PostgreSQL and causes intermittent 500s under mixed request flows.
     */
    protected function setSearchPathStatement(string $searchPath): void
    {
        if (DB::transactionLevel() > 0) {
            DB::statement("SET LOCAL search_path TO {$searchPath}");
            $this->activeSearchPath = $searchPath;
            return;
        }

        DB::statement("SET search_path TO {$searchPath}");
        $this->activeSearchPath = $searchPath;
    }


    /**
     * Build a normalized tenant search path.
     */
    protected function buildSearchPath(string $schemaName): string
    {
        return "{$schemaName}, {$this->systemSchema}";
    }
}
