<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Services\TenantMigrationManager;
use Illuminate\Support\Facades\Auth;

trait TenantAwareJob
{
    /**
     * The tenant ID for this job
     */
    public $tenantId;

    /**
     * Set the tenant context for the job
     */
    public function setTenantContext($tenantId = null): self
    {
        // If tenant ID provided, use it
        if ($tenantId) {
            $this->tenantId = $tenantId;
            return $this;
        }

        // Otherwise, get from authenticated user
        if (Auth::check() && Auth::user()->tenant_id) {
            $this->tenantId = Auth::user()->tenant_id;
        }

        return $this;
    }

    /**
     * Execute job within tenant context
     */
    protected function executeInTenantContext(callable $callback)
    {
        // If no tenant context, execute normally (for system-wide jobs)
        if (!$this->tenantId) {
            \Illuminate\Support\Facades\Log::warning('TenantAwareJob executed without tenant context', [
                'job_class' => get_class($this),
                'queue' => property_exists($this, 'queue') ? $this->queue : null,
                'connection' => property_exists($this, 'connection') ? $this->connection : null,
            ]);

            return $callback();
        }

        // Tenant bootstrap must not depend on the read replica; read-pool DNS
        // or replica lag should not block queue jobs from entering tenant context.
        try {
            $tenant = Tenant::query()->useWritePdo()->find($this->tenantId);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '08006' || $e->getCode() === '08001') {
                \Illuminate\Support\Facades\Log::warning('TenantAwareJob: DB connection refused (startup race?), job will retry', [
                    'tenant_id' => $this->tenantId,
                    'job_class' => get_class($this),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
            throw $e;
        }

        if (!$tenant) {
            throw new \Exception("Tenant not found: {$this->tenantId}");
        }

        if (!$tenant->isActive()) {
            throw new \Exception("Tenant is not active: {$this->tenantId}");
        }

        // Verify tenant schema is created
        if (!$tenant->schema_created || empty($tenant->schema_name)) {
            \Illuminate\Support\Facades\Log::warning('Skipping job execution: Tenant schema not created', [
                'tenant_id' => $this->tenantId,
                'schema_name' => $tenant->schema_name,
                'schema_created' => $tenant->schema_created,
                'job_class' => get_class($this),
            ]);
            return null; // Skip execution gracefully
        }

        // Create a fake authenticated user context for tenant scoping
        // This ensures all queries within the job are scoped to the tenant
        $systemUser = new \App\Models\User([
            'id' => '00000000-0000-0000-0000-000000000000',
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
        ]);

        // Set auth context
        Auth::setUser($systemUser);

        // Auto-migrate: if any tenant migrations have not been executed yet, run them
        // before the job starts. This ensures tables always exist before queries run,
        // preventing 42P01 errors on tenants whose schema was created but never fully migrated.
        $migrationManager = app(TenantMigrationManager::class);
        if ($migrationManager->hasPendingMigrations($tenant)) {
            \Illuminate\Support\Facades\Log::info('Auto-migrating tenant schema before job execution', [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name,
                'job_class' => get_class($this),
            ]);
            $migrationManager->runMigrationsForTenant($tenant);
        }

        // Wrap callback in DB::transaction() so PgBouncer holds a single backend
        // PostgreSQL connection for all statements. SET LOCAL search_path is
        // transaction-scoped: it persists across all statements within the same
        // transaction but is automatically rolled back when the transaction ends.
        // Plain SET search_path is session-level and is lost between statements
        // when PgBouncer transaction pooling rotates the backend connection.
        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $callback) {
                // PgBouncer is usually configured for transaction pooling. We MUST keep
                // all queries on the same PDO inside this transaction, otherwise a SELECT
                // can be routed to the "read" PDO and lose the SET LOCAL search_path.
                $conn = \Illuminate\Support\Facades\DB::connection();
                $conn->useWriteConnectionWhenReading(true);

                try {
                    \Illuminate\Support\Facades\DB::statement("SET LOCAL search_path TO {$tenant->schema_name}, public");

                    return $callback();
                } finally {
                    // Avoid leaking sticky-write mode across jobs.
                    $conn->useWriteConnectionWhenReading(false);
                }
            });
        } finally {
            // Clear auth context — Auth::logout() is not available on token-based guards
            // (Sanctum RequestGuard), so use forgetGuards() to reset the resolved instances.
            Auth::forgetGuards();
        }
    }

    /**
     * Get tags for the job (includes tenant)
     */
    public function tags(): array
    {
        $tags = [];

        if ($this->tenantId) {
            $tags[] = 'tenant:' . $this->tenantId;
        }

        // Merge with parent tags if they exist
        if (method_exists(parent::class, 'tags')) {
            $tags = array_merge($tags, parent::tags());
        }

        return $tags;
    }
}
