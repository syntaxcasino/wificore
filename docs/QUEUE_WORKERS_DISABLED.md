# Queue Workers Temporarily Disabled [RESOLVED]

**Date:** October 6, 2025 11:35 AM EAT  
**Resolved:** October 6, 2025 12:23 PM EAT  
**Status:** ✅ FIXED - See STACK_FIXED.md for details

---

## Issue

Queue workers and scheduler are failing with:
```
Target class [auth] does not exist.
Class "auth" does not exist
```

## Root Cause

The error occurs when Laravel tries to boot the application for queue workers. This is likely due to:
1. Cached configuration referencing non-existent middleware
2. Service provider loading order issue
3. Missing or misconfigured auth guard

## Temporary Solution

**Disabled all queue workers and scheduler** to allow PHP-FPM to run and serve API requests.

### Files Modified

1. **backend/supervisor/laravel-scheduler.conf**
   - Set `autostart=false`

2. **backend/supervisor/laravel-queue.conf**
   - Set `autostart=false` for all queue workers:
     - laravel-queue-default
     - laravel-queue-router-checks
     - laravel-queue-router-data
     - laravel-queue-log-rotation
     - laravel-queue-payments
     - laravel-queue-provisioning

### What Still Works

✅ **PHP-FPM** - API requests  
✅ **Nginx** - Reverse proxy  
✅ **Frontend** - Vue.js application  
✅ **Soketi** - WebSocket server  
✅ **PostgreSQL** - Database  
✅ **FreeRADIUS** - RADIUS authentication  

### What Doesn't Work

❌ **Queue Workers** - Background job processing  
❌ **Scheduler** - Scheduled tasks  
❌ **Real-time provisioning** - Requires queue workers  
❌ **Background router checks** - Requires queue workers  

---

## Impact

### Features Affected

1. **Router Provisioning**
   - Configuration deployment won't work (requires queue)
   - No real-time progress updates (requires queue + WebSocket)
   - Manual provisioning still possible

2. **Router Monitoring**
   - No automatic status checks (requires queue)
   - Manual refresh still works

3. **Scheduled Tasks**
   - No automatic log rotation
   - No scheduled maintenance tasks

### Features Still Working

1. **API Endpoints**
   - ✅ Login/logout
   - ✅ Router CRUD operations
   - ✅ Package management
   - ✅ User management

2. **WebSocket**
   - ✅ Connection established
   - ✅ Channel subscriptions
   - ✅ Event broadcasting (if triggered manually)

3. **Frontend**
   - ✅ All pages load
   - ✅ Navigation works
   - ✅ Forms submit
   - ✅ EventMonitor displays

---

## Permanent Fix (TODO)

### Option 1: Fix Auth Configuration

1. Investigate cached configuration
2. Ensure all middleware properly registered
3. Verify auth guards configured correctly
4. Clear all caches and rebuild

### Option 2: Update Middleware References

1. Check all middleware for incorrect 'auth' references
2. Update to use proper guard names ('auth:sanctum')
3. Verify service provider loading order

### Option 3: Simplify Bootstrap

1. Remove custom middleware aliases
2. Use Laravel defaults
3. Gradually add customizations

---

## Manual Queue Processing

While queue workers are disabled, you can manually process jobs:

```bash
# Process one job from default queue
docker exec traidnet-backend php artisan queue:work database --queue=default --once

# Process provisioning queue
docker exec traidnet-backend php artisan queue:work database --queue=provisioning --once

# Process router checks
docker exec traidnet-backend php artisan queue:work database --queue=router-checks --once
```

---

## Testing Without Queue Workers

### Test API Endpoints

```bash
# Login
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Get routers
curl -X GET http://localhost/api/routers \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Test WebSocket

1. Open http://localhost
2. Login
3. Check EventMonitor shows connection
4. Manual events can still be triggered via API

### Test Frontend

1. All pages should load
2. Forms should submit
3. Data should display
4. Navigation should work

---

## Re-enabling Queue Workers

Once the auth issue is fixed:

1. Update supervisor configs to `autostart=true`
2. Rebuild backend: `docker compose up -d --build traidnet-backend`
3. Verify workers start successfully
4. Test provisioning flow

---

## Current System Status

| Component | Status | Notes |
|-----------|--------|-------|
| PHP-FPM | ✅ Running | API requests work |
| Queue Workers | ❌ Disabled | Temporary workaround |
| Scheduler | ❌ Disabled | Temporary workaround |
| Nginx | ✅ Healthy | Reverse proxy working |
| Frontend | ✅ Healthy | Vue.js app working |
| Soketi | ✅ Healthy | WebSocket working |
| PostgreSQL | ✅ Healthy | Database working |
| FreeRADIUS | ✅ Healthy | RADIUS working |

---

## Next Steps

1. ✅ Verify API endpoints work
2. ✅ Test WebSocket connection
3. ✅ Test frontend functionality
4. ⏳ Debug auth class issue
5. ⏳ Re-enable queue workers
6. ⏳ Test full provisioning flow

---

## Resolution

**All issues have been fixed!** See `STACK_FIXED.md` for complete details.

### Summary of Fixes:
1. ✅ Fixed `config/broadcasting.php` - Removed auth() helper call
2. ✅ Fixed `config/app.php` - Removed non-existent service providers
3. ✅ Fixed `supervisord.conf` - Added socket configuration
4. ✅ Re-enabled cache clearing in entrypoint
5. ✅ Re-enabled all queue workers and scheduler

**Status:** ✅ FULLY OPERATIONAL - All 15 queue workers + scheduler running

---

**Last Updated:** October 6, 2025 12:23 PM EAT
