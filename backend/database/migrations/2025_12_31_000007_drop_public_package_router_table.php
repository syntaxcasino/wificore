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
        // Drop table from public schema to enforce strict isolation
        DB::statement('DROP TABLE IF EXISTS public.package_router CASCADE');
        
        Log::info("Dropped package_router table from public schema");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
