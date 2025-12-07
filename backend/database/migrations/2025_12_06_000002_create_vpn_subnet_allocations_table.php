<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tracks subnet allocations per tenant to prevent overlaps
     * Each tenant gets a unique /16 subnet from 10.0.0.0/8 range
     */
    public function up(): void
    {
        Schema::create('vpn_subnet_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Subnet allocation
            $table->string('subnet_cidr', 20); // e.g., 10.100.0.0/16
            $table->integer('subnet_octet_2')->unique(); // Second octet (100 in 10.100.0.0)
            $table->ipAddress('gateway_ip'); // e.g., 10.100.0.1
            $table->ipAddress('range_start'); // e.g., 10.100.1.1
            $table->ipAddress('range_end'); // e.g., 10.100.255.254
            
            // Usage tracking
            $table->integer('total_ips')->default(65534); // /16 = 65,534 usable IPs
            $table->integer('allocated_ips')->default(0);
            $table->integer('available_ips')->default(65534);
            
            // Status
            $table->enum('status', ['active', 'exhausted', 'reserved'])->default('active');
            
            $table->timestamps();
            
            // Indexes
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_subnet_allocations');
    }
};
