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
        // These tables are now in tenant schemas
        
        DB::statement('DROP TABLE IF EXISTS public.vpn_configurations CASCADE');
        DB::statement('DROP TABLE IF EXISTS public.vpn_subnet_allocations CASCADE');
        
        Log::info("Dropped VPN tables from public schema");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
