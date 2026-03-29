# Soketi WebSocket Fix - Production Deployment Guide

## Summary of Changes

Fixed critical WebSocket connection issues in production:

1. **WebSocket Path Duplication**: Fixed `/app/app/app-key` → `/app/app-key`
2. **Broadcasting Auth 403 Errors**: Fixed authentication endpoint routing
3. **Soketi Configuration**: Corrected port and CORS settings
4. **Frontend Echo Configuration**: Updated to use correct `wsPath` parameter

## Files Modified

- `soketi/soketi.json` - Fixed port (6071) and removed wsPath duplication
- `soketi/Dockerfile.optimized` - Added git dependency and config file mounting
- `nginx/nginx.conf` - Fixed `/broadcasting/auth` endpoint routing
- `frontend/Dockerfile.optimized` - Fixed to install all dependencies for build
- `frontend/src/plugins/echo.js` - Changed `path` to `wsPath`, fixed auth endpoint
- `frontend/src/services/websocket.js` - Fixed auth endpoint to `/api/broadcasting/auth`

## Docker Images Built and Pushed

✅ `kja2aro/wificore:wificore-frontend`
✅ `kja2aro/wificore:wificore-nginx`
✅ `kja2aro/wificore:wificore-soketi`

## Production Deployment Steps

### Step 1: Pull Latest Images

```bash
cd /opt/wificore
docker compose -f docker-compose.production.yml pull wificore-frontend wificore-nginx wificore-soketi
```

### Step 2: Stop Affected Services

```bash
docker compose -f docker-compose.production.yml stop wificore-frontend wificore-nginx wificore-soketi
```

### Step 3: Remove Old Containers

```bash
docker compose -f docker-compose.production.yml rm -f wificore-frontend wificore-nginx wificore-soketi
```

### Step 4: Start Services with New Images

```bash
docker compose -f docker-compose.production.yml up -d wificore-frontend wificore-nginx wificore-soketi
```

### Step 5: Verify Services are Running

```bash
docker compose -f docker-compose.production.yml ps
```

Expected output should show all three services as "Up" and healthy.

### Step 6: Check Logs

```bash
# Check Soketi logs
docker compose -f docker-compose.production.yml logs wificore-soketi --tail 50

# Check Nginx logs
docker compose -f docker-compose.production.yml logs wificore-nginx --tail 50

# Check Frontend logs
docker compose -f docker-compose.production.yml logs wificore-frontend --tail 20
```

## Verification Steps

### 1. WebSocket Connection

Open browser console at `https://wificore.traidsolutions.com` and verify:

**Expected Console Output:**
```
🔧 Echo WebSocket Configuration: {
  mode: 'PRODUCTION',
  protocol: 'wss://',
  host: 'wificore.traidsolutions.com',
  port: 443,
  secure: true,
  authEndpoint: '/api/broadcasting/auth',
  key: 'app-key',
  fullUrl: 'wss://wificore.traidsolutions.com:443/app'
}
```

**WebSocket URL should be:**
```
wss://wificore.traidsolutions.com/app
```

**NOT:**
```
wss://wificore.traidsolutions.com/app/app/app-key  ❌ (OLD - WRONG)
```

### 2. Broadcasting Auth Endpoint

In browser Network tab, verify:
- POST to `/api/broadcasting/auth` returns **200 OK**
- NOT **403 Forbidden** ❌

### 3. Real-time Events

Test real-time functionality:
1. Login to dashboard
2. Create a new todo item
3. Verify WebSocket event is received in console
4. Check that UI updates in real-time

### 4. Soketi Health Check

```bash
curl -s http://localhost:9670/usage | jq
```

Expected: JSON response with Soketi metrics

## Troubleshooting

### Issue: WebSocket still shows 404

**Solution:**
```bash
# Restart nginx to reload configuration
docker compose -f docker-compose.production.yml restart wificore-nginx
```

### Issue: Broadcasting auth still returns 403

**Solution:**
```bash
# Check backend logs for authentication errors
docker compose -f docker-compose.production.yml logs wificore-backend --tail 100

# Verify user is authenticated
# Check Authorization header in browser Network tab
```

### Issue: Soketi not starting

**Solution:**
```bash
# Check Soketi logs
docker compose -f docker-compose.production.yml logs wificore-soketi --tail 100

# Verify config file is loaded
docker compose -f docker-compose.production.yml exec wificore-soketi cat /app/config/soketi.json
```

### Issue: Frontend not loading

**Solution:**
```bash
# Rebuild frontend if needed
docker compose -f docker-compose.production.yml restart wificore-frontend

# Clear browser cache and hard reload (Ctrl+Shift+R)
```

## Rollback Plan

If issues persist, rollback to previous images:

```bash
# Stop services
docker compose -f docker-compose.production.yml stop wificore-frontend wificore-nginx wificore-soketi

# Pull previous versions (if tagged)
docker pull kja2aro/wificore:wificore-frontend-previous
docker pull kja2aro/wificore:wificore-nginx-previous
docker pull kja2aro/wificore:wificore-soketi-previous

# Restart services
docker compose -f docker-compose.production.yml up -d wificore-frontend wificore-nginx wificore-soketi
```

## Expected Results

After successful deployment:

✅ WebSocket connects to `wss://wificore.traidsolutions.com/app`
✅ Broadcasting auth returns 200 OK
✅ Real-time events work (todos, notifications, dashboard updates)
✅ No 404 errors in console
✅ No 403 errors on `/api/broadcasting/auth`
✅ Soketi logs show successful connections and message handling

## Technical Details

### Root Causes Fixed

1. **Path Duplication**: Pusher.js was adding `/app` to the base path, and Soketi was also configured with `wsPath: ""`, causing duplication. Fixed by using `wsPath` parameter in Echo config.

2. **Broadcasting Auth**: Nginx was not properly routing `/broadcasting/auth` to Laravel's `/api/broadcasting/auth` endpoint. Fixed with explicit REQUEST_URI and DOCUMENT_URI parameters.

3. **Soketi Port**: Soketi was configured to listen on port 80 but docker-compose expected 6071. Fixed to use consistent port 6071.

4. **CORS**: Soketi CORS was too restrictive for production. Fixed to allow all origins with proper headers.

## Contact

If issues persist after deployment, check:
- Browser console for detailed error messages
- Soketi logs for connection attempts
- Nginx logs for routing issues
- Backend logs for authentication problems

---

**Deployment Date:** December 27, 2025
**Version:** Production Fix v1.0
**Status:** Ready for Deployment
