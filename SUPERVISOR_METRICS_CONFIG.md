# Supervisor Configuration for Dashboard Metrics

## Overview

This document describes the Supervisor configuration for ensuring dashboard metrics are continuously collected and processed.

## Supervisor Programs

### 1. Laravel Scheduler (`laravel-scheduler`)

**File**: `backend/supervisor/laravel-scheduler.conf`

**Purpose**: Runs Laravel's scheduler which executes scheduled tasks every minute.

**Configuration**:
```ini
[program:laravel-scheduler]
command=php /var/www/html/artisan schedule:work
directory=/var/www/html
autostart=true
autorestart=true
numprocs=1
startsecs=0
redirect_stderr=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
```

**What it does**:
- Executes `php artisan schedule:work` which runs continuously
- Checks for scheduled tasks every minute
- Automatically dispatches jobs defined in `routes/console.php`

**Scheduled Tasks**:
1. `CollectSystemMetricsJob` - Every minute
2. `MetricsService::resetTPSCounter()` - Every minute
3. `MetricsService::storeMetrics()` - Every 5 minutes
4. `MetricsService::cleanupOldMetrics()` - Daily at 2 AM

### 2. Monitoring Queue Worker (`laravel-queue-monitoring`)

**File**: `backend/supervisor/laravel-queue.conf` (included with all other queue workers)

**Purpose**: Processes jobs from the `monitoring` queue, specifically the `CollectSystemMetricsJob`.

**Configuration**:
```ini
[program:laravel-queue-monitoring]
command=/usr/local/bin/php artisan queue:work database --queue=monitoring --sleep=5 --tries=3 --timeout=300 --max-time=3600 --memory=128 --backoff=10,30,60
directory=/var/www/html
environment=LARAVEL_ENV="production"
autostart=true
autorestart=true
startretries=3
startsecs=5
stopwaitsecs=60
stopsignal=TERM
priority=5
user=www-data
numprocs=1
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/monitoring-queue.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=7
stderr_logfile=/var/www/html/storage/logs/monitoring-queue-error.log
stderr_logfile_maxbytes=10MB
stderr_logfile_backups=7
stopasgroup=true
killasgroup=true
```

**Parameters Explained**:
- `--queue=monitoring` - Only processes jobs from the monitoring queue
- `--sleep=5` - Sleep 5 seconds when no jobs are available
- `--tries=3` - Retry failed jobs up to 3 times
- `--timeout=300` - Job timeout of 5 minutes
- `--max-time=3600` - Worker restarts after 1 hour
- `--memory=128` - Worker restarts if memory exceeds 128MB
- `--backoff=10,30,60` - Exponential backoff delays (10s, 30s, 60s)

**What it does**:
- Continuously monitors the `monitoring` queue
- Processes `CollectSystemMetricsJob` when dispatched by the scheduler
- Collects and stores metrics data to database and cache
- Automatically restarts on failure

## How It Works Together

```
┌─────────────────────────────────────────────────────────────┐
│                    Supervisor Process                        │
└─────────────────────────────────────────────────────────────┘
                              │
                ┌─────────────┴─────────────┐
                │                           │
                ▼                           ▼
┌───────────────────────────┐   ┌──────────────────────────┐
│   laravel-scheduler       │   │ laravel-queue-monitoring │
│   (schedule:work)         │   │ (queue:work monitoring)  │
└───────────────────────────┘   └──────────────────────────┘
                │                           │
                │ Every minute              │
                ▼                           │
┌───────────────────────────┐              │
│ Dispatch                  │              │
│ CollectSystemMetricsJob   │──────────────┘
│ to 'monitoring' queue     │
└───────────────────────────┘
                │
                ▼
┌───────────────────────────────────────────────────────────┐
│              CollectSystemMetricsJob                       │
│  - Collects queue metrics                                 │
│  - Collects system health metrics                         │
│  - Collects performance metrics                           │
│  - Stores to database                                     │
│  - Caches latest values                                   │
└───────────────────────────────────────────────────────────┘
                │
                ▼
┌───────────────────────────────────────────────────────────┐
│              Database & Cache                              │
│  - performance_metrics table                              │
│  - queue_metrics table                                    │
│  - system_health_metrics table                            │
│  - metrics:queue:latest cache                             │
│  - metrics:health:latest cache                            │
│  - metrics:performance:latest cache                       │
└───────────────────────────────────────────────────────────┘
                │
                ▼
┌───────────────────────────────────────────────────────────┐
│              Dashboard Widgets                             │
│  - PerformanceMetricsWidget                               │
│  - QueueStatsWidget                                       │
│  - SystemHealthWidget                                     │
└───────────────────────────────────────────────────────────┘
```

## Verification Commands

### Check Supervisor Status
```bash
docker exec traidnet-backend supervisorctl status
```

Expected output should show:
```
laravel-queue-monitoring:laravel-queue-monitoring_00   RUNNING   pid 5482, uptime 0:00:19
laravel-scheduler                                      RUNNING   pid 87, uptime 5:11:45
```

### View Monitoring Queue Logs
```bash
docker exec traidnet-backend tail -f /var/www/html/storage/logs/monitoring-queue.log
```

### View Scheduler Logs
```bash
docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log | grep -i metrics
```

