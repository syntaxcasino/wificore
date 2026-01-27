<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vpn_configurations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_configurations_subnet_cidr_index ON vpn_configurations (subnet_cidr)');
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_configurations_tunnel_id_status_index ON vpn_configurations (tenant_vpn_tunnel_id, status)');
        }

        if (Schema::hasTable('vpn_subnet_allocations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_subnet_allocations_subnet_cidr_index ON vpn_subnet_allocations (subnet_cidr)');
            DB::statement('CREATE INDEX IF NOT EXISTS vpn_subnet_allocations_status_index ON vpn_subnet_allocations (status)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vpn_configurations')) {
            DB::statement('DROP INDEX IF EXISTS vpn_configurations_subnet_cidr_index');
            DB::statement('DROP INDEX IF EXISTS vpn_configurations_tunnel_id_status_index');
        }

        if (Schema::hasTable('vpn_subnet_allocations')) {
            DB::statement('DROP INDEX IF EXISTS vpn_subnet_allocations_subnet_cidr_index');
            DB::statement('DROP INDEX IF EXISTS vpn_subnet_allocations_status_index');
        }
    }
};
