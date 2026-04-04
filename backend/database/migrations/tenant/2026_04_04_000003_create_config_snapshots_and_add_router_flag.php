<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Config snapshots are tenant-specific
     * Stores router configuration baselines for drift detection per tenant
     */
    public function up(): void
    {
        Schema::create('config_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->longText('config_text'); // Full running config
            $table->string('config_hash', 64); // SHA-256 hash for quick comparison
            $table->json('parsed_config')->nullable(); // Structured parsed config
            $table->uuid('created_by')->nullable(); // User who created snapshot
            $table->timestamps();

            // Foreign key
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['router_id', 'created_at']);
            $table->index('config_hash');
        });

        // Add auto_remediate flag to routers table for drift detection
        Schema::table('routers', function (Blueprint $table) {
            $table->boolean('auto_remediate')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_snapshots');
        
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('auto_remediate');
        });
    }
};
