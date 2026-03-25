# Frontend Environment Variables Fix - December 6, 2025

## 🔴 **Problem**

Tenant users (and all users) could not login after container rebuild. Frontend was showing:

```
POST http://localhost:8000/api/login net::ERR_CONNECTION_REFUSED
Network Error
```

## 🔍 **Root Cause**

The frontend was trying to connect to `http://localhost:8000/api` instead of `http://localhost/api` (through Nginx).

### Why This Happened

1. **`.dockerignore` excludes `.env` files**
   - Line 26-27 in `frontend/.dockerignore`:
     ```
     .env
     .env.*
     ```
   - This prevented the `.env` file from being copied during Docker build

2. **No build arguments in Dockerfile**
   - The Dockerfile didn't have `ARG` or `ENV` declarations
   - Vite couldn't access environment variables during build
   - Resulted in hardcoded `localhost:8000` in the built JavaScript

3. **Environment variables are baked at build time**
   - Vite replaces `import.meta.env.VITE_*` with actual values during build
   - Cannot be changed at runtime
   - Must be set during `npm run build`

## ✅ **Solution**

Updated `frontend/Dockerfile` to include build arguments and environment variables.

### Changes Made

**File**: `frontend/Dockerfile`

**Added:**
```dockerfile
# Build arguments for environment variables
ARG VITE_API_URL=http://localhost/api
ARG VITE_API_BASE_URL=http://localhost/api
ARG VITE_PUSHER_APP_KEY=app-key
ARG VITE_PUSHER_HOST=localhost
ARG VITE_PUSHER_PORT=80
ARG VITE_PUSHER_SCHEME=ws
ARG VITE_PUSHER_APP_CLUSTER=mt1
ARG VITE_PUSHER_AUTH_ENDPOINT=/api/broadcasting/auth
ARG VITE_PUSHER_PATH=/app

# Set environment variables for Vite build
ENV VITE_API_URL=$VITE_API_URL
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL
ENV VITE_PUSHER_APP_KEY=$VITE_PUSHER_APP_KEY
ENV VITE_PUSHER_HOST=$VITE_PUSHER_HOST
ENV VITE_PUSHER_PORT=$VITE_PUSHER_PORT
ENV VITE_PUSHER_SCHEME=$VITE_PUSHER_SCHEME
ENV VITE_PUSHER_APP_CLUSTER=$VITE_PUSHER_APP_CLUSTER
ENV VITE_PUSHER_AUTH_ENDPOINT=$VITE_PUSHER_AUTH_ENDPOINT
ENV VITE_PUSHER_PATH=$VITE_PUSHER_PATH
```

### Rebuild Process

```bash
# Rebuild frontend with no cache
docker compose build --no-cache traidnet-frontend

# Restart frontend container
docker compose up -d traidnet-frontend
```

### Verification

```bash
# Check for old hardcoded URL (should return nothing)
docker exec traidnet-frontend sh -c "grep -o 'localhost:8000' /usr/share/nginx/html/assets/index-*.js"
# Result: (empty) ✅

# Check for correct URL (should find matches)
docker exec traidnet-frontend sh -c "grep -o 'localhost/api' /usr/share/nginx/html/assets/index-*.js | head -5"
# Result: localhost/api (multiple times) ✅
```

## 📊 **Architecture**

### Frontend API Configuration Flow

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Build Time (Docker Build)                                │
│    - ARG declarations define build arguments                │
│    - ENV sets environment variables from ARG                │
│    - Vite reads ENV during npm run build                    │
│    - Replaces import.meta.env.VITE_* with actual values     │
│    - Bakes values into JavaScript bundle                    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. Runtime (Browser)                                         │
│    - Browser loads JavaScript bundle                         │
│    - API calls use baked-in values                          │
│    - http://localhost/api → Nginx → Backend                 │
└─────────────────────────────────────────────────────────────┘
```

### Request Flow

```
Browser → http://localhost/api/login
   ↓
Nginx (port 80)
   ↓
Backend (port 9000) → PHP-FPM
   ↓
RADIUS Authentication
   ↓
