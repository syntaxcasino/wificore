<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Support\RouteBinding;
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
        if (RouteBinding::isSentinel($value)) {
            return null;
        }

        $user = request()->user();

        if ($user && $user->tenant_id) {
            $tenant = Tenant::find($user->tenant_id);

            if ($tenant && $tenant->schema_name) {
                $tenantContext = app(\App\Services\TenantContext::class);

                return DB::transaction(
                    function () use ($tenantContext, $tenant, $value, $field) {
                        DB::connection()->recordsHaveBeenModified();

                        // Always call setTenant() inside every transaction even if the
                        // TenantContext object already holds this tenant. Each binding
                        // runs in its own transaction on a potentially different PgBouncer
                        // backend connection; SET LOCAL search_path must be re-issued on
                        // every new connection or it defaults to public (42P01).
                        $tenantContext->setTenant($tenant);
                        Log::debug(static::class . ' route binding: Set tenant context', [
                            'tenant_id'   => $tenant->id,
                            'schema_name' => $tenant->schema_name,
                        ]);

                        return parent::resolveRouteBinding($value, $field);
                    }
                );
            }
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
