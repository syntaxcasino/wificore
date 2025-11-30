<?php

namespace App\Traits;

use App\Models\Tenant;
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
            return $callback();
        }

        // Verify tenant exists and is active
        $tenant = Tenant::find($this->tenantId);
        
        if (!$tenant) {
            throw new \Exception("Tenant not found: {$this->tenantId}");
        }

        if (!$tenant->isActive()) {
            throw new \Exception("Tenant is not active: {$this->tenantId}");
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

        try {
            $result = $callback();
        } finally {
            // Clear auth context
            Auth::logout();
        }

        return $result;
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
