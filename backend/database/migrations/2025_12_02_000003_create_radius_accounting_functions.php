<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates PostgreSQL functions to support RADIUS accounting for NAS-level events
     */
    public function up(): void
    {
        // Drop existing functions to avoid conflicts
        // First drop the old radius_accounting_stop with its exact old signature
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_stop(character varying,character varying,bigint,integer,bigint,bigint,character varying,character varying) CASCADE");
        
        // Then drop all other functions using CASCADE to handle any signature variations
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_onoff CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_start CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_stop CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_update CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_post_auth_insert CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_check CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_reply CASCADE");
        
        // Create function for NAS accounting (Accounting-On/Off)
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_accounting_onoff(
                p_nas_ip VARCHAR,
                p_timestamp BIGINT,
                p_cause VARCHAR DEFAULT \'NAS-Reboot\',
                p_delay INTEGER DEFAULT 0
            )
            RETURNS VOID AS $func$
            BEGIN
                -- Insert NAS accounting record in public schema
                -- This is for NAS-level events (Accounting-On/Off) that don\'t have a username
                INSERT INTO public.radacct (
                    acctsessionid,
                    acctuniqueid,
                    username,
                    nasipaddress,
                    nasportid,
                    nasporttype,
                    acctstarttime,
                    acctstoptime,
                    acctsessiontime,
                    acctinputoctets,
                    acctoutputoctets,
                    calledstationid,
                    callingstationid,
                    acctterminatecause,
                    acctdelaytime,
                    acctauthentic,
                    connectinfo_start,
                    connectinfo_stop
                ) VALUES (
                    \'nas-\' || p_nas_ip || \'-\' || EXTRACT(epoch FROM NOW())::TEXT,
                    \'nas-\' || p_nas_ip || \'-\' || EXTRACT(epoch FROM NOW())::TEXT,
                    \'\',  -- No username for NAS-level accounting
                    p_nas_ip::inet,
                    \'\',  -- No port for NAS-level accounting
                    \'\',  -- No port type for NAS-level accounting
                    to_timestamp(p_timestamp),
                    to_timestamp(p_timestamp),
                    0,    -- Session time is 0 for on/off events
                    0,    -- No octets for on/off events
                    0,    -- No octets for on/off events
                    \'\',  -- No called station for NAS-level accounting
                    \'\',  -- No calling station for NAS-level accounting
                    p_cause,
                    p_delay,
                    \'RADIUS\',  -- Auth method
                    \'NAS Accounting \' || p_cause,
                    \'NAS Accounting \' || p_cause
                );
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Create function for accounting updates
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_accounting_update(
                p_username VARCHAR,
                p_unique_id VARCHAR,
                p_framed_ip VARCHAR DEFAULT NULL,
                p_session_time INTEGER DEFAULT NULL,
                p_input_octets BIGINT DEFAULT NULL,
                p_output_octets BIGINT DEFAULT NULL
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
                
                -- Update accounting record in the correct schema
                EXECUTE format(\'
                    UPDATE %I.radacct SET
                        framedipaddress = %L,
                        acctsessiontime = COALESCE(%L, acctsessiontime),
                        acctinputoctets = COALESCE(%L, acctinputoctets),
                        acctoutputoctets = COALESCE(%L, acctoutputoctets),
                        acctupdatetime = NOW()
                    WHERE acctuniqueid = %L
                \', v_schema, p_framed_ip, p_session_time, p_input_octets, p_output_octets, p_unique_id);
                
                -- Reset search path
                SET search_path TO public;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Create function for accounting stop
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_accounting_stop(
                p_username VARCHAR,
                p_unique_id VARCHAR,
                p_timestamp BIGINT,
                p_session_time INTEGER DEFAULT NULL,
                p_input_octets BIGINT DEFAULT NULL,
                p_output_octets BIGINT DEFAULT NULL,
                p_terminate_cause VARCHAR DEFAULT NULL,
                p_connect_info VARCHAR DEFAULT NULL
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
                
                -- Update accounting record in the correct schema
                EXECUTE format(\'
                    UPDATE %I.radacct SET
                        acctstoptime = to_timestamp(%L),
                        acctsessiontime = COALESCE(%L, acctsessiontime),
                        acctinputoctets = COALESCE(%L, acctinputoctets),
                        acctoutputoctets = COALESCE(%L, acctoutputoctets),
                        acctterminatecause = COALESCE(%L, acctterminatecause),
                        connectinfo_stop = COALESCE(%L, connectinfo_stop),
                        acctupdatetime = NOW()
                    WHERE acctuniqueid = %L
                \', v_schema, p_timestamp, p_session_time, p_input_octets, p_output_octets, p_terminate_cause, p_connect_info, p_unique_id);
                
                -- Reset search path
                SET search_path TO public;
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Create function for post-auth logging
        DB::statement('
            CREATE OR REPLACE FUNCTION radius_post_auth_insert(
                p_username VARCHAR,
                p_password VARCHAR DEFAULT NULL,
                p_reply_type VARCHAR DEFAULT NULL
            )
            RETURNS VOID AS $func$
            DECLARE
                v_schema VARCHAR;
            BEGIN
                -- Get user\'s schema
                v_schema := get_user_schema(p_username);

                IF v_schema IS NULL OR v_schema = \'\' THEN
                    -- If no schema found, log in public schema for troubleshooting
                    INSERT INTO public.radpostauth (
                        username, 
                        pass, 
                        reply, 
                        authdate
                    ) VALUES (
                        p_username, 
                        p_password, 
                        COALESCE(p_reply_type, \'Unknown\'), 
                        NOW()
                    );
                    RETURN;
                END IF;
                
                -- Insert post-auth record in the correct schema using fully qualified name
                EXECUTE format(\'
                    INSERT INTO %I.radpostauth (
                        username, 
                        pass, 
                        reply, 
                        authdate
                    ) VALUES (
                        %L, 
                        %L, 
                        %L, 
                        NOW()
                    )
                \', v_schema, p_username, p_password, COALESCE(p_reply_type, \'Unknown\'));
            END;
            $func$ LANGUAGE plpgsql SECURITY DEFINER
        ');
        
        // Create function for authorize check
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
        
        // Create function for authorize reply
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
        
        \Log::info("Created RADIUS accounting and authorization functions");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_reply CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_authorize_check CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_post_auth_insert CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_stop CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_update CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS radius_accounting_onoff CASCADE");
        
        \Log::info("Dropped RADIUS accounting and authorization functions");
    }
};
