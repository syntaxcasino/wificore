-- Migration script to add user roles and related fields
-- Run this to update existing database with new schema

-- Add new columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(50) DEFAULT 'hotspot_user' NOT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_number VARCHAR(20) UNIQUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_number VARCHAR(50) UNIQUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_balance DECIMAL(10, 2) DEFAULT 0.00;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP;

-- Add constraint for role validation
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'users_role_check'
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'hotspot_user'));
    END IF;
END $$;

-- Update existing users to have admin role if they have username 'admin'
UPDATE users SET role = 'admin' WHERE username = 'admin' OR email LIKE '%admin%';

-- Create indexes for users table
CREATE INDEX IF NOT EXISTS idx_users_phone_number ON users(phone_number) WHERE phone_number IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_account_number ON users(account_number) WHERE account_number IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active);

-- Add new columns to payments table
ALTER TABLE payments ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT 'mpesa';
ALTER TABLE payments ADD COLUMN IF NOT EXISTS mpesa_receipt VARCHAR(255);

-- Modify amount column to DECIMAL if it's FLOAT
DO $$ 
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'payments' AND column_name = 'amount' AND data_type = 'double precision'
    ) THEN
        ALTER TABLE payments ALTER COLUMN amount TYPE DECIMAL(10, 2);
    END IF;
END $$;

-- Create indexes for payments table
CREATE INDEX IF NOT EXISTS idx_payments_user_id ON payments(user_id);
CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status);
CREATE INDEX IF NOT EXISTS idx_payments_phone_number ON payments(phone_number);
CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments(created_at DESC);

-- Create user_subscriptions table
CREATE TABLE IF NOT EXISTS user_subscriptions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    package_id INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
    payment_id INTEGER REFERENCES payments(id) ON DELETE SET NULL,
    mac_address VARCHAR(17) NOT NULL,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    mikrotik_username VARCHAR(255),
    mikrotik_password VARCHAR(255),
    data_used_mb BIGINT DEFAULT 0,
    time_used_minutes INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for user_subscriptions
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_user_id ON user_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_status ON user_subscriptions(status);

-- Display summary
SELECT 'Migration completed successfully!' AS status;
SELECT 'Users table updated with: role, phone_number, account_number, account_balance, is_active, last_login_at' AS changes;
SELECT 'Payments table updated with: user_id, payment_method, mpesa_receipt, amount (DECIMAL)' AS changes;
SELECT 'user_subscriptions table created with indexes' AS changes;
SELECT 'All indexes created for performance optimization' AS changes;

-- Display current schema
SELECT 'Current users table columns:' AS info;
SELECT column_name, data_type, column_default 
FROM information_schema.columns 
WHERE table_name = 'users' 
ORDER BY ordinal_position;
