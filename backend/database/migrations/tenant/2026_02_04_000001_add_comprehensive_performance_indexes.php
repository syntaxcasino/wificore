<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add comprehensive indexes for optimized tenant data loading.
     * 
     * This migration adds indexes on frequently filtered, sorted, and joined columns
     * to dramatically improve query performance for UI data loading.
     */
    public function up(): void
    {
        // =========================================================
        // PPPoE Users - High priority for PPPoE management UI
        // =========================================================
        if (Schema::hasTable('pppoe_users')) {
            // Composite index for common list queries (status + router filtering)
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_users_status_router_id_idx ON pppoe_users (status, router_id)');
            // Composite index for payment-related queries
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_users_payment_status_expires_idx ON pppoe_users (payment_status, expires_at)');
            // For sorting by creation date
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_users_created_at_idx ON pppoe_users (created_at DESC)');
            // For username search (partial/prefix matching)
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_users_username_trgm_idx ON pppoe_users USING gin (username gin_trgm_ops)');
            // Soft deletes filtering
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_users_deleted_at_idx ON pppoe_users (deleted_at) WHERE deleted_at IS NULL');
        }

        // =========================================================
        // Hotspot Users - For hotspot management UI
        // =========================================================
        if (Schema::hasTable('hotspot_users')) {
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_status_idx ON hotspot_users (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_is_active_idx ON hotspot_users (is_active)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_has_active_subscription_idx ON hotspot_users (has_active_subscription)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_last_login_at_idx ON hotspot_users (last_login_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_mac_address_idx ON hotspot_users (mac_address)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_created_at_idx ON hotspot_users (created_at DESC)');
            // Composite for active subscription filtering
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_active_sub_expires_idx ON hotspot_users (has_active_subscription, subscription_expires_at)');
            // Soft deletes
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_users_deleted_at_idx ON hotspot_users (deleted_at) WHERE deleted_at IS NULL');
        }

        // =========================================================
        // Hotspot Sessions - For session monitoring
        // =========================================================
        if (Schema::hasTable('hotspot_sessions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_sessions_mac_address_idx ON hotspot_sessions (mac_address)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_sessions_session_start_idx ON hotspot_sessions (session_start DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_sessions_session_end_idx ON hotspot_sessions (session_end)');
            // Composite for active session queries
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_sessions_active_user_idx ON hotspot_sessions (is_active, hotspot_user_id)');
        }

        // =========================================================
        // RADIUS Accounting (radacct) - Critical for session queries
        // =========================================================
        if (Schema::hasTable('radacct')) {
            // MAC address (calling station ID) - very frequent lookups
            DB::statement('CREATE INDEX IF NOT EXISTS radacct_callingstationid_idx ON radacct (callingstationid)');
            // Framed IP for IP-based lookups
            DB::statement('CREATE INDEX IF NOT EXISTS radacct_framedipaddress_idx ON radacct (framedipaddress)');
            // Termination cause for analytics
            DB::statement('CREATE INDEX IF NOT EXISTS radacct_acctterminatecause_idx ON radacct (acctterminatecause)');
            // Active sessions (no stop time)
            DB::statement('CREATE INDEX IF NOT EXISTS radacct_active_sessions_idx ON radacct (acctstoptime) WHERE acctstoptime IS NULL');
            // Composite for user session history
            DB::statement('CREATE INDEX IF NOT EXISTS radacct_username_start_idx ON radacct (username, acctstarttime DESC)');
        }

        // =========================================================
        // RADIUS Check/Reply - Frequent authentication lookups
        // =========================================================
        if (Schema::hasTable('radcheck')) {
            // Single column username index for faster auth
            DB::statement('CREATE INDEX IF NOT EXISTS radcheck_username_idx ON radcheck (username)');
        }
        
        if (Schema::hasTable('radreply')) {
            DB::statement('CREATE INDEX IF NOT EXISTS radreply_username_idx ON radreply (username)');
        }

        // =========================================================
        // Routers - For router list and management UI
        // =========================================================
        if (Schema::hasTable('routers')) {
            // Name search
            DB::statement('CREATE INDEX IF NOT EXISTS routers_name_idx ON routers (name)');
            // Creation date sorting
            DB::statement('CREATE INDEX IF NOT EXISTS routers_created_at_idx ON routers (created_at DESC)');
            // Composite for status filtering with sorting
            DB::statement('CREATE INDEX IF NOT EXISTS routers_status_created_idx ON routers (status, created_at DESC)');
            // Device type filtering
            DB::statement('CREATE INDEX IF NOT EXISTS routers_device_type_idx ON routers (device_type)');
            // SNMP enabled filtering
            DB::statement('CREATE INDEX IF NOT EXISTS routers_snmp_enabled_idx ON routers (snmp_enabled)');
        }

        // =========================================================
        // Router Services - For service management
        // =========================================================
        if (Schema::hasTable('router_services')) {
            DB::statement('CREATE INDEX IF NOT EXISTS router_services_created_at_idx ON router_services (created_at DESC)');
            // Composite for active services per router
            DB::statement('CREATE INDEX IF NOT EXISTS router_services_router_status_idx ON router_services (router_id, status)');
        }

        // =========================================================
        // Vouchers - For voucher management UI
        // =========================================================
        if (Schema::hasTable('vouchers')) {
            DB::statement('CREATE INDEX IF NOT EXISTS vouchers_created_at_idx ON vouchers (created_at DESC)');
            // Composite for unused voucher queries
            DB::statement('CREATE INDEX IF NOT EXISTS vouchers_status_package_idx ON vouchers (status, package_id)');
            // Soft deletes
            DB::statement('CREATE INDEX IF NOT EXISTS vouchers_deleted_at_idx ON vouchers (deleted_at) WHERE deleted_at IS NULL');
        }

        // =========================================================
        // Payments - For payment history and reporting
        // =========================================================
        if (Schema::hasTable('payments')) {
            // Already has good indexes, add composite
            DB::statement('CREATE INDEX IF NOT EXISTS payments_status_created_idx ON payments (status, created_at DESC)');
            // Package filtering
            DB::statement('CREATE INDEX IF NOT EXISTS payments_package_id_idx ON payments (package_id)');
        }

        // =========================================================
        // User Subscriptions - For subscription management
        // =========================================================
        if (Schema::hasTable('user_subscriptions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS user_subscriptions_package_id_idx ON user_subscriptions (package_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS user_subscriptions_created_at_idx ON user_subscriptions (created_at DESC)');
            // Active subscriptions
            DB::statement('CREATE INDEX IF NOT EXISTS user_subscriptions_active_idx ON user_subscriptions (status, end_time) WHERE status = \'active\'');
        }

        // =========================================================
        // Radius Sessions - For session monitoring
        // =========================================================
        if (Schema::hasTable('radius_sessions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS radius_sessions_created_at_idx ON radius_sessions (created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS radius_sessions_mac_address_idx ON radius_sessions (mac_address)');
            // Active sessions composite
            DB::statement('CREATE INDEX IF NOT EXISTS radius_sessions_active_user_idx ON radius_sessions (status, hotspot_user_id) WHERE status = \'active\'');
        }

        // =========================================================
        // VPN Configurations - For VPN management
        // =========================================================
        if (Schema::hasTable('vpn_configurations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_configurations_router_id_idx ON vpn_configurations (router_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_configurations_status_idx ON vpn_configurations (status)');
        }

        // =========================================================
        // Access Points - For AP management UI
        // =========================================================
        if (Schema::hasTable('access_points')) {
            DB::statement('CREATE INDEX IF NOT EXISTS access_points_created_at_idx ON access_points (created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS access_points_mac_address_idx ON access_points (mac_address)');
            // Composite for status filtering
            DB::statement('CREATE INDEX IF NOT EXISTS access_points_status_router_idx ON access_points (status, router_id)');
        }

        // =========================================================
        // PPPoE Payments - For PPPoE billing
        // =========================================================
        if (Schema::hasTable('pppoe_payments')) {
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_payments_pppoe_user_id_idx ON pppoe_payments (pppoe_user_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_payments_status_idx ON pppoe_payments (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_payments_created_at_idx ON pppoe_payments (created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS pppoe_payments_payment_date_idx ON pppoe_payments (payment_date DESC)');
        }

        // =========================================================
        // Enable pg_trgm extension for text search (if not exists)
        // =========================================================
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Exception $e) {
            // Extension may already exist or require superuser
        }
    }

    public function down(): void
    {
        // PPPoE Users
        DB::statement('DROP INDEX IF EXISTS pppoe_users_status_router_id_idx');
        DB::statement('DROP INDEX IF EXISTS pppoe_users_payment_status_expires_idx');
        DB::statement('DROP INDEX IF EXISTS pppoe_users_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS pppoe_users_username_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS pppoe_users_deleted_at_idx');

        // Hotspot Users
        DB::statement('DROP INDEX IF EXISTS hotspot_users_status_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_users_is_active_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_users_has_active_subscription_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_users_last_login_at_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_users_mac_address_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_users_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_users_active_sub_expires_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_users_deleted_at_idx');

        // Hotspot Sessions
        DB::statement('DROP INDEX IF EXISTS hotspot_sessions_mac_address_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_sessions_session_start_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_sessions_session_end_idx');
        DB::statement('DROP INDEX IF EXISTS hotspot_sessions_active_user_idx');

        // RADIUS Accounting
        DB::statement('DROP INDEX IF EXISTS radacct_callingstationid_idx');
        DB::statement('DROP INDEX IF EXISTS radacct_framedipaddress_idx');
        DB::statement('DROP INDEX IF EXISTS radacct_acctterminatecause_idx');
        DB::statement('DROP INDEX IF EXISTS radacct_active_sessions_idx');
        DB::statement('DROP INDEX IF EXISTS radacct_username_start_idx');

        // RADIUS Check/Reply
        DB::statement('DROP INDEX IF EXISTS radcheck_username_idx');
        DB::statement('DROP INDEX IF EXISTS radreply_username_idx');

        // Routers
        DB::statement('DROP INDEX IF EXISTS routers_name_idx');
        DB::statement('DROP INDEX IF EXISTS routers_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS routers_status_created_idx');
        DB::statement('DROP INDEX IF EXISTS routers_device_type_idx');
        DB::statement('DROP INDEX IF EXISTS routers_snmp_enabled_idx');

        // Router Services
        DB::statement('DROP INDEX IF EXISTS router_services_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS router_services_router_status_idx');

        // Vouchers
        DB::statement('DROP INDEX IF EXISTS vouchers_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS vouchers_status_package_idx');
        DB::statement('DROP INDEX IF EXISTS vouchers_deleted_at_idx');

        // Payments
        DB::statement('DROP INDEX IF EXISTS payments_status_created_idx');
        DB::statement('DROP INDEX IF EXISTS payments_package_id_idx');

        // User Subscriptions
        DB::statement('DROP INDEX IF EXISTS user_subscriptions_package_id_idx');
        DB::statement('DROP INDEX IF EXISTS user_subscriptions_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS user_subscriptions_active_idx');

        // Radius Sessions
        DB::statement('DROP INDEX IF EXISTS radius_sessions_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS radius_sessions_mac_address_idx');
        DB::statement('DROP INDEX IF EXISTS radius_sessions_active_user_idx');

        // VPN Configurations
        DB::statement('DROP INDEX IF EXISTS vpn_configurations_router_id_idx');
        DB::statement('DROP INDEX IF EXISTS vpn_configurations_status_idx');

        // Access Points
        DB::statement('DROP INDEX IF EXISTS access_points_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS access_points_mac_address_idx');
        DB::statement('DROP INDEX IF EXISTS access_points_status_router_idx');

        // PPPoE Payments
        DB::statement('DROP INDEX IF EXISTS pppoe_payments_pppoe_user_id_idx');
        DB::statement('DROP INDEX IF EXISTS pppoe_payments_status_idx');
        DB::statement('DROP INDEX IF EXISTS pppoe_payments_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS pppoe_payments_payment_date_idx');
    }
};
