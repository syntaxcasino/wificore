# Troubleshooting: Router Data Not Updating

**Issue:** Router CPU, Memory, Disk, and Users showing `—` (no data)

**Root Cause:** Queue workers stuck in STARTING state due to missing Redis PHP extension

---

## 🔍 **Diagnosis**

The issue was identified by checking:
```bash
docker exec traidnet-backend supervisorctl status
```

**Problem:** All queue workers showing `STARTING` instead of `RUNNING`
```
laravel-queues:laravel-queue-router-data_00    STARTING  ❌
laravel-queues:laravel-queue-router-data_01    STARTING  ❌
```

**Should be:**
```
laravel-queues:laravel-queue-router-data_00    RUNNING   ✅
laravel-queues:laravel-queue-router-data_01    RUNNING   ✅
```

---

## ✅ **Solution**

### **Step 1: Rebuild Backend Container (with Redis extension)**
```bash
cd d:\traidnet\wifi-hotspot
docker-compose build traidnet-backend
```

### **Step 2: Restart the Container**
```bash
docker-compose up -d traidnet-backend
```

### **Step 3: Verify Queue Workers Are Running**
```bash
docker exec traidnet-backend supervisorctl status
```

**Expected output:**
```
laravel-queues:laravel-queue-router-data_00    RUNNING   pid 123, uptime 0:00:10
laravel-queues:laravel-queue-router-data_01    RUNNING   pid 124, uptime 0:00:10
...
```

### **Step 4: Check Redis Extension**
```bash
docker exec traidnet-backend php -m | grep redis
```

**Expected output:**
```
redis
```

### **Step 5: Clear Caches**
```bash
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan cache:clear
docker exec traidnet-backend php artisan queue:restart
```

### **Step 6: Wait 30 Seconds**
The `FetchRouterLiveData` job runs every 30 seconds. Wait a bit and refresh your browser.

---

## 🔄 **How Router Data Updates Work**

### **Scheduled Task (routes/console.php):**
```php
Schedule::call(function () {
    $routers = Router::whereIn('status', ['online', 'active'])->pluck('id')->toArray();
    if (!empty($routers)) {
        $chunks = array_chunk($routers, 10);
        foreach ($chunks as $chunk) {
            FetchRouterLiveData::dispatch($chunk)->onQueue('router-data');
        }
    }
})->everyThirtySeconds();
```

### **Flow:**
```
1. Scheduler runs every 30 seconds
   ↓
2. Dispatches FetchRouterLiveData job to 'router-data' queue
   ↓
3. Queue worker processes job
   ↓
4. Connects to router via API
   ↓
5. Fetches CPU, Memory, Disk, Users data
   ↓
6. Updates database
   ↓
7. Frontend displays updated data
```

---

## 🧪 **Manual Testing**

### **Test Queue Worker:**
```bash
# Check if queue worker can process jobs
docker exec traidnet-backend php artisan queue:work router-data --once
```

### **Test Router Connection:**
```bash
docker exec traidnet-backend php artisan tinker

# In tinker:
$router = App\Models\Router::first();
$service = new App\Services\MikrotikProvisioningService();
$connection = $service->connect($router->ip_address, $router->username, $router->password);
// Should return connection object
```

### **Manually Dispatch Job:**
```bash
docker exec traidnet-backend php artisan tinker

# In tinker:
$router = App\Models\Router::first();
App\Jobs\FetchRouterLiveData::dispatch([$router->id])->onQueue('router-data');
// Job dispatched, check logs
```

---

## 📊 **Check Logs**

### **Supervisor Logs:**
```bash
docker exec traidnet-backend tail -f /var/log/supervisor/laravel-queue-router-data_00-stdout.log
```

### **Laravel Logs:**
```bash
docker exec traidnet-backend tail -f storage/logs/laravel.log
```

### **Queue Failed Jobs:**
```bash
docker exec traidnet-backend php artisan queue:failed
```

---

## ⚠️ **Common Issues**

### **Issue 1: Queue Workers Not Starting**
**Symptom:** All workers stuck in STARTING
**Cause:** Redis extension missing
**Fix:** Rebuild container (Step 1-2 above)

### **Issue 2: Router Connection Failed**
**Symptom:** Data still showing `—` after queue workers running
**Cause:** Router credentials incorrect or router unreachable
**Fix:** 
```bash
# Test router connection
docker exec traidnet-backend php artisan tinker
$router = App\Models\Router::first();
// Verify: $router->ip_address, $router->username, $router->password
```

### **Issue 3: Jobs Failing Silently**
**Symptom:** Queue workers running but no data
**Cause:** Job exceptions not logged
**Fix:**
```bash
# Check failed jobs
docker exec traidnet-backend php artisan queue:failed

# Retry failed jobs
docker exec traidnet-backend php artisan queue:retry all
```

---

## 🎯 **Quick Verification Checklist**

After rebuild, verify:

- [ ] Redis extension installed: `docker exec traidnet-backend php -m | grep redis`
- [ ] Queue workers RUNNING: `docker exec traidnet-backend supervisorctl status`
- [ ] No failed jobs: `docker exec traidnet-backend php artisan queue:failed`
- [ ] Router reachable: Ping router IP from container
- [ ] Scheduler running: Check supervisorctl for `laravel-scheduler RUNNING`
- [ ] Wait 30 seconds and refresh browser

---

## ✅ **Expected Result**

After fix, you should see:
- ✅ CPU: Shows percentage (e.g., 45%)
- ✅ Memory: Shows usage (e.g., 2.1 GB / 4 GB)
- ✅ Disk: Shows usage (e.g., 15 GB / 32 GB)
- ✅ Users: Shows count (e.g., 12)
- ✅ Last Seen: Updates every 30 seconds

---

## 📝 **Related Files**

- `backend/Dockerfile` - Redis extension added
- `routes/console.php` - Scheduler configuration
- `app/Jobs/FetchRouterLiveData.php` - Job that fetches data
- `supervisor/laravel-queue.conf` - Queue worker configuration

---

**Fixed By:** Cascade AI  
**Date:** 2025-10-11 09:45  
**Root Cause:** Missing Redis PHP extension  
**Impact:** Queue workers couldn't start, router data couldn't update
