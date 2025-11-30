# Fix Database Schema Mismatch

**Date:** October 30, 2025, 12:58 AM  
**Status:** ⚠️ **ACTION REQUIRED**

---

## 🎯 Problem

**Error persists even after migration fix:**
```
column "config_token" of relation "routers" does not exist
```

**Root Cause:** The PostgreSQL database volume contains **old tables** from a previous `init.sql` file that had different schema definitions.

---

## 🔍 What Happened

### Timeline
1. **Initially:** `postgres/init.sql` had full table definitions (SERIAL IDs, no UUIDs)
2. **Laravel migrations:** Use UUID IDs and different schema
3. **Conflict:** Old tables in database volume don't match migration schema
4. **Result:** Even after `migrate:fresh`, old schema persists if container restarts

### Current State
```yaml
# docker-compose.yml line 229
volumes:
  - ./postgres/init.sql:/docker-entrypoint-initdb.d/01-init.sql
```

**Current init.sql is correct (minimal):**
```sql
-- Only extensions, no table definitions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
```

**But database volume still has old tables!**

---

## ✅ Solution: Reset Database Volume

### Option 1: Complete Reset (Recommended)

**⚠️ WARNING: This will delete ALL data in the database!**

```bash
# Stop all containers
docker-compose down

# Remove the database volume
docker volume rm traidnet-postgres-data

# Start containers (will recreate volume with correct schema)
docker-compose up -d

# Migrations will run automatically (AUTO_MIGRATE=true)
```

### Option 2: Manual Migration Inside Container

```bash
# Access the backend container
docker-compose exec traidnet-backend bash

# Run fresh migration
php artisan migrate:fresh --seed --force

# Exit container
exit
```

### Option 3: Drop and Recreate Database

```bash
# Access PostgreSQL
docker-compose exec traidnet-postgres psql -U admin -d postgres

# Drop and recreate database
DROP DATABASE wifi_hotspot;
CREATE DATABASE wifi_hotspot;
\q

# Restart backend to trigger migrations
docker-compose restart traidnet-backend
```

---

## 📊 Verification Steps

### 1. Check if Volume Was Reset
```bash
docker volume ls | grep traidnet-postgres
# Should show: traidnet-postgres-data
```

### 2. Check if Migrations Ran
```bash
docker logs traidnet-backend | grep "Running migrations"
# Should show all 33 migrations completed
```

### 3. Verify Router Table Schema
```bash
docker-compose exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d routers"

# Should show:
# - id (uuid)
# - config_token (uuid)  ✅
# - os_version (varchar) ✅
# - last_checked (timestamp) ✅
```

### 4. Test Router Creation
- Open browser to router management
- Click "Add Router"
- Fill in details
- Submit
- Should create successfully ✅

---

## 🔧 Why This Happens

### Docker Volume Persistence
```
┌─────────────────────────────────────┐
│  Docker Volume (Persistent)         │
│  ├── Old tables (SERIAL IDs)        │
│  ├── Wrong schema                   │
│  └── Survives container restarts    │
└─────────────────────────────────────┘
         ↓
┌─────────────────────────────────────┐
│  Container Restart                  │
│  ├── Mounts old volume              │
│  ├── Sees tables already exist      │
│  └── Skips init.sql                 │
└─────────────────────────────────────┘
         ↓
┌─────────────────────────────────────┐
│  Laravel Migrations                 │
│  ├── Tries to create tables         │
│  ├── Tables already exist!          │
│  └── Uses old schema ❌             │
└─────────────────────────────────────┘
```

---

## 📝 Recommended Steps (Copy-Paste)

```bash
# 1. Stop all containers
cd d:\traidnet\wifi-hotspot
docker-compose down

# 2. Remove database volume (⚠️ DELETES ALL DATA)
docker volume rm traidnet-postgres-data

# 3. Start containers
docker-compose up -d

# 4. Wait for migrations (check logs)
docker logs -f traidnet-backend

# Look for:
# ✅ Running migrations
# ✅ 2025_07_01_140000_create_routers_table .... DONE
# ✅ Database seeding completed successfully

# 5. Test router creation
# Open browser: http://localhost
# Login and try creating a router
```

---

## 🎯 Alternative: Keep Data (Advanced)

If you need to keep existing data:

```bash
# 1. Backup data
docker-compose exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup.sql

# 2. Drop and recreate volume
docker-compose down
docker volume rm traidnet-postgres-data
docker-compose up -d

# 3. Wait for migrations to complete

# 4. Restore data (selective)
docker-compose exec -T traidnet-postgres psql -U admin wifi_hotspot < backup.sql
```

---

## ✅ Expected Result

### Before Reset
```
❌ Router creation fails
❌ Column "config_token" doesn't exist
❌ Old schema from init.sql
❌ SERIAL IDs instead of UUIDs
```

### After Reset
```
✅ Router creation works
✅ All columns exist
✅ Correct UUID schema
✅ Migrations applied correctly
✅ No schema conflicts
```

---

## 🔍 How to Prevent This

### 1. Keep init.sql Minimal
```sql
-- ✅ GOOD: Only extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ❌ BAD: Table definitions
-- CREATE TABLE routers (...);  -- Don't do this!
```

### 2. Use Laravel Migrations Only
- All table definitions in migrations
- No table creation in init.sql
- Let Laravel handle schema

### 3. Document Volume Reset
- Add to README
- Include in deployment docs
- Warn about data loss

---

## 📋 Checklist

Before proceeding:
- [ ] Backup any important data
- [ ] Understand this will delete all database data
- [ ] Have demo data seeder ready (already configured)
- [ ] Ready to test after reset

After reset:
- [ ] Verify migrations completed
- [ ] Check router table has config_token column
- [ ] Test router creation
- [ ] Verify all features work

---

## 🎉 Summary

```
╔════════════════════════════════════════╗
║   DATABASE SCHEMA FIX                 ║
║   ⚠️  ACTION REQUIRED                  ║
║                                        ║
║   Issue:          Volume mismatch ⚠️   ║
║   Solution:       Reset volume ✅      ║
║   Data Loss:      Yes ⚠️               ║
║   Time Required:  2-3 minutes ⏱️       ║
║                                        ║
║   Commands:                            ║
║   1. docker-compose down               ║
║   2. docker volume rm traidnet-...     ║
║   3. docker-compose up -d              ║
║                                        ║
║   🔧 READY TO FIX! 🔧                 ║
╚════════════════════════════════════════╝
```

---

**Action Required:** Run the commands above to reset the database volume and apply the correct schema.

**Estimated Time:** 2-3 minutes  
**Data Loss:** Yes (demo data will be reseeded)  
**Risk:** Low (development environment)
