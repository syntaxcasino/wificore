# Queue Troubleshooting Guide

## Problem: Dashboard and Default Queues Failing

### Quick Diagnosis

Run the diagnostic command to see what's failing:
```bash
php artisan queue:diagnose-failed
```

This will show:
- Failed jobs count by queue
- Recent failed jobs with error messages
- Recommendations for fixing

### Quick Fix

**Option 1: Retry Failed Jobs**
```bash
# Retry all failed jobs
php artisan queue:fix

# Retry only dashboard queue
php artisan queue:fix --queue=dashboard

# Retry only default queue
php artisan queue:fix --queue=default
```

**Option 2: Clear Failed Jobs (if they're old/irrelevant)**
```bash
# Clear all failed jobs
php artisan queue:fix --clear

# Clear specific queue
php artisan queue:fix --clear --queue=dashboard
```

### Common Causes & Solutions

#### 1. **Queue Worker Not Running**

**Symptom:** Jobs pile up in the `jobs` table but never process

**Solution:**
```bash
# Start queue worker
php artisan queue:work --tries=3 --timeout=90

# Or use supervisor for production (recommended)
# See supervisor configuration below
```

#### 2. **Database Connection Issues**

**Symptom:** Errors like "SQLSTATE" or "Connection refused"

**Solution:**
- Check `.env` database credentials
- Ensure database server is running
- Test connection: `php artisan db:show`

#### 3. **Missing Tables**

**Symptom:** "Table 'jobs' doesn't exist" or "Table 'failed_jobs' doesn't exist"

**Solution:**
```bash
# Run migrations
php artisan migrate

# If queue tables are missing, create them
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

#### 4. **Memory Limit Exceeded**

**Symptom:** "Allowed memory size exhausted"

**Solution:**
- Increase PHP memory limit in `php.ini`: `memory_limit = 512M`
- Or run worker with memory limit: `php artisan queue:work --memory=512`

#### 5. **Timeout Issues**

**Symptom:** "Maximum execution time exceeded"

**Solution:**
- Increase timeout in job: `public $timeout = 120;`
- Increase PHP max_execution_time in `php.ini`
- Run worker with timeout: `php artisan queue:work --timeout=120`

#### 6. **UpdateDashboardStatsJob Specific Issues**

**Common errors:**
- Missing relationships (e.g., `router_id` in sessions table)
- Database queries timing out
- Broadcasting issues

**Solution:**
```bash
# Check logs for specific error
tail -f storage/logs/laravel.log

# Test the job manually
php artisan tinker
>>> App\Jobs\UpdateDashboardStatsJob::dispatch();
```

### Production Setup with Supervisor

Create supervisor configuration file: `/etc/supervisor/conf.d/laravel-worker.conf`

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/worker.log
stopwaitsecs=3600
```

Then:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

### Monitoring Queue Health

**Check queue status:**
```bash
# View failed jobs
php artisan queue:failed

# Monitor queue in real-time
php artisan queue:monitor redis:default,redis:dashboard --max=100

# Check queue size
php artisan queue:work --once --queue=dashboard
```

**Check logs:**
```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Worker logs (if using supervisor)
tail -f storage/logs/worker.log
```

### Preventive Measures

1. **Enable Job Logging**
   - Already added to `UpdateDashboardStatsJob`
   - Check logs regularly

2. **Set Proper Retry Logic**
   - Jobs retry 3 times by default
   - Failed jobs go to `failed_jobs` table

3. **Monitor Failed Jobs**
   - Set up alerts when failed jobs exceed threshold
   - Use the System Health widget to monitor

4. **Use Queue Priorities**
   ```bash
   # Process high-priority queues first
   php artisan queue:work --queue=high,default,low
   ```

5. **Regular Maintenance**
   ```bash
   # Weekly: Clear old failed jobs (older than 7 days)
   php artisan queue:prune-failed --hours=168
   
   # Monthly: Clear all processed jobs
   php artisan queue:prune-batches
   ```

### Testing Queue Jobs

```bash
# Test in tinker
php artisan tinker
>>> App\Jobs\UpdateDashboardStatsJob::dispatch();

# Test with queue worker
php artisan queue:work --once

# Test specific queue
php artisan queue:work --queue=dashboard --once
```

### Environment Variables

Ensure these are set in `.env`:
```env
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=pgsql
DB_QUEUE_TABLE=jobs
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=90

# For Redis queue (alternative)
# QUEUE_CONNECTION=redis
# REDIS_QUEUE_CONNECTION=default
# REDIS_QUEUE=default
```

### Useful Commands Reference

```bash
# Diagnostics
php artisan queue:diagnose-failed          # Custom command to diagnose issues
php artisan queue:failed                   # List all failed jobs
php artisan queue:monitor                  # Monitor queue sizes

# Fixing
php artisan queue:fix                      # Custom command to retry/clear
php artisan queue:retry all                # Retry all failed jobs
php artisan queue:retry {id}               # Retry specific job
php artisan queue:flush                    # Delete all failed jobs
php artisan queue:forget {id}              # Delete specific failed job

# Maintenance
php artisan queue:prune-failed             # Remove old failed jobs
php artisan queue:prune-batches            # Remove old batch records
php artisan queue:clear                    # Clear all jobs from queue

# Worker Management
php artisan queue:work                     # Start queue worker
php artisan queue:work --once              # Process single job
php artisan queue:work --stop-when-empty   # Stop when queue is empty
php artisan queue:restart                  # Restart all workers
```

### Getting Help

If issues persist:
1. Check `storage/logs/laravel.log` for detailed errors
2. Run `php artisan queue:diagnose-failed` for analysis
3. Check database connectivity
4. Ensure queue workers are running
5. Verify all migrations are up to date

### Health Monitoring

The System Health widget now shows:
- **Total failed jobs count**
- **Failed jobs by queue** (dashboard, default, etc.)
- **Recent failure information**

Monitor this regularly to catch issues early!
