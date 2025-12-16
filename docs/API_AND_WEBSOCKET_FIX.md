# API and WebSocket Connection Fix - December 16, 2025

## Problem Summary

The application had multiple connection issues preventing tenant registration and real-time features from working:

1. **API requests going to wrong port** - Requests were going to `http://localhost/api` (port 80) instead of `http://localhost:8070/api`
2. **WebSocket connections failing** - WebSocket was trying to connect to `ws://localhost/app` instead of `ws://localhost:8070/app`
3. **Workbox PWA precaching error** - Service worker couldn't precache `apple-touch-icon.png` (404 error)

## Root Causes

### 1. Dockerfile ARG Defaults Were Incorrect
**File:** `frontend/Dockerfile`

The Dockerfile had hardcoded ARG defaults with wrong values:
```dockerfile
ARG VITE_API_URL=http://localhost/api          # Wrong - absolute URL
ARG VITE_API_BASE_URL=http://localhost/api     # Wrong - absolute URL  
ARG VITE_PUSHER_PORT=80                        # Wrong - should be 8070
```

These ARG values are used during Docker build time and override the `.env` file. When Vite builds the application, it bakes these values into the JavaScript bundle.

**Fix:**
```dockerfile
ARG VITE_API_URL=/api                          # Correct - relative path
ARG VITE_API_BASE_URL=/api                     # Correct - relative path
ARG VITE_PUSHER_PORT=8070                      # Correct - matches nginx port
```

### 2. Axios Fallback URLs Were Incorrect
**Files:** 
- `frontend/src/services/api/axios.js`
- `frontend/src/modules/system-admin/views/system/SystemDashboardNew.vue`
- `frontend/src/modules/system-admin/components/dashboard/*.vue` (4 files)

Multiple axios instances had hardcoded fallback URLs:
```javascript
baseURL: import.meta.env.VITE_API_URL || 'http://localhost/api'  // Wrong
```

When environment variables weren't loaded, axios defaulted to port 80.

**Fix:**
```javascript
baseURL: import.meta.env.VITE_API_URL || '/api'  // Correct - relative path
```

### 3. WebSocket Port Fallback Was Hardcoded
**Files:**
- `frontend/src/services/websocket.js`
- `frontend/src/plugins/echo.js`

WebSocket configuration defaulted to port 80:
```javascript
wsPort: env.VITE_PUSHER_PORT || 80  // Wrong
```

**Fix:**
```javascript
wsPort: env.VITE_PUSHER_PORT || window.location.port || 8070  // Correct - dynamic detection
```

### 4. Frontend Nginx Configuration
**File:** `frontend/nginx.conf`

Static assets location block didn't have explicit `try_files` directive, causing nginx to fall through to the Vue router for PNG files.

**Fix:**
```nginx
location ~* \.(?:ico|css|js|gif|jpe?g|png|woff2?|eot|ttf|svg|map)$ {
    try_files $uri =404;  # Added this line
    expires 6M;
    access_log off;
    add_header Cache-Control "public, immutable";
}
```

### 5. Main Nginx Configuration Had Nested Location Blocks
**File:** `nginx/nginx.conf`

The configuration had an invalid nested location block structure:
```nginx
location / {
    # ... proxy config ...
    
    location ~* \.(css|js|png|...)$ {  # INVALID - nested location block
        # ... static assets config ...
    }
}
```

Nginx doesn't support nested location blocks in this way, causing static assets to not be served correctly.

**Fix:**
```nginx
# Static assets location BEFORE location /
location ~* \.(css|js|png|jpg|jpeg|gif|ico|woff|woff2|ttf|svg|eot)$ {
    set $frontend_upstream wificore-frontend:80;
    proxy_pass http://$frontend_upstream;
    # ... headers ...
}

# Vue frontend location
location / {
    set $frontend_upstream wificore-frontend:80;
    proxy_pass http://$frontend_upstream;
    # ... headers ...
}
```

## Why Relative Paths Work

When using relative paths like `/api`:

1. **Browser loads app** from `http://localhost:8070`
2. **API call made** to `/api/register/check-email`
3. **Browser resolves** to `http://localhost:8070/api/register/check-email`
4. **Nginx proxy** at port 8070 receives the request
5. **Nginx forwards** to backend container via FastCGI
6. **Backend processes** and returns response

