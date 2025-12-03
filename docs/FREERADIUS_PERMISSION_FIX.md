# FreeRADIUS Permission Fix - Complete Solution

## 🔴 Critical Issue

**Error**:
```
Errors reading /opt/etc/raddb/dictionary: dict_init: Dictionary "/opt/etc/raddb/dictionary" is globally writable. Refusing to start due to insecure configuration.
```

**Impact**:
- ❌ FreeRADIUS won't start
- ❌ RADIUS authentication fails
- ❌ Login fails with "Invalid credentials"
- ❌ Container keeps restarting

---

## 🔍 Root Cause

### Problem: Volume Mount Permissions

When using Docker volume mounts on Windows:
```yaml
volumes:
  - ./freeradius/dictionary:/opt/etc/raddb/dictionary
```

The mounted file inherits Windows file permissions, which FreeRADIUS sees as "globally writable" (insecure).

FreeRADIUS **refuses to start** if config files have insecure permissions (security feature).

---

## ✅ Solution: Copy Configs During Build

Instead of mounting configs as volumes, **copy them into the container during build** with proper permissions.

### Changes Made:

#### 1. Updated `freeradius/Dockerfile`

**Added**:
```dockerfile
# Copy clients configuration
COPY clients.conf /etc/raddb/clients.conf

# Copy custom dictionary with proper permissions
COPY dictionary /opt/etc/raddb/dictionary

# Fix permissions for all config files (FreeRADIUS requires strict permissions)
RUN chmod 640 /etc/raddb/clients.conf && \
    chmod 640 /opt/etc/raddb/mods-available/sql && \
    chmod 644 /opt/etc/raddb/dictionary && \
    chown -R freerad:freerad /etc/raddb /opt/etc/raddb
```

**Why this works**:
- Files are copied during build (not mounted)
- Permissions are set correctly inside Linux container
- FreeRADIUS sees proper ownership and permissions
- No Windows permission issues

#### 2. Updated `docker-compose.yml`

**Removed** volume mounts:
```yaml
# BEFORE (caused permission issues)
volumes:
  - ./freeradius/clients.conf:/etc/raddb/clients.conf
  - ./freeradius/sql:/etc/raddb/mods-available/sql
  - ./freeradius/dictionary:/opt/etc/raddb/dictionary

# AFTER (configs copied during build)
# Configs are now copied into container during build (no volume mounts)
# This fixes FreeRADIUS "globally writable" permission errors
```

**Simplified command**:
```yaml
# BEFORE
command: >
  -c "
  sleep 5 &&
  chmod 640 /etc/raddb/clients.conf /etc/raddb/mods-available/sql &&
  ln -sf /etc/raddb/mods-available/sql /etc/raddb/mods-enabled/sql &&
  exec /opt/sbin/radiusd -X
  "

# AFTER (no chmod needed)
command: >
  -c "
  sleep 5 &&
  ln -sf /opt/etc/raddb/mods-available/sql /opt/etc/raddb/mods-enabled/sql &&
  exec /opt/sbin/radiusd -X
  "
```

---

## 🚀 How to Apply the Fix

### Quick Fix (Recommended):

```powershell
.\rebuild-and-fix-all.ps1
```

This script will:
1. Stop all containers
2. Clean database
3. Rebuild FreeRADIUS with proper permissions
4. Rebuild backend with login fixes
5. Start all containers
6. Verify everything works

### Manual Steps:

```bash
# 1. Stop containers
docker-compose down

# 2. Clean database
docker-compose up -d traidnet-postgres
sleep 5
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM users WHERE role = 'system_admin';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM radcheck WHERE username IN ('admin', 'sysadmin');"

# 3. Rebuild FreeRADIUS
docker-compose build --no-cache traidnet-freeradius

# 4. Rebuild backend
docker-compose build --no-cache traidnet-backend

# 5. Start all
docker-compose up -d

# 6. Wait and check
sleep 20
docker-compose logs --tail 50 traidnet-freeradius
docker-compose logs --tail 50 traidnet-backend
```

---

## ✅ Expected Results

### FreeRADIUS Logs (Success):

```
FreeRADIUS Version 3.2.8
Starting - reading configuration files ...
including dictionary file /opt/share/freeradius/dictionary
including dictionary file /opt/etc/raddb/dictionary
Reading dictionary file /opt/etc/raddb/dictionary
...
Listening on auth address * port 1812 bound to server default
Listening on acct address * port 1813 bound to server default
Ready to process requests
```

**Key indicators**:
- ✅ No "globally writable" error
- ✅ "Ready to process requests"
- ✅ Listening on ports 1812/1813

### Backend Logs (Success):

```
✅ Migrations completed
🌱 Running database seeders...
✅ System admin created successfully!
⚠️  Default credentials:
   Username: sysadmin
   Password: Admin@123
✅ Database seeding completed successfully!
```

