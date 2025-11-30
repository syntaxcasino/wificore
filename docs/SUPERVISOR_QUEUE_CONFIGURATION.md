# Supervisor Queue Configuration - Package Management

## 📋 Overview

Configured a dedicated supervisor queue worker for processing scheduled package activations/deactivations with broadcasting support.

---

## ✅ Configuration Details

### Queue Worker: `laravel-queue-packages`

**File:** `backend/supervisor/laravel-queue.conf`

**Configuration:**
```ini
[program:laravel-queue-packages]
command=/usr/local/bin/php artisan queue:work database --queue=packages,broadcasts --sleep=2 --tries=3 --timeout=90 --max-time=3600 --memory=128 --backoff=3,10,30
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
numprocs=2
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/packages-queue.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=7
stderr_logfile=/var/www/html/storage/logs/packages-queue-error.log
stderr_logfile_maxbytes=10MB
stderr_logfile_backups=7
stopasgroup=true
killasgroup=true
```

---

## 🎯 Configuration Parameters Explained

### Queue Configuration
| Parameter | Value | Description |
|-----------|-------|-------------|
| `--queue` | `packages,broadcasts` | Processes packages queue first, then broadcasts |
| `--sleep` | `2` | Sleep 2 seconds when no jobs available |
| `--tries` | `3` | Retry failed jobs up to 3 times |
| `--timeout` | `90` | Kill job after 90 seconds |
| `--max-time` | `3600` | Restart worker after 1 hour |
| `--memory` | `128` | Restart worker if memory exceeds 128MB |
| `--backoff` | `3,10,30` | Retry delays: 3s, 10s, 30s |

### Process Configuration
| Parameter | Value | Description |
|-----------|-------|-------------|
| `autostart` | `true` | Start automatically with supervisor |
| `autorestart` | `true` | Restart if process crashes |
| `startretries` | `3` | Retry starting 3 times if fails |
| `startsecs` | `5` | Process must run 5s to be considered started |
| `stopwaitsecs` | `60` | Wait 60s for graceful shutdown |
| `stopsignal` | `TERM` | Use SIGTERM for graceful shutdown |
| `priority` | `5` | High priority (lower number = higher priority) |
| `numprocs` | `2` | Run 2 worker processes |

### Logging Configuration
| Parameter | Value | Description |
|-----------|-------|-------------|
| `stdout_logfile` | `/var/www/html/storage/logs/packages-queue.log` | Standard output log |
| `stderr_logfile` | `/var/www/html/storage/logs/packages-queue-error.log` | Error log |
| `logfile_maxbytes` | `10MB` | Max log file size before rotation |
| `logfile_backups` | `7` | Keep 7 rotated log files |

---

## 🔄 Queue Priority

The packages queue has **priority 5** (high priority) because:
- ✅ Time-sensitive operations (scheduled activations)
- ✅ Real-time broadcasting required
- ✅ Customer-facing functionality
- ✅ Business-critical operations

**Priority Comparison:**
```
Priority 5:  packages, payments, payment-checks, service-control, hotspot-sms
Priority 10: router-checks, router-provisioning, provisioning, hotspot-sessions
Priority 15: dashboard, router-monitoring, hotspot-accounting
Priority 20: router-data, notifications
Priority 30: log-rotation
```

---

## 📊 Process Management

### Number of Processes: 2

**Why 2 processes?**
1. **Redundancy** - If one process fails, the other continues
2. **Load Distribution** - Handles multiple jobs simultaneously
3. **Broadcasting** - One can handle package jobs, other handles broadcasts
4. **Optimal Balance** - Not too many (resource waste), not too few (bottleneck)

**Process Naming:**
```
laravel-queue-packages_00
laravel-queue-packages_01
```

---

## 🚀 Deployment Commands

### 1. Update Supervisor Configuration
```bash
# Copy new configuration
sudo cp backend/supervisor/laravel-queue.conf /etc/supervisor/conf.d/

# Reload supervisor configuration
sudo supervisorctl reread
sudo supervisorctl update
```

### 2. Start the Queue Worker
```bash
# Start the packages queue worker
sudo supervisorctl start laravel-queue-packages:*

# Verify it's running
sudo supervisorctl status laravel-queue-packages:*
```

### 3. Monitor the Queue
```bash
# View real-time logs
tail -f /var/www/html/storage/logs/packages-queue.log

# View error logs
tail -f /var/www/html/storage/logs/packages-queue-error.log

# Check supervisor status
sudo supervisorctl status
```

