<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fix case-sensitive username comparison in get_user_schema and radius functions
     * Hospital-critical: PPPoE users cannot authenticate due to case mismatch
     */
    public function up(): void
    {
        // Recreate get_user_schema with case-insensitive username comparison
        DB::statement("DROP FUNCTION IF EXISTS get_user_schema CASCADE");
        
        DB::statement('
            CREATE OR REPLACE FUNCTION get_user_schema(p_username VARCHAR)
            RETURNS VARCHAR AS $func$
            DECLARE
                v_schema VARCHAR;
                v_count INTEGER;
            BEGIN
                RAISE NOTICE \'get_user_schema called for: %\', p_username;
                
                -- Check if user is a system admin (in public schema) - case insensitive
                SELECT \'public\' INTO v_schema
                FROM public.users
                WHERE LOWER(username) = LOWER(p_username)
                AND role = \'system_admin\'
                LIMIT 1;
                
                RAISE NOTICE \'System admin check result: %\', COALESCE(v_schema, \'NULL\');
                
                IF v_schema IS NOT NULL THEN
                    RETURN v_schema;
                END IF;
                
                -- Count total mappings in table
                SELECT COUNT(*) INTO v_count FROM public.radius_user_schema_mapping;
                RAISE NOTICE \'Total mappings in radius_user_schema_mapping: %\', v_count;
                
                -- Count mappings for this user - case insensitive
                SELECT COUNT(*) INTO v_count 
                FROM public.radius_user_schema_mapping 
                WHERE LOWER(username) = LOWER(p_username);
                RAISE NOTICE \'Mappings for user %: %\', p_username, v_count;
                
                -- Count active mappings for this user - case insensitive
                SELECT COUNT(*) INTO v_count 
                FROM public.radius_user_schema_mapping 
                WHERE LOWER(username) = LOWER(p_username) AND is_active = true;
                RAISE NOTICE \'Active mappings for user %: %\', p_username, v_count;
                
                -- Get tenant schema from mapping table - case insensitive
                SELECT schema_name INTO v_schema
                FROM public.radius_user_schema_mapping
                WHERE LOWER(username) = LOWER(p_username)
                AND is_active = true
                LIMIT 1;
                
                RAISE NOTICE \'Final schema result: %\', COALESCE(v_schema, \'NULL\');

                -- Do not guess. Tenant users must exist in radius_user_schema_mapping.
                -- Return NULL when no mapping exists so RADIUS SQL functions can treat it as "user not found".
                RETURN v_schema;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Recreate radius_authorize_check with case-insensitive username
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
                
                -- Build and log the query - case insensitive username match
                v_query := format(\'SELECT id::INTEGER, username::VARCHAR, attribute::VARCHAR, value::VARCHAR, op::VARCHAR FROM %I.radcheck WHERE LOWER(username) = LOWER($1) ORDER BY id\', v_schema);
                RAISE NOTICE \'Executing query: %\', v_query;
                
                -- Query radcheck table using fully qualified name
                RETURN QUERY EXECUTE v_query USING p_username;
                
                RAISE NOTICE \'Query completed for user: %\', p_username;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Recreate radius_authorize_reply with case-insensitive username
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_reply CASCADE");
        
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_authorize_reply(p_username VARCHAR)
            RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR) AS $func$
            DECLARE
                v_schema VARCHAR;
                v_query TEXT;
            BEGIN
                v_schema := get_user_schema(p_username);
                
                IF v_schema IS NULL OR v_schema = \'\' THEN
                    RETURN;
                END IF;
                
                -- Case insensitive username match
                v_query := format(\'SELECT id::INTEGER, username::VARCHAR, attribute::VARCHAR, value::VARCHAR, op::VARCHAR FROM %I.radreply WHERE LOWER(username) = LOWER($1) ORDER BY id\', v_schema);
                RETURN QUERY EXECUTE v_query USING p_username;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        \Log::info("Fixed case-sensitive username comparison in RADIUS functions");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_check CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_reply CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS get_user_schema CASCADE");
    }
};
