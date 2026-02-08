<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Advisory lock key for this migration.
     * Prevents concurrent execution across multiple containers.
     * Key is a stable int derived from the migration name.
     */
    private const ADVISORY_LOCK_KEY = 206000001; // Unique per migration file

    /**
     * Run the migrations.
     *
     * Adds comprehensive PostgreSQL indexes for public schema tables.
     *
     * SAFETY GUARANTEES:
     * 1. Advisory lock prevents concurrent execution (multi-container safe)
     * 2. pg_class catalog check inside DO $$ block (race-condition safe)
     * 3. Schema-qualified index names prevent cross-schema conflicts
     * 4. Fully idempotent — safe to re-run on partial failures
     */
    public function up(): void
    {
        // Acquire session-level advisory lock — blocks until available.
        // This prevents two containers from running this migration concurrently.
        DB::statement('SELECT pg_advisory_lock(' . self::ADVISORY_LOCK_KEY . ')');

        try {
            // Temporarily raise statement_timeout for index creation on large tables
            DB::statement("SET LOCAL statement_timeout = '300s'");

            // =================================================================
            // TENANTS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'tenants', 'tenants_subscription_status_idx',
                ['subscription_ends_at', 'is_active', 'is_suspended']);
            $this->safeCreateIndex('public', 'tenants', 'tenants_billing_lookup_idx',
                ['last_invoice_at', 'subscription_warning_sent_at']);
            $this->safeCreateIndex('public', 'tenants', 'tenants_custom_paybill_idx',
                ['custom_paybill']);

            // =================================================================
            // USERS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'users', 'users_tenant_role_active_idx',
                ['tenant_id', 'role', 'is_active']);
            $this->safeCreateIndex('public', 'users', 'users_email_active_idx',
                ['email', 'is_active']);
            $this->safeCreateIndex('public', 'users', 'users_last_login_idx',
                ['last_login_at']);

            // =================================================================
            // TENANT_VPN_TUNNELS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'tenant_vpn_tunnels', 'tenant_vpn_tunnels_tenant_status_idx',
                ['tenant_id', 'status']);
            $this->safeCreateIndex('public', 'tenant_vpn_tunnels', 'tenant_vpn_tunnels_interface_idx',
                ['interface_name']);

            // =================================================================
            // TENANT_PAYMENTS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'tenant_payments', 'tenant_payments_tenant_status_idx',
                ['tenant_id', 'status']);
            $this->safeCreateIndex('public', 'tenant_payments', 'tenant_payments_created_idx',
                ['created_at']);

            // =================================================================
            // PACKAGES TABLE (PUBLIC)
            // =================================================================
            $this->safeCreateIndex('public', 'packages', 'packages_tenant_type_active_idx',
                ['tenant_id', 'type', 'is_active']);
            $this->safeCreateIndex('public', 'packages', 'packages_public_lookup_idx',
                ['is_public', 'is_active', 'type']);

            // =================================================================
            // RADIUS_USER_SCHEMA_MAPPING TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'radius_user_schema_mapping', 'radius_mapping_username_idx',
                ['username']);
            $this->safeCreateIndex('public', 'radius_user_schema_mapping', 'radius_mapping_tenant_idx',
                ['tenant_id', 'user_role']);

            // =================================================================
            // MPESA_TRANSACTION_MAPS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'mpesa_transaction_maps', 'mpesa_maps_checkout_idx',
                ['checkout_request_id']);
            $this->safeCreateIndex('public', 'mpesa_transaction_maps', 'mpesa_maps_tenant_idx',
                ['tenant_id']);

            // =================================================================
            // PERFORMANCE_METRICS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'performance_metrics', 'perf_metrics_recorded_idx',
                ['recorded_at']);
            $this->safeCreateIndex('public', 'performance_metrics', 'perf_metrics_tps_time_idx',
                ['tps_current', 'recorded_at']);

            // =================================================================
            // SYSTEM_HEALTH_METRICS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'system_health_metrics', 'health_metrics_recorded_idx',
                ['recorded_at']);

            // =================================================================
            // RADIUS CORE TABLES
            // =================================================================
            $this->safeCreateIndex('public', 'radcheck', 'radcheck_username_attribute_idx',
                ['username', 'attribute']);
            $this->safeCreateIndex('public', 'radreply', 'radreply_username_attribute_idx',
                ['username', 'attribute']);
            $this->safeCreateIndex('public', 'radacct', 'radacct_username_start_idx',
                ['username', 'acctstarttime']);
            $this->safeCreateIndex('public', 'radacct', 'radacct_username_stop_idx',
                ['username', 'acctstoptime']);
            $this->safeCreateIndex('public', 'radacct', 'radacct_nasipaddress_idx',
                ['nasipaddress']);
            $this->safeCreateIndex('public', 'radacct', 'radacct_acctsessionid_idx',
                ['acctsessionid']);
            $this->safeCreatePartialIndex('public', 'radacct', 'radacct_active_sessions_idx',
                ['username'], 'acctstoptime IS NULL');

            // =================================================================
            // RADPOSTAUTH TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'radpostauth', 'radpostauth_username_reply_idx',
                ['username', 'reply']);
            $this->safeCreateIndex('public', 'radpostauth', 'radpostauth_authdate_idx',
                ['authdate']);

            // =================================================================
            // JOBS/QUEUE TABLES
            // =================================================================
            $this->safeCreateIndex('public', 'jobs', 'jobs_queue_reserved_idx',
                ['queue', 'reserved_at']);
            $this->safeCreateIndex('public', 'failed_jobs', 'failed_jobs_failed_at_idx',
                ['failed_at']);

            // =================================================================
            // TENANT REGISTRATIONS TABLE
            // =================================================================
            $this->safeCreateIndex('public', 'tenant_registrations', 'tenant_reg_status_idx',
                ['status']);
            $this->safeCreateIndex('public', 'tenant_registrations', 'tenant_reg_email_idx',
                ['tenant_email']);

        } finally {
            // Always release the advisory lock, even on failure
            DB::statement('SELECT pg_advisory_unlock(' . self::ADVISORY_LOCK_KEY . ')');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexes = [
            ['public', 'tenants_subscription_status_idx'],
            ['public', 'tenants_billing_lookup_idx'],
            ['public', 'tenants_custom_paybill_idx'],
            ['public', 'users_tenant_role_active_idx'],
            ['public', 'users_email_active_idx'],
            ['public', 'users_last_login_idx'],
            ['public', 'tenant_vpn_tunnels_tenant_status_idx'],
            ['public', 'tenant_vpn_tunnels_interface_idx'],
            ['public', 'tenant_payments_tenant_status_idx'],
            ['public', 'tenant_payments_created_idx'],
            ['public', 'packages_tenant_type_active_idx'],
            ['public', 'packages_public_lookup_idx'],
            ['public', 'radius_mapping_username_idx'],
            ['public', 'radius_mapping_tenant_idx'],
            ['public', 'mpesa_maps_checkout_idx'],
            ['public', 'mpesa_maps_tenant_idx'],
            ['public', 'perf_metrics_recorded_idx'],
            ['public', 'perf_metrics_type_time_idx'],
            ['public', 'health_metrics_recorded_idx'],
            ['public', 'radcheck_username_attribute_idx'],
            ['public', 'radreply_username_attribute_idx'],
            ['public', 'radacct_username_start_idx'],
            ['public', 'radacct_username_stop_idx'],
            ['public', 'radacct_nasipaddress_idx'],
            ['public', 'radacct_acctsessionid_idx'],
            ['public', 'radacct_active_sessions_idx'],
            ['public', 'radpostauth_username_reply_idx'],
            ['public', 'radpostauth_authdate_idx'],
            ['public', 'jobs_queue_reserved_idx'],
            ['public', 'failed_jobs_failed_at_idx'],
            ['public', 'tenant_reg_status_idx'],
            ['public', 'tenant_reg_email_idx'],
        ];

        foreach ($indexes as [$schema, $indexName]) {
            DB::statement("DROP INDEX IF EXISTS \"{$schema}\".\"{$indexName}\"");
        }
    }

    // =====================================================================
    // SAFE INDEX CREATION — Race-condition free via pg_class catalog check
    // =====================================================================

    /**
     * Create a regular index using a PL/pgSQL DO block with pg_class check.
     *
     * This is safe against concurrent execution because:
     * - The existence check and CREATE INDEX are in a single SQL statement
     * - The advisory lock at the migration level prevents parallel runs
     * - Falls back gracefully if the index already exists (duplicate_table SQLSTATE 42P07)
     * - Validates all columns exist before attempting index creation
     */
    private function safeCreateIndex(string $schema, string $table, string $indexName, array $columns): void
    {
        if (!$this->tableExists($schema, $table)) {
            return;
        }

        // Validate all columns exist before creating index
        foreach ($columns as $column) {
            if (!$this->columnExists($schema, $table, $column)) {
                Log::warning("Skipping index {$indexName}: column {$column} does not exist in {$schema}.{$table}");
                return;
            }
        }

        $columnList = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));

        DB::statement("
            DO \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = '{$indexName}'
                      AND n.nspname = '{$schema}'
                ) THEN
                    CREATE INDEX \"{$indexName}\" ON \"{$schema}\".\"{$table}\" ({$columnList});
                END IF;
            EXCEPTION WHEN duplicate_table THEN
                -- Another session created it between our check and create — safe to ignore
                NULL;
            END;
            \$\$;
        ");
    }

    /**
     * Create a partial index using a PL/pgSQL DO block with pg_class check.
     */
    private function safeCreatePartialIndex(string $schema, string $table, string $indexName, array $columns, string $whereClause): void
    {
        if (!$this->tableExists($schema, $table)) {
            return;
        }

        // Validate all columns exist before creating index
        foreach ($columns as $column) {
            if (!$this->columnExists($schema, $table, $column)) {
                Log::warning("Skipping partial index {$indexName}: column {$column} does not exist in {$schema}.{$table}");
                return;
            }
        }

        $columnList = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));

        DB::statement("
            DO \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = '{$indexName}'
                      AND n.nspname = '{$schema}'
                ) THEN
                    CREATE INDEX \"{$indexName}\" ON \"{$schema}\".\"{$table}\" ({$columnList}) WHERE {$whereClause};
                END IF;
            EXCEPTION WHEN duplicate_table THEN
                NULL;
            END;
            \$\$;
        ");
    }

    /**
     * Check table existence via pg_class (bypasses search_path issues with PgBouncer).
     */
    private function tableExists(string $schema, string $table): bool
    {
        $result = DB::selectOne("
            SELECT 1 FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = ?
              AND n.nspname = ?
              AND c.relkind IN ('r', 'p')
        ", [$table, $schema]);

        return $result !== null;
    }

    /**
     * Check column existence via information_schema.
     * This is critical for ensuring indexes are only created on existing columns.
     */
    private function columnExists(string $schema, string $table, string $column): bool
    {
        $result = DB::selectOne("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = ?
              AND table_name = ?
              AND column_name = ?
        ", [$schema, $table, $column]);

        return $result !== null;
    }
};
