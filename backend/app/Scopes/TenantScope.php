<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Skip tenant scope for system administrators (they can see all data)
        if (auth()->check() && auth()->user()->role === 'system_admin') {
            return;
        }

        // Only apply tenant scope if user is authenticated and has tenant_id
        if (auth()->check() && auth()->user()->tenant_id) {
            $builder->where($model->getQualifiedTenantColumn(), auth()->user()->tenant_id);
        }
    }

    /**
     * Extend the query builder with the needed functions.
     */
    public function extend(Builder $builder): void
    {
        $this->addWithoutTenant($builder);
        $this->addWithTenant($builder);
        $this->addAllTenants($builder);
    }

    /**
     * Add the without-tenant extension to the builder.
     */
    protected function addWithoutTenant(Builder $builder): void
    {
        $builder->macro('withoutTenant', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }

    /**
     * Add the with-tenant extension to the builder.
     */
    protected function addWithTenant(Builder $builder): void
    {
        $builder->macro('withTenant', function (Builder $builder, $tenantId) {
            $model = $builder->getModel();
            return $builder->withoutGlobalScope($this)
                ->where($model->getQualifiedTenantColumn(), $tenantId);
        });
    }

    /**
     * Add the all-tenants extension to the builder.
     */
    protected function addAllTenants(Builder $builder): void
    {
        $builder->macro('allTenants', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}
