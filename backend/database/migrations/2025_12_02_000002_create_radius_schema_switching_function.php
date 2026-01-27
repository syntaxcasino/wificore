<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates PostgreSQL functions to support schema-based RADIUS authentication
     */
    public function up(): void
    {
        // Create function to get user's schema
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
        
        // Create function to check RADIUS credentials (schema-aware)
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_check_password(
                p_username VARCHAR,
                p_password VARCHAR
            )
            RETURNS TABLE(
                username VARCHAR,
                attribute VARCHAR,
                op VARCHAR,
                value VARCHAR
            ) AS $func$
            DECLARE
                v_schema VARCHAR;
                v_query TEXT;
            BEGIN
                -- Get user\'s schema
                v_schema := get_user_schema(p_username);

                IF v_schema IS NULL OR v_schema = \'\' THEN
                    RETURN;
                END IF;
                
                -- Query radcheck table in the correct schema using fully qualified name
                RETURN QUERY EXECUTE format(\'
                    SELECT 
                        username::VARCHAR,
                        attribute::VARCHAR,
                        op::VARCHAR,
                        value::VARCHAR
                    FROM %I.radcheck
                    WHERE username = $1
                    ORDER BY id
                \', v_schema)
                USING p_username;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Create function to get RADIUS reply attributes (schema-aware)
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_get_reply(p_username VARCHAR)
            RETURNS TABLE(
                username VARCHAR,
                attribute VARCHAR,
                op VARCHAR,
                value VARCHAR
            ) AS $func$
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
                        username::VARCHAR,
                        attribute::VARCHAR,
                        op::VARCHAR,
                        value::VARCHAR
                    FROM %I.radreply
                    WHERE username = $1
                    ORDER BY id
                \', v_schema)
                USING p_username;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Create function to log RADIUS accounting (schema-aware)
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_accounting_start(
                p_username VARCHAR,
                p_session_id VARCHAR,
                p_unique_id VARCHAR,
                p_nas_ip VARCHAR,
                p_nas_port VARCHAR,
                p_nas_port_type VARCHAR,
                p_called_station VARCHAR,
                p_calling_station VARCHAR
            )
            RETURNS VOID AS $func$
            DECLARE
                v_schema VARCHAR;
            BEGIN
                -- Get user\'s schema
                v_schema := get_user_schema(p_username);

                IF v_schema IS NULL OR v_schema = \'\' THEN
                    RETURN;
                END IF;
                
                -- Set search path to user\'s schema
                EXECUTE format(\'SET search_path TO %I, public\', v_schema);
                
                -- Insert accounting record in the correct schema
                EXECUTE format(\'
                    INSERT INTO %I.radacct (
                        acctsessionid, acctuniqueid, username,
                        nasipaddress, nasportid, nasporttype,
                        acctstarttime, acctupdatetime,
                        calledstationid, callingstationid
                    ) VALUES (
                        $1, $2, $3, $4::inet, $5, $6,
                        NOW(), NOW(), $7, $8
                    )
                \', v_schema)
                USING p_session_id, p_unique_id, p_username,
                      p_nas_ip, p_nas_port, p_nas_port_type,
                      p_called_station, p_calling_station;
                
                -- Reset search path
                SET search_path TO public;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        \Log::info("Created RADIUS schema-switching functions");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_start CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_get_reply CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_check_password CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS get_user_schema CASCADE");
        
        \Log::info("Dropped RADIUS schema-switching functions");
    }
};
