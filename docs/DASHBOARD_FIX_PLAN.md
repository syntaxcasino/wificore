# Dashboard Metrics Fix Plan

## Issues Identified

### 1. **Laravel Scheduler Not Running**
- The `routes/console.php` defines scheduled jobs to collect metrics every minute
- `CollectSystemMetricsJob` should run every minute
- `MetricsService::resetTPSCounter()` should run every minute
- `MetricsService::storeMetrics()` should run every 5 minutes
- **Problem**: Laravel scheduler requires `php artisan schedule:run` to be executed every minute via cron/task scheduler

### 2. **No Metrics in Database**
- `performance_metrics` table is empty
- `queue_metrics` table is empty
- `system_health_metrics` table is empty
- **Problem**: Without scheduler running, no data is being collected

### 3. **Cache Not Populated**
- Metrics cache keys (`metrics:queue:latest`, `metrics:health:latest`, `metrics:performance:latest`) are empty
- **Problem**: `CollectSystemMetricsJob` is responsible for populating these caches

### 4. **Frontend Widgets Failing**
- `PerformanceMetricsWidget.vue` calls `/system/metrics` - returns default/empty data
- `QueueStatsWidget.vue` calls `/system/queue/stats` - returns zeros
- `SystemHealthWidget.vue` calls `/system/health` - returns loading state
- **Problem**: API endpoints return empty data because cache and database are empty

## Solution Steps

### Step 1: Verify Scheduler Setup
Check if Laravel scheduler is configured in Windows Task Scheduler or as a service

### Step 2: Manual Metrics Collection
Run the metrics collection job manually to populate initial data:
```bash
php artisan queue:work --queue=monitoring --once
```

### Step 3: Dispatch Metrics Collection Job
Manually dispatch the job to test:
```bash
php artisan tinker
>>> dispatch(new \App\Jobs\CollectSystemMetricsJob());
```

### Step 4: Setup Windows Task Scheduler
Create a scheduled task to run every minute:
```powershell
schtasks /create /tn "Laravel Scheduler" /tr "php d:\traidnet\wifi-hotspot\backend\artisan schedule:run" /sc minute /mo 1
```

### Step 5: Verify API Endpoints
Test each endpoint after metrics collection:
- GET /api/system/metrics
- GET /api/system/queue/stats
- GET /api/system/health

### Step 6: Check Database Tables
Verify data is being inserted:
```sql
SELECT COUNT(*) FROM performance_metrics;
SELECT COUNT(*) FROM queue_metrics;
SELECT COUNT(*) FROM system_health_metrics;
```

## Quick Fix Commands

### 1. Dispatch Metrics Collection Immediately
```bash
cd d:\traidnet\wifi-hotspot\backend
php artisan queue:work --queue=monitoring --stop-when-empty
```

### 2. Manually Store Performance Metrics
```bash
php artisan tinker
>>> \App\Services\MetricsService::storeMetrics();
```

### 3. Check Cache Keys
```bash
php artisan tinker
>>> Cache::get('metrics:queue:latest');
>>> Cache::get('metrics:health:latest');
>>> Cache::get('metrics:performance:latest');
```

## Files Involved

### Backend
- `backend/routes/console.php` - Scheduler definitions
- `backend/app/Jobs/CollectSystemMetricsJob.php` - Metrics collection job
- `backend/app/Services/MetricsService.php` - Performance metrics service
- `backend/app/Services/SystemMetricsService.php` - System health metrics
- `backend/app/Http/Controllers/Api/SystemMetricsController.php` - API endpoints
- `backend/app/Http/Controllers/Api/MetricsController.php` - Performance API

### Frontend
- `frontend/src/modules/system-admin/components/dashboard/PerformanceMetricsWidget.vue`
- `frontend/src/modules/system-admin/components/dashboard/QueueStatsWidget.vue`
- `frontend/src/modules/system-admin/components/dashboard/SystemHealthWidget.vue`

### Database
- `backend/database/migrations/2025_10_17_000001_create_performance_metrics_table.php`
- `backend/database/migrations/2025_11_01_035000_create_system_metrics_tables.php`

## Expected Behavior After Fix

1. Metrics collected every minute and stored in cache
2. Metrics persisted to database every 5 minutes
3. Frontend widgets display real-time data
4. Dashboard shows:
   - TPS (Transactions Per Second)
   - OPS (Operations Per Second)
   - Database connections and queries
   - Queue statistics (pending, processing, failed jobs)
   - System health (CPU, memory, disk, uptime)
   - Active workers by queue
