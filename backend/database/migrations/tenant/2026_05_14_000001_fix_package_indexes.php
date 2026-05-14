<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix package-related indexes.
 *
 * Historical context:
 * - Packages are assigned to routers via the tenant pivot table `package_router`,
 *   not via a `packages.router_id` column.
 * - Older deployments attempted to create `packages_router_idx` on a non-existent
 *   `packages.router_id`, which produced warnings and left the real lookup paths
 *   under-indexed.
 *
 * This migration:
 * - Drops the bogus index if it exists
 * - Adds the correct pivot index for router-scoped package lookups
 * - Adds an index that matches the common public-packages query pattern
 *
 * Uses CONCURRENTLY to avoid blocking writes on live systems.
 */
return new class extends Migration
{
    // CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction block.
    public $withinTransaction = false;

    public function up(): void
    {
        // If the tenant schema isn't fully initialized yet, do nothing.
        if (!Schema::hasTable('packages')) {
            return;
        }

        // Drop legacy/incorrect index if it exists.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS packages_router_idx');

        // Index the pivot table used for package-to-router assignment.
        if (Schema::hasTable('package_router')
            && Schema::hasColumn('package_router', 'router_id')
            && Schema::hasColumn('package_router', 'package_id')) {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS package_router_router_package_idx ' .
                'ON package_router (router_id, package_id)'
            );
        }

        // Index to match the common captive-portal / public-packages query.
        if (Schema::hasColumn('packages', 'type')
            && Schema::hasColumn('packages', 'is_active')
            && Schema::hasColumn('packages', 'is_public')
            && Schema::hasColumn('packages', 'is_global')
            && Schema::hasColumn('packages', 'price')) {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS packages_type_active_public_global_price_idx ' .
                'ON packages (type, is_active, is_public, is_global, price)'
            );
        }
    }

    public function down(): void
    {
        // Best-effort rollback.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS packages_type_active_public_global_price_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS package_router_router_package_idx');
    }
};

