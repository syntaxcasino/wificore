# WebSocket and Authentication Fix - Production Issues

## Date: December 28, 2025

## Issues Identified

### 1. WebSocket Connection Failure (404 Error)
**Error:** `WebSocket connection to 'wss://wificore.traidsolutions.com/app/app/app-key' failed: Error during WebSocket handshake: Unexpected response code: 404`

**Root Cause:** 
- Frontend `echo.js` had a typo: `config.wsPath` instead of `config.path`
- This caused incorrect URL construction with duplicate `/app/app/` path

**Fix:**
- Updated `frontend/src/plugins/echo.js` line 105-106 to use `config.path` instead of `config.wsPath`

### 2. Authentication Token Expiration (401 Errors)
**Error:** Multiple 401 errors on `/api/dashboard/stats`, `/api/broadcasting/auth`, `/api/login`

**Root Cause:**
- Sanctum tokens were expiring after 24 hours (set in UnifiedAuthController.php line 317)
- `config/sanctum.php` had `expiration => null` which didn't enforce proper token lifecycle
- Session lifetime was only 120 minutes

**Fix:**
- Updated `backend/config/sanctum.php` to set `expiration => env('SANCTUM_TOKEN_EXPIRATION', 43200)` (30 days)
- Updated `.env.production`:
  - `SESSION_DRIVER=redis` (from database for better performance)
  - `SESSION_LIFETIME=43200` (30 days)
  - `SESSION_DOMAIN=.traidsolutions.com` (proper domain for subdomains)
  - Added `SANCTUM_TOKEN_EXPIRATION=43200`

### 3. CORS Configuration for Broadcasting Auth
**Root Cause:**
- Broadcasting auth endpoint wasn't explicitly included in CORS paths

**Fix:**
- Updated `backend/config/cors.php` to include `'broadcasting/auth'` in paths array

## Files Modified

1. **frontend/src/plugins/echo.js**
   - Fixed typo: `config.wsPath` → `config.path`
   - Ensured auth endpoint uses `/api/broadcasting/auth`

2. **backend/config/sanctum.php**
   - Set token expiration to 43200 minutes (30 days)

3. **backend/config/cors.php**
   - Added `'broadcasting/auth'` to CORS paths

4. **.env.production**
   - Changed `SESSION_DRIVER` to `redis`
   - Extended `SESSION_LIFETIME` to 43200 minutes
   - Set `SESSION_DOMAIN` to `.traidsolutions.com`
   - Added `SANCTUM_TOKEN_EXPIRATION=43200`

5. **frontend/Dockerfile**
   - Ensured `VITE_PUSHER_AUTH_ENDPOINT=/api/broadcasting/auth`

## Testing Checklist

- [ ] WebSocket connects successfully without 404 errors
- [ ] Broadcasting auth returns 200 instead of 401
- [ ] Dashboard stats load without 401 errors
- [ ] Login persists for 30 days with "remember me"
- [ ] Tenant channel subscriptions work
- [ ] User private channel subscriptions work
- [ ] Real-time events are received

## Deployment Steps

1. Rebuild frontend container:
   ```bash
   docker-compose -f docker-compose.production.yml build wificore-frontend
   ```

2. Rebuild backend container (for config changes):
   ```bash
   docker-compose -f docker-compose.production.yml build wificore-backend
   ```

3. Stop and restart all containers:
   ```bash
   docker-compose -f docker-compose.production.yml down
   docker-compose -f docker-compose.production.yml up -d
   ```

4. Verify services are healthy:
   ```bash
   docker-compose -f docker-compose.production.yml ps
   ```

5. Check logs for any errors:
   ```bash
   docker-compose -f docker-compose.production.yml logs -f wificore-frontend
   docker-compose -f docker-compose.production.yml logs -f wificore-backend
   docker-compose -f docker-compose.production.yml logs -f wificore-soketi
   ```

## Configuration Summary

### WebSocket Configuration (Production)
- **Host:** wificore.traidsolutions.com
- **Port:** 443 (WSS)
- **Path:** /app
- **Auth Endpoint:** /api/broadcasting/auth
- **Scheme:** wss (secure WebSocket)

### Token & Session Configuration
- **Token Expiration:** 43200 minutes (30 days)
- **Session Driver:** Redis
- **Session Lifetime:** 43200 minutes (30 days)
- **Session Domain:** .traidsolutions.com

## Notes

- All changes are backward compatible
- Redis session driver improves performance over database sessions
- Extended token lifetime reduces login frequency for users
- Proper session domain enables subdomain-based multi-tenancy
