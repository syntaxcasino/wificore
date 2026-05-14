<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Package indexes for optimal CRUD performance
        // NOTE: In newer versions these entities are tenant-scoped and created via
        // `database/migrations/tenant/*`. This base migration must therefore be
        // resilient on fresh installs where the public schema has no such tables.
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                // Composite index for common queries
                $table->index(['status', 'is_active', 'created_at'], 'packages_status_active_created');
                $table->index(['type', 'is_public', 'status'], 'packages_type_public_status');
                $table->index(['is_global', 'status'], 'packages_global_status');

                // Search optimization
                $table->index('name', 'packages_name_index');

                // Sorting optimization
                $table->index('created_at', 'packages_created_at_index');
                $table->index('updated_at', 'packages_updated_at_index');
            });
        }

        // Voucher indexes for optimal CRUD performance
        if (Schema::hasTable('vouchers')) {
            Schema::table('vouchers', function (Blueprint $table) {
                // Composite index for common queries
                $table->index(['status', 'created_at'], 'vouchers_status_created');
                $table->index(['package_id', 'status'], 'vouchers_package_status');
                $table->index(['router_id', 'status'], 'vouchers_router_status');

                // Batch operations
                $table->index('batch_id', 'vouchers_batch_id_index');

                // Search optimization (prefix search)
                $table->index('code', 'vouchers_code_index');

                // Expiration management
                $table->index(['expires_at', 'status'], 'vouchers_expires_status');

                // Sorting optimization
                $table->index('created_at', 'vouchers_created_at_index');
                $table->index('updated_at', 'vouchers_updated_at_index');

                // Usage tracking
                $table->index(['used_by', 'used_at'], 'vouchers_used_by_at');
            });
        }

        // Package-Router relationship optimization
        if (Schema::hasTable('package_router')) {
            Schema::table('package_router', function (Blueprint $table) {
                $table->index(['package_id', 'router_id'], 'package_router_package_router');
                $table->index(['router_id', 'package_id'], 'package_router_router_package');
            });
        }

        // Add partial indexes for PostgreSQL if available
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // Active packages only (only if packages table exists in public schema)
            if ($hasPackagesInPublic->exists ?? false) {
                DB::statement('CREATE INDEX IF NOT EXISTS packages_active_only ON public.packages (created_at) WHERE status = \'active\' AND is_active = true');
            }
            
            // Unused vouchers only
            if (Schema::hasTable('vouchers')) {
                DB::statement('CREATE INDEX IF NOT EXISTS vouchers_unused_only ON vouchers (code, created_at) WHERE status = \'unused\'');
                // Expired vouchers
                DB::statement('CREATE INDEX IF NOT EXISTS vouchers_expired ON vouchers (expires_at) WHERE expires_at < NOW() AND status != \'expired\'');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop package indexes
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropIndex('packages_status_active_created');
                $table->dropIndex('packages_type_public_status');
                $table->dropIndex('packages_global_status');
                $table->dropIndex('packages_name_index');
                $table->dropIndex('packages_created_at_index');
                $table->dropIndex('packages_updated_at_index');
            });
        }

        // Drop voucher indexes
        if (Schema::hasTable('vouchers')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropIndex('vouchers_status_created');
                $table->dropIndex('vouchers_package_status');
                $table->dropIndex('vouchers_router_status');
                $table->dropIndex('vouchers_batch_id_index');
                $table->dropIndex('vouchers_code_index');
                $table->dropIndex('vouchers_expires_status');
                $table->dropIndex('vouchers_created_at_index');
                $table->dropIndex('vouchers_updated_at_index');
                $table->dropIndex('vouchers_used_by_at');
            });
        }

        // Drop package-router indexes
        if (Schema::hasTable('package_router')) {
            Schema::table('package_router', function (Blueprint $table) {
                $table->dropIndex('package_router_package_router');
                $table->dropIndex('package_router_router_package');
            });
        }

        // Drop partial indexes for PostgreSQL
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS packages_active_only');
            DB::statement('DROP INDEX IF EXISTS vouchers_unused_only');
            DB::statement('DROP INDEX IF EXISTS vouchers_expired');
        }
    }
};
