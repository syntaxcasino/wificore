# Queue Management - Quick Reference Card

## 🚀 Quick Fix

```bash
# Linux/Mac
chmod +x fix-queues.sh && ./fix-queues.sh

# Windows
.\fix-queues.ps1
```

## 🔍 Diagnostic Commands

| Command | Description |
|---------|-------------|
| `php artisan queue:diagnose-failed` | Show failed jobs with details |
| `php artisan queue:failed` | List all failed jobs |
| `php artisan test:dashboard-job --sync` | Test dashboard job |
| `php artisan queue:monitor` | Monitor queue sizes |
| `tail -f storage/logs/laravel.log` | Watch logs in real-time |

## 🔧 Fix Commands

| Command | Description |
|---------|-------------|
| `php artisan queue:fix` | Retry all failed jobs |
| `php artisan queue:fix --clear` | Clear all failed jobs |
| `php artisan queue:fix --queue=dashboard` | Fix specific queue |
| `php artisan queue:retry all` | Retry all failed jobs |
| `php artisan queue:flush` | Delete all failed jobs |

## 👷 Worker Management

### Start Worker
```bash
# Foreground (development)
php artisan queue:work --tries=3 --timeout=120

# Background (Linux)
nohup php artisan queue:work --tries=3 --timeout=120 > storage/logs/worker.log 2>&1 &

# Specific queues
php artisan queue:work --queue=dashboard,default --tries=3
```

### Check Worker
```bash
# Linux/Mac
ps aux | grep "queue:work"

# Windows
Get-Process | Where-Object {$_.ProcessName -like "*php*"}
```

### Stop Worker
```bash
# Graceful restart (waits for current job)
php artisan queue:restart

# Force kill (Linux)
kill <PID>

# Force kill (Windows)
Stop-Process -Id <PID>
```

## 📊 Monitoring

### Real-time Monitoring
```bash
# Watch logs
tail -f storage/logs/laravel.log

# Watch worker logs
tail -f storage/logs/worker.log

# Both
tail -f storage/logs/*.log

# Monitor queue sizes
watch -n 5 'php artisan queue:monitor database:dashboard,database:default'
```

### Check Status
```bash
# Failed jobs count
php artisan queue:failed | wc -l

# Queue health
php artisan queue:diagnose-failed

# System health
curl http://localhost/api/health
```

## 🗄️ Database Operations

### Queue Tables
```bash
# Create queue tables
php artisan queue:table
php artisan queue:failed-table
php artisan migrate

# Check tables exist
php artisan db:show
```

### Clear Queues
```bash
# Clear all jobs from queue
php artisan queue:clear

# Clear specific queue
php artisan queue:clear --queue=dashboard

# Prune old failed jobs (7 days)
php artisan queue:prune-failed --hours=168
```

## 🧪 Testing

### Test Dashboard Job
```bash
# Synchronous (immediate)
php artisan test:dashboard-job --sync

# With cache clear
php artisan test:dashboard-job --sync --clear-cache

# Queued (requires worker)
php artisan test:dashboard-job
```

### Manual Testing
```bash
# Test in tinker
php artisan tinker
>>> App\Jobs\UpdateDashboardStatsJob::dispatch();
>>> exit

# Process one job
php artisan queue:work --once
```

## 🐛 Debugging

### View Errors
```bash
# Last 50 lines of log
tail -50 storage/logs/laravel.log

# Search for errors
grep -i "error" storage/logs/laravel.log | tail -20

# View specific failed job
php artisan queue:failed
# Note the ID, then:
php artisan tinker
>>> DB::table('failed_jobs')->where('id', 1)->first()->exception
```

### Common Issues

| Issue | Solution |
|-------|----------|
| Jobs not processing | Start worker: `php artisan queue:work` |
| Jobs keep failing | Check logs: `tail -f storage/logs/laravel.log` |
| Table doesn't exist | Run migrations: `php artisan migrate` |
| Memory exhausted | Increase: `php artisan queue:work --memory=512` |
| Timeout | Increase: `php artisan queue:work --timeout=300` |

## 📝 Configuration

### Environment Variables (.env)
```env
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=pgsql
DB_QUEUE_TABLE=jobs
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=90
```

### Queue Config (config/queue.php)
```php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ],
],
```

## 🔄 Supervisor (Production)

### Configuration
```ini
[program:laravel-worker]
command=php /path/to/artisan queue:work --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker.log
```

### Commands
```bash
# Reload config
sudo supervisorctl reread
sudo supervisorctl update

# Control workers
sudo supervisorctl start laravel-worker:*
sudo supervisorctl stop laravel-worker:*
sudo supervisorctl restart laravel-worker:*
sudo supervisorctl status
```

## 📈 Performance

### Optimize Worker
```bash
# Multiple workers
php artisan queue:work --queue=high,default,low

# Limit jobs per worker
php artisan queue:work --max-jobs=1000

# Limit time per worker
php artisan queue:work --max-time=3600

# Memory limit
php artisan queue:work --memory=512

# Sleep between jobs
php artisan queue:work --sleep=3
```

### Monitor Performance
```bash
# Jobs processed per minute
watch -n 60 'php artisan queue:monitor'

# Worker memory usage
ps aux | grep "queue:work" | awk '{print $6}'

# Queue size
php artisan tinker
>>> DB::table('jobs')->count()
```

## 🎯 Best Practices

1. **Always run workers in production**
   - Use Supervisor or systemd
   - Monitor with alerts

2. **Handle failures gracefully**
   - Set appropriate retry attempts
   - Log errors properly
   - Alert on critical failures

3. **Monitor queue health**
   - Check failed jobs daily
   - Monitor queue sizes
   - Watch for memory leaks

4. **Regular maintenance**
   - Prune old failed jobs weekly
   - Restart workers daily
   - Clear processed jobs monthly

5. **Test before deploying**
   - Run `test:dashboard-job --sync`
   - Check for errors in logs
   - Verify queue configuration

## 🆘 Emergency Procedures

### Queue Completely Stuck
```bash
# 1. Stop all workers
php artisan queue:restart

# 2. Clear all queues
php artisan queue:clear

# 3. Flush failed jobs
php artisan queue:flush

# 4. Restart workers
php artisan queue:work --tries=3 --timeout=120
```

### Too Many Failed Jobs
```bash
# 1. Diagnose
php artisan queue:diagnose-failed

# 2. Fix underlying issue

# 3. Clear old failures
php artisan queue:prune-failed --hours=24

# 4. Retry recent failures
php artisan queue:fix
```

### Worker Keeps Dying
```bash
# 1. Check logs
tail -100 storage/logs/laravel.log

# 2. Increase resources
php artisan queue:work --memory=512 --timeout=300

# 3. Use Supervisor for auto-restart
```

## 📚 Additional Resources

- **Full Guide:** `QUEUE_TROUBLESHOOTING.md`
- **Fix Steps:** `QUEUE_FIX_STEPS.md`
- **README:** `QUEUE_FIX_README.md`
- **Laravel Docs:** https://laravel.com/docs/queues

---

**Print this card and keep it handy!** 📋
