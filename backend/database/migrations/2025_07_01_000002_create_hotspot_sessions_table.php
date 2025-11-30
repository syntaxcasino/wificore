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
        Schema::create('hotspot_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('hotspot_user_id');
            
            // Session details
            $table->string('mac_address', 17)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('session_start');
            $table->timestamp('session_end')->nullable();
            $table->timestamp('last_activity')->nullable();
            $table->timestamp('expires_at')->nullable();
            
            // Session status
            $table->boolean('is_active')->default(true);
            
            // Data usage for this session
            $table->bigInteger('bytes_uploaded')->default(0);
            $table->bigInteger('bytes_downloaded')->default(0);
            $table->bigInteger('total_bytes')->default(0);
            
            // Connection details
            $table->string('user_agent')->nullable();
            $table->string('device_type', 50)->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('hotspot_user_id')
                ->references('id')
                ->on('hotspot_users')
                ->onDelete('cascade');
            $table->index('hotspot_user_id');
            $table->index('is_active');
            $table->index('session_start');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotspot_sessions');
    }
};
