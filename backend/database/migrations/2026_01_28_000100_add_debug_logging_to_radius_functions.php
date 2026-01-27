<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add debug logging to radius_authorize_check to diagnose why it returns 0 rows from FreeRADIUS
     */
    public function up(): void
    {
        // Recreate get_user_schema with the full logic including system_admin check
        DB::statement("DROP FUNCTION IF EXISTS get_user_schema CASCADE");
        
        DB::statement('
            CREATE OR REPLACE FUNCTION get_user_schema(p_username VARCHAR)
            RETURNS VARCHAR AS $func$
            DECLARE
                v_schema VARCHAR;
            BEGIN
                -- Check if user is a system admin (in public schema)
                SELECT \'public\' INTO v_schema
                FROM public.users
                WHERE username = p_username
                AND role = \'system_admin\'
                LIMIT 1;
                
                IF v_schema IS NOT NULL THEN
                    RETURN v_schema;
                END IF;
                
                -- Get tenant schema from mapping table
                SELECT schema_name INTO v_schema
                FROM public.radius_user_schema_mapping
                WHERE username = p_username
                AND is_active = true
                LIMIT 1;

                -- Do not guess. Tenant users must exist in radius_user_schema_mapping.
                -- Return NULL when no mapping exists so RADIUS SQL functions can treat it as "user not found".
                RETURN v_schema;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Recreate radius_authorize_check with explicit logging
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_check CASCADE");
        
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_authorize_check(p_username VARCHAR)
            RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR) AS $func$
            DECLARE
                v_schema VARCHAR;
                v_query TEXT;
            BEGIN
                -- Get user\'s schema
                v_schema := get_user_schema(p_username);
                
                -- Log to PostgreSQL log for debugging
                RAISE NOTICE \'radius_authorize_check called for user: %, schema: %\', p_username, COALESCE(v_schema, \'NULL\');

                IF v_schema IS NULL OR v_schema = \'\' THEN
                    RAISE NOTICE \'No schema found for user: %\', p_username;
                    RETURN;
                END IF;
                
                -- Build and log the query
                v_query := format(\'SELECT id::INTEGER, username::VARCHAR, attribute::VARCHAR, value::VARCHAR, op::VARCHAR FROM %I.radcheck WHERE username = $1 ORDER BY id\', v_schema);
                RAISE NOTICE \'Executing query: %\', v_query;
                
                -- Query radcheck table using fully qualified name
                RETURN QUERY EXECUTE v_query USING p_username;
                
                RAISE NOTICE \'Query completed for user: %\', p_username;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        \Log::info("Added debug logging to RADIUS functions");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove debug logging by recreating without RAISE NOTICE statements
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_check CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS get_user_schema CASCADE");
    }
};
