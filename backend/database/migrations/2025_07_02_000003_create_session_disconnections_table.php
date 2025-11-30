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
        Schema::create('session_disconnections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('radius_session_id');
            $table->uuid('hotspot_user_id');
            
            // Disconnection details
            $table->string('disconnect_method', 50)->nullable();
            $table->string('disconnect_reason')->nullable();
            $table->timestamp('disconnected_at');
            $table->uuid('disconnected_by')->nullable();
            
            // Session summary
            $table->bigInteger('total_duration')->nullable();
            $table->bigInteger('total_data_used')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('radius_session_id')->references('id')->on('radius_sessions')->onDelete('cascade');
            $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
            $table->foreign('disconnected_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('hotspot_user_id');
            $table->index('disconnected_at');
            $table->index('disconnect_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_disconnections');
    }
};
