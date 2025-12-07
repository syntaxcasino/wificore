-- Create test user for tenant
INSERT INTO users (id, tenant_id, name, username, email, password, role, is_active, created_at, updated_at)
VALUES (
    gen_random_uuid(),
    '71331238-e316-46e2-bf74-79d6ec74169a',
    'Test Admin E2E',
    'testadmin',
    'testadmin@teste2e.com',
    '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5OMgDobMRvxPO', -- Test@123!
    'admin',
    true,
    NOW(),
    NOW()
);

-- Add RADIUS credentials to tenant schema
SET search_path TO ts_97bb2a895800, public;

INSERT INTO radcheck (username, attribute, op, value, created_at, updated_at)
VALUES ('testadmin', 'Cleartext-Password', ':=', 'Test@123!', NOW(), NOW());

INSERT INTO radreply (username, attribute, op, value, created_at, updated_at)
VALUES ('testadmin', 'Service-Type', ':=', 'Administrative-User', NOW(), NOW());

-- Add to radius_user_schema_mapping
SET search_path TO public;

INSERT INTO radius_user_schema_mapping (username, schema_name, created_at)
VALUES ('testadmin', 'ts_97bb2a895800', NOW());

-- Verify
SELECT 'User created:' as status, id, username, email, role, tenant_id FROM users WHERE username = 'testadmin';
SET search_path TO ts_97bb2a895800, public;
SELECT 'RADIUS credentials:' as status, username, attribute, value FROM radcheck WHERE username = 'testadmin';
SET search_path TO public;
SELECT 'Schema mapping:' as status, username, schema_name FROM radius_user_schema_mapping WHERE username = 'testadmin';
