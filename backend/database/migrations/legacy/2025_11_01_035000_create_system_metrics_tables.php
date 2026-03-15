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
        // Retired: consolidated into 2025_10_17_000001_create_performance_metrics_table.php
        // to keep a single authoritative public metrics migration.
        return;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: migration retired in favor of consolidated base migration.
        return;
    }
};
