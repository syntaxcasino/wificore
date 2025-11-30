# Queue Management & Monitoring Commands

Complete reference for managing and monitoring Laravel queue system with Supervisor.

---

## Table of Contents
1. [Queue Statistics & Monitoring](#queue-statistics--monitoring)
2. [Queue Worker Management](#queue-worker-management)
3. [Failed Jobs Management](#failed-jobs-management)
4. [Supervisor Management](#supervisor-management)
5. [Database Queries](#database-queries)
6. [Cache Management](#cache-management)
7. [Redis Monitoring](#redis-monitoring)
8. [Troubleshooting](#troubleshooting)

---

## Queue Statistics & Monitoring

### View Queue Statistics
```bash
# Show comprehensive queue stats
docker exec traidnet-backend php artisan queue:stats

# Reset statistics counter
docker exec traidnet-backend php artisan queue:stats --reset
```

**Output includes:**
- Total jobs (all time)
- Processed successfully
- Pending in queue
- Failed jobs
- Success/failure rates
- Pending jobs by queue
- Recent activity (24h and 7 days)
- Worker status

### Monitor Queue in Real-Time
```bash
# Watch queue stats (updates every 2 seconds)
watch -n 2 'docker exec traidnet-backend php artisan queue:stats'

# Monitor queue worker logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/dashboard-queue.log
docker exec traidnet-backend tail -f /var/www/html/storage/logs/payment-checks-queue.log
docker exec traidnet-backend tail -f /var/www/html/storage/logs/default-queue.log
```

### Count Processed Jobs
```bash
# Count all DONE entries in queue logs
docker exec traidnet-backend sh -c "grep -h ' DONE' /var/www/html/storage/logs/*-queue.log | wc -l"

# Count jobs in specific queue
docker exec traidnet-backend sh -c "grep -c ' DONE' /var/www/html/storage/logs/dashboard-queue.log"
```

---

## Queue Worker Management

### Start Queue Worker Manually
```bash
# Start worker for all queues
docker exec traidnet-backend php artisan queue:work --tries=3 --timeout=120

# Start worker for specific queue
docker exec traidnet-backend php artisan queue:work --queue=dashboard --tries=3 --timeout=120

# Start worker with verbose output
docker exec traidnet-backend php artisan queue:work --verbose --tries=3 --timeout=120

# Process one job only (testing)
docker exec traidnet-backend php artisan queue:work --once --verbose
```

### Queue Worker Options
- `--tries=3` - Retry failed jobs 3 times
- `--timeout=120` - Job timeout in seconds
- `--sleep=3` - Seconds to sleep when no jobs available
- `--max-time=3600` - Maximum seconds worker should run
- `--memory=128` - Memory limit in MB
- `--queue=name` - Specific queue to process
- `--once` - Process single job and exit
- `--verbose` - Show detailed output

---

## Failed Jobs Management

### Diagnose Failed Jobs
```bash
# Show all failed jobs summary
docker exec traidnet-backend php artisan queue:diagnose-failed

# Show specific number of failed jobs
docker exec traidnet-backend php artisan queue:diagnose-failed --limit=5

# Show failed jobs for specific queue
docker exec traidnet-backend php artisan queue:diagnose-failed --queue=payment-checks
```

### List Failed Jobs
```bash
# List all failed jobs
docker exec traidnet-backend php artisan queue:failed

# Show details of specific failed job
docker exec traidnet-backend php artisan queue:failed <job-id>
```

### Retry Failed Jobs
```bash
# Retry all failed jobs
docker exec traidnet-backend php artisan queue:retry all

# Retry specific failed job
docker exec traidnet-backend php artisan queue:retry <job-id>

# Retry jobs from specific queue
docker exec traidnet-backend php artisan queue:retry --queue=payment-checks
```

### Clear Failed Jobs
```bash
# Delete all failed jobs
docker exec traidnet-backend php artisan queue:flush

# Delete specific failed job
docker exec traidnet-backend php artisan queue:forget <job-id>

# Clear failed jobs older than 48 hours
docker exec traidnet-backend php artisan queue:prune-failed --hours=48
```

### Fix Failed Queues (Interactive)
```bash
# Interactive tool to fix failed jobs
docker exec traidnet-backend php artisan queue:fix-failed

# Options:
# 1. Retry all failed jobs
# 2. Clear all failed jobs
# 3. Retry specific queue
# 4. Show failed job details
```

---

## Supervisor Management

### Check Supervisor Status
```bash
# Show all supervisor processes
docker exec traidnet-backend supervisorctl status

# Show only queue workers
docker exec traidnet-backend supervisorctl status | grep laravel-queue

# Count running workers
docker exec traidnet-backend supervisorctl status | grep RUNNING | wc -l
```

### Manage Queue Workers
```bash
# Start all queue workers
docker exec traidnet-backend supervisorctl start laravel-queues:*

# Stop all queue workers
docker exec traidnet-backend supervisorctl stop laravel-queues:*

# Restart all queue workers
docker exec traidnet-backend supervisorctl restart laravel-queues:*

# Start specific queue worker
docker exec traidnet-backend supervisorctl start laravel-queues:laravel-queue-dashboard_00

# Restart specific worker
docker exec traidnet-backend supervisorctl restart laravel-queues:laravel-queue-payment-checks_00
```

### Update Supervisor Configuration
```bash
# After modifying supervisor config file
docker cp d:\traidnet\wifi-hotspot\backend\supervisor\laravel-queue.conf traidnet-backend:/etc/supervisor/conf.d/laravel-queue.conf

# Reload configuration
docker exec traidnet-backend supervisorctl reread

# Apply changes
docker exec traidnet-backend supervisorctl update

# Start new workers
docker exec traidnet-backend supervisorctl start laravel-queues:*
```

### View Supervisor Logs
```bash
# View supervisor main log
docker exec traidnet-backend tail -f /var/log/supervisor/supervisord.log

# View specific worker log
docker exec traidnet-backend tail -f /var/log/supervisor/laravel-queue-dashboard-stdout.log
```

---

## Database Queries

### Check Pending Jobs
```bash
# Count pending jobs
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) FROM jobs;"

# List pending jobs by queue
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT queue, COUNT(*) as count 
FROM jobs 
GROUP BY queue 
ORDER BY count DESC;
"

# Show distinct queues with jobs
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT DISTINCT queue FROM jobs ORDER BY queue;"
```

### Check Failed Jobs
```bash
# Count failed jobs
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) FROM failed_jobs;"

# Failed jobs by queue
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT queue, COUNT(*) as count 
FROM failed_jobs 
GROUP BY queue 
ORDER BY count DESC;
"

# Recent failed jobs
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT queue, failed_at, LEFT(exception, 100) as error 
FROM failed_jobs 
ORDER BY failed_at DESC 
LIMIT 10;
"
```

### Combined Queue Status
```bash
# Show all queue statistics
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT 
    'Pending' as status,
    queue, 
    COUNT(*) as count 
FROM jobs 
GROUP BY queue
UNION ALL
SELECT 
    'Failed' as status,
    queue, 
    COUNT(*) as count 
FROM failed_jobs 
GROUP BY queue
ORDER BY status, count DESC;
"
```

---

## Cache Management

### Clear Cache
```bash
# Clear application cache
docker exec traidnet-backend php artisan cache:clear

# Clear configuration cache
docker exec traidnet-backend php artisan config:clear

# Clear route cache
docker exec traidnet-backend php artisan route:clear

# Clear all caches
docker exec traidnet-backend php artisan optimize:clear
```

### Cache Configuration
```bash
# Cache routes for performance
docker exec traidnet-backend php artisan route:cache

# Cache configuration
docker exec traidnet-backend php artisan config:cache

# View cache configuration
docker exec traidnet-backend php artisan config:show cache
```

### Check Cache Driver
```bash
# Check current cache driver
docker exec traidnet-backend cat .env | grep CACHE_STORE

# Should be: CACHE_STORE=redis
```

### Change Cache Driver
```bash
# Change to Redis (recommended)
docker exec traidnet-backend sed -i 's/CACHE_STORE=file/CACHE_STORE=redis/g' .env

# Clear config after change
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan cache:clear
```

---

## Redis Monitoring

### Check Redis Connection
```bash
# Ping Redis
docker exec traidnet-redis redis-cli PING

# Should return: PONG
```

### Monitor Redis Keys
```bash
# Count total keys
docker exec traidnet-redis redis-cli DBSIZE

# List all keys (use carefully in production)
docker exec traidnet-redis redis-cli KEYS "*"

# List queue-related keys
docker exec traidnet-redis redis-cli KEYS "queue*"
docker exec traidnet-redis redis-cli KEYS "*processed*"

# Get key value
docker exec traidnet-redis redis-cli GET "queue_processed_jobs_count"
```

### Redis Statistics
```bash
# Get Redis info
docker exec traidnet-redis redis-cli INFO

# Get memory stats
docker exec traidnet-redis redis-cli INFO memory

# Get stats section
docker exec traidnet-redis redis-cli INFO stats

# Monitor Redis operations in real-time
docker exec traidnet-redis redis-cli MONITOR
```

### Clear Redis Cache
```bash
# Flush all Redis data (use with caution!)
docker exec traidnet-redis redis-cli FLUSHALL

# Flush current database only
docker exec traidnet-redis redis-cli FLUSHDB
```

---

## Troubleshooting

### Queue Not Processing

**Check workers are running:**
```bash
docker exec traidnet-backend supervisorctl status | grep laravel-queue
```

**Restart workers:**
```bash
docker exec traidnet-backend supervisorctl restart laravel-queues:*
```

**Check for errors in logs:**
```bash
docker exec traidnet-backend tail -50 /var/www/html/storage/logs/laravel.log
```

### High Failed Job Count

**Diagnose issues:**
```bash
docker exec traidnet-backend php artisan queue:diagnose-failed --limit=10
```

**Common fixes:**
- Missing database tables/columns
- Invalid job data
- Timeout issues
- Memory limits

**Clear and retry:**
```bash
# Clear old failed jobs
docker exec traidnet-backend php artisan queue:flush

# Monitor new failures
docker exec traidnet-backend php artisan queue:failed
```

### Worker Not Starting

**Check supervisor logs:**
```bash
docker exec traidnet-backend tail -50 /var/log/supervisor/supervisord.log
```

**Check worker-specific logs:**
```bash
docker exec traidnet-backend tail -50 /var/www/html/storage/logs/dashboard-queue-error.log
```

**Restart supervisor:**
```bash
docker restart traidnet-backend
```

### Database Connection Issues

**Test database connection:**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT 1;"
```

**Check Laravel can connect:**
```bash
docker exec traidnet-backend php artisan db:show
```

### Redis Connection Issues

**Test Redis connection:**
```bash
docker exec traidnet-redis redis-cli PING
```

**Check Laravel Redis config:**
```bash
docker exec traidnet-backend cat .env | grep REDIS
```

**Restart Redis:**
```bash
docker restart traidnet-redis
```

---

## Testing Commands

### Test Dashboard Job
```bash
# Test dashboard stats job
docker exec traidnet-backend php artisan test:dashboard-job

# Run synchronously (immediate)
docker exec traidnet-backend php artisan test:dashboard-job --sync

# Dispatch to queue
docker exec traidnet-backend php artisan test:dashboard-job --queue
```

### Dispatch Test Jobs
```bash
# Dispatch a test job to queue
docker exec traidnet-backend php artisan queue:work --once --verbose
```

---

## Performance Monitoring

### Check Queue Performance
```bash
# Count jobs processed in last hour
docker exec traidnet-backend sh -c "grep ' DONE' /var/www/html/storage/logs/*-queue.log | grep '$(date +%Y-%m-%d\ %H)' | wc -l"

# Average job processing time
docker exec traidnet-backend sh -c "grep ' DONE' /var/www/html/storage/logs/dashboard-queue.log | tail -100"
```

### Monitor System Resources
```bash
# Check container stats
docker stats traidnet-backend --no-stream

# Check memory usage
docker exec traidnet-backend free -h

# Check disk usage
docker exec traidnet-backend df -h
```

---

## Quick Reference

### Most Common Commands

```bash
# Check queue status
docker exec traidnet-backend php artisan queue:stats

# Check workers
docker exec traidnet-backend supervisorctl status | grep laravel-queue

# Restart workers
docker exec traidnet-backend supervisorctl restart laravel-queues:*

# Check failed jobs
docker exec traidnet-backend php artisan queue:diagnose-failed

# Clear failed jobs
docker exec traidnet-backend php artisan queue:flush

# Monitor logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log
```

---

## API Endpoints

### Queue Statistics API
```bash
# Get queue stats (requires authentication)
curl -H "Authorization: Bearer YOUR_TOKEN" http://localhost/api/queue/stats
```

**Response includes:**
- Summary (total, processed, pending, failed, rates)
- Pending jobs by queue
- Failed jobs by queue
- Recent activity (24h, 7 days)
- Worker status
- Health status

---

## Dashboard Widgets

### System Health Widget
- Shows queue metrics in "Job Queues" card
- Displays: Processed, Pending, Failed, Workers running
- Auto-refreshes every 30 seconds

### Queue Statistics Widget
- Comprehensive queue dashboard
- Real-time stats and charts
- Worker status monitoring
- Auto-refreshes every 30 seconds

---

## Configuration Files

### Supervisor Config
**Location:** `backend/supervisor/laravel-queue.conf`

**Queues configured:**
- default (1 worker)
- dashboard (1 worker)
- payment-checks (2 workers)
- payments (2 workers)
- router-checks (1 worker)
- router-data (4 workers)
- provisioning (3 workers)
- log-rotation (1 worker)
- hotspot-sms (2 workers)
- hotspot-sessions (2 workers)
- hotspot-accounting (1 worker)

**Total:** 20 workers

### Environment Variables
```env
QUEUE_CONNECTION=database
CACHE_STORE=redis
REDIS_HOST=traidnet-redis
REDIS_PASSWORD=null
REDIS_PORT=6379
```

---

## Maintenance Schedule

### Daily
- Check failed jobs count
- Monitor queue stats
- Review error logs

### Weekly
- Clear old failed jobs (>7 days)
- Review queue performance
- Check worker uptime

### Monthly
- Analyze queue trends
- Optimize worker counts
- Review and update documentation

---

## Frontend Build & Deployment

### Rebuild Frontend After Changes
```bash
# Rebuild frontend container
docker-compose build traidnet-frontend

# Restart frontend container
docker-compose up -d traidnet-frontend

# Or rebuild and restart in one command
docker-compose up -d --build traidnet-frontend
```

### Check Frontend Build
```bash
# Check frontend container logs
docker logs traidnet-frontend

# Check if frontend is serving files
curl http://localhost
```

### Frontend Development
```bash
# For local development (if needed)
cd frontend
npm install
npm run dev

# Build for production
npm run build
```

---

## Support & Resources

### Log Files
- Application: `/var/www/html/storage/logs/laravel.log`
- Queue workers: `/var/www/html/storage/logs/*-queue.log`
- Supervisor: `/var/log/supervisor/supervisord.log`

### Useful Links
- Laravel Queue Documentation: https://laravel.com/docs/queues
- Supervisor Documentation: http://supervisord.org/
- Redis Documentation: https://redis.io/documentation

---

**Last Updated:** October 12, 2025
**Version:** 1.1
