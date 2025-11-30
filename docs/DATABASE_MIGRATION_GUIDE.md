# Database Migration Guide - User Roles System

## Overview
This guide helps you migrate your existing WiFi Hotspot database to support the new user roles system.

**Date:** 2025-10-04  
**Migration Version:** 1.0

---

## What Gets Added

### Users Table
- `role` VARCHAR(50) - User role (admin or hotspot_user)
- `phone_number` VARCHAR(20) - Phone number for M-Pesa
- `account_balance` DECIMAL(10,2) - Prepaid account balance
- `is_active` BOOLEAN - Account status
- `last_login_at` TIMESTAMP - Last login tracking

### Payments Table
- `user_id` INTEGER - Link to users table
- `payment_method` VARCHAR(50) - Payment method (mpesa, cash, account_balance)

### New Table: user_subscriptions
Complete subscription management for hotspot users

---

## Migration Methods

### Method 1: Automated Script (Recommended)

**Windows:**
```powershell
# Run migration script
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -f /docker-entrypoint-initdb.d/migrate-user-roles.sql

# Or copy and run
Get-Content .\scripts\migrate-user-roles.sql | docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot
```

**Linux/macOS:**
```bash
# Run migration script
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -f /docker-entrypoint-initdb.d/migrate-user-roles.sql

# Or copy and run
cat scripts/migrate-user-roles.sql | docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot
```

---

### Method 2: Manual SQL Commands

```sql
-- 1. Add new columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(50) DEFAULT 'hotspot_user' NOT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_number VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_balance DECIMAL(10, 2) DEFAULT 0.00;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP;

-- 2. Add role constraint
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'hotspot_user'));

-- 3. Update existing admin users
UPDATE users SET role = 'admin' WHERE username = 'admin' OR email LIKE '%admin%';

-- 4. Add new columns to payments table
ALTER TABLE payments ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT 'mpesa';

-- 5. Create user_subscriptions table
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

-- 6. Create indexes
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_user_id ON user_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_status ON user_subscriptions(status);
```

---

### Method 3: Fresh Installation (Clean Slate)

If you're starting fresh or can afford to lose existing data:

```bash
# 1. Backup existing data (if needed)
docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup_$(date +%Y%m%d).sql

# 2. Stop services
docker-compose down

# 3. Remove old database volume
docker volume rm wifi-hotspot_postgres_data

# 4. Start services (will use updated init.sql)
docker-compose up -d

# 5. Wait for initialization
sleep 30

# 6. Verify
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d users"
```

---

## Step-by-Step Migration Process

### Step 1: Backup Database

**Always backup before migration!**

```bash
# Create backup
docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup_before_migration_$(date +%Y%m%d_%H%M%S).sql

# Verify backup
ls -lh backup_before_migration_*.sql
```

### Step 2: Check Current Schema

```bash
# Check users table structure
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d users"

# Check if role column exists
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT column_name FROM information_schema.columns WHERE table_name='users' AND column_name='role';"
```

### Step 3: Run Migration

**Option A: Using migration script**
```bash
cat scripts/migrate-user-roles.sql | docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot
```

**Option B: Manual commands**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(50) DEFAULT 'hotspot_user' NOT NULL;"
# ... (run each command from Method 2)
```

### Step 4: Verify Migration

```bash
# Check users table has new columns
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d users"

# Check user_subscriptions table exists
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d user_subscriptions"

# Check payments table has new columns
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d payments"
```

### Step 5: Test Login

```bash
# Test admin login
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' | jq '.'
```

---

## Verification Checklist

After migration, verify:

- [ ] Users table has `role` column
- [ ] Users table has `phone_number` column
- [ ] Users table has `account_balance` column
- [ ] Users table has `is_active` column
- [ ] Users table has `last_login_at` column
- [ ] Users table has `users_role_check` constraint
- [ ] Payments table has `user_id` column
- [ ] Payments table has `payment_method` column
- [ ] `user_subscriptions` table exists
- [ ] Indexes on `user_subscriptions` exist
- [ ] Existing admin users have `role = 'admin'`
- [ ] Admin login works
- [ ] No errors in Laravel logs

---

## Verification Commands

```bash
# 1. Check all new columns in users table
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT column_name, data_type, column_default 
FROM information_schema.columns 
WHERE table_name = 'users' 
AND column_name IN ('role', 'phone_number', 'account_balance', 'is_active', 'last_login_at')
ORDER BY column_name;"

# 2. Check user_subscriptions table
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT COUNT(*) as table_exists 
FROM information_schema.tables 
WHERE table_name = 'user_subscriptions';"

