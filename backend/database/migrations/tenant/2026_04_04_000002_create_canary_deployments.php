<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Canary deployments are tenant-specific
     * Tracks gradual rollout of configuration changes per tenant
     */
    public function up(): void
    {
        Schema::create('canary_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('config_version', 100);
            $table->string('config_hash', 64); // SHA-256 hash
            $table->integer('total_routers');
            $table->integer('canary_count');
            $table->json('canary_routers'); // Array of router IDs
            $table->json('remaining_routers'); // Array of router IDs
            $table->integer('percentage')->default(10); // 10-50
            $table->string('status', 50)->default('canary_running'); // canary_running, promoting, rolling_back, completed, failed, auto_rolled_back
            $table->integer('health_check_interval')->default(60); // seconds
            $table->decimal('health_score', 5, 2)->nullable(); // 0-100
            $table->text('config_content'); // Encrypted at rest by model
            $table->timestamp('started_at');
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_health_check')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('config_version');
            $table->index('status');
            $table->index('started_at');
            $table->index(['status', 'health_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canary_deployments');
    }
};
