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
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('duration', 50);
            $table->string('upload_speed', 50);
            $table->string('download_speed', 50);
            $table->string('speed', 50)->nullable();
            $table->float('price');
            $table->integer('devices');
            $table->string('data_limit', 50)->nullable();
            $table->string('validity', 50)->nullable();
            $table->boolean('enable_burst')->default(false);
            $table->boolean('enable_schedule')->default(false);
            $table->timestamp('scheduled_activation_time')->nullable();
            $table->timestamp('scheduled_deactivation_time')->nullable();
            $table->boolean('hide_from_client')->default(false);
            $table->string('status', 20)->default('active');
            $table->boolean('is_active')->default(true);
            $table->integer('users_count')->default(0);
            $table->timestamps();
            
            // Foreign keys and indexes
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index('type');
            $table->index('status');
            $table->index('is_active');
            $table->index('scheduled_activation_time');
            $table->index('scheduled_deactivation_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
