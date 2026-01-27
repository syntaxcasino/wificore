-- =====================================================
-- Fix Script for james.saro RADIUS Authentication
-- Run this AFTER running diagnose_james_saro.sql
-- =====================================================

-- INSTRUCTIONS:
-- 1. First run diagnose_james_saro.sql to identify the issue
-- 2. Based on the diagnosis, uncomment the appropriate fix below
-- 3. Replace placeholders with actual values from your system

\echo '=== RADIUS Authentication Fix for james.saro ==='
\echo 'Choose the appropriate fix based on diagnosis results:'
\echo ''

-- =====================================================
-- FIX 1: Missing radius_user_schema_mapping entry
-- Use this if Step 1 of diagnosis returned 0 rows
-- =====================================================
\echo 'FIX 1: Add missing radius_user_schema_mapping entry'
\echo 'Uncomment and modify the following if mapping is missing:'
\echo ''

-- First, find the correct tenant_id and schema for james.saro
-- Run this query to find the user:
/*
SELECT 
    u.id AS user_id,
    u.username,
    u.email,
    t.id AS tenant_id,
    t.schema_name
FROM ts_XXXXXX.users u  -- Replace XXXXXX with actual schema
JOIN public.tenants t ON t.schema_name = 'ts_XXXXXX'
WHERE u.username = 'james.saro';
*/

-- Then insert the mapping (replace values):
/*
INSERT INTO public.radius_user_schema_mapping (
    username,
    schema_name,
    tenant_id,
    is_active,
    created_at,
    updated_at
) VALUES (
    'james.saro',
    'ts_XXXXXX',  -- Replace with actual schema name
    'TENANT-UUID-HERE',  -- Replace with actual tenant UUID
    true,
    NOW(),
    NOW()
)
ON CONFLICT (username) DO UPDATE SET
    schema_name = EXCLUDED.schema_name,
    tenant_id = EXCLUDED.tenant_id,
    is_active = true,
    updated_at = NOW();
*/

-- =====================================================
-- FIX 2: Inactive mapping
-- Use this if Step 1 shows is_active = false
-- =====================================================
\echo ''
\echo 'FIX 2: Reactivate inactive mapping'
\echo 'Uncomment if mapping exists but is_active = false:'
\echo ''

/*
UPDATE public.radius_user_schema_mapping
SET 
    is_active = true,
    updated_at = NOW()
WHERE username = 'james.saro';
*/

-- =====================================================
-- FIX 3: Missing radcheck entries in tenant schema
-- Use this if mapping exists but radcheck is empty
-- =====================================================
\echo ''
\echo 'FIX 3: Add missing radcheck entries'
\echo 'Uncomment and modify if user exists but radcheck is empty:'
\echo ''

-- You need to get the password from the users table first:
/*
DO $$
DECLARE
    v_schema VARCHAR;
    v_password VARCHAR;
    v_nt_hash VARCHAR;
BEGIN
    -- Get schema
    v_schema := get_user_schema('james.saro');
    
    IF v_schema IS NULL THEN
        RAISE EXCEPTION 'Cannot find schema for james.saro';
    END IF;
    
    -- Get password from users table
    EXECUTE format('SELECT password FROM %I.users WHERE username = $1', v_schema)
    INTO v_password
    USING 'james.saro';
    
    IF v_password IS NULL THEN
        RAISE EXCEPTION 'User james.saro not found in schema %', v_schema;
    END IF;
    
    -- Note: Laravel stores hashed passwords, but RADIUS needs plaintext or NT hash
    -- You'll need to either:
    -- 1. Reset the user's password and capture it in plaintext
    -- 2. Or calculate NT-Password hash from the plaintext password
    
    RAISE NOTICE 'Found user in schema: %', v_schema;
    RAISE NOTICE 'Password is hashed (Laravel bcrypt): %', LEFT(v_password, 20) || '...';
    RAISE NOTICE 'You need to reset password or provide plaintext to generate RADIUS credentials';
    
    -- If you have the plaintext password, uncomment and use this:
    -- v_password := 'PLAINTEXT_PASSWORD_HERE';
    -- v_nt_hash := encode(digest(convert_to(v_password, 'UTF16LE'), 'md4'), 'hex');
    
    -- Insert radcheck entries
    -- EXECUTE format('
    --     INSERT INTO %I.radcheck (username, attribute, op, value, created_at, updated_at)
    --     VALUES 
    --         ($1, ''Cleartext-Password'', '':='', $2, NOW(), NOW()),
    --         ($1, ''NT-Password'', '':='', $3, NOW(), NOW()),
    --         ($1, ''Simultaneous-Use'', '':='', ''1'', NOW(), NOW())
    --     ON CONFLICT (username, attribute) DO UPDATE SET
    --         value = EXCLUDED.value,
    --         updated_at = NOW()
    -- ', v_schema)
    -- USING 'james.saro', v_password, v_nt_hash;
    
END $$;
*/

-- =====================================================
-- FIX 4: Re-sync user from Laravel application
-- This is the RECOMMENDED approach
-- =====================================================
\echo ''
\echo 'FIX 4: Re-sync from Laravel (RECOMMENDED)'
\echo 'Run this artisan command on the production server:'
\echo ''
\echo 'cd /opt/wificore/backend'
\echo 'docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker'
\echo ''
\echo 'Then in tinker, run:'
\echo ''
\echo '$user = App\Models\User::where("username", "james.saro")->first();'
\echo 'if ($user) {'
\echo '    // This will re-sync RADIUS credentials'
\echo '    $controller = new App\Http\Controllers\Api\PppoeUserController();'
\echo '    $reflection = new ReflectionClass($controller);'
\echo '    $method = $reflection->getMethod("syncRadiusForUser");'
\echo '    $method->setAccessible(true);'
\echo '    '
\echo '    // You need the plaintext password - if you dont have it, reset it first'
\echo '    $plainPassword = "USER_PASSWORD_HERE";'
\echo '    '
\echo '    $method->invoke('
\echo '        $controller,'
\echo '        $user->username,'
\echo '        $plainPassword,'
\echo '        $user->expires_at,'
\echo '        $user->rate_limit,'
\echo '        $user->simultaneous_use ?? 1,'
\echo '        $user->tenant->schema_name,'
\echo '        $user->tenant_id'
\echo '    );'
\echo '    '
\echo '    echo "RADIUS sync completed for " . $user->username;'
\echo '} else {'
\echo '    echo "User not found";'
\echo '}'
\echo ''

-- =====================================================
-- VERIFICATION QUERIES
-- Run these after applying any fix
-- =====================================================
\echo ''
\echo '=== VERIFICATION ==='
\echo 'After applying fix, run these queries to verify:'
\echo ''

-- Test the complete flow
SELECT 'Testing radius_authorize_check function:' AS step;
SELECT * FROM public.radius_authorize_check('james.saro');

SELECT 'Testing radius_authorize_reply function:' AS step;
SELECT * FROM public.radius_authorize_reply('james.saro');

SELECT 'Checking mapping:' AS step;
SELECT 
    username,
    schema_name,
    is_active,
    updated_at
FROM public.radius_user_schema_mapping
WHERE username = 'james.saro';

\echo ''
\echo '=== If verification shows data, test with radtest ==='
\echo 'From the server, run:'
\echo 'docker compose -f docker-compose.production.yml exec wificore-freeradius radtest james.saro PASSWORD localhost 0 testing123'
\echo ''
