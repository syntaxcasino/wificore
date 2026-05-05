-- ============================================================================
-- HOTSPOT TEST USER - Auto-created account for UI testing
-- ============================================================================
-- This user simulates a customer who was auto-created upon payment.
-- The account will expire in 7 days (payment-based expiry system).
-- ============================================================================

-- Generate UUIDs for the records
-- Use these or generate your own with: SELECT gen_random_uuid();

DO $$
DECLARE
    v_user_id UUID := 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    v_package_id UUID;
    v_tenant_schema TEXT := current_schema();
    v_now TIMESTAMP := CURRENT_TIMESTAMP;
    v_expires_at TIMESTAMP := CURRENT_TIMESTAMP + INTERVAL '7 days';
BEGIN
    -- Get first available hotspot package (or you can hardcode a specific package ID)
    SELECT id INTO v_package_id FROM packages WHERE type = 'hotspot' LIMIT 1;
    
    -- If no hotspot package exists, use any package
    IF v_package_id IS NULL THEN
        SELECT id INTO v_package_id FROM packages LIMIT 1;
    END IF;

    -- Insert test hotspot user (auto-created upon payment scenario)
    INSERT INTO hotspot_users (
        id,
        username,
        password,
        phone_number,
        mac_address,
        has_active_subscription,
        package_name,
        package_id,
        subscription_starts_at,
        subscription_expires_at,
        data_limit,
        data_used,
        last_login_at,
        last_login_ip,
        is_active,
        status,
        created_at,
        updated_at,
        deleted_at
    ) VALUES (
        v_user_id,
        'test.user001',           -- Username (auto-generated or phone-based)
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- bcrypt hash of 'password123'
        '+254712345678',           -- Phone number (primary identifier for payments)
        'AA:BB:CC:DD:EE:FF',       -- MAC address (auto-captured on first login)
        true,                      -- has_active_subscription
        COALESCE((SELECT name FROM packages WHERE id = v_package_id), 'Daily 1GB'), -- package_name
        v_package_id,              -- package_id
        v_now,                     -- subscription_starts_at (payment date)
        v_expires_at,              -- subscription_expires_at (payment + package duration)
        1073741824,                -- data_limit: 1GB in bytes
        268435456,                 -- data_used: 256MB used so far
        v_now - INTERVAL '2 hours', -- last_login_at
        '192.168.88.101',          -- last_login_ip
        true,                      -- is_active
        'active',                  -- status: active, inactive, expired, blocked
        v_now - INTERVAL '7 days', -- created_at (first payment date)
        v_now,                     -- updated_at
        NULL                       -- deleted_at
    )
    ON CONFLICT (username) DO NOTHING
    ON CONFLICT (phone_number) DO NOTHING;

    -- Check if insert succeeded
    IF FOUND THEN
        RAISE NOTICE 'Hotspot test user created successfully:';
        RAISE NOTICE '  Username: test.user001';
        RAISE NOTICE '  Phone: +254712345678';
        RAISE NOTICE '  Password: password123 (plain text for testing)';
        RAISE NOTICE '  Expires: %', v_expires_at;
        RAISE NOTICE '  Package ID: %', v_package_id;
    ELSE
        RAISE NOTICE 'User may already exist. Check existing records with:';
        RAISE NOTICE '  SELECT * FROM hotspot_users WHERE username = ''test.user001'';';
    END IF;

END $$;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- View the created user
-- SELECT * FROM hotspot_users WHERE username = 'test.user001';

-- View user with package details
/*
SELECT 
    hu.id,
    hu.username,
    hu.phone_number,
    hu.mac_address,
    hu.status,
    hu.has_active_subscription,
    hu.package_name,
    hu.subscription_expires_at,
    ROUND(hu.data_used / 1048576.0, 2) as data_used_mb,
    ROUND(hu.data_limit / 1048576.0, 2) as data_limit_mb,
    hu.created_at,
    p.name as current_package,
    p.price as package_price,
    p.duration as package_duration
FROM hotspot_users hu
LEFT JOIN packages p ON hu.package_id = p.id
WHERE hu.username = 'test.user001';
*/

-- Simulate expired user (for testing expiry UI)
-- UPDATE hotspot_users 
-- SET status = 'expired', 
--     has_active_subscription = false,
--     subscription_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 day'
-- WHERE username = 'test.user001';

-- Simulate reactivation upon new payment
-- UPDATE hotspot_users 
-- SET status = 'active', 
--     has_active_subscription = true,
--     subscription_starts_at = CURRENT_TIMESTAMP,
--     subscription_expires_at = CURRENT_TIMESTAMP + INTERVAL '7 days'
-- WHERE username = 'test.user001';
