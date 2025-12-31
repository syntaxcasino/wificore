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
        
        // We need to be careful with foreign keys. 
        // payments references users, packages, routers.
        // user_subscriptions references users, packages, payments.
        
        // Use CASCADE to remove dependent foreign keys and constraints
        DB::statement('DROP TABLE IF EXISTS public.user_subscriptions CASCADE');
        DB::statement('DROP TABLE IF EXISTS public.payments CASCADE');
        
        Log::info("Dropped Payment and UserSubscription tables from public schema");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
