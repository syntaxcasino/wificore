<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track individual IP assignments to provisioned devices within a tenant.
 * 
 * This table lives in the tenant schema (schema-based isolation).
 * The CIDR pool definitions live in the public schema (tenant_ip_pools).
 * This table records which specific IP from a pool is assigned to which device/service.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ip_allocations')) {
            return;
        }

        Schema::create('ip_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ip_pool_id')->index();
            $table->string('ip_address', 45);
            $table->enum('type', ['router_service', 'access_point', 'user_device', 'management'])->index();
            $table->uuid('allocatable_id')->nullable()->index();
            $table->string('allocatable_type')->nullable();
            $table->string('description')->nullable();
            $table->enum('status', ['active', 'reserved', 'released', 'expired'])->default('active')->index();
            $table->timestamp('allocated_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['ip_pool_id', 'ip_address']);
            $table->index(['allocatable_type', 'allocatable_id']);
            $table->index(['status', 'ip_pool_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_allocations');
    }
};