Response → Nginx → Browser
```

## 🔧 **Environment Variables**

### Frontend (.env)
```env
VITE_API_URL=http://localhost/api
VITE_API_BASE_URL=http://localhost/api
VITE_PUSHER_APP_KEY=app-key
VITE_PUSHER_HOST=localhost
VITE_PUSHER_PORT=80
VITE_PUSHER_SCHEME=ws
VITE_PUSHER_APP_CLUSTER=mt1
VITE_PUSHER_AUTH_ENDPOINT=/api/broadcasting/auth
VITE_PUSHER_PATH=/app
```

### Why These Values?

| Variable | Value | Reason |
|----------|-------|--------|
| `VITE_API_URL` | `http://localhost/api` | Goes through Nginx reverse proxy |
| `VITE_API_BASE_URL` | `http://localhost/api` | Axios base URL (same as above) |
| `VITE_PUSHER_HOST` | `localhost` | Soketi via Nginx |
| `VITE_PUSHER_PORT` | `80` | Nginx port (not 6001 directly) |
| `VITE_PUSHER_SCHEME` | `ws` | WebSocket (not wss in dev) |
| `VITE_PUSHER_PATH` | `/app` | Soketi path via Nginx |

## 🎯 **Key Learnings**

### 1. **Vite Environment Variables**
- ✅ Must be prefixed with `VITE_`
- ✅ Baked at build time (not runtime)
- ✅ Accessed via `import.meta.env.VITE_*`
- ✅ Replaced with actual values in production build

### 2. **Docker Build Context**
- ✅ `.dockerignore` prevents files from being copied
- ✅ Use `ARG` and `ENV` for build-time variables
- ✅ `ARG` can have default values
- ✅ `ENV` sets environment for build process

### 3. **Multi-Stage Builds**
- ✅ Builder stage needs environment variables
- ✅ Final stage only gets built artifacts
- ✅ Environment variables don't persist to final image
- ✅ Values are baked into JavaScript bundle

## 📝 **Files Modified**

1. ✅ `frontend/Dockerfile` - Added ARG and ENV declarations
2. ✅ Frontend container rebuilt with correct environment

## 🚀 **Testing**

### Test System Admin Login
```bash
# Open browser: http://localhost
Username: sysadmin
Password: Admin@123!

# Expected:
✅ No ERR_CONNECTION_REFUSED
✅ POST http://localhost/api/login
✅ 200 OK response
✅ Token generated
```

### Test Tenant Admin Login
```bash
# Open browser: http://localhost
Username: admin-a
Password: [tenant password]

# Expected:
✅ No ERR_CONNECTION_REFUSED
✅ POST http://localhost/api/login
✅ 200 OK response
✅ Token generated
```

### Check Browser Console
```javascript
// Should see:
🔐 Attempting login... {username: 'sysadmin'}
🍪 Getting CSRF cookie from: http://localhost/sanctum/csrf-cookie
✅ CSRF cookie obtained
📍 Posting to: /login (baseURL: /api)
✅ Login successful
```

## 🔄 **For Production Deployment**

When deploying to production, update the build arguments:

```yaml
# docker-compose.prod.yml
services:
  traidnet-frontend:
    build:
      context: ./frontend
      args:
        VITE_API_URL: https://yourdomain.com/api
        VITE_API_BASE_URL: https://yourdomain.com/api
        VITE_PUSHER_HOST: yourdomain.com
        VITE_PUSHER_PORT: 443
        VITE_PUSHER_SCHEME: wss
```

Or use build command:

```bash
docker compose build \
  --build-arg VITE_API_URL=https://yourdomain.com/api \
  --build-arg VITE_API_BASE_URL=https://yourdomain.com/api \
  --build-arg VITE_PUSHER_HOST=yourdomain.com \
  --build-arg VITE_PUSHER_PORT=443 \
  --build-arg VITE_PUSHER_SCHEME=wss \
  traidnet-frontend
```

## 📚 **Related Documentation**

- `LOGIN_FIX.md` - Backend login fixes (RADIUS functions)
- `IMPLEMENTATION_COMPLETE.md` - Container setup
- `QUICK_REFERENCE.md` - Quick commands

---

**Issue Resolved**: December 6, 2025  
**Status**: ✅ FIXED - Frontend now connects to correct API endpoint  
**Impact**: All users (system admin, tenant admin, hotspot users) can now login
