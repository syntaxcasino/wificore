<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates a public-schema lookup table that maps router IDs and IP addresses
     * to tenant IDs. This is needed because routers live in tenant schemas,
     * but public endpoints (captive portal, payment callbacks) need to find
     * which tenant a router belongs to without knowing the schema.
     */
    public function up(): void
    {
        Schema::create('router_tenant_map', function (Blueprint $table) {
            $table->uuid('router_id')->primary();
            $table->uuid('tenant_id');
            $table->string('ip_address', 45)->nullable();
            $table->string('vpn_ip', 45)->nullable();
            $table->string('config_token', 64)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index('ip_address');
            $table->index('vpn_ip');
            $table->index('config_token');
        });

        // Populate from existing tenant schemas
        $this->populateFromExistingSchemas();
    }

    /**
     * Populate the map from existing tenant schemas
     */
    protected function populateFromExistingSchemas(): void
    {
        $tenants = DB::table('tenants')
            ->where('schema_created', true)
            ->whereNotNull('schema_name')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $routers = DB::select(
                    "SELECT id, ip_address, vpn_ip, config_token FROM {$tenant->schema_name}.routers"
                );

                foreach ($routers as $router) {
                    DB::table('router_tenant_map')->insertOrIgnore([
                        'router_id' => $router->id,
                        'tenant_id' => $tenant->id,
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'config_token' => $router->config_token,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                Log::info("Populated router_tenant_map for tenant {$tenant->name}", [
                    'tenant_id' => $tenant->id,
                    'router_count' => count($routers),
                ]);
            } catch (\Exception $e) {
                Log::warning("Failed to populate router_tenant_map for tenant {$tenant->name}: " . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_tenant_map');
    }
};
