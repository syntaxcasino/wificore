<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds comprehensive PostgreSQL indexes for all frequently queried columns.
     * Uses pg_indexes catalog for idempotent index creation (Laravel 11 compatible).
     */
    public function up(): void
    {
        // =========================================================================
        // PUBLIC SCHEMA INDEXES (Landlord Tables)
        // =========================================================================

        // TENANTS TABLE - Subscription enforcement, billing, and lookup queries
        $this->createIndexIfNotExists('public', 'tenants', 'tenants_subscription_status_idx', 
            ['subscription_ends_at', 'is_active', 'is_suspended']);
        
        $this->createIndexIfNotExists('public', 'tenants', 'tenants_billing_lookup_idx', 
            ['last_invoice_at', 'subscription_warning_sent_at']);
        
        $this->createIndexIfNotExists('public', 'tenants', 'tenants_custom_paybill_idx', 
            ['custom_paybill']);

        // USERS TABLE - Multi-tenant user lookup and authentication
        $this->createIndexIfNotExists('public', 'users', 'users_tenant_role_active_idx', 
            ['tenant_id', 'role', 'is_active']);
        
        $this->createIndexIfNotExists('public', 'users', 'users_email_active_idx', 
            ['email', 'is_active']);
        
        $this->createIndexIfNotExists('public', 'users', 'users_last_login_idx', 
            ['last_login_at']);

        // TENANT_VPN_TUNNELS TABLE - VPN status and tenant lookup
        $this->createIndexIfNotExists('public', 'tenant_vpn_tunnels', 'tenant_vpn_tunnels_tenant_status_idx', 
            ['tenant_id', 'status']);
        
        $this->createIndexIfNotExists('public', 'tenant_vpn_tunnels', 'tenant_vpn_tunnels_interface_idx', 
            ['interface_name']);

        // TENANT_PAYMENTS TABLE - Payment status and billing queries
        $this->createIndexIfNotExists('public', 'tenant_payments', 'tenant_payments_tenant_status_idx', 
            ['tenant_id', 'status']);
        
        $this->createIndexIfNotExists('public', 'tenant_payments', 'tenant_payments_created_idx', 
            ['created_at']);

        // PACKAGES TABLE (PUBLIC) - Package type and availability queries
        if (Schema::hasTable('packages')) {
            $this->createIndexIfNotExists('public', 'packages', 'packages_tenant_type_active_idx', 
                ['tenant_id', 'type', 'is_active']);
            
            $this->createIndexIfNotExists('public', 'packages', 'packages_public_lookup_idx', 
                ['is_public', 'is_active', 'type']);
        }

        // RADIUS_USER_SCHEMA_MAPPING TABLE - Username lookup for RADIUS
        if (Schema::hasTable('radius_user_schema_mapping')) {
            $this->createIndexIfNotExists('public', 'radius_user_schema_mapping', 'radius_mapping_username_idx', 
                ['username']);
            
            $this->createIndexIfNotExists('public', 'radius_user_schema_mapping', 'radius_mapping_tenant_idx', 
                ['tenant_id', 'user_type']);
        }

        // MPESA_TRANSACTION_MAPS TABLE - Transaction lookup
        if (Schema::hasTable('mpesa_transaction_maps')) {
            $this->createIndexIfNotExists('public', 'mpesa_transaction_maps', 'mpesa_maps_checkout_idx', 
                ['checkout_request_id']);
            
            $this->createIndexIfNotExists('public', 'mpesa_transaction_maps', 'mpesa_maps_tenant_idx', 
                ['tenant_id']);
        }

        // PERFORMANCE_METRICS TABLE - Time-series queries
        if (Schema::hasTable('performance_metrics')) {
            $this->createIndexIfNotExists('public', 'performance_metrics', 'perf_metrics_recorded_idx', 
                ['recorded_at']);
            
            $this->createIndexIfNotExists('public', 'performance_metrics', 'perf_metrics_type_time_idx', 
                ['metric_type', 'recorded_at']);
        }

        // SYSTEM_HEALTH_METRICS TABLE - Health monitoring queries
        if (Schema::hasTable('system_health_metrics')) {
            $this->createIndexIfNotExists('public', 'system_health_metrics', 'health_metrics_recorded_idx', 
                ['recorded_at']);
        }

        // =========================================================================
        // RADIUS CORE TABLES (Public Schema)
        // =========================================================================

        // RADCHECK TABLE - Authentication queries (high frequency)
        if (Schema::hasTable('radcheck')) {
            $this->createIndexIfNotExists('public', 'radcheck', 'radcheck_username_attribute_idx', 
                ['username', 'attribute']);
        }

        // RADREPLY TABLE - Authorization queries
        if (Schema::hasTable('radreply')) {
            $this->createIndexIfNotExists('public', 'radreply', 'radreply_username_attribute_idx', 
                ['username', 'attribute']);
        }

        // RADACCT TABLE - Accounting queries (very high frequency)
        if (Schema::hasTable('radacct')) {
            $this->createIndexIfNotExists('public', 'radacct', 'radacct_username_start_idx', 
                ['username', 'acctstarttime']);
            
            $this->createIndexIfNotExists('public', 'radacct', 'radacct_username_stop_idx', 
                ['username', 'acctstoptime']);
            
            $this->createIndexIfNotExists('public', 'radacct', 'radacct_nasipaddress_idx', 
                ['nasipaddress']);
            
            $this->createIndexIfNotExists('public', 'radacct', 'radacct_acctsessionid_idx', 
                ['acctsessionid']);
            
            // Partial index for active sessions (acctstoptime IS NULL)
            $this->createPartialIndexIfNotExists('public', 'radacct', 'radacct_active_sessions_idx',
                ['username'], 'acctstoptime IS NULL');
        }

        // RADPOSTAUTH TABLE - Post-auth logging
        if (Schema::hasTable('radpostauth')) {
            $this->createIndexIfNotExists('public', 'radpostauth', 'radpostauth_username_reply_idx', 
                ['username', 'reply']);
            
            $this->createIndexIfNotExists('public', 'radpostauth', 'radpostauth_authdate_idx', 
                ['authdate']);
        }

        // =========================================================================
        // JOBS/QUEUE TABLES - Queue processing
        // =========================================================================

        if (Schema::hasTable('jobs')) {
            $this->createIndexIfNotExists('public', 'jobs', 'jobs_queue_reserved_idx', 
                ['queue', 'reserved_at']);
        }

        if (Schema::hasTable('failed_jobs')) {
            $this->createIndexIfNotExists('public', 'failed_jobs', 'failed_jobs_failed_at_idx', 
                ['failed_at']);
        }

        // =========================================================================
        // TENANT REGISTRATIONS TABLE
        // =========================================================================

        if (Schema::hasTable('tenant_registrations')) {
            $this->createIndexIfNotExists('public', 'tenant_registrations', 'tenant_reg_status_idx', 
                ['status']);
            
            $this->createIndexIfNotExists('public', 'tenant_registrations', 'tenant_reg_email_idx', 
                ['email']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop all indexes created by this migration
        $indexes = [
            // Tenants
            ['public', 'tenants', 'tenants_subscription_status_idx'],
            ['public', 'tenants', 'tenants_billing_lookup_idx'],
            ['public', 'tenants', 'tenants_custom_paybill_idx'],
            // Users
            ['public', 'users', 'users_tenant_role_active_idx'],
            ['public', 'users', 'users_email_active_idx'],
            ['public', 'users', 'users_last_login_idx'],
            // VPN Tunnels
            ['public', 'tenant_vpn_tunnels', 'tenant_vpn_tunnels_tenant_status_idx'],
            ['public', 'tenant_vpn_tunnels', 'tenant_vpn_tunnels_interface_idx'],
            // Tenant Payments
            ['public', 'tenant_payments', 'tenant_payments_tenant_status_idx'],
            ['public', 'tenant_payments', 'tenant_payments_created_idx'],
            // Packages
            ['public', 'packages', 'packages_tenant_type_active_idx'],
            ['public', 'packages', 'packages_public_lookup_idx'],
            // RADIUS mapping
            ['public', 'radius_user_schema_mapping', 'radius_mapping_username_idx'],
            ['public', 'radius_user_schema_mapping', 'radius_mapping_tenant_idx'],
            // MPesa
            ['public', 'mpesa_transaction_maps', 'mpesa_maps_checkout_idx'],
            ['public', 'mpesa_transaction_maps', 'mpesa_maps_tenant_idx'],
            // Metrics
            ['public', 'performance_metrics', 'perf_metrics_recorded_idx'],
            ['public', 'performance_metrics', 'perf_metrics_type_time_idx'],
            ['public', 'system_health_metrics', 'health_metrics_recorded_idx'],
            // RADIUS
            ['public', 'radcheck', 'radcheck_username_attribute_idx'],
            ['public', 'radreply', 'radreply_username_attribute_idx'],
            ['public', 'radacct', 'radacct_username_start_idx'],
            ['public', 'radacct', 'radacct_username_stop_idx'],
            ['public', 'radacct', 'radacct_nasipaddress_idx'],
            ['public', 'radacct', 'radacct_acctsessionid_idx'],
            ['public', 'radacct', 'radacct_active_sessions_idx'],
            ['public', 'radpostauth', 'radpostauth_username_reply_idx'],
            ['public', 'radpostauth', 'radpostauth_authdate_idx'],
            // Jobs
            ['public', 'jobs', 'jobs_queue_reserved_idx'],
            ['public', 'failed_jobs', 'failed_jobs_failed_at_idx'],
            // Tenant registrations
            ['public', 'tenant_registrations', 'tenant_reg_status_idx'],
            ['public', 'tenant_registrations', 'tenant_reg_email_idx'],
        ];

        foreach ($indexes as [$schema, $table, $indexName]) {
            $this->dropIndexIfExists($schema, $table, $indexName);
        }
    }

    /**
     * Create an index if it does not exist (PostgreSQL native).
     * Uses CREATE INDEX IF NOT EXISTS for atomic, idempotent operation.
     */
    private function createIndexIfNotExists(string $schema, string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        // Verify all columns exist
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $columnList = implode(', ', array_map(fn($c) => "\"$c\"", $columns));
        // Use IF NOT EXISTS for atomic idempotent index creation (PostgreSQL 9.5+)
        DB::statement("CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON \"{$schema}\".\"{$table}\" ({$columnList})");
    }

    /**
     * Create a partial index if it does not exist (PostgreSQL native).
     * Uses CREATE INDEX IF NOT EXISTS for atomic, idempotent operation.
     */
    private function createPartialIndexIfNotExists(string $schema, string $table, string $indexName, array $columns, string $whereClause): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $columnList = implode(', ', array_map(fn($c) => "\"$c\"", $columns));
        // Use IF NOT EXISTS for atomic idempotent index creation (PostgreSQL 9.5+)
        DB::statement("CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON \"{$schema}\".\"{$table}\" ({$columnList}) WHERE {$whereClause}");
    }

    /**
     * Drop an index if it exists.
     */
    private function dropIndexIfExists(string $schema, string $table, string $indexName): void
    {
        if (!$this->indexExists($schema, $table, $indexName)) {
            return;
        }

        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".\"{$indexName}\"");
    }

    /**
     * Check if an index exists using PostgreSQL system catalog.
     */
    private function indexExists(string $schema, string $table, string $indexName): bool
    {
        $result = DB::selectOne("
            SELECT 1 FROM pg_indexes 
            WHERE schemaname = ? 
            AND tablename = ? 
            AND indexname = ?
        ", [$schema, $table, $indexName]);
        
        return $result !== null;
    }
};
