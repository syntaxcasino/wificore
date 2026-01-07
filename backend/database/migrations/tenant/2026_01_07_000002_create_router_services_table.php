<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('router_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->string('interface_name', 100);
            $table->enum('service_type', ['hotspot', 'pppoe', 'hybrid', 'none'])->index();
            $table->uuid('ip_pool_id')->nullable();
            $table->integer('vlan_id')->nullable();
            $table->boolean('vlan_required')->default(false);
            $table->string('radius_profile')->nullable();
            $table->jsonb('advanced_config')->nullable();
            $table->enum('deployment_status', ['pending', 'deploying', 'deployed', 'failed'])->default('pending')->index();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
            $table->foreign('ip_pool_id')->references('id')->on('tenant_ip_pools')->onDelete('set null');
            
            $table->unique(['router_id', 'interface_name', 'service_type']);
            $table->index(['router_id', 'deployment_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_services');
    }
};
