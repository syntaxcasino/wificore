<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fix radius_authorize_check and radius_authorize_reply to remove SET search_path
     * which doesn't work correctly in FreeRADIUS connection pool context
     */
    public function up(): void
    {
        // Drop and recreate radius_authorize_check without SET search_path
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_check CASCADE");
        
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_authorize_check(p_username VARCHAR)
            RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR) AS $func$
            DECLARE
                v_schema VARCHAR;
            BEGIN
                -- Get user\'s schema
                v_schema := get_user_schema(p_username);

                IF v_schema IS NULL OR v_schema = \'\' THEN
                    RETURN;
                END IF;
                
                -- Query radcheck table in the correct schema using fully qualified name
                RETURN QUERY EXECUTE format(\'
                    SELECT 
                        id::INTEGER,
                        username::VARCHAR,
                        attribute::VARCHAR,
                        value::VARCHAR,
                        op::VARCHAR
                    FROM %I.radcheck
                    WHERE username = $1
                    ORDER BY id
                \', v_schema)
                USING p_username;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Drop and recreate radius_authorize_reply without SET search_path
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_reply CASCADE");
        
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_authorize_reply(p_username VARCHAR)
            RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR) AS $func$
            DECLARE
                v_schema VARCHAR;
            BEGIN
                -- Get user\'s schema
                v_schema := get_user_schema(p_username);

                IF v_schema IS NULL OR v_schema = \'\' THEN
                    RETURN;
                END IF;
                
                -- Query radreply table in the correct schema using fully qualified name
                RETURN QUERY EXECUTE format(\'
                    SELECT 
                        id::INTEGER,
                        username::VARCHAR,
                        attribute::VARCHAR,
                        value::VARCHAR,
                        op::VARCHAR
                    FROM %I.radreply
                    WHERE username = $1
                    ORDER BY id
                \', v_schema)
                USING p_username;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        \Log::info("Fixed RADIUS authorize functions to remove search_path manipulation");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to old versions with SET search_path (not recommended)
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_check CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_reply CASCADE");
        
        // Note: down() intentionally doesn't recreate old broken versions
        // If you need to rollback, the old migration will recreate them
    }
};
