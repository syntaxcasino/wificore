<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop tables from public schema to enforce strict isolation
        // We use raw SQL to be explicit about public schema
        
        DB::statement('DROP TABLE IF EXISTS public.wireguard_peers CASCADE');
        DB::statement('DROP TABLE IF EXISTS public.router_services CASCADE');
        DB::statement('DROP TABLE IF EXISTS public.routers CASCADE');
        
        Log::info("Dropped Router tables from public schema");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-create public tables (definitions copied from original migrations)
        
        if (!Schema::hasTable('routers')) {
            Schema::create('routers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id'); // Add tenant_id back for public schema
                $table->string('name');
                $table->string('ip_address', 45);
                $table->string('vpn_ip', 45)->nullable();
                $table->string('vpn_status', 20)->default('inactive');
                $table->boolean('vpn_enabled')->default(false);
                $table->timestamp('vpn_last_handshake')->nullable();
                $table->string('model')->nullable();
                $table->string('os_version')->nullable();
                $table->timestamp('last_seen')->nullable();
                $table->integer('port')->default(8728);
                $table->string('username');
                $table->string('password'); 
                $table->string('location')->nullable();
                $table->string('status', 20)->default('unknown');
                $table->string('provisioning_stage', 50)->default('pending');
                $table->json('interface_assignments')->nullable();
                $table->json('configurations')->nullable();
                $table->string('config_token', 64)->nullable();
                $table->string('vendor', 50)->default('mikrotik');
                $table->string('device_type', 50)->default('router');
                $table->json('capabilities')->nullable();
                $table->json('interface_list')->nullable();
                $table->json('reserved_interfaces')->nullable();
                $table->timestamps();
                $table->softDeletes();
                
                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('router_services')) {
            Schema::create('router_services', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id'); // Add tenant_id back
                $table->uuid('router_id');
                $table->string('service_type', 50);
                $table->string('service_name', 100);
                $table->json('interfaces')->default('[]');
                $table->json('configuration')->default('{}');
                $table->string('status', 20)->default('inactive');
                $table->integer('active_users')->default(0);
                $table->integer('total_sessions')->default(0);
                $table->timestamp('last_checked_at')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
                
                $table->index('router_id');
                $table->index('service_type');
                $table->index('status');
            });
        }

        if (!Schema::hasTable('wireguard_peers')) {
            Schema::create('wireguard_peers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id'); // Add tenant_id back
                $table->uuid('router_id');
                $table->string('peer_name')->nullable();
                $table->text('public_key')->nullable();
                $table->string('endpoint')->nullable();
                $table->text('allowed_ips')->nullable();
                $table->timestamp('last_handshake')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
                
                $table->index('router_id');
            });
        }
    }
};