---

## 🔍 Monitoring & Management

### Check Queue Status
```bash
# View all queue workers
sudo supervisorctl status

# View packages queue specifically
sudo supervisorctl status laravel-queue-packages:*

# Expected output:
# laravel-queue-packages:laravel-queue-packages_00   RUNNING   pid 1234, uptime 0:05:23
# laravel-queue-packages:laravel-queue-packages_01   RUNNING   pid 1235, uptime 0:05:23
```

### Control Queue Workers
```bash
# Start
sudo supervisorctl start laravel-queue-packages:*

# Stop
sudo supervisorctl stop laravel-queue-packages:*

# Restart
sudo supervisorctl restart laravel-queue-packages:*

# Restart all queues
sudo supervisorctl restart laravel-queues:*
```

### View Logs
```bash
# Live tail
tail -f /var/www/html/storage/logs/packages-queue.log

# Last 100 lines
tail -n 100 /var/www/html/storage/logs/packages-queue.log

# Search for errors
grep -i error /var/www/html/storage/logs/packages-queue-error.log

# Search for specific package
grep "Package activated" /var/www/html/storage/logs/packages-queue.log
```

---

## 🧪 Testing

### 1. Verify Queue Worker is Running
```bash
sudo supervisorctl status laravel-queue-packages:*

# Should show RUNNING status
```

### 2. Test Job Dispatch
```bash
# From Laravel tinker
php artisan tinker

# Dispatch test job
\App\Jobs\ProcessScheduledPackages::dispatch();

# Check logs
tail -f storage/logs/packages-queue.log
```

### 3. Test Scheduled Activation
```bash
# Create package with schedule 2 minutes in future
# Wait 2-3 minutes
# Check logs for activation

grep "Package activated" storage/logs/packages-queue.log
```

### 4. Monitor Queue Performance
```bash
# Check queue size
php artisan queue:monitor packages

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

---

## 🛠️ Troubleshooting

### Issue 1: Queue Worker Not Starting

**Symptoms:**
```
laravel-queue-packages:laravel-queue-packages_00   FATAL
```

**Solutions:**
```bash
# Check supervisor error log
sudo tail /var/log/supervisor/supervisord.log

# Check PHP-FPM is running
sudo supervisorctl status php-fpm

# Verify file permissions
ls -la /var/www/html/storage/logs/

# Fix permissions if needed
sudo chown -R www-data:www-data /var/www/html/storage
sudo chmod -R 775 /var/www/html/storage
```

### Issue 2: Jobs Not Processing

**Symptoms:**
- Jobs queued but not executing
- No log entries

**Solutions:**
```bash
# Check database queue table
php artisan tinker
DB::table('jobs')->where('queue', 'packages')->count();

# Check worker is listening to correct queue
ps aux | grep "queue:work"

# Restart queue worker
sudo supervisorctl restart laravel-queue-packages:*

# Clear failed jobs
php artisan queue:flush
```

### Issue 3: Memory Leaks

**Symptoms:**
- Worker keeps restarting
- High memory usage

**Solutions:**
```bash
# Check current memory usage
ps aux | grep "queue:work" | grep packages

# Reduce max-time (restart more frequently)
# Edit supervisor config: --max-time=1800

# Reduce memory limit if needed
# Edit supervisor config: --memory=64

# Reload configuration
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-queue-packages:*
```

### Issue 4: Broadcasting Not Working

**Symptoms:**
- Package activates but no broadcast event
- Frontend doesn't receive updates

**Solutions:**
```bash
# Check broadcasts queue is being processed
grep "broadcasts" /var/www/html/storage/logs/packages-queue.log

# Verify WebSocket server is running
ps aux | grep soketi

# Check Laravel broadcasting configuration
php artisan tinker
config('broadcasting.default');

# Test broadcasting manually
broadcast(new \App\Events\PackageStatusChanged($package, 'inactive', 'active'));
```

---

## 📈 Performance Optimization

### Tuning Parameters

**For High Load:**
```ini
numprocs=4              # Increase workers
--sleep=1               # Reduce sleep time
--max-time=1800         # Restart more frequently
--memory=256            # Increase memory limit
```

**For Low Load:**
```ini
numprocs=1              # Reduce workers
--sleep=5               # Increase sleep time
--max-time=7200         # Restart less frequently
--memory=64             # Reduce memory limit
```

**For Critical Operations:**
```ini
priority=1              # Highest priority
--tries=5               # More retries
--timeout=180           # Longer timeout
--backoff=5,15,30,60,120 # Progressive backoff
```

---

## 🔒 Security Considerations

### User Permissions
```bash
# Queue worker runs as www-data
user=www-data

