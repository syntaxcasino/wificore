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
        Schema::create('data_usage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('hotspot_user_id');
            $table->uuid('radius_session_id');
            
            // Usage data
            $table->bigInteger('bytes_in');
            $table->bigInteger('bytes_out');
            $table->bigInteger('total_bytes');
            
            // Snapshot time
            $table->timestamp('recorded_at')->useCurrent();
            
            // Source
            $table->string('source', 50)->default('radius_accounting');
            
            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
            $table->foreign('radius_session_id')->references('id')->on('radius_sessions')->onDelete('cascade');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('hotspot_user_id');
            $table->index('radius_session_id');
            $table->index('recorded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_usage_logs');
    }
};
