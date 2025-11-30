# Queue Fix - End-to-End Steps

## Step 1: Check Current Status

```bash
# Check failed jobs
php artisan queue:diagnose-failed

# Check queue tables exist
php artisan db:show
```

## Step 2: Ensure Migrations Are Up to Date

```bash
# Run all migrations
php artisan migrate

# If jobs table is missing, create it
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

## Step 3: Test the Dashboard Job

```bash
# Test synchronously (immediate execution)
php artisan test:dashboard-job --sync

# If successful, test queued version
php artisan test:dashboard-job
```

## Step 4: Fix Any Failed Jobs

```bash
# Option A: Retry all failed jobs
php artisan queue:fix

# Option B: Clear failed jobs (if they're old/irrelevant)
php artisan queue:fix --clear

# Option C: Retry specific queue
php artisan queue:fix --queue=dashboard
php artisan queue:fix --queue=default
```

## Step 5: Start Queue Worker

```bash
# Start worker (development)
php artisan queue:work --tries=3 --timeout=120

# Or start specific queues
php artisan queue:work --queue=dashboard,default --tries=3 --timeout=120

# For production, use supervisor (see QUEUE_TROUBLESHOOTING.md)
```

## Step 6: Monitor

```bash
# Watch logs in real-time
tail -f storage/logs/laravel.log

# Check queue status
php artisan queue:diagnose-failed

# Monitor queue sizes
watch -n 5 'php artisan queue:monitor database:dashboard,database:default'
```

## Step 7: Verify Dashboard

1. Open your browser and navigate to the dashboard
2. Check System Health widget for:
   - No failed jobs
   - All metrics loading correctly
   - Redis stats showing
   - Log stats showing

## Common Issues and Solutions

### Issue: "Table 'jobs' doesn't exist"
```bash
php artisan queue:table
php artisan migrate
```

### Issue: "Class 'Redis' not found"
```bash
# Install Redis PHP extension
# Or change QUEUE_CONNECTION in .env to 'database'
```

### Issue: Jobs keep failing with same error
```bash
# Check the specific error
php artisan queue:diagnose-failed --limit=1

# Clear and retry
php artisan queue:fix --clear
php artisan test:dashboard-job --sync
```

### Issue: "Maximum execution time exceeded"
```bash
# Increase timeout in .env or php.ini
# Or run worker with higher timeout
php artisan queue:work --timeout=300
```

### Issue: "Allowed memory size exhausted"
```bash
# Run worker with more memory
php artisan queue:work --memory=512
```

## Automated Fix Script

Run this complete sequence:

```bash
# 1. Check status
echo "=== Checking Current Status ==="
php artisan queue:diagnose-failed

# 2. Ensure migrations
echo "=== Running Migrations ==="
php artisan migrate --force

# 3. Test the job
echo "=== Testing Dashboard Job ==="
php artisan test:dashboard-job --sync --clear-cache

# 4. Fix failed jobs
echo "=== Fixing Failed Jobs ==="
php artisan queue:fix

# 5. Test queued version
echo "=== Testing Queued Job ==="
php artisan test:dashboard-job

# 6. Start worker (in separate terminal)
echo "=== Start Queue Worker in Separate Terminal ==="
echo "Run: php artisan queue:work --tries=3 --timeout=120"
```

## Production Deployment Checklist

- [ ] All migrations run successfully
- [ ] Queue tables exist (jobs, failed_jobs, job_batches)
- [ ] Supervisor configured for queue workers
- [ ] Queue workers are running
- [ ] No failed jobs in queue
- [ ] Dashboard loads without errors
- [ ] System Health widget shows all green
- [ ] Logs are being written correctly
- [ ] Redis is connected (if using)
- [ ] Broadcasting is configured (if using WebSockets)

## Monitoring Setup

Add to your monitoring system:

1. **Alert on failed jobs > 10**
   ```bash
   php artisan queue:diagnose-failed | grep "Failed Count"
   ```

2. **Alert on queue worker down**
   ```bash
   ps aux | grep "queue:work" | grep -v grep
   ```

3. **Alert on large log files**
   ```bash
   du -h storage/logs/laravel.log
   ```

4. **Dashboard stats not updating**
   ```bash
   # Check cache age
   php artisan tinker
   >>> Cache::get('dashboard_stats')['last_updated']
   ```

## Success Criteria

✅ All tests pass
✅ No failed jobs
✅ Queue worker running
✅ Dashboard loads successfully
✅ System Health widget shows all metrics
✅ No errors in logs
✅ Stats update every 30 seconds