# Ensure proper permissions
sudo chown -R www-data:www-data /var/www/html/storage
sudo chmod -R 775 /var/www/html/storage
```

### Log Security
```bash
# Restrict log access
sudo chmod 640 /var/www/html/storage/logs/packages-queue.log
sudo chmod 640 /var/www/html/storage/logs/packages-queue-error.log

# Only www-data and root can read logs
```

### Environment Variables
```ini
# Production environment
environment=LARAVEL_ENV="production"

# Sensitive data should be in .env file, not supervisor config
```

---

## 📊 Monitoring Metrics

### Key Metrics to Track

1. **Queue Size**
   ```bash
   php artisan queue:monitor packages --max=100
   ```

2. **Processing Time**
   ```bash
   grep "ProcessScheduledPackages job completed" storage/logs/packages-queue.log | tail -20
   ```

3. **Failure Rate**
   ```bash
   php artisan queue:failed | grep ProcessScheduledPackages | wc -l
   ```

4. **Memory Usage**
   ```bash
   ps aux | grep "queue:work.*packages" | awk '{print $6}'
   ```

5. **Worker Uptime**
   ```bash
   sudo supervisorctl status laravel-queue-packages:*
   ```

---

## 📝 Log Analysis

### Useful Log Queries

**Find all activations:**
```bash
grep "Package activated" storage/logs/packages-queue.log
```

**Find all deactivations:**
```bash
grep "Package deactivated" storage/logs/packages-queue.log
```

**Find errors:**
```bash
grep -i "error\|failed" storage/logs/packages-queue-error.log
```

**Count jobs processed today:**
```bash
grep "$(date +%Y-%m-%d)" storage/logs/packages-queue.log | grep "job completed" | wc -l
```

**Find slow jobs (>30s):**
```bash
# Add timing logs to job, then:
grep "ProcessScheduledPackages" storage/logs/packages-queue.log | grep -E "[3-9][0-9]s|[0-9]{3}s"
```

---

## 🎯 Best Practices

### 1. Regular Monitoring
```bash
# Set up cron job to check queue health
*/5 * * * * /usr/local/bin/php /var/www/html/artisan queue:monitor packages --max=100
```

### 2. Log Rotation
```bash
# Supervisor handles log rotation automatically
# But you can also use logrotate for additional control

# /etc/logrotate.d/laravel-packages-queue
/var/www/html/storage/logs/packages-queue*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    missingok
    create 0640 www-data www-data
}
```

### 3. Alerting
```bash
# Monitor failed jobs and alert
*/10 * * * * [ $(php /var/www/html/artisan queue:failed | wc -l) -gt 10 ] && echo "High failed job count" | mail -s "Queue Alert" admin@example.com
```

### 4. Graceful Restarts
```bash
# Use supervisorctl for graceful restarts
sudo supervisorctl restart laravel-queue-packages:*

# Not kill -9 (hard kill)
```

---

## 🎉 Summary

### ✅ What Was Configured

1. **Dedicated Queue Worker** - `laravel-queue-packages`
2. **Dual Processing** - Handles both `packages` and `broadcasts` queues
3. **High Priority** - Priority 5 for time-sensitive operations
4. **Redundancy** - 2 worker processes for reliability
5. **Auto-Recovery** - Automatic restart on failure
6. **Comprehensive Logging** - Separate logs for stdout and stderr
7. **Resource Management** - Memory limits and timeouts
8. **Graceful Shutdown** - SIGTERM for clean shutdowns

### ✅ Benefits

- ⚡ **Fast Processing** - 2-second sleep time
- 🔄 **Reliable** - Auto-restart and retry logic
- 📊 **Monitored** - Comprehensive logging
- 🛡️ **Resilient** - Multiple workers and retries
- 🎯 **Prioritized** - High priority for critical operations
- 📈 **Scalable** - Easy to add more workers

---

**Configuration Date:** October 23, 2025  
**Status:** ✅ **CONFIGURED AND PRODUCTION READY**  
**Version:** 2.2.0