This works regardless of the port the application is served on, making it portable and correct.

## Files Modified

### Frontend Configuration
1. **`frontend/Dockerfile`** - Fixed ARG defaults for environment variables
2. **`frontend/nginx.conf`** - Added `try_files` directive for static assets
3. **`frontend/src/services/api/axios.js`** - Fixed baseURL fallback
4. **`frontend/src/services/websocket.js`** - Fixed WebSocket port fallback
5. **`frontend/src/plugins/echo.js`** - Fixed WebSocket port fallback
6. **`frontend/src/modules/system-admin/views/system/SystemDashboardNew.vue`** - Fixed baseURL fallback
7. **`frontend/src/modules/system-admin/components/dashboard/SystemHealthWidget.vue`** - Fixed baseURL fallback
8. **`frontend/src/modules/system-admin/components/dashboard/QueueStatsWidget.vue`** - Fixed baseURL fallback
9. **`frontend/src/modules/system-admin/components/dashboard/PerformanceMetricsWidget.vue`** - Fixed baseURL fallback

### Nginx Configuration
10. **`nginx/nginx.conf`** - Fixed location block nesting for static assets

## Verification Steps

### 1. Verify Environment Variables Are Injected
```bash
# Check built files don't contain hardcoded localhost/api
docker exec wificore-frontend sh -c "grep -r 'localhost/api' /usr/share/nginx/html/ || echo 'No localhost/api found'"
# Expected: "No localhost/api found"
```

### 2. Test Apple Touch Icon
```bash
Invoke-WebRequest -Uri http://localhost:8070/apple-touch-icon.png -Method Head
# Expected: HTTP 200 OK
```

### 3. Test API Endpoint
```bash
Invoke-WebRequest -Uri http://localhost:8070/api/register/check-username `
  -Method POST `
  -ContentType "application/json" `
  -Body '{"username":"testuser"}'
# Expected: HTTP 200 OK with JSON response
```

### 4. Test WebSocket Connection
Open browser console at `http://localhost:8070/register` and verify:
- No `ERR_CONNECTION_REFUSED` errors for WebSocket
- WebSocket connects to `ws://localhost:8070/app/app-key`

### 5. Test Service Worker
Open browser console and verify:
- No `bad-precaching-response` errors
- Service worker registers successfully

## Git Commits

1. **`20afeb1`** - Fix API and WebSocket connection issues - use relative paths and dynamic port detection
2. **`dcd5a08`** - Fix environment variable injection and nginx configuration for proper API and asset serving

## Browser Cache Clearing

After deploying these fixes, users must clear their browser cache:

**Chrome/Edge:**
1. Press `Ctrl + Shift + Delete`
2. Select "Cached images and files"
3. Click "Clear data"

**Or use hard refresh:**
- Press `Ctrl + F5` or `Ctrl + Shift + R`

**Service Worker:**
1. Open DevTools (F12)
2. Go to Application tab
3. Click "Service Workers"
4. Click "Unregister" for the old service worker
5. Refresh the page

## Testing Tenant Registration

1. Navigate to `http://localhost:8070/register`
2. Fill in the registration form
3. Verify real-time validation works (username/email availability checks)
4. Submit the form
5. Verify no connection errors in browser console
6. Verify WebSocket connection is established
7. Verify tenant is created in database

## System Status

All services are now operational:
- ✅ Frontend serving static assets correctly
- ✅ API endpoints accessible through nginx proxy
- ✅ WebSocket connections working on correct port
- ✅ Service worker precaching without errors
- ✅ Tenant registration functional
- ✅ Queue workers processing jobs

## Lessons Learned

1. **Always use relative paths** for API URLs in Docker/proxy setups
2. **Dockerfile ARG defaults matter** - they override `.env` files during build
3. **Test environment variable injection** by checking built files
4. **Nginx location blocks cannot be nested** - use separate blocks
5. **Service workers cache aggressively** - users need to clear cache after fixes
6. **Dynamic port detection** (`window.location.port`) makes apps more portable