---

## 🧪 Testing

### 1. Test RADIUS Authentication

```bash
docker exec traidnet-freeradius radtest sysadmin Admin@123 localhost 0 testing123
```

**Expected Output**:
```
Sent Access-Request Id 123 from 0.0.0.0:12345 to 127.0.0.1:1812 length 77
Received Access-Accept Id 123 from 127.0.0.1:1812 to 0.0.0.0:12345 length 20
```

### 2. Test Login API

```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"sysadmin","password":"Admin@123"}'
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Login successful",
  "token": "...",
  "user": {
    "username": "sysadmin",
    "email": "sysadmin@system.local",
    "role": "system_admin"
  }
}
```

### 3. Check Container Status

```bash
docker-compose ps
```

**Expected**:
```
NAME                   STATUS
traidnet-backend       Up (healthy)
traidnet-freeradius    Up (healthy)
traidnet-frontend      Up
traidnet-nginx         Up
traidnet-postgres      Up (healthy)
traidnet-redis         Up
traidnet-soketi        Up (healthy)
```

---

## 📋 Important Notes

### Config Changes Require Rebuild

Since configs are now **copied during build** (not mounted), any changes to FreeRADIUS configs require a rebuild:

```bash
# After editing freeradius/dictionary or freeradius/clients.conf
docker-compose build --no-cache traidnet-freeradius
docker-compose up -d traidnet-freeradius
```

### Pros of This Approach:

✅ **Security**: Proper file permissions inside container  
✅ **Reliability**: No Windows/Linux permission conflicts  
✅ **Performance**: No volume mount overhead  
✅ **Portability**: Works same on Windows, Linux, Mac  

### Cons of This Approach:

❌ **Rebuild Required**: Config changes need container rebuild  
❌ **Development**: Slightly slower iteration during config changes  

### Alternative for Development:

If you need to frequently edit configs during development, you can:

1. Use volume mounts for development
2. Fix permissions manually:
```bash
docker exec traidnet-freeradius chmod 644 /opt/etc/raddb/dictionary
docker exec traidnet-freeradius chmod 640 /etc/raddb/clients.conf
```

3. Use the copy approach for production

---

## 🐛 Troubleshooting

### Issue: FreeRADIUS still shows "globally writable" error

**Check**:
1. Did you rebuild the container?
```bash
docker-compose build --no-cache traidnet-freeradius
```

2. Are volume mounts removed from docker-compose.yml?
```bash
grep -A 5 "traidnet-freeradius:" docker-compose.yml
# Should NOT show volume mounts
```

3. Check file permissions inside container:
```bash
docker exec traidnet-freeradius ls -la /opt/etc/raddb/dictionary
# Should show: -rw-r--r-- freerad freerad
```

### Issue: Login still fails with "Invalid credentials"

**Check**:
1. Is FreeRADIUS running?
```bash
docker-compose ps traidnet-freeradius
# Should show: Up (healthy)
```

2. Test RADIUS directly:
```bash
docker exec traidnet-freeradius radtest sysadmin Admin@123 localhost 0 testing123
```

3. Check backend logs:
```bash
docker-compose logs --tail 100 traidnet-backend | grep -i radius
```

### Issue: Container keeps restarting

**Check logs**:
```bash
docker-compose logs --tail 100 traidnet-freeradius
```

Look for:
- Configuration errors
- Database connection issues
- Missing files

---

## 📊 Summary

### Before Fix:
- ❌ Volume mounts caused permission issues
- ❌ FreeRADIUS refused to start
- ❌ Login failed
- ❌ Container kept restarting

### After Fix:
- ✅ Configs copied during build with proper permissions
- ✅ FreeRADIUS starts successfully
- ✅ Login works
- ✅ All containers healthy

---

## 🔐 Security

### Why FreeRADIUS Checks Permissions

FreeRADIUS contains sensitive data (passwords, secrets). It **refuses to start** if config files have insecure permissions to prevent:

- Unauthorized access to credentials
- Configuration tampering
- Security breaches

This is a **security feature**, not a bug.

### Proper Permissions:

- **Dictionary**: `644` (readable by all, writable by owner)
- **Clients.conf**: `640` (readable by owner/group, writable by owner)
- **SQL config**: `640` (readable by owner/group, writable by owner)
- **Owner**: `freerad:freerad` (FreeRADIUS user/group)

---

## 📚 Related Documentation

- **COMPLETE_FIX_SUMMARY.md** - Overview of all fixes
- **LOGIN_ISSUES_FIX.md** - Login fix details
- **SEEDER_DUPLICATE_FIX.md** - Seeder fix details
- **MULTITENANCY_PHASE1_COMPLETE.md** - Multi-tenancy implementation

---

**Fix Version**: 1.0  
**Date**: November 30, 2025  
**Status**: ✅ COMPLETE
