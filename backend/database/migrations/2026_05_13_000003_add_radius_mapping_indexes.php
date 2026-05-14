<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add optimized indexes for RADIUS user schema mapping table
 * 
 * CRITICAL for PPPoE portal performance - this table is queried on EVERY:
 * - Login attempt
 * - Token validation
 * - User lookup
 */
return new class extends Migration
{
    public function up(): void
    {
        // OPTIMIZATION: Composite index for username lookups with active status
        // Used by: PppoePortalAuth middleware, login, token validation
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_radius_mapping_username_active 
            ON radius_user_schema_mapping(username, is_active) 
            WHERE is_active = true
        ');

        // OPTIMIZATION: Index for user ID lookups (token validation)
        // Used by: PppoePortalAuth middleware on every request
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_radius_mapping_user_id_active 
            ON radius_user_schema_mapping(pppoe_user_id, is_active) 
            WHERE is_active = true
        ');

        // OPTIMIZATION: Index for tenant lookups
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_radius_mapping_tenant 
            ON radius_user_schema_mapping(tenant_id, is_active)
        ');

        // OPTIMIZATION: Unique constraint to prevent duplicate mappings
        // Also creates index automatically
        try {
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS idx_radius_mapping_unique_user 
                ON radius_user_schema_mapping(pppoe_user_id)
            ');
        } catch (\Throwable $e) {
            // May fail if duplicates exist - log and continue
            Log::warning('Could not create unique index on radius_user_schema_mapping - duplicates may exist');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_radius_mapping_username_active');
        DB::statement('DROP INDEX IF EXISTS idx_radius_mapping_user_id_active');
        DB::statement('DROP INDEX IF EXISTS idx_radius_mapping_tenant');
        DB::statement('DROP INDEX IF EXISTS idx_radius_mapping_unique_user');
    }
};
