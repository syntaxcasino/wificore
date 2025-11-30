# Supervisor Queue Worker Fix Report

**Date:** October 20, 2025  
**Issue:** SIGUSR2 termination warnings in supervisor logs  
**Status:** ✅ RESOLVED

---

## Problem Summary

The dockerized stack was experiencing repeated supervisor warnings:
```
WARN exited: laravel-queue-service-control_00 (terminated by SIGUSR2; not expected)
WARN exited: laravel-queue-router-data_03 (terminated by SIGUSR2; not expected)
WARN exited: laravel-queue-log-rotation_00 (terminated by SIGUSR2; not expected)
WARN exited: laravel-queue-payments_01 (terminated by SIGUSR2; not expected)
```

## Root Cause Analysis

1. **Log Rotation Job Behavior**: The `RotateLogs` job (running every minute) was sending SIGUSR2 signals to all queue workers via:
   ```php
   supervisorctl signal USR2 laravel-queues:*
   ```

2. **Supervisor Configuration Issue**: Queue workers were configured with:
   - `autorestart=unexpected`
   - `exitcodes=0`
   
   This meant supervisor expected workers to only exit with code 0, but SIGUSR2 caused a different exit behavior, triggering "not expected" warnings.

3. **Functional but Noisy**: The workers were restarting correctly, but the warnings cluttered logs and could mask real issues.

## Solution Implemented

### 1. Updated Supervisor Configuration
**File:** `backend/supervisor/laravel-queue.conf`

**Changes:**
- Changed `autorestart=unexpected` → `autorestart=true` for all 15 queue worker programs
- Removed `exitcodes=0` directive (no longer needed)

This tells supervisor to always restart workers regardless of exit reason, eliminating the "unexpected" warnings.

### 2. Removed Unnecessary SIGUSR2 Signaling
**File:** `backend/app/Jobs/RotateLogs.php`

**Changes:**
- Removed the `signalSupervisor()` call that sent SIGUSR2 to workers
- Added comment explaining that workers already restart periodically via `--max-time` parameter

**Rationale:**
- Queue workers already have `--max-time` parameters (ranging from 1800-3600 seconds)
- Workers automatically restart and reopen log file handles when they hit max-time
- No need to force immediate restart via SIGUSR2

## Affected Queue Workers (26 total processes)

All queue workers now use `autorestart=true`:
- `laravel-queue-default` (1 process)
- `laravel-queue-router-checks` (1 process)
- `laravel-queue-router-data` (4 processes)
- `laravel-queue-log-rotation` (1 process)
- `laravel-queue-payments` (2 processes)
- `laravel-queue-payment-checks` (2 processes)
- `laravel-queue-router-provisioning` (3 processes)
- `laravel-queue-dashboard` (1 process)
- `laravel-queue-hotspot-sms` (2 processes)
- `laravel-queue-hotspot-sessions` (2 processes)
- `laravel-queue-hotspot-accounting` (1 process)
- `laravel-queue-notifications` (1 process)
- `laravel-queue-service-control` (2 processes)
- `laravel-queue-provisioning` (2 processes)
- `laravel-queue-router-monitoring` (1 process)

## Verification Results

### ✅ All Services Healthy
```
traidnet-backend      HEALTHY (2 minutes uptime)
traidnet-frontend     HEALTHY (25 hours uptime)
traidnet-nginx        HEALTHY (26 hours uptime)
traidnet-postgres     HEALTHY (26 hours uptime)
traidnet-redis        HEALTHY (26 hours uptime)
traidnet-soketi       HEALTHY (26 hours uptime)
traidnet-freeradius   HEALTHY (26 hours uptime)
```

### ✅ All Queue Workers Running
```bash
$ docker exec traidnet-backend supervisorctl status
# All 26 queue workers + scheduler + php-fpm = 28 processes RUNNING
```

### ✅ No Failed Jobs
```bash
$ docker exec traidnet-backend php artisan queue:failed
INFO  No failed jobs found.
```

### ✅ Clean Logs
- No SIGUSR2 warnings in supervisor logs
- RotateLogs job completing successfully
- All scheduled jobs executing normally

### ✅ Application Responding
- API endpoints responding with 200 status codes
- Dashboard stats updating every 30 seconds
- Queue stats being tracked
- Health checks passing

## Technical Details

### Queue Worker Configuration Pattern
Each worker now follows this pattern:
```ini
[program:laravel-queue-{name}]
command=/usr/local/bin/php artisan queue:work database --queue={name} ...
autostart=true
autorestart=true          # Changed from 'unexpected'
startretries=3
startsecs=5
stopwaitsecs=60
stopsignal=TERM
user=www-data
stopasgroup=true
killasgroup=true
```

### Log Rotation Strategy
- Supervisor handles its own log rotation via `stdout_logfile_maxbytes` and `stdout_logfile_backups`
- Queue workers restart naturally via `--max-time` parameter
- No manual SIGUSR2 signaling needed

## Files Modified

1. `backend/supervisor/laravel-queue.conf` - Updated all 15 queue worker configurations
2. `backend/app/Jobs/RotateLogs.php` - Removed SIGUSR2 signaling logic
3. `backend/Dockerfile` - Rebuilt with updated configurations

## Deployment Steps Taken

1. Modified supervisor configuration files
2. Modified RotateLogs job
3. Rebuilt backend Docker image
4. Recreated backend container
5. Verified all services healthy
6. Monitored logs for 2+ minutes
7. Confirmed no SIGUSR2 warnings

## Recommendations

1. **Monitor for 24 hours** to ensure workers restart cleanly at their max-time intervals
2. **Check log rotation** continues to work properly without SIGUSR2 signaling
3. **Review worker max-time values** if you need more frequent restarts:
   - Current values range from 1800s (30min) to 3600s (60min)
   - Adjust in `laravel-queue.conf` if needed

## Conclusion

The SIGUSR2 warnings have been eliminated by:
1. Configuring supervisor to expect any restart reason (`autorestart=true`)
2. Removing unnecessary SIGUSR2 signaling from log rotation

The stack is now running cleanly with all 7 services healthy and 26 queue workers processing jobs without warnings.

---

**Fixed by:** Cascade AI  
**Tested:** October 20, 2025 at 15:28 UTC+3
