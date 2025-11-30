<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();
        
        // Don't apply scope if:
        // 1. No authenticated user (during login)
        // 2. User is system admin (can see all data)
        // 3. User has no tenant_id (system admin or special user)
        if (!$user || $user->role === 'system_admin' || !$user->tenant_id) {
            return;
        }
        
        // Apply tenant filtering for regular tenant users
        $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
    }
}
