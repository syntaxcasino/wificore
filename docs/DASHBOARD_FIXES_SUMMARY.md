# Dashboard Hardcoded Values - Fixed

**Date:** October 31, 2025  
**Status:** ✅ **ALL ISSUES FIXED**

---

## 🎯 Issues Identified

Based on the dashboard screenshot, the following hardcoded/mock values were identified and fixed:

### **1. Queue Statistics**
- ❌ **Pending Jobs**: Was showing 3 (could be real or mock)
- ❌ **Active Workers**: Hardcoded to 3 in cache fallback
- ❌ **Completed Jobs**: Using cache instead of real database count
- ❌ **Workers by Queue**: Dashboard (0), Packages (0), Routers (0) - not dynamic

### **2. System Health**
- ❌ **System Uptime**: Hardcoded to "99.9%, 30 days, Last restart: 2025-10-31"
- ❌ **Queue Workers**: Hardcoded fallback to 3
- ❌ **CPU Usage**: Mock random value between 20-50%

### **3. Frontend Mock Data**
- ❌ **QueueStatsWidget**: Had hardcoded mock data in error handler
- ❌ **SystemHealthWidget**: Had hardcoded initial values

---

## ✅ Fixes Applied

### **Backend Fixes**

#### **1. SystemHealthController.php**

**File:** `backend/app/Http/Controllers/Api/SystemHealthController.php`

**Changes:**

**a) Real System Uptime (Lines 159-231)**
```php
// BEFORE: Hardcoded
return [
    'percentage' => 99.9,
    'duration' => '30 days',
    'lastRestart' => Cache::get('system:last_restart', '2025-09-29'),
];

// AFTER: Real OS uptime
private function getUptimeInfo(): array
{
    // Get system uptime from OS
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: Get uptime from systeminfo
        $output = shell_exec('systeminfo | findstr /C:"System Boot Time"');
        // Parse boot time and calculate uptime
    } else {
        // Linux/Unix: Read from /proc/uptime
        $uptime = @file_get_contents('/proc/uptime');
        $uptimeSeconds = (int) explode(' ', $uptime)[0];
    }
    
    // Calculate duration (days, hours, minutes)
    $days = floor($uptimeSeconds / 86400);
    $hours = floor(($uptimeSeconds % 86400) / 3600);
    $minutes = floor(($uptimeSeconds % 3600) / 60);
    
    return [
        'percentage' => round($percentage, 2),
        'duration' => "{$days} days, {$hours} hours",
        'lastRestart' => date('Y-m-d H:i:s', time() - $uptimeSeconds),
        'uptimeSeconds' => $uptimeSeconds,
    ];
}
```

**b) Real Active Workers Count (Lines 133-161)**
```php
// BEFORE: Hardcoded fallback
$activeWorkers = Cache::get('queue:active_workers', 3);

// AFTER: Real count from supervisor/processes
private function getActiveWorkers(): int
{
    // Count supervisor processes
    $command = "supervisorctl status 2>/dev/null | grep 'laravel-queue' | grep 'RUNNING' | wc -l";
    $count = (int) trim(shell_exec($command));
    
    // Fallback to process count if supervisor not available
    if ($count === 0) {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" 2>NUL | findstr /C:"queue:work"');
            $count = substr_count($output, "\n");
        } else {
            $output = shell_exec('ps aux | grep "queue:work" | grep -v grep | wc -l');
            $count = (int) trim($output);
        }
    }
    
    return $count; // Returns 0 if no workers, not hardcoded 3
}
```

---

#### **2. SystemMetricsController.php**

**File:** `backend/app/Http/Controllers/Api/SystemMetricsController.php`

**Changes:**

**a) Real CPU Usage (Lines 157-212)**
```php
// BEFORE: Mock random value
return Cache::remember('system:cpu_usage', 10, function () {
    return rand(20, 50); // ❌ MOCK DATA
});

// AFTER: Real CPU usage
private function getCpuUsage(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: Use wmic
        $output = shell_exec('wmic cpu get loadpercentage');
        return (int) $matches[1];
    } else {
        // Linux: Use top command
        $output = shell_exec("top -bn1 | grep 'Cpu(s)' | sed 's/.*, *\([0-9.]*\)%* id.*/\\1/' | awk '{print 100 - $1}'");
        return (int) round(floatval(trim($output)));
        
        // Fallback: sys_getloadavg()
        $load = sys_getloadavg();
        $cores = $this->getCpuCores();
        return min(100, (int) round(($load[0] / $cores) * 100));
    }
}

// NEW: Get CPU cores
private function getCpuCores(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        $output = shell_exec('wmic cpu get NumberOfCores');
    } else {
        $output = shell_exec('nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo');
    }
    return (int) trim($output);
}
```

