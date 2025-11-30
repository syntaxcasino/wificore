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
        // Queue metrics - historical data
        if (!Schema::hasTable('queue_metrics')) {
            Schema::create('queue_metrics', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->timestamp('recorded_at');
                
                // Queue statistics
                $table->integer('pending_jobs')->default(0);
                $table->integer('processing_jobs')->default(0);
                $table->integer('failed_jobs')->default(0);
                $table->integer('completed_jobs')->default(0);
                $table->integer('active_workers')->default(0);
                
                // Workers by queue (JSON)
                $table->json('workers_by_queue')->nullable();
                $table->json('pending_by_queue')->nullable();
                $table->json('failed_by_queue')->nullable();
                
                // Indexes for querying
                $table->index('recorded_at');
                $table->index(['recorded_at', 'active_workers']);
                
                $table->timestamps();
            });
        }

        // System health metrics - historical data
        if (!Schema::hasTable('system_health_metrics')) {
            Schema::create('system_health_metrics', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->timestamp('recorded_at');
                
                // Database metrics
                $table->integer('db_connections')->default(0);
                $table->integer('db_max_connections')->default(0);
                $table->decimal('db_response_time', 8, 2)->default(0);
                $table->integer('db_slow_queries')->default(0);
                
                // Redis metrics
                $table->decimal('redis_hit_rate', 5, 2)->default(0);
                $table->bigInteger('redis_memory_used')->default(0);
                $table->bigInteger('redis_memory_peak')->default(0);
                
                // Disk metrics
                $table->bigInteger('disk_total')->default(0);
                $table->bigInteger('disk_available')->default(0);
                $table->decimal('disk_used_percentage', 5, 2)->default(0);
                
                // System uptime
                $table->decimal('uptime_percentage', 5, 2)->default(0);
                $table->string('uptime_duration')->nullable();
                $table->timestamp('last_restart')->nullable();
                
                // Indexes
                $table->index('recorded_at');
                $table->index(['recorded_at', 'db_connections']);
                
                $table->timestamps();
            });
        }

        // NOTE: performance_metrics table already exists from 2025_10_17_000001 migration
        // We'll use the existing table structure

        // Worker status snapshots - for detailed worker tracking
        if (!Schema::hasTable('worker_snapshots')) {
            Schema::create('worker_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->timestamp('recorded_at');
                
                $table->string('queue_name')->index();
                $table->integer('worker_count')->default(0);
                $table->integer('pending_jobs')->default(0);
                $table->integer('failed_jobs')->default(0);
                $table->decimal('avg_processing_time', 8, 2)->nullable();
                
                // Indexes
                $table->index(['recorded_at', 'queue_name']);
                $table->index(['queue_name', 'recorded_at']);
                
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_snapshots');
        // Don't drop performance_metrics - it's managed by 2025_10_17_000001 migration
        Schema::dropIfExists('system_health_metrics');
        Schema::dropIfExists('queue_metrics');
    }
};
