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
        Schema::create('tenant_ip_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->enum('service_type', ['hotspot', 'pppoe', 'management'])->index();
            $table->string('pool_name');
            $table->string('network_cidr', 50);
            $table->string('gateway_ip', 45);
            $table->string('range_start', 45);
            $table->string('range_end', 45);
            $table->string('dns_primary', 45)->nullable();
            $table->string('dns_secondary', 45)->nullable();
            $table->integer('total_ips');
            $table->integer('allocated_ips')->default(0);
            $table->integer('available_ips');
            $table->boolean('auto_generated')->default(true);
            $table->enum('status', ['active', 'exhausted', 'disabled'])->default('active')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'service_type', 'network_cidr']);
            $table->index(['tenant_id', 'service_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_ip_pools');
    }
};
