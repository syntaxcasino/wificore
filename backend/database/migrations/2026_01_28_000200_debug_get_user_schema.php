<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add comprehensive debug logging to get_user_schema to diagnose mapping lookup failure
     */
    public function up(): void
    {
        // Recreate get_user_schema with extensive debug logging
        DB::statement("DROP FUNCTION IF EXISTS get_user_schema CASCADE");
        
        DB::statement('
            CREATE OR REPLACE FUNCTION get_user_schema(p_username VARCHAR)
            RETURNS VARCHAR AS $func$
            DECLARE
                v_schema VARCHAR;
                v_count INTEGER;
            BEGIN
                RAISE NOTICE \'get_user_schema called for: %\', p_username;
                
                -- Check if user is a system admin (in public schema)
                SELECT \'public\' INTO v_schema
                FROM public.users
                WHERE username = p_username
                AND role = \'system_admin\'
                LIMIT 1;
                
                RAISE NOTICE \'System admin check result: %\', COALESCE(v_schema, \'NULL\');
                
                IF v_schema IS NOT NULL THEN
                    RETURN v_schema;
                END IF;
                
                -- Count total mappings in table
                SELECT COUNT(*) INTO v_count FROM public.radius_user_schema_mapping;
                RAISE NOTICE \'Total mappings in radius_user_schema_mapping: %\', v_count;
                
                -- Count mappings for this user
                SELECT COUNT(*) INTO v_count 
                FROM public.radius_user_schema_mapping 
                WHERE username = p_username;
                RAISE NOTICE \'Mappings for user %: %\', p_username, v_count;
                
                -- Count active mappings for this user
                SELECT COUNT(*) INTO v_count 
                FROM public.radius_user_schema_mapping 
                WHERE username = p_username AND is_active = true;
                RAISE NOTICE \'Active mappings for user %: %\', p_username, v_count;
                
                -- Get tenant schema from mapping table
                SELECT schema_name INTO v_schema
                FROM public.radius_user_schema_mapping
                WHERE username = p_username
                AND is_active = true
                LIMIT 1;
                
                RAISE NOTICE \'Final schema result: %\', COALESCE(v_schema, \'NULL\');

                -- Do not guess. Tenant users must exist in radius_user_schema_mapping.
                -- Return NULL when no mapping exists so RADIUS SQL functions can treat it as "user not found".
                RETURN v_schema;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        \Log::info("Added comprehensive debug logging to get_user_schema");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP FUNCTION IF EXISTS get_user_schema CASCADE");
    }
};
