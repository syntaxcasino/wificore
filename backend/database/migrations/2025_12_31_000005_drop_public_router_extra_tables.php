<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop tables from public schema to enforce strict isolation
        DB::statement('DROP TABLE IF EXISTS public.router_vpn_configs CASCADE');
        DB::statement('DROP TABLE IF EXISTS public.router_configs CASCADE');
        
        Log::info("Dropped Router Extra tables from public schema");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op for down as we don't want to restore public tables without data
        // Data restoration is handled by specific recovery migrations if needed
    }
};
