<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            // Skip for system admins (they don't belong to a tenant)
            if (auth()->check() && auth()->user()->role === 'system_admin') {
                return;
            }

            if (!$model->tenant_id && auth()->check()) {
                $model->tenant_id = auth()->user()->tenant_id;
            }

            // Fallback to default tenant if no tenant_id is set
            if (!$model->tenant_id) {
                $defaultTenant = Tenant::where('slug', 'default')->first();
                if ($defaultTenant) {
                    $model->tenant_id = $defaultTenant->id;
                }
            }
        });
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
