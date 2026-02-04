<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes to public schema tables.
     */
    public function up(): void
    {
        // =========================================================
        // Packages - Frequently joined from tenant tables
        // =========================================================
        if (Schema::hasTable('packages')) {
            // Composite for tenant + type filtering (common query pattern)
            DB::statement('CREATE INDEX IF NOT EXISTS packages_tenant_type_status_idx ON packages (tenant_id, type, status)');
            // Name search
            DB::statement('CREATE INDEX IF NOT EXISTS packages_name_idx ON packages (name)');
            // Price sorting
            DB::statement('CREATE INDEX IF NOT EXISTS packages_price_idx ON packages (price)');
            // Created at for sorting
            DB::statement('CREATE INDEX IF NOT EXISTS packages_created_at_idx ON packages (created_at DESC)');
        }

        // =========================================================
        // Users - For user management and auth
        // =========================================================
        if (Schema::hasTable('users')) {
            DB::statement('CREATE INDEX IF NOT EXISTS users_tenant_id_idx ON users (tenant_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS users_email_idx ON users (email)');
            DB::statement('CREATE INDEX IF NOT EXISTS users_created_at_idx ON users (created_at DESC)');
            // Composite for tenant user listing
            DB::statement('CREATE INDEX IF NOT EXISTS users_tenant_role_idx ON users (tenant_id, role)');
        }

        // =========================================================
        // Tenants - For multi-tenant lookups
        // =========================================================
        if (Schema::hasTable('tenants')) {
            DB::statement('CREATE INDEX IF NOT EXISTS tenants_schema_name_idx ON tenants (schema_name)');
            DB::statement('CREATE INDEX IF NOT EXISTS tenants_is_active_idx ON tenants (is_active)');
            DB::statement('CREATE INDEX IF NOT EXISTS tenants_subdomain_idx ON tenants (subdomain)');
        }

        // =========================================================
        // Enable pg_trgm extension for fuzzy text search
        // =========================================================
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Exception $e) {
            // Extension may require superuser privileges
        }
    }

    public function down(): void
    {
        // Packages
        DB::statement('DROP INDEX IF EXISTS packages_tenant_type_status_idx');
        DB::statement('DROP INDEX IF EXISTS packages_name_idx');
        DB::statement('DROP INDEX IF EXISTS packages_price_idx');
        DB::statement('DROP INDEX IF EXISTS packages_created_at_idx');

        // Users
        DB::statement('DROP INDEX IF EXISTS users_tenant_id_idx');
        DB::statement('DROP INDEX IF EXISTS users_email_idx');
        DB::statement('DROP INDEX IF EXISTS users_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS users_tenant_role_idx');

        // Tenants
        DB::statement('DROP INDEX IF EXISTS tenants_schema_name_idx');
        DB::statement('DROP INDEX IF EXISTS tenants_is_active_idx');
        DB::statement('DROP INDEX IF EXISTS tenants_subdomain_idx');
    }
};
