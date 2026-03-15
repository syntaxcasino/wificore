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
     * This migration adds indexes to router-related tables in the current tenant schema.
     * It will be executed by TenantMigrationManager for each tenant individually.
     */
    public function up(): void
    {
        if (Schema::hasTable('routers')) {
            DB::statement('CREATE INDEX IF NOT EXISTS routers_status_index ON routers (status)');
        }

        if (Schema::hasTable('router_services')) {
            DB::statement('CREATE INDEX IF NOT EXISTS router_services_router_id_index ON router_services (router_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS router_services_service_type_index ON router_services (service_type)');
        }

        if (Schema::hasTable('access_points')) {
            DB::statement('CREATE INDEX IF NOT EXISTS access_points_router_id_index ON access_points (router_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS access_points_status_index ON access_points (status)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('routers')) {
            DB::statement('DROP INDEX IF EXISTS routers_status_index');
        }

        if (Schema::hasTable('router_services')) {
            DB::statement('DROP INDEX IF EXISTS router_services_router_id_index');
            DB::statement('DROP INDEX IF EXISTS router_services_service_type_index');
        }

        if (Schema::hasTable('access_points')) {
            DB::statement('DROP INDEX IF EXISTS access_points_router_id_index');
            DB::statement('DROP INDEX IF EXISTS access_points_status_index');
        }
    }
};
