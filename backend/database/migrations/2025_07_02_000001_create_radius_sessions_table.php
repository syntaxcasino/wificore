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
        Schema::create('radius_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('hotspot_user_id');
            $table->uuid('payment_id')->nullable();
            $table->uuid('package_id')->nullable();
            
            // RADIUS data
            $table->bigInteger('radacct_id')->nullable();
            $table->string('username', 64);
            $table->string('mac_address', 17)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->ipAddress('nas_ip_address')->nullable();
            
            // Session timing
            $table->timestamp('session_start');
            $table->timestamp('session_end')->nullable();
            $table->timestamp('expected_end');
            $table->bigInteger('duration_seconds')->default(0);
            
            // Data usage
            $table->bigInteger('bytes_in')->default(0);
            $table->bigInteger('bytes_out')->default(0);
            $table->bigInteger('total_bytes')->default(0);
            
            // Status
            $table->string('status', 20)->default('active');
            $table->string('disconnect_reason', 100)->nullable();
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('set null');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('hotspot_user_id');
            $table->index('status');
            $table->index('expected_end');
            $table->index('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('radius_sessions');
    }
};
