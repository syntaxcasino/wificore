<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUTHORITATIVE RADIUS FUNCTIONS MIGRATION
 * 
 * This migration consolidates ALL RADIUS SQL function definitions into a single source of truth.
 * It completely drops and recreates all functions to ensure consistency.
 * 
 * Previous migrations that defined these functions:
 * - 2026_01_23_000000_create_freeradius_sql_functions.php
 * - 2026_01_25_000001_enforce_radius_mapping_only_and_migrate_public_rows.php
 * - 2026_01_28_000100_add_debug_logging_to_radius_functions.php
 * - 2026_01_28_000200_debug_get_user_schema.php
 * - 2026_01_29_000001_fix_radius_username_case_sensitivity.php
 * - 2026_01_31_000300_normalize_radius_username_in_sql_functions.php
 * 
 * CRITICAL: This is the ONLY migration that should define these functions going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Drop ALL existing RADIUS functions to ensure clean slate
        // Using explicit DROP statements with CASCADE to handle all dependencies
        DB::statement('DROP FUNCTION IF EXISTS public.get_user_schema(VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_authorize_check(VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_authorize_reply(VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_post_auth_insert(VARCHAR, VARCHAR, VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_onoff(VARCHAR, INTEGER, VARCHAR, INTEGER) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_start(VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, INTEGER, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_update(VARCHAR, VARCHAR, VARCHAR, INTEGER, BIGINT, BIGINT) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_stop(VARCHAR, VARCHAR, INTEGER, INTEGER, BIGINT, BIGINT, VARCHAR, VARCHAR) CASCADE');

        // Step 2: Create get_user_schema - THE CORE FUNCTION
        // This function determines which tenant schema a user belongs to
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.get_user_schema(p_username VARCHAR)
RETURNS VARCHAR
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_count INTEGER;
    v_username VARCHAR;
BEGIN
    -- Normalize username: lowercase, trim whitespace, remove control characters
    v_username := LOWER(BTRIM(REGEXP_REPLACE(COALESCE(p_username, ''), '[[:cntrl:]]', '', 'g')));

    RAISE NOTICE 'get_user_schema called for: %, normalized: %', p_username, v_username;

    -- Check if user is a system admin (in public schema) - case insensitive
    -- Guard: public.users may not exist yet before Laravel migrations run
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users') THEN
        SELECT 'public' INTO v_schema
        FROM public.users
        WHERE LOWER(BTRIM(REGEXP_REPLACE(COALESCE(username, ''), '[[:cntrl:]]', '', 'g'))) = v_username
        AND role = 'system_admin'
        LIMIT 1;
    END IF;

    RAISE NOTICE 'System admin check result: %', COALESCE(v_schema, 'NULL');

    IF v_schema IS NOT NULL THEN
        RETURN v_schema;
    END IF;

    -- Count total mappings in table (diagnostic)
    SELECT COUNT(*) INTO v_count FROM public.radius_user_schema_mapping;
    RAISE NOTICE 'Total mappings in radius_user_schema_mapping: %', v_count;

    -- Count mappings for this specific user - case insensitive + normalized
    SELECT COUNT(*) INTO v_count
    FROM public.radius_user_schema_mapping
    WHERE LOWER(BTRIM(REGEXP_REPLACE(COALESCE(username, ''), '[[:cntrl:]]', '', 'g'))) = v_username;
    RAISE NOTICE 'Mappings for user %: %', p_username, v_count;

    -- Count active mappings for this user
    SELECT COUNT(*) INTO v_count
    FROM public.radius_user_schema_mapping
    WHERE LOWER(BTRIM(REGEXP_REPLACE(COALESCE(username, ''), '[[:cntrl:]]', '', 'g'))) = v_username
      AND is_active = true;
    RAISE NOTICE 'Active mappings for user %: %', p_username, v_count;

    -- Get tenant schema from mapping table - case insensitive + normalized
    SELECT schema_name INTO v_schema
    FROM public.radius_user_schema_mapping
    WHERE LOWER(BTRIM(REGEXP_REPLACE(COALESCE(username, ''), '[[:cntrl:]]', '', 'g'))) = v_username
    AND is_active = true
    LIMIT 1;

    RAISE NOTICE 'Final schema result: %', COALESCE(v_schema, 'NULL');

    RETURN v_schema;
END;
$$;
SQL);

        // Step 3: Create radius_authorize_check - Returns radcheck entries for FreeRADIUS
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_authorize_check(p_username VARCHAR)
RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR)
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
    v_username VARCHAR;
