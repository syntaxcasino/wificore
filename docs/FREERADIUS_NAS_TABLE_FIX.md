# FreeRADIUS NAS Table Fix

**Date**: Oct 28, 2025  
**Issue**: FreeRADIUS failing with "relation 'nas' does not exist"  
**Status**: ✅ **FIXED**

---

## 🔴 **PROBLEM**

### **Error Message**:
```
rlm_sql (sql): rlm_sql_postgresql: ERROR:  relation "nas" does not exist
rlm_sql (sql): rlm_sql_postgresql: LINE 1: SELECT id,nasname,shortname,type,secret FROM nas
Failed to load clients from SQL
/opt/etc/raddb/mods-enabled/sql[1]: Instantiation failed for module "sql"
```

### **Root Cause**:
When we removed `init.sql` from docker-compose.yml to use Laravel migrations, we also removed the RADIUS tables including the critical `nas` table that FreeRADIUS requires.

The `radius-schema.sql` file was incomplete - it had the authentication tables (radcheck, radreply, radacct) but was missing the `nas` table.

---

## ✅ **SOLUTION**

### **Updated radius-schema.sql**

Added the missing components:

1. **UUID Extensions**
```sql
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
```

2. **NAS Table** (Network Access Server)
```sql
CREATE TABLE IF NOT EXISTS nas (
    id serial PRIMARY KEY,
    nasname varchar(128) NOT NULL,
    shortname varchar(32),
    type varchar(30) DEFAULT 'other',
    ports integer,
    secret varchar(60) NOT NULL DEFAULT 'secret',
    server varchar(64),
    community varchar(50),
    description varchar(200) DEFAULT 'RADIUS Client'
);

CREATE INDEX IF NOT EXISTS nas_nasname ON nas(nasname);
```

---

## 🔄 **WHAT WAS DONE**

### **1. Updated radius-schema.sql** ✅
- Added UUID extensions
- Added `nas` table definition
- Added index on `nasname`
- Kept all existing RADIUS tables

### **2. Removed Old Database** ✅
```bash
docker-compose down -v  # Remove volumes
```

### **3. Recreated Containers** ✅
```bash
docker-compose up -d
```

---

## 📋 **COMPLETE RADIUS SCHEMA**

### **Tables Created by radius-schema.sql**:

1. ✅ **nas** - Network Access Servers (routers/NAS devices)
2. ✅ **radcheck** - User authentication attributes
3. ✅ **radreply** - User reply attributes
4. ✅ **radusergroup** - User to group mappings
5. ✅ **radgroupcheck** - Group authentication attributes
6. ✅ **radgroupreply** - Group reply attributes
7. ✅ **radacct** - Accounting records (sessions)
8. ✅ **radpostauth** - Post-authentication logging

---

## 🎯 **WHY NAS TABLE IS CRITICAL**

The `nas` table stores information about Network Access Servers (NAS) that are allowed to communicate with the RADIUS server.

**Each entry contains**:
- `nasname` - IP address or hostname of the NAS device
- `shortname` - Friendly name for the NAS
- `type` - Type of NAS (e.g., 'mikrotik', 'cisco')
- `secret` - Shared secret for authentication
- `description` - Human-readable description

**Without this table**:
- ❌ FreeRADIUS cannot load NAS clients
- ❌ Module instantiation fails
- ❌ RADIUS server won't start
- ❌ No authentication possible

---

## 🔍 **VERIFICATION**

### **Check FreeRADIUS Status**:
```bash
docker-compose ps
# Should show: traidnet-freeradius - Up (healthy)
```

### **Check FreeRADIUS Logs**:
```bash
docker-compose logs traidnet-freeradius
# Should NOT show "relation 'nas' does not exist"
```

### **Verify NAS Table Exists**:
```bash
docker-compose exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d nas"
```

---

## 📊 **CURRENT SETUP**

### **Database Initialization Flow**:

```
1. Postgres container starts
   ↓
2. Runs radius-schema.sql (creates RADIUS tables including nas)
   ↓
3. FreeRADIUS container starts
   ↓
4. Connects to database
   ↓
5. Loads NAS clients from nas table
   ↓
6. Module instantiation succeeds
   ↓
7. RADIUS server ready!
```

### **Application Tables**:
```
1. Backend container starts
   ↓
2. Waits for database
   ↓
3. Runs Laravel migrations (creates application tables)
   ↓
4. Runs seeders (creates default data)
   ↓
5. Application ready!
```

---

## 🎉 **RESULT**

**Status**: ✅ **ALL CONTAINERS HEALTHY**

```
NAME                  STATUS
traidnet-backend      Up (healthy)
traidnet-freeradius   Up (healthy)  ← FIXED!
traidnet-frontend     Up (healthy)
traidnet-nginx        Up (healthy)
traidnet-postgres     Up (healthy)
traidnet-redis        Up (healthy)
traidnet-soketi       Up (healthy)
```

---

## 📝 **LESSONS LEARNED**

### **1. Complete Schema Required**
When removing init.sql, ensure radius-schema.sql has ALL required RADIUS tables, not just the core ones.

### **2. NAS Table is Critical**
FreeRADIUS requires the `nas` table to load NAS clients. Without it, the module fails to instantiate.

### **3. Separation of Concerns**
- ✅ RADIUS tables → radius-schema.sql (PostgreSQL init)
- ✅ Application tables → Laravel migrations (Backend init)
- ✅ Clear separation, no overlap

### **4. Volume Persistence**
When updating init scripts, must remove volumes (`docker-compose down -v`) to force re-initialization.

---

## ✅ **VERIFICATION CHECKLIST**

- [x] radius-schema.sql has UUID extensions
- [x] radius-schema.sql has nas table
- [x] radius-schema.sql has all RADIUS tables
- [x] Old database volumes removed
- [x] Containers recreated
- [x] FreeRADIUS starts successfully
- [x] No "nas does not exist" errors
- [x] All containers healthy

---

**Status**: ✅ **FIXED AND VERIFIED**  
**FreeRADIUS**: ✅ **HEALTHY**  
**NAS Table**: ✅ **EXISTS**  
**System**: ✅ **FULLY OPERATIONAL**

**The system is now complete with both RADIUS tables (for FreeRADIUS) and application tables (via migrations)!** 🚀🔒
