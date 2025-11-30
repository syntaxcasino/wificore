<?php

namespace App\Services\MikroTik;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * Base service class that provides tenant awareness for MikroTik services
 * All MikroTik services should extend this class to ensure proper tenant scoping
 */
abstract class TenantAwareService
{
    /**
     * Current tenant context
     */
    protected ?Tenant $tenant = null;

    /**
     * Constructor - automatically sets tenant from authenticated user
     */
    public function __construct()
    {
        $this->setTenantFromAuth();
    }

    /**
     * Set tenant from authenticated user
     */
    protected function setTenantFromAuth(): void
    {
        $user = Auth::user();
        
        if ($user && $user->tenant_id) {
            $this->tenant = Tenant::find($user->tenant_id);
        }
    }

    /**
     * Set tenant explicitly
     */
    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    /**
     * Get current tenant
     */
    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Get tenant ID
     */
    public function getTenantId(): ?string
    {
        return $this->tenant?->id;
    }

    /**
     * Check if tenant is set
     */
    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Ensure tenant is set, throw exception if not
     */
    protected function ensureTenant(): void
    {
        if (!$this->hasTenant()) {
            throw new \RuntimeException('Tenant context is required but not set');
        }
    }
}
