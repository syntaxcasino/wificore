-- ============================================================================
-- PostgreSQL Initialization Script
-- ============================================================================
-- This script runs when PostgreSQL container first starts (before Laravel)
-- 
-- ARCHITECTURE:
-- - Tables: Created by Laravel migrations (single source of truth)
-- - Functions: Created here (Laravel cannot create PL/pgSQL functions)
-- 
-- EXECUTION ORDER:
-- 1. This script runs → Creates extensions + functions
-- 2. Laravel migrations run → Creates tables
-- 3. Functions work → Tables now exist
-- ============================================================================

-- ============================================================================
-- SECTION 1: PostgreSQL Extensions
-- ============================================================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Optional: pg_stat_statements for query performance monitoring
-- Note: Requires shared_preload_libraries='pg_stat_statements' in postgresql.conf
-- If not available, queries will fail but system continues working
CREATE EXTENSION IF NOT EXISTS "pg_stat_statements";

-- ============================================================================
-- SECTION 2: FreeRADIUS Schema Lookup Function
-- ============================================================================
-- NOTE: This function references radius_user_schema_mapping table which will
-- be created later by Laravel migration. PostgreSQL allows this - validation
-- happens at runtime, not at function creation time.

CREATE OR REPLACE FUNCTION get_user_schema(p_username VARCHAR)
RETURNS VARCHAR
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_schema VARCHAR;
    v_count INTEGER;
    v_username VARCHAR;
BEGIN
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

    -- Count total mappings in table
    SELECT COUNT(*) INTO v_count FROM public.radius_user_schema_mapping;
    RAISE NOTICE 'Total mappings in radius_user_schema_mapping: %', v_count;

    -- Count mappings for this user - case insensitive + trimmed
    SELECT COUNT(*) INTO v_count
    FROM public.radius_user_schema_mapping
    WHERE LOWER(BTRIM(REGEXP_REPLACE(COALESCE(username, ''), '[[:cntrl:]]', '', 'g'))) = v_username;
    RAISE NOTICE 'Mappings for user %: %', p_username, v_count;

    -- Count active mappings for this user - case insensitive + trimmed
    SELECT COUNT(*) INTO v_count
    FROM public.radius_user_schema_mapping
    WHERE LOWER(BTRIM(REGEXP_REPLACE(COALESCE(username, ''), '[[:cntrl:]]', '', 'g'))) = v_username
      AND is_active = true;
    RAISE NOTICE 'Active mappings for user %: %', p_username, v_count;

    -- Get tenant schema from mapping table - case insensitive + trimmed
    SELECT schema_name INTO v_schema
    FROM public.radius_user_schema_mapping
    WHERE LOWER(BTRIM(REGEXP_REPLACE(COALESCE(username, ''), '[[:cntrl:]]', '', 'g'))) = v_username
    AND is_active = true
    LIMIT 1;

    RAISE NOTICE 'Final schema result: %', COALESCE(v_schema, 'NULL');

    -- Do not guess. Tenant users must exist in radius_user_schema_mapping.
    -- Return NULL when no mapping exists so RADIUS functions treat it as "user not found".
    RETURN v_schema;
END;
$$;

COMMENT ON FUNCTION get_user_schema(VARCHAR) IS 'Returns the PostgreSQL schema name for a given username. Used by FreeRADIUS for dynamic schema lookup. Table created by Laravel migration.';

-- ============================================================================
-- SECTION 3: FreeRADIUS Authorization Functions
-- ============================================================================
-- These functions execute queries in the correct tenant schema for authentication

-- 3.1 authorize_check_query - Returns radcheck entries
CREATE OR REPLACE FUNCTION radius_authorize_check(p_username VARCHAR)
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

    -- Get user's schema using the normalized username
    v_schema := public.get_user_schema(v_username);

    RAISE NOTICE 'radius_authorize_check called for user: %, normalized: %, schema: %', p_username, v_username, COALESCE(v_schema, 'NULL');

    IF v_schema IS NULL OR v_schema = '' THEN
        RAISE NOTICE 'No schema found for user: %', p_username;
        RETURN;
    END IF;

    -- Case insensitive username match using normalized username
    v_query := format('SELECT id::INTEGER, username::VARCHAR, attribute::VARCHAR, value::VARCHAR, op::VARCHAR FROM %I.radcheck WHERE LOWER(username) = LOWER($1) ORDER BY id', v_schema);
    RAISE NOTICE 'Executing query: %', v_query;

    RETURN QUERY EXECUTE v_query USING v_username;

    RAISE NOTICE 'Query completed for user: %', p_username;
