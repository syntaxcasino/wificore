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
     * Adds comprehensive PostgreSQL indexes for tenant schema tables.
     * These indexes are created within the tenant's schema context.
     */
    public function up(): void
    {
        $schema = $this->getTenantSchema();
        
        // =========================================================================
        // ROUTERS TABLE - Router status and lookup queries
        // =========================================================================
        if (Schema::hasTable('routers')) {
            $this->createIndexIfNotExists($schema, 'routers', 'routers_status_active_idx', 
                ['status', 'is_active']);
            
            $this->createIndexIfNotExists($schema, 'routers', 'routers_ip_address_idx', 
                ['ip_address']);
            
            $this->createIndexIfNotExists($schema, 'routers', 'routers_last_seen_idx', 
                ['last_seen_at']);
            
            $this->createIndexIfNotExists($schema, 'routers', 'routers_created_idx', 
                ['created_at']);
        }

        // =========================================================================
        // ROUTER_SERVICES TABLE - Service type queries
        // =========================================================================
        if (Schema::hasTable('router_services')) {
            $this->createIndexIfNotExists($schema, 'router_services', 'router_services_router_type_idx', 
                ['router_id', 'service_type']);
            
            $this->createIndexIfNotExists($schema, 'router_services', 'router_services_status_idx', 
                ['status']);
        }

        // =========================================================================
        // PACKAGES TABLE - Package availability queries
        // =========================================================================
        if (Schema::hasTable('packages')) {
            $this->createIndexIfNotExists($schema, 'packages', 'packages_type_active_idx', 
                ['type', 'is_active']);
            
            $this->createIndexIfNotExists($schema, 'packages', 'packages_router_idx', 
                ['router_id']);
        }

        // =========================================================================
        // PPPOE_USERS TABLE - User status and lookup queries
        // =========================================================================
        if (Schema::hasTable('pppoe_users')) {
            $this->createIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_status_idx', 
                ['status']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_username_idx', 
                ['username']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_phone_idx', 
                ['phone']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_account_idx', 
                ['account_number']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_router_status_idx', 
                ['router_id', 'status']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_package_idx', 
                ['package_id']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_expiry_idx', 
                ['subscription_expires_at']);
            
            // Partial index for active users
            $this->createPartialIndexIfNotExists($schema, 'pppoe_users', 'pppoe_users_active_idx',
                ['username'], "status = 'active'", ['status']);
        }

        // =========================================================================
        // PPPOE_PAYMENTS TABLE - Payment lookup and reporting
        // =========================================================================
        if (Schema::hasTable('pppoe_payments')) {
            $this->createIndexIfNotExists($schema, 'pppoe_payments', 'pppoe_payments_user_status_idx', 
                ['pppoe_user_id', 'status']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_payments', 'pppoe_payments_created_idx', 
                ['created_at']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_payments', 'pppoe_payments_mpesa_idx', 
                ['mpesa_receipt']);
            
            $this->createIndexIfNotExists($schema, 'pppoe_payments', 'pppoe_payments_phone_idx', 
                ['phone']);
        }

        // =========================================================================
        // HOTSPOT_USERS TABLE - Hotspot user queries
        // =========================================================================
        if (Schema::hasTable('hotspot_users')) {
            $this->createIndexIfNotExists($schema, 'hotspot_users', 'hotspot_users_username_idx', 
                ['username']);
            
            $this->createIndexIfNotExists($schema, 'hotspot_users', 'hotspot_users_status_idx', 
                ['status']);
            
            $this->createIndexIfNotExists($schema, 'hotspot_users', 'hotspot_users_mac_idx', 
                ['mac_address']);
            
            $this->createIndexIfNotExists($schema, 'hotspot_users', 'hotspot_users_subscription_idx', 
                ['has_active_subscription', 'subscription_expires_at']);
            
            $this->createIndexIfNotExists($schema, 'hotspot_users', 'hotspot_users_package_idx', 
                ['current_package_id']);
        }

        // =========================================================================
        // HOTSPOT_SESSIONS TABLE - Session tracking
        // Note: hotspot_sessions uses is_active (boolean), NOT status (string)
        // =========================================================================
        if (Schema::hasTable('hotspot_sessions')) {
            $this->createIndexIfNotExists($schema, 'hotspot_sessions', 'hotspot_sessions_user_idx', 
                ['hotspot_user_id']);
            
            $this->createIndexIfNotExists($schema, 'hotspot_sessions', 'hotspot_sessions_is_active_idx', 
                ['is_active']);
            
            $this->createIndexIfNotExists($schema, 'hotspot_sessions', 'hotspot_sessions_session_start_idx', 
                ['session_start']);
            
            // Partial index for active sessions (is_active = true)
            $this->createPartialIndexIfNotExists($schema, 'hotspot_sessions', 'hotspot_sessions_active_idx',
                ['hotspot_user_id'], 'is_active = true', ['is_active']);
        }

        // =========================================================================
        // USER_SUBSCRIPTIONS TABLE - Subscription management
        // =========================================================================
        if (Schema::hasTable('user_subscriptions')) {
            $this->createIndexIfNotExists($schema, 'user_subscriptions', 'user_subs_user_status_idx', 
                ['user_id', 'status']);
            
            $this->createIndexIfNotExists($schema, 'user_subscriptions', 'user_subs_expires_idx', 
                ['expires_at']);
            
            $this->createIndexIfNotExists($schema, 'user_subscriptions', 'user_subs_package_idx', 
                ['package_id']);
        }

        // =========================================================================
        // PAYMENTS TABLE - Payment queries
        // =========================================================================
        if (Schema::hasTable('payments')) {
            $this->createIndexIfNotExists($schema, 'payments', 'payments_user_status_idx', 
                ['user_id', 'status']);
            
            $this->createIndexIfNotExists($schema, 'payments', 'payments_created_idx', 
                ['created_at']);
            
            $this->createIndexIfNotExists($schema, 'payments', 'payments_transaction_idx', 
                ['transaction_id']);
        }

        // =========================================================================
        // RADIUS_SESSIONS TABLE - RADIUS accounting
        // =========================================================================
        if (Schema::hasTable('radius_sessions')) {
            $this->createIndexIfNotExists($schema, 'radius_sessions', 'radius_sessions_user_idx', 
                ['user_id']);
            
            $this->createIndexIfNotExists($schema, 'radius_sessions', 'radius_sessions_session_idx', 
                ['session_id']);
            
            $this->createIndexIfNotExists($schema, 'radius_sessions', 'radius_sessions_start_idx', 
                ['start_time']);
            
            // Partial index for active sessions
            $this->createPartialIndexIfNotExists($schema, 'radius_sessions', 'radius_sessions_active_idx',
                ['user_id'], 'stop_time IS NULL', ['stop_time']);
        }

        // =========================================================================
        // TENANT RADIUS TABLES (radcheck, radreply, radacct)
        // =========================================================================
        if (Schema::hasTable('radcheck')) {
            $this->createIndexIfNotExists($schema, 'radcheck', 'radcheck_username_attr_idx', 
                ['username', 'attribute']);
        }

        if (Schema::hasTable('radreply')) {
            $this->createIndexIfNotExists($schema, 'radreply', 'radreply_username_attr_idx', 
                ['username', 'attribute']);
        }

        if (Schema::hasTable('radacct')) {
            $this->createIndexIfNotExists($schema, 'radacct', 'radacct_user_start_idx', 
                ['username', 'acctstarttime']);
            
            $this->createIndexIfNotExists($schema, 'radacct', 'radacct_session_idx', 
                ['acctsessionid']);
            
            // Partial index for active sessions
            $this->createPartialIndexIfNotExists($schema, 'radacct', 'radacct_active_idx',
                ['username'], 'acctstoptime IS NULL', ['acctstoptime']);
        }

        // =========================================================================
        // ACCESS_POINTS TABLE - AP management
        // =========================================================================
        if (Schema::hasTable('access_points')) {
            $this->createIndexIfNotExists($schema, 'access_points', 'access_points_router_idx', 
                ['router_id']);
            
            $this->createIndexIfNotExists($schema, 'access_points', 'access_points_status_idx', 
                ['status']);
            
            $this->createIndexIfNotExists($schema, 'access_points', 'access_points_mac_idx', 
                ['mac_address']);
        }

        // =========================================================================
        // TODOS TABLE - Task management
        // =========================================================================
        if (Schema::hasTable('todos')) {
            $this->createIndexIfNotExists($schema, 'todos', 'todos_status_priority_idx', 
                ['status', 'priority']);
            
            $this->createIndexIfNotExists($schema, 'todos', 'todos_due_date_idx', 
                ['due_date']);
            
            $this->createIndexIfNotExists($schema, 'todos', 'todos_assigned_idx', 
                ['assigned_to']);
        }

        // =========================================================================
        // EXPENSES TABLE - Financial tracking
        // =========================================================================
        if (Schema::hasTable('expenses')) {
            $this->createIndexIfNotExists($schema, 'expenses', 'expenses_status_idx', 
                ['status']);
            
            $this->createIndexIfNotExists($schema, 'expenses', 'expenses_date_idx', 
                ['expense_date']);
            
            $this->createIndexIfNotExists($schema, 'expenses', 'expenses_department_idx', 
                ['department_id']);
        }

        // =========================================================================
        // REVENUES TABLE - Revenue tracking
        // =========================================================================
        if (Schema::hasTable('revenues')) {
            $this->createIndexIfNotExists($schema, 'revenues', 'revenues_status_idx', 
                ['status']);
            
            $this->createIndexIfNotExists($schema, 'revenues', 'revenues_date_idx', 
                ['revenue_date']);
        }

        // =========================================================================
        // EMPLOYEES TABLE - HR management
        // =========================================================================
        if (Schema::hasTable('employees')) {
            $this->createIndexIfNotExists($schema, 'employees', 'employees_department_idx', 
                ['department_id']);
            
            $this->createIndexIfNotExists($schema, 'employees', 'employees_position_idx', 
                ['position_id']);
            
            $this->createIndexIfNotExists($schema, 'employees', 'employees_status_idx', 
                ['is_active']);
        }

        // =========================================================================
        // DEPARTMENTS TABLE - Organization structure
        // =========================================================================
        if (Schema::hasTable('departments')) {
            $this->createIndexIfNotExists($schema, 'departments', 'departments_active_idx', 
                ['is_active']);
        }

        // =========================================================================
        // TENANT_IP_POOLS TABLE - IP management
        // =========================================================================
        if (Schema::hasTable('tenant_ip_pools')) {
            $this->createIndexIfNotExists($schema, 'tenant_ip_pools', 'ip_pools_router_status_idx', 
                ['router_id', 'status']);
            
            $this->createIndexIfNotExists($schema, 'tenant_ip_pools', 'ip_pools_service_idx', 
                ['service_type']);
        }

        // =========================================================================
        // VPN_CONFIGURATIONS TABLE
        // =========================================================================
        if (Schema::hasTable('vpn_configurations')) {
            $this->createIndexIfNotExists($schema, 'vpn_configurations', 'vpn_config_status_idx', 
                ['status']);
            
            $this->createIndexIfNotExists($schema, 'vpn_configurations', 'vpn_config_router_idx', 
                ['router_id']);
        }

        // =========================================================================
        // SERVICE_VLANS TABLE
        // =========================================================================
        if (Schema::hasTable('service_vlans')) {
            $this->createIndexIfNotExists($schema, 'service_vlans', 'service_vlans_service_idx', 
                ['router_service_id']);
            
            $this->createIndexIfNotExists($schema, 'service_vlans', 'service_vlans_vlan_idx', 
                ['vlan_id']);
        }

        // =========================================================================
        // TENANT_PAYBILL_SETTINGS TABLE
        // =========================================================================
        if (Schema::hasTable('tenant_paybill_settings')) {
            $this->createIndexIfNotExists($schema, 'tenant_paybill_settings', 'paybill_settings_active_idx', 
                ['is_active']);
        }

        // =========================================================================
        // GENIEACS TABLES
        // =========================================================================
        if (Schema::hasTable('genieacs_devices')) {
            $this->createIndexIfNotExists($schema, 'genieacs_devices', 'genieacs_devices_serial_idx', 
                ['serial_number']);
        }

        if (Schema::hasTable('genieacs_tasks')) {
            $this->createIndexIfNotExists($schema, 'genieacs_tasks', 'genieacs_tasks_device_status_idx', 
                ['device_id', 'status']);
        }

        // =========================================================================
        // ROUTER_SNMP_SNAPSHOTS TABLE - Time-series data
        // =========================================================================
        if (Schema::hasTable('router_snmp_snapshots')) {
            $this->createIndexIfNotExists($schema, 'router_snmp_snapshots', 'snmp_snapshots_router_time_idx', 
                ['router_id', 'recorded_at']);
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
            ['routers', 'routers_status_active_idx'],
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
            ['pppoe_users', 'pppoe_users_phone_idx'],
            ['pppoe_users', 'pppoe_users_account_idx'],
            ['pppoe_users', 'pppoe_users_router_status_idx'],
            ['pppoe_users', 'pppoe_users_package_idx'],
            ['pppoe_users', 'pppoe_users_expiry_idx'],
            ['pppoe_users', 'pppoe_users_active_idx'],
            // PPPoE Payments
            ['pppoe_payments', 'pppoe_payments_user_status_idx'],
            ['pppoe_payments', 'pppoe_payments_created_idx'],
            ['pppoe_payments', 'pppoe_payments_mpesa_idx'],
            ['pppoe_payments', 'pppoe_payments_phone_idx'],
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
            ['user_subscriptions', 'user_subs_expires_idx'],
            ['user_subscriptions', 'user_subs_package_idx'],
            // Payments
            ['payments', 'payments_user_status_idx'],
            ['payments', 'payments_created_idx'],
            ['payments', 'payments_transaction_idx'],
            // RADIUS Sessions
            ['radius_sessions', 'radius_sessions_user_idx'],
            ['radius_sessions', 'radius_sessions_session_idx'],
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
            ['expenses', 'expenses_department_idx'],
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
            ['tenant_ip_pools', 'ip_pools_router_status_idx'],
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

    /**
     * Get the current tenant schema name.
     */
    private function getTenantSchema(): string
    {
        $result = DB::selectOne("SELECT current_schema()");
        return $result->current_schema ?? 'public';
    }

    /**
     * Create an index if it does not exist.
     * Uses CREATE INDEX IF NOT EXISTS for atomic, idempotent operation.
     */
    private function createIndexIfNotExists(string $schema, string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

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
     * Create a partial index if it does not exist.
     * Uses CREATE INDEX IF NOT EXISTS for atomic, idempotent operation.
     * 
     * @param string $schema The schema name
     * @param string $table The table name
     * @param string $indexName The index name
     * @param array $columns Columns to index
     * @param string $whereClause The WHERE clause for the partial index
     * @param array $whereColumns Columns referenced in the WHERE clause (for validation)
     */
    private function createPartialIndexIfNotExists(string $schema, string $table, string $indexName, array $columns, string $whereClause, array $whereColumns = []): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        // Validate indexed columns exist
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        // Validate columns referenced in WHERE clause exist
        foreach ($whereColumns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
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
