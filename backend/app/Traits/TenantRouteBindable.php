<?php

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TenantRouteBindable
 *
 * Provides a resolveRouteBinding() override for schema-isolated models.
 *
 * Problem: Laravel's SubstituteBindings middleware resolves route model
 * bindings BEFORE SetTenantContext middleware opens its DB::transaction().
 * Without a transaction, SET LOCAL search_path issued by setTenant() is
 * immediately discarded by PgBouncer transaction pooling before the SELECT
 * runs, causing SQLSTATE 42P01 (relation does not exist).
 *
 * Fix: Open a dedicated DB::transaction() + recordsHaveBeenModified() here
 * so SET LOCAL search_path is scoped to the transaction and all queries
 * within it use the write PDO (sticky-write mode) instead of the read PDO.
 */
trait TenantRouteBindable
{
    public function resolveRouteBinding($value, $field = null)
    {
        $user = request()->user();

        if ($user && $user->tenant_id) {
            $tenant = Tenant::find($user->tenant_id);

            if ($tenant && $tenant->schema_name) {
                $tenantContext = app(\App\Services\TenantContext::class);

                return DB::transaction(
                    function () use ($tenantContext, $tenant, $value, $field) {
                        DB::connection()->recordsHaveBeenModified();

                        if (!$tenantContext->getTenant()) {
                            $tenantContext->setTenant($tenant);
                            Log::debug(static::class . ' route binding: Set tenant context', [
                                'tenant_id'   => $tenant->id,
                                'schema_name' => $tenant->schema_name,
                            ]);
                        }

                        return parent::resolveRouteBinding($value, $field);
                    }
                );
            }
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
