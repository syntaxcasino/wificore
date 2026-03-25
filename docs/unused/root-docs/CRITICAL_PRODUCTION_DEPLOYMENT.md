# 🔴 CRITICAL: Production Deployment Required

## ⚠️ Current Status

**Production is still running OLD CODE with bugs:**

### Error 1: Router Creation - Null Tenant ✅ FIXED IN CODE, ❌ NOT DEPLOYED
```
[2026-01-03 18:46:33] production.ERROR: App\Services\VpnService::createVpnConfiguration(): 
Argument #1 ($tenant) must be of type App\Models\Tenant, null given
```

**Status:** Fixed in commit `72860b1` but **backend container NOT rebuilt on production**

### Error 2: WebSocket Path Doubling ✅ FIXED IN CODE, ❌ NOT DEPLOYED
```
WebSocket connection to 'wss://wificore.traidsolutions.com//app/app-key' failed: 404
```

**Status:** Fixed in commit `ec0983e` but **frontend container NOT rebuilt on production**

---

## 🚀 DEPLOY ALL FIXES NOW

Run these commands on production server (144.91.71.208):

```bash
ssh root@144.91.71.208
cd /opt/wificore

# ============================================
# STEP 1: Pull Latest Code
# ============================================
git pull origin main

# Should show commits:
# - 72860b1: Router creation null tenant fix
# - ec0983e: WebSocket path doubling fix

# ============================================
# STEP 2: Rebuild Backend (Router Fix)
# ============================================
docker compose -f docker-compose.production.yml build wificore-backend --no-cache

# ============================================
# STEP 3: Rebuild Frontend (WebSocket Fix)
# ============================================
docker compose -f docker-compose.production.yml build wificore-frontend --no-cache

# ============================================
# STEP 4: Restart Services
# ============================================
docker compose -f docker-compose.production.yml down
docker compose -f docker-compose.production.yml up -d

# ============================================
# STEP 5: Wait for Services
# ============================================
sleep 30

# ============================================
# STEP 6: Verify Deployment
# ============================================

# A. Check backend logs - should NOT show null tenant error
docker compose -f docker-compose.production.yml logs wificore-backend --tail=100 | grep -i "null given"
# Expected: No output (error is gone)

# B. Check WebSocket endpoint
curl -I https://wificore.traidsolutions.com/app/app-key
# Expected: HTTP 400 Bad Request (NOT 404)

# C. Test router creation
# Login to web interface → Routers → Add Router
# Should succeed without "Server Error"

# D. Check WebSocket in browser console
# Should show: wss://wificore.traidsolutions.com/app/app-key (single slash)
# NOT: wss://wificore.traidsolutions.com//app/app-key (double slash)
```

---

## 📋 Deployment Checklist

- [ ] SSH to production server
- [ ] `git pull origin main` (should get commits 72860b1 and ec0983e)
- [ ] Rebuild backend: `docker compose -f docker-compose.production.yml build wificore-backend --no-cache`
- [ ] Rebuild frontend: `docker compose -f docker-compose.production.yml build wificore-frontend --no-cache`
- [ ] Restart all: `docker compose -f docker-compose.production.yml down && up -d`
- [ ] Wait 30 seconds for services to start
- [ ] Verify no "null tenant" errors in backend logs
- [ ] Verify WebSocket connects without double slash
- [ ] Test router creation end-to-end
- [ ] Clear browser cache or use incognito mode

---

## 🔍 Why This Happened

1. **Code was fixed and committed** ✅
2. **Code was pushed to GitHub** ✅
3. **Production did NOT pull and rebuild** ❌

**The production server is still running containers built from old code.**

Docker containers are **immutable** - you must rebuild them to include new code changes.

---

## ⏱️ Time to Deploy

**Estimated time:** 5-10 minutes

**Downtime:** ~30 seconds during container restart

---

## 🆘 If Issues Persist After Deployment

1. **Check git log on production:**
   ```bash
   cd /opt/wificore
   git log --oneline -5
   ```
   Should show commits `ec0983e` and `72860b1`

2. **Verify containers were rebuilt:**
   ```bash
   docker images | grep wificore
   ```
   Check timestamps - should be recent (within last 10 minutes)

3. **Check container logs:**
   ```bash
   docker compose -f docker-compose.production.yml logs wificore-backend --tail=100
   docker compose -f docker-compose.production.yml logs wificore-frontend --tail=100
   ```

4. **Force browser cache clear:**
   - Chrome: Ctrl+Shift+Delete → Clear cached images and files
   - Or use Incognito mode

---

**DEPLOY NOW TO FIX PRODUCTION ISSUES**
