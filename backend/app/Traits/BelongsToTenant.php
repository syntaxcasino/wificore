<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use RuntimeException;

trait BelongsToTenant
{
    /**
     * Boot the trait
     */
    protected static function bootBelongsToTenant(): void
    {
        // Add global scope to automatically filter by tenant
        static::addGlobalScope(new TenantScope);

        // Automatically set tenant_id when creating
        static::creating(function ($model) {
            // Skip for system admins (they don't belong to a tenant).
            if (auth()->check() && auth()->user()->role === 'system_admin') {
                return;
            }

            if (! $model->tenant_id && auth()->check()) {
                $model->tenant_id = auth()->user()->tenant_id;
            }

            if (! $model->tenant_id && method_exists($model, 'requiresTenantContext') && ! $model->requiresTenantContext()) {
                return;
            }

            if (! $model->tenant_id) {
                Log::critical('Tenant context missing during tenant-scoped model creation', [
                    'model' => get_class($model),
                    'authenticated' => auth()->check(),
                    'authenticated_user_id' => auth()->id(),
                    'authenticated_tenant_id' => auth()->user()?->tenant_id,
                ]);

                throw new RuntimeException(
                    sprintf(
                        'Tenant context is required for %s creation. Missing tenant_id and authenticated tenant user.',
                        get_class($model)
                    )
                );
            }
        });
    }


    /**
     * Whether this model must always have a tenant context at creation time.
     */
    public function requiresTenantContext(): bool
    {
        return true;
    }

    /**
     * Get the tenant that owns the model
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope to get records for a specific tenant
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where($this->getQualifiedTenantColumn(), $tenantId);
    }

    /**
     * Get the fully qualified tenant column name
     */
    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable() . '.tenant_id';
    }

    /**
     * Get all records without tenant scope
     */
    public function scopeWithoutTenantScope($query)
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
