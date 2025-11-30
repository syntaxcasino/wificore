# Database Updated - Hotspot Tables Added

## ✅ PostgreSQL Schema Updated

The `postgres/init.sql` file has been updated with the new hotspot tables.

## 📊 Tables Added

### 1. hotspot_users
**Purpose:** Store hotspot user credentials and subscription information

**Columns:**
- `id` - Primary key
- `username` - Unique username
- `password` - Hashed password
- `phone_number` - Unique phone number
- `mac_address` - Device MAC address
- `has_active_subscription` - Boolean flag
- `package_name` - Current package name
- `package_id` - Foreign key to packages table
- `subscription_starts_at` - Subscription start date
- `subscription_expires_at` - Subscription expiry date
- `data_limit` - Data limit in bytes
- `data_used` - Data used in bytes
- `last_login_at` - Last login timestamp
- `last_login_ip` - Last login IP address
- `is_active` - User active status
- `status` - User status (active/suspended/expired)
- `created_at` - Creation timestamp
- `updated_at` - Update timestamp
- `deleted_at` - Soft delete timestamp

**Indexes:**
- `idx_hotspot_users_username`
- `idx_hotspot_users_phone_number`
- `idx_hotspot_users_has_active_subscription`
- `idx_hotspot_users_subscription_expires_at`
- `idx_hotspot_users_deleted_at`

### 2. hotspot_sessions
**Purpose:** Track active and historical hotspot user sessions

**Columns:**
- `id` - Primary key
- `hotspot_user_id` - Foreign key to hotspot_users
- `mac_address` - Device MAC address
- `ip_address` - Device IP address
- `session_start` - Session start time
- `session_end` - Session end time
- `last_activity` - Last activity timestamp
- `expires_at` - Session expiry time
- `is_active` - Session active status
- `bytes_uploaded` - Bytes uploaded in session
- `bytes_downloaded` - Bytes downloaded in session
- `total_bytes` - Total bytes transferred
- `user_agent` - Browser user agent
- `device_type` - Device type
- `created_at` - Creation timestamp
- `updated_at` - Update timestamp

**Indexes:**
- `idx_hotspot_sessions_user_id`
- `idx_hotspot_sessions_is_active`
- `idx_hotspot_sessions_session_start`
- `idx_hotspot_sessions_expires_at`
- `idx_hotspot_sessions_mac_address`

## 🔗 Relationships

```
packages
    ↓ (one-to-many)
hotspot_users
    ↓ (one-to-many)
hotspot_sessions
```

## 🌱 Seed Data

### Test User 1 (Active)
```
Username: testuser
Password: password
Phone: +254712345678
Status: Active with 24-hour subscription
Data Limit: 1GB
```

### Test User 2 (Expired)
```
Username: expireduser
Password: password
Phone: +254712345679
Status: Expired subscription
```

## ⚡ Triggers Added

### 1. update_hotspot_users_updated_at()
Automatically updates `updated_at` timestamp on hotspot_users table updates.

### 2. update_hotspot_sessions_updated_at()
Automatically updates `updated_at` timestamp on hotspot_sessions table updates.

## 📝 Comments Added

Table and column comments for documentation:
- Table descriptions
- Data limit explanation (bytes)
- Status values explanation
- Data usage columns explanation

## 🚀 How to Apply

### Option 1: Fresh Database
If you're starting fresh, the tables will be created automatically when you run:
```bash
docker-compose up -d postgres
```

### Option 2: Existing Database
If you already have a database running, you need to apply the new schema:

```bash
# Connect to PostgreSQL container
docker exec -it wifi-hotspot-postgres psql -U postgres -d wifi_hotspot

# Then run the new table creation commands
# Copy the CREATE TABLE statements from init.sql
```

### Option 3: Recreate Database
```bash
# Stop and remove containers
docker-compose down

# Remove postgres volume (WARNING: This deletes all data!)
docker volume rm wifi-hotspot_postgres_data

# Start fresh
docker-compose up -d postgres
```

## 🧪 Verify Installation

### Check if tables exist:
```sql
-- Connect to database
docker exec -it wifi-hotspot-postgres psql -U postgres -d wifi_hotspot

-- List tables
\dt

-- Check hotspot_users table
SELECT * FROM hotspot_users;

-- Check hotspot_sessions table
SELECT * FROM hotspot_sessions;

-- Verify test user
SELECT username, phone_number, has_active_subscription, 
       subscription_expires_at, status 
FROM hotspot_users 
WHERE username = 'testuser';
```

### Expected Output:
```
 username  | phone_number   | has_active_subscription | subscription_expires_at | status
-----------+----------------+-------------------------+------------------------+--------
 testuser  | +254712345678  | t                       | 2025-01-09 00:00:00    | active
```

## 📊 Table Statistics

### hotspot_users
- **Columns:** 18
- **Indexes:** 5
- **Foreign Keys:** 1 (to packages)
- **Triggers:** 1 (updated_at)
- **Seed Records:** 2

### hotspot_sessions
- **Columns:** 16
- **Indexes:** 5
- **Foreign Keys:** 1 (to hotspot_users)
- **Triggers:** 1 (updated_at)
- **Seed Records:** 0

## 🔒 Security Features

### Password Storage
- ✅ Passwords stored as bcrypt hashes
- ✅ Test password: `password` (hashed)

### Data Integrity
- ✅ Foreign key constraints
- ✅ Unique constraints (username, phone_number)
- ✅ NOT NULL constraints on critical fields
- ✅ Cascade delete on sessions when user deleted

### Indexes
- ✅ Optimized for common queries
- ✅ Username and phone lookups
- ✅ Active subscription filtering
- ✅ Expiry date queries
- ✅ Session tracking

## 📈 Performance Optimizations

### Indexes Created For:
1. **Fast login lookups** - username index
2. **Phone number searches** - phone_number index
3. **Active user filtering** - has_active_subscription index
4. **Expiry checks** - subscription_expires_at index
5. **Session queries** - user_id, is_active, dates indexes
6. **MAC address tracking** - mac_address index

### Query Examples:

```sql
-- Fast login query (uses username index)
SELECT * FROM hotspot_users WHERE username = 'testuser';

-- Active subscriptions (uses has_active_subscription index)
SELECT * FROM hotspot_users 
WHERE has_active_subscription = TRUE 
AND subscription_expires_at > CURRENT_TIMESTAMP;

-- Active sessions (uses is_active index)
SELECT * FROM hotspot_sessions WHERE is_active = TRUE;

-- User's session history (uses user_id index)
SELECT * FROM hotspot_sessions 
WHERE hotspot_user_id = 1 
ORDER BY session_start DESC;
```

## 📝 Summary

**Tables Added:** 2 (hotspot_users, hotspot_sessions)  
**Indexes Created:** 10  
**Foreign Keys:** 2  
**Triggers:** 2  
**Seed Records:** 2 test users  
**Status:** ✅ Ready for use  

---

**Updated:** 2025-01-08  
**File:** postgres/init.sql  
**Ready for:** Production 🚀
