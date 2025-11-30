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
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payment_id')->nullable();
            $table->string('voucher')->unique();
            $table->string('mac_address', 17);
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            // Note: Foreign key for payment_id is added in a later migration
            // after payments table is created (see 2025_07_02_000000_add_user_sessions_foreign_keys.php)
            
            $table->index('tenant_id');
            $table->index('payment_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
