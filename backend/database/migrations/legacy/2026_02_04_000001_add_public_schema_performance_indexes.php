<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes to public schema tables.
     */
    public function up(): void
    {
        // No-op: public schema indexes are consolidated in 2026_02_06_000001_add_comprehensive_postgresql_indexes.php.
    }

    public function down(): void
    {
        // No-op: retained for migration history compatibility.
    }
};
