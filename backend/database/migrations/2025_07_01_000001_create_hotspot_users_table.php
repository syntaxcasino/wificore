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
        Schema::create('hotspot_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('username')->unique();
            $table->string('password');
            $table->string('phone_number')->unique();
            $table->string('mac_address', 17)->nullable();
            
            // Subscription details
            $table->boolean('has_active_subscription')->default(false);
            $table->string('package_name')->nullable();
            $table->uuid('package_id')->nullable();
            $table->timestamp('subscription_starts_at')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            
            // Data usage
            $table->bigInteger('data_limit')->nullable()->comment('in bytes');
            $table->bigInteger('data_used')->default(0)->comment('in bytes');
            
            // Login tracking
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('active');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('set null');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('username');
            $table->index('phone_number');
            $table->index('has_active_subscription');
            $table->index('subscription_expires_at');
            $table->index('package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotspot_users');
    }
};