# 3. Check admin users
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT id, username, email, role, is_active 
FROM users 
WHERE role = 'admin';"

# 4. Check constraints
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT conname, contype, pg_get_constraintdef(oid) 
FROM pg_constraint 
WHERE conrelid = 'users'::regclass 
AND conname = 'users_role_check';"
```

---

## Rollback Plan

If migration fails, you can rollback:

### Option 1: Restore from Backup

```bash
# Stop services
docker-compose down

# Restore database
cat backup_before_migration_*.sql | docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot

# Start services
docker-compose up -d
```

### Option 2: Remove New Columns

```sql
-- Remove new columns from users
ALTER TABLE users DROP COLUMN IF EXISTS role;
ALTER TABLE users DROP COLUMN IF EXISTS phone_number;
ALTER TABLE users DROP COLUMN IF EXISTS account_balance;
ALTER TABLE users DROP COLUMN IF EXISTS is_active;
ALTER TABLE users DROP COLUMN IF EXISTS last_login_at;

-- Remove new columns from payments
ALTER TABLE payments DROP COLUMN IF EXISTS user_id;
ALTER TABLE payments DROP COLUMN IF EXISTS payment_method;

-- Drop user_subscriptions table
DROP TABLE IF EXISTS user_subscriptions;
```

---

## Troubleshooting

### Issue: Column already exists

**Error:** `column "role" of relation "users" already exists`

**Solution:** This is normal if migration was partially run. The `IF NOT EXISTS` clause handles this.

---

### Issue: Constraint violation

**Error:** `new row for relation "users" violates check constraint "users_role_check"`

**Solution:** Ensure role values are only 'admin' or 'hotspot_user':
```sql
UPDATE users SET role = 'hotspot_user' WHERE role NOT IN ('admin', 'hotspot_user');
```

---

### Issue: Foreign key constraint fails

**Error:** `insert or update on table "user_subscriptions" violates foreign key constraint`

**Solution:** Ensure referenced users and packages exist:
```sql
-- Check user exists
SELECT id FROM users WHERE id = <user_id>;

-- Check package exists
SELECT id FROM packages WHERE id = <package_id>;
```

---

### Issue: Login still fails after migration

**Check:**
1. Verify role column exists and has correct values
2. Check Laravel logs for specific errors
3. Ensure User model has been updated with new fields
4. Clear Laravel cache

```bash
# Clear Laravel cache
docker exec traidnet-backend php artisan cache:clear
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan route:clear
```

---

## Post-Migration Tasks

### 1. Update Existing Users

```sql
-- Set all existing users to admin (if they should be admins)
UPDATE users SET role = 'admin' WHERE username IN ('admin', 'administrator');

-- Set specific users to hotspot_user
UPDATE users SET role = 'hotspot_user' WHERE role IS NULL OR role = '';

-- Activate all users
UPDATE users SET is_active = TRUE;
```

### 2. Test User Creation

```bash
# Test creating a hotspot user via payment
curl -X POST http://localhost/api/payments/initiate \
  -H "Content-Type: application/json" \
  -d '{
    "package_id": 1,
    "phone_number": "+254712345678",
    "mac_address": "AA:BB:CC:DD:EE:FF"
  }'
```

### 3. Monitor Logs

```bash
# Watch Laravel logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log

# Watch queue logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/payments-queue.log
```

---

## Migration Checklist

Complete this checklist during migration:

- [ ] **Pre-Migration**
  - [ ] Backup database
  - [ ] Document current schema
  - [ ] Notify users of maintenance
  - [ ] Stop queue workers (optional)

- [ ] **Migration**
  - [ ] Run migration script
  - [ ] Verify no errors
  - [ ] Check all tables updated
  - [ ] Verify constraints added

- [ ] **Post-Migration**
  - [ ] Test admin login
  - [ ] Test package viewing
  - [ ] Test payment flow
  - [ ] Run E2E tests
  - [ ] Check queue processing
  - [ ] Monitor logs for errors

- [ ] **Cleanup**
  - [ ] Remove backup (after verification)
  - [ ] Update documentation
  - [ ] Notify users system is ready

---

## Quick Migration (One-Liner)

**For experienced users:**

```bash
# Backup, migrate, and verify
docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup.sql && \
cat scripts/migrate-user-roles.sql | docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot && \
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d users" && \
echo "Migration complete! Test login now."
```

---

## Support

If you encounter issues:

1. Check `docs/TROUBLESHOOTING_GUIDE.md`
2. Review Laravel logs
3. Verify database schema
4. Test with E2E test suite
5. Restore from backup if needed

---

**Last Updated:** 2025-10-04  
**Version:** 1.0
