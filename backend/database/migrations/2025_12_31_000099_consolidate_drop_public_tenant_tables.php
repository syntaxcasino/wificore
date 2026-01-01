<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Consolidates all DROP operations for tenant-specific tables from public schema
     * These tables have been moved to tenant schemas for proper isolation
     */
    public function up(): void
    {
        // Drop all tenant-specific tables from public schema
        $tablesToDrop = [
            // Router tables
            'wireguard_peers',
            'router_services',
            'router_configurations',
            'router_interfaces',
            'routers',
            
            // VPN tables
            'vpn_subnet_allocations',
            'router_vpn_configs',
            'vpn_configurations',
            
            // Package assignment
            'package_router',
            
            // Payment tables
            'mpesa_transaction_maps',
            'tenant_payments',
            
            // Hotspot tables
            'user_sessions',
            'user_subscriptions',
            'hotspot_users',
            'vouchers',
            
            // Access points
            'access_points',
        ];

        foreach ($tablesToDrop as $table) {
            DB::statement("DROP TABLE IF EXISTS public.{$table} CASCADE");
        }
        
        Log::info("Consolidated drop of tenant-specific tables from public schema completed");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback - tables are in tenant schemas now
        Log::info("Rollback skipped - tables exist in tenant schemas");
    }
};