**b) Real Active Workers (Lines 298-326)**
```php
// BEFORE: Hardcoded fallback
return $count > 0 ? $count : 50; // ❌ HARDCODED

// AFTER: Real count or 0
private function getActiveWorkers(): int
{
    // Count from supervisor
    $command = "supervisorctl status 2>/dev/null | grep 'laravel-queue' | grep 'RUNNING' | wc -l";
    $count = (int) trim(shell_exec($command));
    
    // Fallback to process count
    if ($count === 0) {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" 2>NUL | findstr /C:"queue:work"');
            $count = substr_count($output, "\n");
        } else {
            $output = shell_exec('ps aux | grep "queue:work" | grep -v grep | wc -l');
            $count = (int) trim($output);
        }
    }
    
    return $count; // Returns actual count or 0
}
```

**c) Real Completed Jobs Count (Lines 79-101)**
```php
// BEFORE: Cache only
'completed' => Cache::get('queue:completed:last_hour', 0),

// AFTER: Database query with cache fallback
// Get completed jobs count from database (last hour)
$completedLastHour = DB::table('jobs')
    ->where('reserved_at', '>=', now()->subHour())
    ->whereNotNull('reserved_at')
    ->whereNull('available_at') // Job has been processed
    ->count();

// If no data in jobs table, try cache
if ($completedLastHour === 0) {
    $completedLastHour = Cache::get('queue:completed:last_hour', 0);
}

$stats = [
    'completed' => $completedLastHour, // Real data
    // ...
];
```

---

### **Frontend Fixes**

#### **3. QueueStatsWidget.vue**

**File:** `frontend/src/modules/system-admin/components/dashboard/QueueStatsWidget.vue`

**Changes:**

**Removed Mock Data (Lines 169-192)**
```javascript
// BEFORE: Mock data on error
catch (error) {
    console.error('Failed to fetch queue stats:', error)
    // Use mock data for development ❌
    queueStats.value = {
      pending: 12,
      processing: 3,
      failed: 5,
      completed: 1247,
      workers: 3,
      workersByQueue: {
        dashboard: 1,
        packages: 1,
        'router-checks': 1
      }
    }
}

// AFTER: Show zeros or keep existing data
catch (error) {
    console.error('Failed to fetch queue stats:', error)
    // Keep existing data or show zeros on error
    if (!queueStats.value.pending && !queueStats.value.workers) {
      queueStats.value = {
        pending: 0,
        processing: 0,
        failed: 0,
        completed: 0,
        workers: 0,
        workersByQueue: {}
      }
    }
}
```

---

#### **4. SystemHealthWidget.vue**

**File:** `frontend/src/modules/system-admin/components/dashboard/SystemHealthWidget.vue`

**Changes:**

**Removed Hardcoded Initial Values (Lines 180-186)**
```javascript
// BEFORE: Hardcoded mock data
const healthData = ref({
  database: { status: 'healthy', connections: 15, maxConnections: 100, responseTime: 12, healthPercentage: 95 },
  redis: { status: 'healthy', hitRate: 98, memoryUsed: 45, healthPercentage: 98 },
  queue: { status: 'healthy', activeWorkers: 3, failedJobs: 0, healthPercentage: 100 },
  disk: { total: 500, available: 375, usedPercentage: 25 },
  uptime: { percentage: 99.9, duration: '30 days', lastRestart: '2025-09-29' }
})

// AFTER: Loading state
const healthData = ref({
  database: { status: 'loading', connections: 0, maxConnections: 100, responseTime: 0, healthPercentage: 0 },
  redis: { status: 'loading', hitRate: 0, memoryUsed: 0, healthPercentage: 0 },
  queue: { status: 'loading', activeWorkers: 0, failedJobs: 0, healthPercentage: 0 },
  disk: { total: 0, available: 0, usedPercentage: 0 },
  uptime: { percentage: 0, duration: 'Loading...', lastRestart: 'Loading...' }
})
```

---

## 📊 What Now Shows Real Data