### Check Database Metrics
```bash
docker exec traidnet-backend php artisan tinker --execute="
echo 'Performance Metrics: ' . DB::table('performance_metrics')->count();
echo 'Queue Metrics: ' . DB::table('queue_metrics')->count();
echo 'Health Metrics: ' . DB::table('system_health_metrics')->count();
"
```

### Check Cache Keys
```bash
docker exec traidnet-backend php artisan tinker --execute="
echo 'Queue Cache: ' . (Cache::has('metrics:queue:latest') ? 'EXISTS' : 'MISSING');
echo 'Health Cache: ' . (Cache::has('metrics:health:latest') ? 'EXISTS' : 'MISSING');
echo 'Performance Cache: ' . (Cache::has('metrics:performance:latest') ? 'EXISTS' : 'MISSING');
"
```

## Troubleshooting

### Worker Not Running

**Check status**:
```bash
docker exec traidnet-backend supervisorctl status laravel-queue-monitoring:*
```

**Start worker**:
```bash
docker exec traidnet-backend supervisorctl start laravel-queue-monitoring:*
```

**Restart worker**:
```bash
docker exec traidnet-backend supervisorctl restart laravel-queue-monitoring:*
```

### Scheduler Not Running

**Check status**:
```bash
docker exec traidnet-backend supervisorctl status laravel-scheduler
```

**Restart scheduler**:
```bash
docker exec traidnet-backend supervisorctl restart laravel-scheduler
```

### No Metrics Being Collected

1. **Check if scheduler is dispatching jobs**:
   ```bash
   docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log
   ```
   Look for: "System metrics collected and persisted"

2. **Check if monitoring queue has jobs**:
   ```bash
   docker exec traidnet-backend php artisan queue:work monitoring --once
   ```

3. **Manually dispatch job**:
   ```bash
   docker exec traidnet-backend php artisan tinker --execute="dispatch(new \App\Jobs\CollectSystemMetricsJob());"
   ```

### Worker Keeps Failing

**View error logs**:
```bash
docker exec traidnet-backend tail -f /var/www/html/storage/logs/monitoring-queue-error.log
```

**Common issues**:
- Database connection errors
- Redis connection errors
- Memory limit exceeded
- Timeout exceeded

## Maintenance

### Reload Supervisor Configuration

After modifying `laravel-queue.conf`:

```bash
# Copy updated config to container
docker cp backend/supervisor/laravel-queue.conf traidnet-backend:/etc/supervisor/conf.d/

# Reload Supervisor
docker exec traidnet-backend supervisorctl reread
docker exec traidnet-backend supervisorctl update

# Restart queue workers group
docker exec traidnet-backend supervisorctl restart laravel-queues:*
```

Or use the provided script:
```bash
powershell -ExecutionPolicy Bypass -File reload-supervisor.ps1
```

### View All Supervisor Programs
```bash
docker exec traidnet-backend supervisorctl status
```

### Stop All Queue Workers
```bash
docker exec traidnet-backend supervisorctl stop laravel-queues:*
```

### Start All Queue Workers
```bash
docker exec traidnet-backend supervisorctl start laravel-queues:*
```

### Restart All Programs
```bash
docker exec traidnet-backend supervisorctl restart all
```

## Log Files

| Program | Log Location |
|---------|-------------|
| Monitoring Queue | `/var/www/html/storage/logs/monitoring-queue.log` |
| Monitoring Queue Errors | `/var/www/html/storage/logs/monitoring-queue-error.log` |
| Laravel Application | `/var/www/html/storage/logs/laravel.log` |
| Supervisor | `/tmp/supervisord.log` (inside container) |

## Performance Tuning

### Adjust Worker Count

To increase the number of monitoring queue workers, edit the `[program:laravel-queue-monitoring]` section in `laravel-queue.conf`:

```ini
numprocs=2  # Change from 1 to 2 or more
```

Then reload Supervisor configuration.

### Adjust Sleep Time

To make the worker check for jobs more frequently:

```ini
command=/usr/local/bin/php artisan queue:work database --queue=monitoring --sleep=1  # Reduced from 5 to 1
```

### Adjust Memory Limit

For jobs that require more memory:

```ini
command=/usr/local/bin/php artisan queue:work database --queue=monitoring --memory=256  # Increased from 128 to 256
```

## Best Practices

1. **Monitor Logs Regularly**: Check logs for errors or warnings
2. **Set Up Alerts**: Configure monitoring for Supervisor process failures
3. **Test After Changes**: Always verify configuration changes work as expected
4. **Keep Backups**: Backup `.conf` files before making changes
5. **Document Changes**: Update this document when modifying configurations

## Related Files

- `backend/supervisor/supervisord.conf` - Main Supervisor configuration
- `backend/supervisor/laravel-scheduler.conf` - Scheduler configuration
- `backend/supervisor/laravel-queue.conf` - All queue workers (including monitoring)
- `backend/supervisor/php-fpm.conf` - PHP-FPM configuration
- `backend/routes/console.php` - Scheduled tasks definitions
- `backend/app/Jobs/CollectSystemMetricsJob.php` - Metrics collection job
- `backend/app/Services/MetricsService.php` - Performance metrics service
- `backend/app/Services/SystemMetricsService.php` - System health metrics service

## Status: ✅ CONFIGURED AND RUNNING

The Supervisor configuration is now complete with:
- ✅ Laravel Scheduler running continuously
- ✅ Monitoring queue worker processing metrics jobs
- ✅ Automatic restart on failure
- ✅ Proper logging configured
- ✅ All metrics being collected every minute
