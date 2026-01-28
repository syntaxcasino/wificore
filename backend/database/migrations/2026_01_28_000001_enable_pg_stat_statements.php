<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Enable pg_stat_statements extension for performance monitoring
     */
    public function up(): void
    {
        // Enable pg_stat_statements extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        
        \Log::info('pg_stat_statements extension enabled for performance monitoring');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop pg_stat_statements extension
        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements CASCADE');
        
        \Log::info('pg_stat_statements extension disabled');
    }
};
