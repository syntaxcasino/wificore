-- Fix radius_authorize_check and radius_authorize_reply functions
-- This script removes SET search_path which doesn't work in FreeRADIUS connection pool

-- Drop and recreate radius_authorize_check
DROP FUNCTION IF EXISTS radius_authorize_check(VARCHAR) CASCADE;

CREATE OR REPLACE FUNCTION radius_authorize_check(p_username VARCHAR)
RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR) AS $func$
DECLARE
    v_schema VARCHAR;
BEGIN
    -- Get user's schema
    v_schema := get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN;
    END IF;
    
    -- Query radcheck table using fully qualified name
    RETURN QUERY EXECUTE format('
        SELECT 
            id::INTEGER,
            username::VARCHAR,
            attribute::VARCHAR,
            value::VARCHAR,
            op::VARCHAR
        FROM %I.radcheck
        WHERE username = $1
        ORDER BY id
    ', v_schema)
    USING p_username;
END;
$func$ LANGUAGE plpgsql SECURITY DEFINER;

-- Drop and recreate radius_authorize_reply
DROP FUNCTION IF EXISTS radius_authorize_reply(VARCHAR) CASCADE;

CREATE OR REPLACE FUNCTION radius_authorize_reply(p_username VARCHAR)
RETURNS TABLE(id INTEGER, username VARCHAR, attribute VARCHAR, value VARCHAR, op VARCHAR) AS $func$
DECLARE
    v_schema VARCHAR;
BEGIN
    -- Get user's schema
    v_schema := get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN;
    END IF;
    
    -- Query radreply table using fully qualified name
    RETURN QUERY EXECUTE format('
        SELECT 
            id::INTEGER,
            username::VARCHAR,
            attribute::VARCHAR,
            value::VARCHAR,
            op::VARCHAR
        FROM %I.radreply
        WHERE username = $1
        ORDER BY id
    ', v_schema)
    USING p_username;
END;
$func$ LANGUAGE plpgsql SECURITY DEFINER;
