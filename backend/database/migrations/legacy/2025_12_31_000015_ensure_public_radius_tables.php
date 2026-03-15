<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No-op: public RADIUS tables are created in 2025_10_29_000001_create_radius_core_tables.php.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: retained for migration history compatibility.
    }
};