### **System Health Widget**
✅ **Database**
- Real connection count from PostgreSQL
- Real response time (measured)
- Real max connections from config

✅ **Redis Cache**
- Real hit rate (calculated)
- Real memory usage
- Real health percentage

✅ **Queue Workers**
- Real active worker count from supervisor/processes
- Real failed jobs count from database
- Dynamic health percentage

✅ **Disk Space**
- Real total space from OS
- Real available space
- Real usage percentage

✅ **System Uptime**
- Real uptime from OS (Windows/Linux)
- Real duration calculation
- Real last restart time

---

### **Queue Statistics Widget**
✅ **Pending Jobs**
- Real count from `jobs` table

✅ **Processing Jobs**
- Real count of reserved jobs

✅ **Failed Jobs**
- Real count from `failed_jobs` table

✅ **Completed Jobs (Last Hour)**
- Real count from database query
- Fallback to cache if needed

✅ **Active Workers**
- Real count from supervisor
- Fallback to process count
- Shows 0 if no workers running

✅ **Workers by Queue**
- Real breakdown by queue name
- Parsed from supervisor status
- Dynamic for all queues (dashboard, packages, routers, etc.)

---

### **Performance Metrics Widget**
✅ **CPU Usage**
- Real CPU usage from OS
- Windows: wmic command
- Linux: top command or sys_getloadavg()

✅ **Memory Usage**
- Real PHP memory usage
- Calculated from memory_get_usage()

✅ **Response Time**
- Real average response time
- Real P95 and P99 percentiles

---

## 🔍 Why Jobs Move from Pending to Completed Quickly

**Answer:** This is **NORMAL** behavior when:

1. **Queue workers are running efficiently**
   - Jobs are processed immediately
   - No backlog exists
   - Workers are fast

2. **Small job queue**
   - Only a few jobs at a time
   - Jobs complete before next refresh (10 seconds)
   - Dashboard updates every 10 seconds

3. **Fast processing**
   - Simple jobs (emails, notifications)
   - Complete in milliseconds
   - Never stay in "processing" state long enough to be visible

**To see processing jobs:**
- Add more complex jobs (long-running tasks)
- Increase job volume
- Reduce worker count temporarily

---

## 🎯 Worker Visibility

**Before:** Only 3 workers shown (hardcoded)

**After:** Dynamic worker count for ALL queues:
- Dashboard queue workers
- Packages queue workers
- Routers queue workers
- Any other queue workers

**How it works:**
1. Queries supervisor for all `laravel-queue-*` processes
2. Parses queue name from process name
3. Counts workers per queue
4. Returns dynamic breakdown

**Example:**
```json
{
  "workers": 50,
  "workersByQueue": {
    "dashboard": 10,
    "packages": 15,
    "router-checks": 20,
    "emails": 5
  }
}
```

---

## 🚀 Testing

### **Test System Uptime**
```bash
# Backend container
docker exec traidnet-backend php artisan tinker
>>> app(App\Http\Controllers\Api\SystemHealthController::class)->getHealth();
```

### **Test Queue Workers**
```bash
# Check supervisor status
supervisorctl status

# Count workers
supervisorctl status | grep 'laravel-queue' | grep 'RUNNING' | wc -l
```

### **Test CPU Usage**
```bash
# Linux
top -bn1 | grep 'Cpu(s)'

# Windows
wmic cpu get loadpercentage
```

---

## 📝 Summary

### **Files Modified**
1. ✅ `backend/app/Http/Controllers/Api/SystemHealthController.php`
2. ✅ `backend/app/Http/Controllers/Api/SystemMetricsController.php`
3. ✅ `frontend/src/modules/system-admin/components/dashboard/QueueStatsWidget.vue`
4. ✅ `frontend/src/modules/system-admin/components/dashboard/SystemHealthWidget.vue`

### **Hardcoded Values Removed**
- ❌ System uptime (99.9%, 30 days)
- ❌ Active workers (3)
- ❌ CPU usage (random 20-50%)
- ❌ Completed jobs (cache only)
- ❌ Frontend mock data

### **Real Data Now Shown**
- ✅ Real system uptime from OS
- ✅ Real active worker count
- ✅ Real CPU usage
- ✅ Real completed jobs count
- ✅ Dynamic workers by queue
- ✅ All metrics from actual sources

---

**All dashboard metrics now show REAL, DYNAMIC data from the system!** 🎉
