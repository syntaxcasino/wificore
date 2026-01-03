# Production Deployment Fixes - Router Creation & WebSocket

## 🔴 CRITICAL ISSUES FIXED

### 1. Router Creation - Null Tenant Error ✅ FIXED
**Error:** `VpnService::createVpnConfiguration(): Argument #1 ($tenant) must be of type App\Models\Tenant, null given`

**Root Cause:** 
- `RouterController` tried to access `$router->tenant` relationship
- `Router` model is in tenant schema (no `tenant_id` column)
- Schema-based multi-tenancy means routers don't have a `tenant` relationship

**Fix Applied:**
- Modified `RouterController::store()` to get tenant from authenticated user
- Added validation to ensure user belongs to a tenant
- Pass `$tenant` object to `VpnService::createVpnConfiguration()`

**File:** `backend/app/Http/Controllers/Api/RouterController.php`

---

### 2. WebSocket Path Doubling - 404 Errors ❌ REQUIRES PRODUCTION REBUILD
**Error:** `wss://wificore.traidsolutions.com//app/app-key` (double slash causing 404)

**Root Cause:**
- Frontend container on production was NOT rebuilt after code fix
- Old compiled JavaScript still has wrong WebSocket path
- Previous fix changed `websocket.js` but production is using old build

**Fix Required:**
```bash
# On production server (144.91.71.208)
cd /opt/wificore
git pull origin main
docker compose -f docker-compose.production.yml build wificore-frontend --no-cache
docker compose -f docker-compose.production.yml up -d wificore-frontend
```

---

### 3. Session Timeout - 30 Minutes ✅ FIXED
**Requirement:** Auto-logout after 30 minutes of inactivity

**Fix Applied:**
- Changed `SESSION_LIFETIME` default from 120 to 30 minutes
- Updated `backend/config/session.php`
- Updated `.env.example` to reflect new default

**Files:**
- `backend/config/session.php` - Changed default to 30
- `.env.example` - Updated SESSION_LIFETIME=30

---

## 🚀 PRODUCTION DEPLOYMENT STEPS

### Step 1: Update Production .env.production
```bash
ssh root@144.91.71.208
cd /opt/wificore

# Update session lifetime
sed -i 's/SESSION_LIFETIME=120/SESSION_LIFETIME=30/g' .env.production

# Verify change
grep SESSION_LIFETIME .env.production
# Should show: SESSION_LIFETIME=30
```

### Step 2: Pull Latest Code
```bash
cd /opt/wificore
git pull origin main
```

### Step 3: Rebuild Frontend Container (CRITICAL)
```bash
# This rebuilds frontend with WebSocket path fix
docker compose -f docker-compose.production.yml build wificore-frontend --no-cache
```

### Step 4: Rebuild Backend Container (Router Fix)
```bash
# This includes the router creation tenant fix
docker compose -f docker-compose.production.yml build wificore-backend --no-cache
```

### Step 5: Restart All Services
```bash
docker compose -f docker-compose.production.yml down
docker compose -f docker-compose.production.yml up -d

# Wait for services
sleep 30
```

### Step 6: Verify Fixes

#### A. Check WebSocket Path (No Double Slash)
```bash
# Check nginx is proxying correctly
curl -I https://wificore.traidsolutions.com/app/app-key
# Should return 400 Bad Request (not 404)

# Check browser console should show:
# ✅ wss://wificore.traidsolutions.com/app/app-key (single slash)
# ❌ NOT: wss://wificore.traidsolutions.com//app/app-key (double slash)
```

#### B. Test Router Creation
```bash
# Login to web interface
# Navigate to Routers
# Click "Add Router"
# Fill in name
# Submit

# Should succeed without "Server Error"
# Check backend logs:
docker compose -f docker-compose.production.yml logs wificore-backend | tail -50
# Should NOT show: "Argument #1 ($tenant) must be of type App\Models\Tenant, null given"
```

#### C. Test Session Timeout
```bash
# Login to web interface
# Wait 30 minutes without activity
# Try to perform any action
# Should be automatically logged out and redirected to login
```

---

## 📋 VERIFICATION CHECKLIST

- [ ] `.env.production` has `SESSION_LIFETIME=30`
- [ ] Latest code pulled from git
- [ ] Frontend container rebuilt (no-cache)
- [ ] Backend container rebuilt (no-cache)
- [ ] All containers restarted
- [ ] WebSocket connects without double slash
- [ ] No 404 errors on WebSocket handshake
- [ ] Router creation succeeds
- [ ] No "null tenant" errors in logs
- [ ] Session expires after 30 minutes of inactivity

---

## 🔍 TROUBLESHOOTING

### Issue: Still seeing WebSocket 404 errors
**Cause:** Frontend not rebuilt or browser cache

**Fix:**
```bash
# Force rebuild frontend
docker compose -f docker-compose.production.yml build wificore-frontend --no-cache
docker compose -f docker-compose.production.yml up -d wificore-frontend

# Clear browser cache or use incognito mode
```

### Issue: Router creation still fails with "Server Error"
**Cause:** Backend not rebuilt or user not authenticated

**Fix:**
```bash
# Check backend logs for exact error
docker compose -f docker-compose.production.yml logs wificore-backend | grep -i error

# Rebuild backend
docker compose -f docker-compose.production.yml build wificore-backend --no-cache
docker compose -f docker-compose.production.yml up -d wificore-backend

# Verify user is logged in and has tenant_id
```

### Issue: Session not expiring after 30 minutes
**Cause:** `.env.production` not updated or containers not restarted

**Fix:**
```bash
# Verify SESSION_LIFETIME in .env.production
grep SESSION_LIFETIME .env.production

# If still 120, update it:
sed -i 's/SESSION_LIFETIME=120/SESSION_LIFETIME=30/g' .env.production

# Restart backend
docker compose -f docker-compose.production.yml restart wificore-backend
```

---

## 📝 SUMMARY OF CHANGES

**Commit:** (pending)

**Files Modified:**
1. `backend/app/Http/Controllers/Api/RouterController.php` - Fixed null tenant error
2. `backend/config/session.php` - Changed default session lifetime to 30 minutes
3. `.env.example` - Updated SESSION_LIFETIME to 30, fixed duplicate SANCTUM_STATEFUL_DOMAINS

**Production Actions Required:**
1. Update `.env.production` SESSION_LIFETIME
2. Pull latest code
3. Rebuild frontend container (WebSocket fix)
4. Rebuild backend container (Router fix)
5. Restart all services
6. Test all three fixes

**Expected Results:**
- ✅ Router creation works without errors
- ✅ WebSocket connects without path doubling
- ✅ Users auto-logout after 30 minutes of inactivity