BEGIN
    -- Normalize username
    v_username := BTRIM(REGEXP_REPLACE(COALESCE(p_username, ''), '[[:cntrl:]]', '', 'g'));

    -- Get user's schema
    v_schema := public.get_user_schema(v_username);

    RAISE NOTICE 'radius_authorize_check called for user: %, normalized: %, schema: %', p_username, v_username, COALESCE(v_schema, 'NULL');

    IF v_schema IS NULL OR v_schema = '' THEN
        RAISE NOTICE 'No schema found for user: %', p_username;
        RETURN;
    END IF;

    -- Query radcheck with case-insensitive username match
    v_query := format('SELECT id::INTEGER, username::VARCHAR, attribute::VARCHAR, value::VARCHAR, op::VARCHAR FROM %I.radcheck WHERE LOWER(username) = LOWER($1) ORDER BY id', v_schema);
    RAISE NOTICE 'Executing query: %', v_query;

    RETURN QUERY EXECUTE v_query USING v_username;

    RAISE NOTICE 'Query completed for user: %', p_username;
END;
$$;
SQL);

        // Step 4: Create radius_authorize_reply - Returns radreply entries for FreeRADIUS
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_authorize_reply(p_username VARCHAR)
RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR)
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
    v_username VARCHAR;
BEGIN
    v_username := BTRIM(REGEXP_REPLACE(COALESCE(p_username, ''), '[[:cntrl:]]', '', 'g'));
    v_schema := public.get_user_schema(v_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN;
    END IF;

    v_query := format('SELECT id::INTEGER, username::VARCHAR, attribute::VARCHAR, value::VARCHAR, op::VARCHAR FROM %I.radreply WHERE LOWER(username) = LOWER($1) ORDER BY id', v_schema);
    RETURN QUERY EXECUTE v_query USING v_username;
END;
$$;
SQL);

        // Step 5: Create radius_post_auth_insert - Logs authentication attempts
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_post_auth_insert(
    p_username VARCHAR,
    p_pass VARCHAR,
    p_reply VARCHAR
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
    v_username VARCHAR;
BEGIN
    v_username := BTRIM(REGEXP_REPLACE(COALESCE(p_username, ''), '[[:cntrl:]]', '', 'g'));
    v_schema := public.get_user_schema(v_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    v_query := format('INSERT INTO %I.radpostauth (username, pass, reply, authdate) VALUES ($1, $2, $3, NOW())', v_schema);
    EXECUTE v_query USING v_username, COALESCE(p_pass, ''), COALESCE(p_reply, '');

    RETURN 1;
END;
$$;
SQL);

        // Step 6: Create accounting functions
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_accounting_onoff(
    p_nas_ip VARCHAR,
    p_event_timestamp INTEGER,
    p_terminate_cause VARCHAR,
    p_delay INTEGER
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    RETURN 1;
END;
$$;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_accounting_start(
    p_acct_session_id VARCHAR,
    p_acct_unique_session_id VARCHAR,
    p_username VARCHAR,
    p_realm VARCHAR,
    p_nas_ip VARCHAR,
    p_nas_port_id VARCHAR,
    p_event_timestamp INTEGER,
    p_acct_authentic VARCHAR,
    p_connect_info VARCHAR,
    p_called_station_id VARCHAR,
    p_calling_station_id VARCHAR,
    p_service_type VARCHAR,
    p_framed_protocol VARCHAR,
    p_framed_ip_address VARCHAR
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_username VARCHAR;
BEGIN
    v_username := BTRIM(REGEXP_REPLACE(COALESCE(p_username, ''), '[[:cntrl:]]', '', 'g'));
    v_schema := public.get_user_schema(v_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    EXECUTE format('
        INSERT INTO %I.radacct (
            acctsessionid, acctuniqueid, username, realm, nasipaddress, nasportid,
            acctstarttime, acctupdatetime, acctauthentic, connectinfo_start,
            calledstationid, callingstationid, servicetype, framedprotocol,
            framedipaddress, acctstartdelay
        )
        SELECT $1, $2, $3, NULLIF($4, ''''), NULLIF($5, '''')::inet, NULLIF($6, ''''),
               TO_TIMESTAMP($7), TO_TIMESTAMP($7), NULLIF($8, ''''), NULLIF($9, ''''),
               NULLIF($10, ''''), NULLIF($11, ''''), NULLIF($12, ''''), NULLIF($13, ''''),
               NULLIF($14, '''')::inet, 0
        WHERE NOT EXISTS (SELECT 1 FROM %I.radacct WHERE acctuniqueid = $2)
    ', v_schema, v_schema)
    USING p_acct_session_id, p_acct_unique_session_id, v_username, p_realm, p_nas_ip,
          p_nas_port_id, p_event_timestamp, p_acct_authentic, p_connect_info,
          p_called_station_id, p_calling_station_id, p_service_type, p_framed_protocol,
          p_framed_ip_address;

    RETURN 1;
END;
$$;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_accounting_update(
    p_username VARCHAR,
    p_acct_unique_session_id VARCHAR,
    p_framed_ip_address VARCHAR,
    p_acct_session_time INTEGER,
    p_acct_input_octets BIGINT,
    p_acct_output_octets BIGINT
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_username VARCHAR;
BEGIN
    v_username := BTRIM(REGEXP_REPLACE(COALESCE(p_username, ''), '[[:cntrl:]]', '', 'g'));
    v_schema := public.get_user_schema(v_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    EXECUTE format('
        UPDATE %I.radacct
        SET acctupdatetime = NOW(), acctsessiontime = $1, acctinputoctets = $2,
            acctoutputoctets = $3, framedipaddress = NULLIF($4, '''')::inet
        WHERE acctuniqueid = $5 AND LOWER(username) = LOWER($6)
    ', v_schema)
    USING p_acct_session_time, p_acct_input_octets, p_acct_output_octets,
          p_framed_ip_address, p_acct_unique_session_id, v_username;

    RETURN 1;
END;
$$;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_accounting_stop(
    p_username VARCHAR,
    p_acct_unique_session_id VARCHAR,
    p_event_timestamp INTEGER,
    p_acct_session_time INTEGER,
    p_acct_input_octets BIGINT,
    p_acct_output_octets BIGINT,
    p_acct_terminate_cause VARCHAR,
    p_connect_info VARCHAR
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_username VARCHAR;
BEGIN
    v_username := BTRIM(REGEXP_REPLACE(COALESCE(p_username, ''), '[[:cntrl:]]', '', 'g'));
    v_schema := public.get_user_schema(v_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    EXECUTE format('
        UPDATE %I.radacct
        SET acctstoptime = TO_TIMESTAMP($1), acctsessiontime = $2,
            acctinputoctets = $3, acctoutputoctets = $4,
            acctterminatecause = NULLIF($5, ''''), connectinfo_stop = NULLIF($6, '''')
        WHERE acctuniqueid = $7 AND LOWER(username) = LOWER($8)
    ', v_schema)
    USING p_event_timestamp, p_acct_session_time, p_acct_input_octets, p_acct_output_octets,
          p_acct_terminate_cause, p_connect_info, p_acct_unique_session_id, v_username;

    RETURN 1;
END;
$$;
SQL);

        // Step 7: Grant permissions to admin user (used by FreeRADIUS)
        DB::statement("GRANT SELECT ON public.users TO admin");
        DB::statement("GRANT SELECT ON public.radius_user_schema_mapping TO admin");
        
        // Grant EXECUTE on all functions
        DB::statement("GRANT EXECUTE ON FUNCTION public.get_user_schema(VARCHAR) TO admin");
        DB::statement("GRANT EXECUTE ON FUNCTION public.radius_authorize_check(VARCHAR) TO admin");
        DB::statement("GRANT EXECUTE ON FUNCTION public.radius_authorize_reply(VARCHAR) TO admin");
        DB::statement("GRANT EXECUTE ON FUNCTION public.radius_post_auth_insert(VARCHAR, VARCHAR, VARCHAR) TO admin");
        DB::statement("GRANT EXECUTE ON FUNCTION public.radius_accounting_onoff(VARCHAR, INTEGER, VARCHAR, INTEGER) TO admin");
        DB::statement("GRANT EXECUTE ON FUNCTION public.radius_accounting_start(VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, INTEGER, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR) TO admin");
        DB::statement("GRANT EXECUTE ON FUNCTION public.radius_accounting_update(VARCHAR, VARCHAR, VARCHAR, INTEGER, BIGINT, BIGINT) TO admin");
        DB::statement("GRANT EXECUTE ON FUNCTION public.radius_accounting_stop(VARCHAR, VARCHAR, INTEGER, INTEGER, BIGINT, BIGINT, VARCHAR, VARCHAR) TO admin");

        \Log::info("Consolidated RADIUS functions migration completed - all functions recreated with proper permissions");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_stop(VARCHAR, VARCHAR, INTEGER, INTEGER, BIGINT, BIGINT, VARCHAR, VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_update(VARCHAR, VARCHAR, VARCHAR, INTEGER, BIGINT, BIGINT) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_start(VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, INTEGER, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_accounting_onoff(VARCHAR, INTEGER, VARCHAR, INTEGER) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_post_auth_insert(VARCHAR, VARCHAR, VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_authorize_reply(VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.radius_authorize_check(VARCHAR) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.get_user_schema(VARCHAR) CASCADE');
    }
};
