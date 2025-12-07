-- Create complete test user for Tenant A
-- Tenant: ts_6afeb880f879
-- User: testuser / Test@123!

BEGIN;

-- 1. Create user in public.users table
INSERT INTO users (id, tenant_id, name, username, email, password, role, is_active, created_at, updated_at)
VALUES (
    gen_random_uuid(),
    '5c767124-5fd3-42b2-badf-77b5d4a13a93',  -- Tenant A
    'Test User',
    'testuser',
    'testuser@tenanta.com',
    '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5OMgDobMRvxPO', -- Test@123!
    'admin',
    true,
    NOW(),
    NOW()
) ON CONFLICT (username) DO NOTHING;

-- 2. Add RADIUS credentials to tenant schema
SET search_path TO ts_6afeb880f879, public;

INSERT INTO radcheck (username, attribute, op, value, created_at, updated_at)
VALUES ('testuser', 'Cleartext-Password', ':=', 'Test@123!', NOW(), NOW())
ON CONFLICT DO NOTHING;

INSERT INTO radreply (username, attribute, op, value, created_at, updated_at)
VALUES 
    ('testuser', 'Service-Type', ':=', 'Administrative-User', NOW(), NOW()),
    ('testuser', 'Tenant-ID', ':=', 'ts_6afeb880f879', NOW(), NOW())
ON CONFLICT DO NOTHING;

-- 3. Add to radius_user_schema_mapping in public schema
SET search_path TO public;

INSERT INTO radius_user_schema_mapping (username, schema_name, created_at)
VALUES ('testuser', 'ts_6afeb880f879', NOW())
ON CONFLICT (username) DO UPDATE SET schema_name = EXCLUDED.schema_name;

COMMIT;

-- Verify everything
SELECT '=== USER CREATED ===' as status;
SELECT id, username, email, role, tenant_id, is_active FROM users WHERE username = 'testuser';

SELECT '=== RADIUS CHECK ===' as status;
SET search_path TO ts_6afeb880f879, public;
SELECT username, attribute, op, value FROM radcheck WHERE username = 'testuser';

SELECT '=== RADIUS REPLY ===' as status;
SELECT username, attribute, op, value FROM radreply WHERE username = 'testuser';

SELECT '=== SCHEMA MAPPING ===' as status;
SET search_path TO public;
SELECT username, schema_name FROM radius_user_schema_mapping WHERE username = 'testuser';
