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
        Schema::create('performance_metrics', function (Blueprint $table) {
            $table->id(); // BIGSERIAL to match init.sql
            $table->timestamp('recorded_at');
            
            // TPS Metrics
            $table->decimal('tps_current', 10, 2)->default(0);
            $table->decimal('tps_average', 10, 2)->default(0);
            $table->decimal('tps_max', 10, 2)->default(0);
            $table->decimal('tps_min', 10, 2)->default(0);
            
            // OPS Metrics (Redis)
            $table->decimal('ops_current', 10, 2)->default(0);
            
            // Database Metrics
            $table->integer('db_active_connections')->default(0);
            $table->bigInteger('db_total_queries')->default(0);
            $table->integer('db_slow_queries')->default(0);
            
            // Cache Metrics
            $table->bigInteger('cache_keys')->default(0);
            $table->string('cache_memory_used', 50)->nullable();
            $table->decimal('cache_hit_rate', 5, 2)->default(0);
            
            // System Metrics
            $table->integer('active_sessions')->default(0);
            $table->integer('pending_jobs')->default(0);
            $table->integer('failed_jobs')->default(0);
            
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index('recorded_at');
            $table->index(['recorded_at', 'tps_current']);
            $table->index(['recorded_at', 'ops_current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_metrics');
    }
};
