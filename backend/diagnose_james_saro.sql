-- =====================================================
-- Diagnostic Script for james.saro Authentication Issue
-- Run this on production database: wms_770_ts
-- =====================================================

\echo '=== Step 1: Check radius_user_schema_mapping ==='
SELECT 
    username,
    schema_name,
    tenant_id,
    is_active,
    created_at,
    updated_at
FROM public.radius_user_schema_mapping
WHERE username = 'james.saro';

\echo ''
\echo '=== Step 2: Test get_user_schema function ==='
SELECT get_user_schema('james.saro') AS detected_schema;

\echo ''
\echo '=== Step 3: Check if user exists in users table ==='
-- First check public schema
SELECT 
    'public' AS schema,
    id,
    username,
    email,
    role,
    created_at
FROM public.users
WHERE username = 'james.saro';

-- Check all tenant schemas (replace with actual schema names)
\echo ''
\echo '=== Step 4: List all tenant schemas ==='
SELECT 
    schema_name
FROM information_schema.schemata
WHERE schema_name LIKE 'ts_%'
ORDER BY schema_name;

\echo ''
\echo '=== Step 5: Check radcheck in detected schema ==='
-- This will be run dynamically based on the schema found
DO $$
DECLARE
    v_schema VARCHAR;
    v_query TEXT;
BEGIN
    -- Get the schema for james.saro
    v_schema := get_user_schema('james.saro');
    
    IF v_schema IS NULL OR v_schema = '' THEN
        RAISE NOTICE 'ERROR: No schema found for james.saro in radius_user_schema_mapping';
        RAISE NOTICE 'This is the root cause - the mapping is missing or inactive';
    ELSE
        RAISE NOTICE 'Found schema: %', v_schema;
        
        -- Check radcheck table
        v_query := format('SELECT COUNT(*) FROM %I.radcheck WHERE username = $1', v_schema);
        EXECUTE v_query USING 'james.saro' INTO v_query;
        RAISE NOTICE 'radcheck entries: %', v_query;
        
        -- Show actual radcheck entries
        RAISE NOTICE 'Radcheck entries for james.saro:';
        FOR v_query IN EXECUTE format('
            SELECT 
                id,
                username,
                attribute,
                op,
                CASE 
                    WHEN attribute IN (''Cleartext-Password'', ''NT-Password'') THEN ''[HIDDEN]''
                    ELSE value
                END AS value,
                created_at
            FROM %I.radcheck
            WHERE username = $1
            ORDER BY id
        ', v_schema) USING 'james.saro'
        LOOP
            RAISE NOTICE '%', v_query;
        END LOOP;
        
        -- Check radreply table
        v_query := format('SELECT COUNT(*) FROM %I.radreply WHERE username = $1', v_schema);
        EXECUTE v_query USING 'james.saro' INTO v_query;
        RAISE NOTICE 'radreply entries: %', v_query;
    END IF;
END $$;

\echo ''
\echo '=== Step 6: Check all radius_user_schema_mapping entries ==='
SELECT 
    COUNT(*) AS total_mappings,
    COUNT(CASE WHEN is_active THEN 1 END) AS active_mappings,
    COUNT(CASE WHEN NOT is_active THEN 1 END) AS inactive_mappings
FROM public.radius_user_schema_mapping;

\echo ''
\echo '=== Step 7: Find james.saro in any tenant schema ==='
-- Search for james.saro in all tenant schemas
DO $$
DECLARE
    v_schema RECORD;
    v_count INTEGER;
BEGIN
    FOR v_schema IN 
        SELECT schema_name 
        FROM information_schema.schemata 
        WHERE schema_name LIKE 'ts_%'
    LOOP
        BEGIN
            EXECUTE format('SELECT COUNT(*) FROM %I.radcheck WHERE username = $1', v_schema.schema_name)
            INTO v_count
            USING 'james.saro';
            
            IF v_count > 0 THEN
                RAISE NOTICE 'Found james.saro in schema: % (% entries)', v_schema.schema_name, v_count;
            END IF;
        EXCEPTION WHEN OTHERS THEN
            -- Schema might not have radcheck table, skip
            NULL;
        END;
    END LOOP;
END $$;