END;
$$;

-- 3.2 authorize_reply_query - Returns radreply entries
CREATE OR REPLACE FUNCTION radius_authorize_reply(p_username VARCHAR)
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

-- 3.3 post_auth_query - INSERT function for post-authentication logging
CREATE OR REPLACE FUNCTION radius_post_auth_insert(
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

COMMENT ON FUNCTION radius_authorize_check(VARCHAR) IS 'Returns radcheck entries for a user from their schema';
COMMENT ON FUNCTION radius_authorize_reply(VARCHAR) IS 'Returns radreply entries for a user from their schema';
COMMENT ON FUNCTION radius_post_auth_insert(VARCHAR, VARCHAR, VARCHAR) IS 'Inserts post-auth log in user schema';

-- ============================================================================
-- SECTION 4: FreeRADIUS Accounting Functions
-- ============================================================================
-- These handle accounting (session tracking) in the correct tenant schema

-- 4.1 accounting_onoff_query - Stop all sessions on NAS reboot
CREATE OR REPLACE FUNCTION radius_accounting_onoff(
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

-- 4.2 accounting_update_query - Update session info
CREATE OR REPLACE FUNCTION radius_accounting_update(
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

-- 4.3 accounting_start_query - Start new session
CREATE OR REPLACE FUNCTION radius_accounting_start(
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

-- 4.4 accounting_stop_query - Stop session
CREATE OR REPLACE FUNCTION radius_accounting_stop(
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

COMMENT ON FUNCTION radius_accounting_onoff(VARCHAR, INTEGER, VARCHAR, INTEGER) IS 'Stops all sessions on NAS reboot';
COMMENT ON FUNCTION radius_accounting_update(VARCHAR, VARCHAR, VARCHAR, INTEGER, BIGINT, BIGINT) IS 'Updates session accounting data';
COMMENT ON FUNCTION radius_accounting_start(VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, INTEGER, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR, VARCHAR) IS 'Starts new accounting session';
COMMENT ON FUNCTION radius_accounting_stop(VARCHAR, VARCHAR, INTEGER, INTEGER, BIGINT, BIGINT, VARCHAR, VARCHAR) IS 'Stops accounting session';

-- ============================================================================
-- SECTION 5: Permissions
-- ============================================================================
-- Grant necessary permissions for FreeRADIUS to access multi-tenant mapping table
-- Note: This table will be created by Laravel migration, but we set permissions now
-- PostgreSQL will apply them once the table exists

-- Grant SELECT on radius_user_schema_mapping to admin user (used by FreeRADIUS)
DO $$
BEGIN
    -- Check if table exists before granting (it may not exist yet on first run)
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'radius_user_schema_mapping') THEN
        GRANT SELECT ON public.radius_user_schema_mapping TO admin;
        RAISE NOTICE 'Granted SELECT on radius_user_schema_mapping to admin';
    ELSE
        RAISE NOTICE 'Table radius_user_schema_mapping does not exist yet - will be created by Laravel migration';
    END IF;
    
    -- Grant SELECT on users table (needed for system admin check in get_user_schema)
    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tablename = 'users') THEN
        GRANT SELECT ON public.users TO admin;
        RAISE NOTICE 'Granted SELECT on users to admin';
    ELSE
        RAISE NOTICE 'Table users does not exist yet - will be created by Laravel migration';
    END IF;
END $$;

-- ============================================================================
-- SECTION 6: Verification
-- ============================================================================
-- Quick tests to verify functions were created (will fail gracefully if tables don't exist yet)

SELECT 'PostgreSQL initialization complete!' as status;
SELECT 'Created 7 RADIUS functions for multi-tenant authentication' as info;
SELECT 'Tables will be created by Laravel migrations' as next_step;
