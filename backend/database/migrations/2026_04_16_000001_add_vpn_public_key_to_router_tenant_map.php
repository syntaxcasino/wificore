<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('router_tenant_map', 'vpn_public_key')) {
            Schema::table('router_tenant_map', function (Blueprint $table) {
                $table->string('vpn_public_key', 64)->nullable()->after('config_token');
                $table->index('vpn_public_key');
            });
        }

        // Backfill from existing tenant schemas
        $tenants = DB::table('tenants')
            ->where('schema_created', true)
            ->whereNotNull('schema_name')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $rows = DB::select(
                    "SELECT vc.router_id, vc.client_public_key
                     FROM {$tenant->schema_name}.vpn_configurations vc
                     WHERE vc.client_public_key IS NOT NULL"
                );

                foreach ($rows as $row) {
                    DB::table('router_tenant_map')
                        ->where('router_id', $row->router_id)
                        ->update(['vpn_public_key' => $row->client_public_key]);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to backfill vpn_public_key for tenant {$tenant->name}: " . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        Schema::table('router_tenant_map', function (Blueprint $table) {
            $table->dropIndex(['vpn_public_key']);
            $table->dropColumn('vpn_public_key');
        });
    }
};
