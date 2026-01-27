<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('routers')) {
            DB::statement('CREATE INDEX IF NOT EXISTS routers_ip_address_index ON routers (ip_address)');
            DB::statement('CREATE INDEX IF NOT EXISTS routers_vpn_ip_index ON routers (vpn_ip)');
            DB::statement('CREATE INDEX IF NOT EXISTS routers_vpn_status_index ON routers (vpn_status)');
            DB::statement('CREATE INDEX IF NOT EXISTS routers_provisioning_stage_index ON routers (provisioning_stage)');
            DB::statement('CREATE INDEX IF NOT EXISTS routers_last_seen_index ON routers (last_seen)');
            DB::statement('CREATE INDEX IF NOT EXISTS routers_last_checked_index ON routers (last_checked)');
            DB::statement('CREATE INDEX IF NOT EXISTS routers_config_token_index ON routers (config_token)');
        }

        if (Schema::hasTable('router_services')) {
            DB::statement('CREATE INDEX IF NOT EXISTS router_services_status_router_id_index ON router_services (status, router_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS router_services_last_checked_at_index ON router_services (last_checked_at)');
        }

        if (Schema::hasTable('wireguard_peers')) {
            DB::statement('CREATE INDEX IF NOT EXISTS wireguard_peers_last_handshake_index ON wireguard_peers (last_handshake)');
        }

        if (Schema::hasTable('access_points')) {
            DB::statement('CREATE INDEX IF NOT EXISTS access_points_last_seen_at_index ON access_points (last_seen_at)');
        }

        if (Schema::hasTable('ap_active_sessions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS ap_active_sessions_connected_at_index ON ap_active_sessions (connected_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS ap_active_sessions_last_activity_at_index ON ap_active_sessions (last_activity_at)');
        }

        if (Schema::hasTable('hotspot_sessions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_sessions_session_start_index ON hotspot_sessions (session_start)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_sessions_session_end_index ON hotspot_sessions (session_end)');
            DB::statement('CREATE INDEX IF NOT EXISTS hotspot_sessions_last_activity_index ON hotspot_sessions (last_activity)');
        }

        if (Schema::hasTable('radius_sessions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS radius_sessions_session_start_index ON radius_sessions (session_start)');
            DB::statement('CREATE INDEX IF NOT EXISTS radius_sessions_expected_end_index ON radius_sessions (expected_end)');
            DB::statement('CREATE INDEX IF NOT EXISTS radius_sessions_session_end_index ON radius_sessions (session_end)');
        }

        if (Schema::hasTable('user_sessions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS user_sessions_start_time_index ON user_sessions (start_time)');
            DB::statement('CREATE INDEX IF NOT EXISTS user_sessions_end_time_index ON user_sessions (end_time)');
            DB::statement('CREATE INDEX IF NOT EXISTS user_sessions_mac_address_index ON user_sessions (mac_address)');
        }

        if (Schema::hasTable('vouchers')) {
            DB::statement('CREATE INDEX IF NOT EXISTS vouchers_router_id_index ON vouchers (router_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS vouchers_used_at_index ON vouchers (used_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS vouchers_expires_at_index ON vouchers (expires_at)');
        }

        if (Schema::hasTable('payments')) {
            DB::statement('CREATE INDEX IF NOT EXISTS payments_router_id_index ON payments (router_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS payments_mac_address_index ON payments (mac_address)');
        }

        if (Schema::hasTable('user_subscriptions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS user_subscriptions_mac_address_index ON user_subscriptions (mac_address)');
            DB::statement('CREATE INDEX IF NOT EXISTS user_subscriptions_start_time_index ON user_subscriptions (start_time)');
        }

        if (Schema::hasTable('vpn_configurations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_configurations_client_ip_index ON vpn_configurations (client_ip)');
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_configurations_server_ip_index ON vpn_configurations (server_ip)');
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_configurations_last_handshake_at_index ON vpn_configurations (last_handshake_at)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('routers')) {
            DB::statement('DROP INDEX IF EXISTS routers_ip_address_index');
            DB::statement('DROP INDEX IF EXISTS routers_vpn_ip_index');
            DB::statement('DROP INDEX IF EXISTS routers_vpn_status_index');
            DB::statement('DROP INDEX IF EXISTS routers_provisioning_stage_index');
            DB::statement('DROP INDEX IF EXISTS routers_last_seen_index');
            DB::statement('DROP INDEX IF EXISTS routers_last_checked_index');
            DB::statement('DROP INDEX IF EXISTS routers_config_token_index');
        }

        if (Schema::hasTable('router_services')) {
            DB::statement('DROP INDEX IF EXISTS router_services_status_router_id_index');
            DB::statement('DROP INDEX IF EXISTS router_services_last_checked_at_index');
        }

        if (Schema::hasTable('wireguard_peers')) {
            DB::statement('DROP INDEX IF EXISTS wireguard_peers_last_handshake_index');
        }

        if (Schema::hasTable('access_points')) {
            DB::statement('DROP INDEX IF EXISTS access_points_last_seen_at_index');
        }

        if (Schema::hasTable('ap_active_sessions')) {
            DB::statement('DROP INDEX IF EXISTS ap_active_sessions_connected_at_index');
            DB::statement('DROP INDEX IF EXISTS ap_active_sessions_last_activity_at_index');
        }

        if (Schema::hasTable('hotspot_sessions')) {
            DB::statement('DROP INDEX IF EXISTS hotspot_sessions_session_start_index');
            DB::statement('DROP INDEX IF EXISTS hotspot_sessions_session_end_index');
            DB::statement('DROP INDEX IF EXISTS hotspot_sessions_last_activity_index');
        }

        if (Schema::hasTable('radius_sessions')) {
            DB::statement('DROP INDEX IF EXISTS radius_sessions_session_start_index');
            DB::statement('DROP INDEX IF EXISTS radius_sessions_expected_end_index');
            DB::statement('DROP INDEX IF EXISTS radius_sessions_session_end_index');
        }

        if (Schema::hasTable('user_sessions')) {
            DB::statement('DROP INDEX IF EXISTS user_sessions_start_time_index');
            DB::statement('DROP INDEX IF EXISTS user_sessions_end_time_index');
            DB::statement('DROP INDEX IF EXISTS user_sessions_mac_address_index');
        }

        if (Schema::hasTable('vouchers')) {
            DB::statement('DROP INDEX IF EXISTS vouchers_router_id_index');
            DB::statement('DROP INDEX IF EXISTS vouchers_used_at_index');
            DB::statement('DROP INDEX IF EXISTS vouchers_expires_at_index');
        }

        if (Schema::hasTable('payments')) {
            DB::statement('DROP INDEX IF EXISTS payments_router_id_index');
            DB::statement('DROP INDEX IF EXISTS payments_mac_address_index');
        }

        if (Schema::hasTable('user_subscriptions')) {
            DB::statement('DROP INDEX IF EXISTS user_subscriptions_mac_address_index');
            DB::statement('DROP INDEX IF EXISTS user_subscriptions_start_time_index');
        }

        if (Schema::hasTable('vpn_configurations')) {
            DB::statement('DROP INDEX IF EXISTS vpn_configurations_client_ip_index');
            DB::statement('DROP INDEX IF EXISTS vpn_configurations_server_ip_index');
            DB::statement('DROP INDEX IF EXISTS vpn_configurations_last_handshake_at_index');
        }
    }
};
