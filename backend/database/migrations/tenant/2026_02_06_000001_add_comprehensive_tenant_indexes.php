<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Advisory lock key for tenant index migration.
     * Uses a different key than the public schema migration to allow parallel execution
     * of public + tenant migrations, but serialize tenant migrations against each other.
     */
    private const ADVISORY_LOCK_KEY = 206000002;

    /**
     * Run the migrations.
     *
     * Adds comprehensive PostgreSQL indexes for tenant schema tables.
     * These indexes are created within the tenant's schema context.
     *
     * SAFETY GUARANTEES:
     * 1. Advisory lock prevents concurrent execution per-tenant
     * 2. pg_class catalog check inside DO $$ block (race-condition safe)
     * 3. Schema-qualified names prevent cross-tenant conflicts
     * 4. Fully idempotent — safe to re-run on partial failures
     */
    public function up(): void
    {
        $schema = $this->getTenantSchema();

        // Acquire advisory lock scoped to this migration + schema
        $lockKey = self::ADVISORY_LOCK_KEY + crc32($schema) % 100000;
        DB::statement("SELECT pg_advisory_lock({$lockKey})");

        try {
            DB::statement("SET LOCAL statement_timeout = '300s'");
        
        // =========================================================================
        // ROUTERS TABLE - Router status and lookup queries
        // =========================================================================
            $this->safeCreateIndex($schema, 'routers', 'routers_status_idx', 
                ['status']);
            
            $this->safeCreateIndex($schema, 'routers', 'routers_ip_address_idx', 
                ['ip_address']);
            
            $this->safeCreateIndex($schema, 'routers', 'routers_last_seen_idx', 
                ['last_seen']);
            
            $this->safeCreateIndex($schema, 'routers', 'routers_created_idx', 
                ['created_at']);

        // =========================================================================
        // ROUTER_SERVICES TABLE - Service type queries
        // =========================================================================
            $this->safeCreateIndex($schema, 'router_services', 'router_services_router_type_idx', 
                ['router_id', 'service_type']);
            
            $this->safeCreateIndex($schema, 'router_services', 'router_services_status_idx', 
                ['status']);

        // =========================================================================
        // PACKAGES TABLE - Package availability queries
        // =========================================================================
            $this->safeCreateIndex($schema, 'packages', 'packages_type_active_idx', 
                ['type', 'is_active']);
            
            $this->safeCreateIndex($schema, 'packages', 'packages_router_idx', 
                ['router_id']);

        // =========================================================================
        // PPPOE_USERS TABLE - User status and lookup queries
        // =========================================================================
            $this->safeCreateIndex($schema, 'pppoe_users', 'pppoe_users_status_idx', 
                ['status']);
            
            $this->safeCreateIndex($schema, 'pppoe_users', 'pppoe_users_username_idx', 
                ['username']);
            
            $this->safeCreateIndex($schema, 'pppoe_users', 'pppoe_users_router_status_idx', 
                ['router_id', 'status']);
            
            $this->safeCreateIndex($schema, 'pppoe_users', 'pppoe_users_package_idx', 
                ['package_id']);
            
            $this->safeCreateIndex($schema, 'pppoe_users', 'pppoe_users_expiry_idx', 
                ['expires_at']);
            
            // Partial index for active users
            $this->safeCreatePartialIndex($schema, 'pppoe_users', 'pppoe_users_active_idx',
                ['username'], "status = 'active'");

        // =========================================================================
        // PPPOE_PAYMENTS TABLE - Payment lookup and reporting
        // =========================================================================
            $this->safeCreateIndex($schema, 'pppoe_payments', 'pppoe_payments_user_status_idx', 
                ['pppoe_user_id', 'status']);
            
            $this->safeCreateIndex($schema, 'pppoe_payments', 'pppoe_payments_date_idx', 
                ['payment_date']);
            
            $this->safeCreateIndex($schema, 'pppoe_payments', 'pppoe_payments_reference_idx', 
                ['payment_reference']);
            
            $this->safeCreateIndex($schema, 'pppoe_payments', 'pppoe_payments_account_idx', 
                ['account_number']);

        // =========================================================================
        // HOTSPOT_USERS TABLE - Hotspot user queries
        // =========================================================================
            $this->safeCreateIndex($schema, 'hotspot_users', 'hotspot_users_username_idx', 
                ['username']);
            
            $this->safeCreateIndex($schema, 'hotspot_users', 'hotspot_users_status_idx', 
                ['status']);
            
            $this->safeCreateIndex($schema, 'hotspot_users', 'hotspot_users_mac_idx', 
                ['mac_address']);
            
            $this->safeCreateIndex($schema, 'hotspot_users', 'hotspot_users_subscription_idx', 
                ['has_active_subscription', 'subscription_expires_at']);
            
            $this->safeCreateIndex($schema, 'hotspot_users', 'hotspot_users_package_idx', 
                ['package_id']);

        // =========================================================================
        // HOTSPOT_SESSIONS TABLE - Session tracking
        // Note: hotspot_sessions uses is_active (boolean), NOT status (string)
        // =========================================================================
            $this->safeCreateIndex($schema, 'hotspot_sessions', 'hotspot_sessions_user_idx', 
                ['hotspot_user_id']);
            
            $this->safeCreateIndex($schema, 'hotspot_sessions', 'hotspot_sessions_is_active_idx', 
                ['is_active']);
            
            $this->safeCreateIndex($schema, 'hotspot_sessions', 'hotspot_sessions_session_start_idx', 
                ['session_start']);
            
            // Partial index for active sessions (is_active = true)
            $this->safeCreatePartialIndex($schema, 'hotspot_sessions', 'hotspot_sessions_active_idx',
                ['hotspot_user_id'], 'is_active = true');

        // =========================================================================
        // USER_SUBSCRIPTIONS TABLE - Subscription management
        // =========================================================================
            $this->safeCreateIndex($schema, 'user_subscriptions', 'user_subs_user_status_idx', 
                ['user_id', 'status']);
            
            $this->safeCreateIndex($schema, 'user_subscriptions', 'user_subs_end_time_idx', 
                ['end_time']);
            
            $this->safeCreateIndex($schema, 'user_subscriptions', 'user_subs_package_idx', 
                ['package_id']);

        // =========================================================================
        // PAYMENTS TABLE - Payment queries
        // =========================================================================
            $this->safeCreateIndex($schema, 'payments', 'payments_user_status_idx', 
                ['user_id', 'status']);
            
            $this->safeCreateIndex($schema, 'payments', 'payments_created_idx', 
                ['created_at']);
            
            $this->safeCreateIndex($schema, 'payments', 'payments_transaction_idx', 
                ['transaction_id']);

        // =========================================================================
        // RADIUS_SESSIONS TABLE - RADIUS accounting
        // =========================================================================
            $this->safeCreateIndex($schema, 'radius_sessions', 'radius_sessions_user_idx', 
                ['hotspot_user_id']);
            
            $this->safeCreateIndex($schema, 'radius_sessions', 'radius_sessions_username_idx', 
                ['username']);
            
            $this->safeCreateIndex($schema, 'radius_sessions', 'radius_sessions_start_idx', 
                ['session_start']);
            
            // Partial index for active sessions
            $this->safeCreatePartialIndex($schema, 'radius_sessions', 'radius_sessions_active_idx',
                ['hotspot_user_id'], 'session_end IS NULL');

        // =========================================================================
        // TENANT RADIUS TABLES (radcheck, radreply, radacct)
        // =========================================================================
            $this->safeCreateIndex($schema, 'radcheck', 'radcheck_username_attr_idx', 
                ['username', 'attribute']);
            $this->safeCreateIndex($schema, 'radreply', 'radreply_username_attr_idx', 
                ['username', 'attribute']);
            $this->safeCreateIndex($schema, 'radacct', 'radacct_user_start_idx', 
                ['username', 'acctstarttime']);
            
            $this->safeCreateIndex($schema, 'radacct', 'radacct_session_idx', 
                ['acctsessionid']);
            
            // Partial index for active sessions
            $this->safeCreatePartialIndex($schema, 'radacct', 'radacct_active_idx',
                ['username'], 'acctstoptime IS NULL');

        // =========================================================================
        // ACCESS_POINTS TABLE - AP management
        // =========================================================================
            $this->safeCreateIndex($schema, 'access_points', 'access_points_router_idx', 
                ['router_id']);
            
            $this->safeCreateIndex($schema, 'access_points', 'access_points_status_idx', 
                ['status']);
            
            $this->safeCreateIndex($schema, 'access_points', 'access_points_mac_idx', 
                ['mac_address']);

        // =========================================================================
        // TODOS TABLE - Task management
        // =========================================================================
            $this->safeCreateIndex($schema, 'todos', 'todos_status_priority_idx', 
                ['status', 'priority']);
            
            $this->safeCreateIndex($schema, 'todos', 'todos_due_date_idx', 
                ['due_date']);
            
            $this->safeCreateIndex($schema, 'todos', 'todos_assigned_idx', 
                ['user_id']);

        // =========================================================================
        // EXPENSES TABLE - Financial tracking
        // =========================================================================
            $this->safeCreateIndex($schema, 'expenses', 'expenses_status_idx', 
                ['status']);
            
            $this->safeCreateIndex($schema, 'expenses', 'expenses_date_idx', 
                ['expense_date']);
            
            $this->safeCreateIndex($schema, 'expenses', 'expenses_category_idx', 
                ['category']);

        // =========================================================================
        // REVENUES TABLE - Revenue tracking
        // =========================================================================
            $this->safeCreateIndex($schema, 'revenues', 'revenues_status_idx', 
                ['status']);
            
            $this->safeCreateIndex($schema, 'revenues', 'revenues_date_idx', 
                ['revenue_date']);

        // =========================================================================
        // EMPLOYEES TABLE - HR management
        // =========================================================================
            $this->safeCreateIndex($schema, 'employees', 'employees_department_idx', 
                ['department_id']);
            
            $this->safeCreateIndex($schema, 'employees', 'employees_position_idx', 
                ['position_id']);
            
            $this->safeCreateIndex($schema, 'employees', 'employees_status_idx', 
                ['is_active']);

        // =========================================================================
        // DEPARTMENTS TABLE - Organization structure
        // =========================================================================
            $this->safeCreateIndex($schema, 'departments', 'departments_active_idx', 
                ['is_active']);

        // =========================================================================
        // TENANT_IP_POOLS TABLE - IP management
        // =========================================================================
            $this->safeCreateIndex($schema, 'tenant_ip_pools', 'ip_pools_tenant_status_idx', 
                ['tenant_id', 'status']);
            
            $this->safeCreateIndex($schema, 'tenant_ip_pools', 'ip_pools_service_idx', 
                ['service_type']);

        // =========================================================================
        // VPN_CONFIGURATIONS TABLE
        // =========================================================================
            $this->safeCreateIndex($schema, 'vpn_configurations', 'vpn_config_status_idx', 
                ['status']);
            
            $this->safeCreateIndex($schema, 'vpn_configurations', 'vpn_config_router_idx', 
                ['router_id']);

        // =========================================================================
        // SERVICE_VLANS TABLE
        // =========================================================================
            $this->safeCreateIndex($schema, 'service_vlans', 'service_vlans_service_idx', 
                ['router_service_id']);
            
            $this->safeCreateIndex($schema, 'service_vlans', 'service_vlans_vlan_idx', 
                ['vlan_id']);

        // =========================================================================
        // TENANT_PAYBILL_SETTINGS TABLE
        // =========================================================================
            $this->safeCreateIndex($schema, 'tenant_paybill_settings', 'paybill_settings_active_idx', 
                ['is_active']);

        // =========================================================================
        // GENIEACS TABLES
        // =========================================================================
            $this->safeCreateIndex($schema, 'genieacs_devices', 'genieacs_devices_serial_idx', 
                ['serial_number']);
            $this->safeCreateIndex($schema, 'genieacs_tasks', 'genieacs_tasks_device_status_idx', 
                ['device_id', 'status']);

        // =========================================================================
        // ROUTER_SNMP_SNAPSHOTS TABLE - Time-series data
        // =========================================================================
            $this->safeCreateIndex($schema, 'router_snmp_snapshots', 'snmp_snapshots_router_time_idx', 
                ['router_id', 'collected_at']);

        } finally {
            // Always release advisory lock
            DB::statement("SELECT pg_advisory_unlock({$lockKey})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = $this->getTenantSchema();
        
        $indexes = [
            // Routers
            ['routers', 'routers_status_idx'],
            ['routers', 'routers_ip_address_idx'],
            ['routers', 'routers_last_seen_idx'],
            ['routers', 'routers_created_idx'],
            // Router Services
            ['router_services', 'router_services_router_type_idx'],
            ['router_services', 'router_services_status_idx'],
            // Packages
            ['packages', 'packages_type_active_idx'],
            ['packages', 'packages_router_idx'],
            // PPPoE Users
            ['pppoe_users', 'pppoe_users_status_idx'],
            ['pppoe_users', 'pppoe_users_username_idx'],
            ['pppoe_users', 'pppoe_users_router_status_idx'],
            ['pppoe_users', 'pppoe_users_package_idx'],
            ['pppoe_users', 'pppoe_users_expiry_idx'],
            ['pppoe_users', 'pppoe_users_active_idx'],
            // PPPoE Payments
            ['pppoe_payments', 'pppoe_payments_user_status_idx'],
            ['pppoe_payments', 'pppoe_payments_date_idx'],
            ['pppoe_payments', 'pppoe_payments_reference_idx'],
            ['pppoe_payments', 'pppoe_payments_account_idx'],
            // Hotspot Users
            ['hotspot_users', 'hotspot_users_username_idx'],
            ['hotspot_users', 'hotspot_users_status_idx'],
            ['hotspot_users', 'hotspot_users_mac_idx'],
            ['hotspot_users', 'hotspot_users_subscription_idx'],
            ['hotspot_users', 'hotspot_users_package_idx'],
            // Hotspot Sessions
            ['hotspot_sessions', 'hotspot_sessions_user_idx'],
            ['hotspot_sessions', 'hotspot_sessions_is_active_idx'],
            ['hotspot_sessions', 'hotspot_sessions_session_start_idx'],
            ['hotspot_sessions', 'hotspot_sessions_active_idx'],
            // User Subscriptions
            ['user_subscriptions', 'user_subs_user_status_idx'],
            ['user_subscriptions', 'user_subs_end_time_idx'],
            ['user_subscriptions', 'user_subs_package_idx'],
            // Payments
            ['payments', 'payments_user_status_idx'],
            ['payments', 'payments_created_idx'],
            ['payments', 'payments_transaction_idx'],
            // RADIUS Sessions
            ['radius_sessions', 'radius_sessions_user_idx'],
            ['radius_sessions', 'radius_sessions_username_idx'],
            ['radius_sessions', 'radius_sessions_start_idx'],
            ['radius_sessions', 'radius_sessions_active_idx'],
            // Tenant RADIUS
            ['radcheck', 'radcheck_username_attr_idx'],
            ['radreply', 'radreply_username_attr_idx'],
            ['radacct', 'radacct_user_start_idx'],
            ['radacct', 'radacct_session_idx'],
            ['radacct', 'radacct_active_idx'],
            // Access Points
            ['access_points', 'access_points_router_idx'],
            ['access_points', 'access_points_status_idx'],
            ['access_points', 'access_points_mac_idx'],
            // Todos
            ['todos', 'todos_status_priority_idx'],
            ['todos', 'todos_due_date_idx'],
            ['todos', 'todos_assigned_idx'],
            // Expenses
            ['expenses', 'expenses_status_idx'],
            ['expenses', 'expenses_date_idx'],
            ['expenses', 'expenses_category_idx'],
            // Revenues
            ['revenues', 'revenues_status_idx'],
            ['revenues', 'revenues_date_idx'],
            // Employees
            ['employees', 'employees_department_idx'],
            ['employees', 'employees_position_idx'],
            ['employees', 'employees_status_idx'],
            // Departments
            ['departments', 'departments_active_idx'],
            // IP Pools
            ['tenant_ip_pools', 'ip_pools_tenant_status_idx'],
            ['tenant_ip_pools', 'ip_pools_service_idx'],
            // VPN
            ['vpn_configurations', 'vpn_config_status_idx'],
            ['vpn_configurations', 'vpn_config_router_idx'],
            // Service VLANs
            ['service_vlans', 'service_vlans_service_idx'],
            ['service_vlans', 'service_vlans_vlan_idx'],
            // Paybill
            ['tenant_paybill_settings', 'paybill_settings_active_idx'],
            // GenieACS
            ['genieacs_devices', 'genieacs_devices_serial_idx'],
            ['genieacs_tasks', 'genieacs_tasks_device_status_idx'],
            // SNMP
            ['router_snmp_snapshots', 'snmp_snapshots_router_time_idx'],
        ];

        foreach ($indexes as [$table, $indexName]) {
            $this->dropIndexIfExists($schema, $table, $indexName);
        }
    }

    // =====================================================================
    // SAFE HELPER METHODS — Race-condition free via pg_class catalog check
    // =====================================================================

    /**
     * Get the current tenant schema name from the active search_path.
     */
    private function getTenantSchema(): string
    {
        $result = DB::selectOne("SELECT current_schema()");
        return $result->current_schema ?? 'public';
    }

    /**
     * Create a regular index using a PL/pgSQL DO block with pg_class check.
     *
     * Safe against concurrent execution:
     * - Existence check and CREATE INDEX in a single SQL statement
     * - EXCEPTION handler catches duplicate_table if another session races
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
                \Log::warning("Skipping index {$indexName}: column {$column} does not exist in {$schema}.{$table}");
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
                \Log::warning("Skipping partial index {$indexName}: column {$column} does not exist in {$schema}.{$table}");
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
     * Drop an index if it exists (safe for rollback).
     */
    private function dropIndexIfExists(string $schema, string $table, string $indexName): void
    {
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".\"{$indexName}\"");
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
