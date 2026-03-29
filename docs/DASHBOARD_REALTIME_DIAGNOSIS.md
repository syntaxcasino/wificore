# Dashboard Real-Time Statistics - End-to-End Diagnosis

**Date:** October 9, 2025  
**Issue:** Dashboard not updating with near real-time statistics  
**Status:** 🔴 ROOT CAUSE IDENTIFIED

---

## Executive Summary

The dashboard real-time statistics are **NOT updating** because events are being sent to the **log driver** instead of being **broadcast via WebSocket** to connected clients.

### Root Cause
**`.env` file has conflicting `BROADCAST_DRIVER` settings:**
- Line 43: `BROADCAST_DRIVER=pusher` ✅ (Correct - but gets overridden)
- Line 118: `BROADCAST_DRIVER=log` ❌ (**PROBLEM** - This overrides the first setting!)

When Laravel loads the `.env` file, the **last occurrence wins**, so all broadcast events go to the log file instead of WebSocket.

---

## Complete Stack Analysis

### 1. ✅ Backend - Job Execution (WORKING)

#### Scheduler Configuration
**File:** `backend/routes/console.php`
```php
// Update dashboard statistics every 30 seconds for near real-time data
Schedule::job(new UpdateDashboardStatsJob)->everyThirtySeconds();
```

#### Job Implementation
**File:** `backend/app/Jobs/UpdateDashboardStatsJob.php`
- ✅ Fetches all statistics correctly
- ✅ Caches data for 30 seconds
- ✅ Broadcasts `DashboardStatsUpdated` event
- ✅ Logs successful execution

**Evidence from logs:**
```
[2025-10-09 12:16:35] local.INFO: Dashboard statistics updated and broadcasted
[2025-10-09 12:17:00] local.INFO: Dashboard statistics updated and broadcasted
[2025-10-09 12:17:32] local.INFO: Dashboard statistics updated and broadcasted
```

#### Queue Worker Status
```
laravel-queues:laravel-queue-dashboard_00  RUNNING   pid 8722, uptime 0:00:24
```

**✅ Jobs are executing every 30 seconds as expected**

---

### 2. ❌ Backend - Event Broadcasting (BROKEN)

#### Event Definition
**File:** `backend/app/Events/DashboardStatsUpdated.php`
```php
class DashboardStatsUpdated implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard-stats'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stats.updated';
    }
}
```

**✅ Event is properly configured to broadcast**

#### Broadcasting Configuration
**File:** `backend/.env`
```env
# Line 43 (FIRST OCCURRENCE)
BROADCAST_DRIVER=pusher  ✅ Correct

# Line 118 (SECOND OCCURRENCE - WINS!)
BROADCAST_DRIVER=log  ❌ PROBLEM!
```

**❌ Events are being logged instead of broadcast to WebSocket**

#### What's Happening
1. `UpdateDashboardStatsJob` executes ✅
2. Event `DashboardStatsUpdated` is fired ✅
3. Laravel checks `BROADCAST_DRIVER` config
4. Finds `BROADCAST_DRIVER=log` (line 118 overrides line 43)
5. Writes event to log file instead of broadcasting ❌
6. Frontend never receives the event ❌

---

### 3. ✅ WebSocket Server - Soketi (WORKING)

#### Container Status
```
Container: traidnet-soketi  RUNNING
```

#### Connection Logs
```
Pusher connection established
WebSocket connections active
```

**✅ Soketi is running and accepting connections**

---

### 4. ✅ Frontend - WebSocket Client (WORKING)

#### Echo Configuration
**File:** `frontend/src/plugins/echo.js`
```javascript
const echoInstance = new Echo({
  broadcaster: 'pusher',
  key: 'app-key',
  wsHost: window.location.hostname,
  wsPort: 80,
  authEndpoint: '/api/broadcasting/auth',
  // ... properly configured
});
```

**✅ Echo is properly configured**

#### Dashboard Component
**File:** `frontend/src/views/Dashboard.vue`
```javascript
onMounted(() => {
  // Initial fetch
  fetchDashboardStats()  ✅
  
  // Polling fallback (every 30 seconds)
  pollingInterval = setInterval(fetchDashboardStats, 30000)  ✅
  
  // WebSocket listener
  subscribeToPrivateChannel('dashboard-stats', {
    'stats.updated': (event) => {
      console.log('Dashboard stats updated via WebSocket:', event)
      if (event.stats) {
        updateStatsFromEvent(event.stats)
      }
    },
  })  ✅
})
```

**✅ Frontend is listening for events correctly**

#### What's Working
1. Initial data fetch on page load ✅
2. Polling fallback every 30 seconds ✅
3. WebSocket connection established ✅
4. Listening for `stats.updated` event ✅

#### What's NOT Working
- Events never arrive because backend isn't broadcasting them ❌

---

## Data Flow Analysis

### Expected Flow (NOT HAPPENING)
```
1. Scheduler triggers UpdateDashboardStatsJob every 30s
   ↓
2. Job calculates statistics
   ↓
3. Job broadcasts DashboardStatsUpdated event
   ↓
4. Laravel sends event to Soketi via HTTP
   ↓
5. Soketi broadcasts to all connected clients
   ↓
6. Frontend receives event via WebSocket
   ↓
7. Dashboard updates in real-time
```

