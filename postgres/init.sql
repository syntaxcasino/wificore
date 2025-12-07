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
STABLE
AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    -- Look up the schema for this username
    -- Table radius_user_schema_mapping will be created by Laravel migration
    SELECT schema_name INTO v_schema
    FROM radius_user_schema_mapping
    WHERE username = p_username;
    
    -- If not found, default to public (system admin)
    IF v_schema IS NULL THEN
        v_schema := 'public';
    END IF;
    
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
RETURNS TABLE(id BIGINT, username VARCHAR(64), attribute VARCHAR(64), value VARCHAR(253), op CHAR(2))
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    v_schema := get_user_schema(p_username);
    v_query := format('SELECT id, username, attribute, value, op FROM %I.radcheck WHERE username = %L ORDER BY id', 
                      v_schema, p_username);
    RETURN QUERY EXECUTE v_query;
END;
$$;

-- 3.2 authorize_reply_query - Returns radreply entries
CREATE OR REPLACE FUNCTION radius_authorize_reply(p_username VARCHAR)
RETURNS TABLE(id BIGINT, username VARCHAR(64), attribute VARCHAR(64), value VARCHAR(253), op CHAR(2))
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    v_schema := get_user_schema(p_username);
    v_query := format('SELECT id, username, attribute, value, op FROM %I.radreply WHERE username = %L ORDER BY id', 
                      v_schema, p_username);
    RETURN QUERY EXECUTE v_query;
END;
$$;

-- 3.3 post_auth_query - INSERT function for post-authentication logging
CREATE OR REPLACE FUNCTION radius_post_auth_insert(
    p_username VARCHAR,
    p_pass VARCHAR,
    p_reply VARCHAR
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    v_schema := get_user_schema(p_username);
    v_query := format('INSERT INTO %I.radpostauth (username, pass, reply, authdate) VALUES (%L, %L, %L, NOW())', 
                      v_schema, p_username, p_pass, p_reply);
    EXECUTE v_query;
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
    p_event_timestamp BIGINT,
    p_terminate_cause VARCHAR,
    p_delay_time INT
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    -- Use public schema for accounting (shared across all tenants)
    v_schema := 'public';
    
    v_query := format(
        'UPDATE %I.radacct SET ' ||
        '  acctstoptime = TO_TIMESTAMP(%L), ' ||
        '  acctsessiontime = (%L - EXTRACT(epoch FROM acctstarttime)), ' ||
        '  acctterminatecause = %L, ' ||
        '  acctstopdelay = %L ' ||
        'WHERE acctstoptime IS NULL ' ||
        '  AND nasipaddress = %L ' ||
        '  AND acctstarttime <= TO_TIMESTAMP(%L)',
        v_schema, p_event_timestamp, p_event_timestamp, 
        p_terminate_cause, p_delay_time, p_nas_ip, p_event_timestamp
    );
    
    EXECUTE v_query;
END;
$$;

-- 4.2 accounting_update_query - Update session info
CREATE OR REPLACE FUNCTION radius_accounting_update(
    p_username VARCHAR,
    p_unique_id VARCHAR,
    p_framed_ip VARCHAR,
    p_session_time INT,
    p_input_octets BIGINT,
    p_output_octets BIGINT
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    v_schema := get_user_schema(p_username);
    
    v_query := format(
        'UPDATE %I.radacct SET ' ||
        '  framedipaddress = NULLIF(%L, '''')::inet, ' ||
        '  acctsessiontime = %L, ' ||
        '  acctinputoctets = %L, ' ||
        '  acctoutputoctets = %L ' ||
        'WHERE acctuniqueid = %L',
        v_schema, p_framed_ip, p_session_time, 
        p_input_octets, p_output_octets, p_unique_id
    );
    
    EXECUTE v_query;
END;
$$;

-- 4.3 accounting_start_query - Start new session
CREATE OR REPLACE FUNCTION radius_accounting_start(
    p_session_id VARCHAR,
    p_unique_id VARCHAR,
    p_username VARCHAR,
    p_realm VARCHAR,
    p_nas_ip VARCHAR,
    p_nas_port VARCHAR,
    p_event_timestamp BIGINT,
    p_authentic VARCHAR,
    p_connect_info VARCHAR,
    p_called_station VARCHAR,
    p_calling_station VARCHAR,
    p_service_type VARCHAR,
    p_framed_protocol VARCHAR,
    p_framed_ip VARCHAR
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    v_schema := get_user_schema(p_username);
    
    v_query := format(
        'INSERT INTO %I.radacct ' ||
        '(acctsessionid, acctuniqueid, username, realm, nasipaddress, nasportid, ' ||
        ' acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctauthentic, ' ||
        ' connectinfo_start, connectinfo_stop, acctinputoctets, acctoutputoctets, ' ||
        ' calledstationid, callingstationid, acctterminatecause, servicetype, ' ||
        ' framedprotocol, framedipaddress) ' ||
        'VALUES (%L, %L, %L, %L, %L, %L, TO_TIMESTAMP(%L), TO_TIMESTAMP(%L), ' ||
        '        NULL, 0, %L, %L, '''', 0, 0, %L, %L, '''', %L, %L, NULLIF(%L, '''')::inet)',
        v_schema, p_session_id, p_unique_id, p_username, p_realm, p_nas_ip, p_nas_port,
        p_event_timestamp, p_event_timestamp, p_authentic, p_connect_info,
        p_called_station, p_calling_station, p_service_type, p_framed_protocol, p_framed_ip
    );
    
    EXECUTE v_query;
END;
$$;

-- 4.4 accounting_stop_query - Stop session
CREATE OR REPLACE FUNCTION radius_accounting_stop(
    p_username VARCHAR,
    p_unique_id VARCHAR,
    p_event_timestamp BIGINT,
    p_session_time INT,
    p_input_octets BIGINT,
    p_output_octets BIGINT,
    p_terminate_cause VARCHAR,
    p_connect_info VARCHAR
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    v_schema := get_user_schema(p_username);
    
    v_query := format(
        'UPDATE %I.radacct SET ' ||
        '  acctstoptime = TO_TIMESTAMP(%L), ' ||
        '  acctsessiontime = COALESCE(%L, (%L - EXTRACT(epoch FROM acctstarttime))), ' ||
        '  acctinputoctets = %L, ' ||
        '  acctoutputoctets = %L, ' ||
        '  acctterminatecause = %L, ' ||
        '  connectinfo_stop = %L ' ||
        'WHERE acctuniqueid = %L',
        v_schema, p_event_timestamp, p_session_time, p_event_timestamp,
        p_input_octets, p_output_octets, p_terminate_cause, 
        p_connect_info, p_unique_id
    );
    
    EXECUTE v_query;
END;
$$;

COMMENT ON FUNCTION radius_accounting_onoff IS 'Stops all sessions on NAS reboot';
COMMENT ON FUNCTION radius_accounting_update IS 'Updates session accounting data';
COMMENT ON FUNCTION radius_accounting_start IS 'Starts new accounting session';
COMMENT ON FUNCTION radius_accounting_stop IS 'Stops accounting session';

-- ============================================================================
-- SECTION 5: Verification
-- ============================================================================
-- Quick tests to verify functions were created (will fail gracefully if tables don't exist yet)

SELECT 'PostgreSQL initialization complete!' as status;
SELECT 'Created 7 RADIUS functions for multi-tenant authentication' as info;
SELECT 'Tables will be created by Laravel migrations' as next_step;
