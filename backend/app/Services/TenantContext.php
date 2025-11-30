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
     * Set tenant context by tenant object
     * 
     * @param Tenant|null $tenant
     * @return void
     */
    public function setTenant(?Tenant $tenant): void
    {
        if (!$tenant) {
            $this->clearTenant();
            return;
        }
        
        $this->tenant = $tenant;
        
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
        if ($this->originalSearchPath) {
            DB::statement("SET search_path TO {$this->originalSearchPath}");
            $this->originalSearchPath = null;
        } else {
            DB::statement("SET search_path TO {$this->systemSchema}");
        }
        
        $this->tenant = null;
        
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
        
        // Save original search path if not already saved
        if (!$this->originalSearchPath) {
            $result = DB::selectOne("SHOW search_path");
            $this->originalSearchPath = $result->search_path ?? 'public';
        }
        
        // Set search path: tenant schema first, then public
        // This allows tenant tables to override public tables
        DB::statement("SET search_path TO {$schemaName}, {$this->systemSchema}");
        
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
        
        try {
            $this->setTenant($tenant);
            return $callback();
        } finally {
            // Restore previous tenant context or clear
            if ($previousTenant) {
                $this->setTenant($previousTenant);
            } else {
                $this->clearTenant();
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
                $this->setTenant($previousTenant);
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
        $result = DB::selectOne("SHOW search_path");
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
}