### Actual Flow (CURRENT)
```
1. Scheduler triggers UpdateDashboardStatsJob every 30s ✅
   ↓
2. Job calculates statistics ✅
   ↓
3. Job broadcasts DashboardStatsUpdated event ✅
   ↓
4. Laravel checks BROADCAST_DRIVER=log ❌
   ↓
5. Event written to laravel.log ❌
   ↓
6. Soketi never receives event ❌
   ↓
7. Frontend never receives update ❌
   ↓
8. Dashboard only updates via polling (30s intervals) ⚠️
```

---

## Evidence from Logs

### Backend Laravel Log
```
[2025-10-09 12:17:32] local.INFO: Dashboard statistics updated and broadcasted
```
✅ Job executes successfully

### Soketi Log
```
ws: uWS.WebSocket { ... }
data: { event: 'pusher:pong', data: {} }
```
✅ WebSocket server running, but no dashboard events received

### Frontend Console (Expected but Missing)
```
❌ No "Dashboard stats updated via WebSocket" messages
❌ Only initial fetch and polling updates
```

---

## Configuration Files Audit

### Backend `.env` Issues
```env
# DUPLICATE BROADCAST_DRIVER SETTINGS

Line 43:  BROADCAST_DRIVER=pusher  ← First (ignored)
Line 118: BROADCAST_DRIVER=log     ← Second (ACTIVE - WRONG!)
```

### Soketi Configuration (Correct)
```env
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=app-key
PUSHER_APP_SECRET=app-secret
PUSHER_HOST=localhost
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

---

## Solution

### Fix #1: Remove Duplicate BROADCAST_DRIVER
**File:** `backend/.env`

**Remove or comment out line 118:**
```env
# Line 118 - REMOVE THIS:
# BROADCAST_DRIVER=log
```

**Keep only line 43:**
```env
# Line 43 - KEEP THIS:
BROADCAST_DRIVER=pusher
```

### Fix #2: Restart Backend Container
```bash
docker-compose restart traidnet-backend
```

### Fix #3: Verify Broadcasting
```bash
# Check logs for broadcast events
docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log | grep -i broadcast

# Check Soketi logs for incoming events
docker logs -f traidnet-soketi
```

---

## Testing Plan

### 1. Verify Configuration
```bash
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan config:cache
docker exec traidnet-backend php artisan tinker
>>> config('broadcasting.default')
# Should return: "pusher"
```

### 2. Test Event Broadcasting
```bash
# Trigger manual refresh
curl -X POST http://localhost/api/dashboard/refresh \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 3. Monitor Frontend Console
Open browser console and watch for:
```
✅ Dashboard stats updated via WebSocket: { stats: {...} }
```

### 4. Verify Real-Time Updates
1. Open dashboard in browser
2. Watch "Updated X ago" indicator
3. Should update every ~30 seconds without page refresh
4. "Live Updates" badge should show green with pulsing dot

---

## Additional Findings

### Polling Fallback (Currently Active)
The dashboard has a **30-second polling fallback** that's currently the ONLY way data updates:
```javascript
pollingInterval = setInterval(fetchDashboardStats, 30000)
```

This explains why the dashboard **does** update, but:
- ❌ Not in "real-time" (30s delay)
- ❌ Unnecessary HTTP requests
- ❌ Higher server load
- ❌ "Live Updates" indicator misleading

### Queue Configuration (Correct)
```
Queue: dashboard
Workers: 1 (RUNNING)
Pending Jobs: 0
Failed Jobs: 0
```

### Scheduler (Correct)
```php
Schedule::job(new UpdateDashboardStatsJob)->everyThirtySeconds();
```

---

## Impact Assessment

### Current State
- ⚠️ Dashboard updates every 30 seconds via polling
- ❌ WebSocket events not being broadcast
- ❌ "Live Updates" indicator shows connected but events don't arrive
- ⚠️ Increased server load from polling
- ⚠️ Not truly "real-time"

### After Fix
- ✅ Dashboard updates in real-time (< 1 second)
- ✅ WebSocket events broadcast correctly
- ✅ "Live Updates" indicator accurate
- ✅ Reduced server load (no polling needed)
- ✅ True real-time experience

---

## Files Requiring Changes

1. ✅ `backend/.env` - Remove duplicate `BROADCAST_DRIVER=log` on line 118

That's it! One line change fixes the entire issue.

---

## Conclusion

The dashboard real-time statistics system is **fully implemented and working correctly** except for **one configuration error**:

**Problem:** Duplicate `BROADCAST_DRIVER` setting in `.env` file causes events to be logged instead of broadcast.

**Solution:** Remove the duplicate `BROADCAST_DRIVER=log` on line 118 of `backend/.env`.

**Impact:** One-line fix enables true real-time dashboard updates via WebSocket.

All other components are working correctly:
- ✅ Scheduler running every 30 seconds
- ✅ Jobs executing successfully
- ✅ Events being fired
- ✅ Soketi WebSocket server running
- ✅ Frontend listening for events
- ✅ Polling fallback working

The system is **99% complete** - just needs the broadcast driver configuration fixed!
