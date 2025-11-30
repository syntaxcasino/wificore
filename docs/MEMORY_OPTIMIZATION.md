# Backend Container Memory Optimization

## Current Status
- **Before**: 679.5 MiB (8.62%)
- **Target**: ~300-400 MiB (3-5%)

## Changes Made

### 1. Reduced Queue Workers (Primary Fix)
**Before**: 17 worker processes
**After**: 9 worker processes

| Queue | Before | After | Savings |
|-------|--------|-------|---------|
| default | 2 | 1 | -1 worker |
| router-checks | 2 | 1 | -1 worker |
| router-data | 3 | 2 | -1 worker |
| log-rotation | 1 | 1 | 0 |
| payments | 4 | 2 | -2 workers |
| provisioning | 3 | 2 | -1 worker |
| dashboard | 2 | 1 | -1 worker |
| **TOTAL** | **17** | **10** | **-7 workers** |

**Expected Memory Savings**: ~280-350 MiB (each PHP worker uses ~40-50 MiB)

### 2. Deployment Steps

```bash
# Rebuild backend container with new configuration
cd /d/traidnet/wifi-hotspot
docker compose build traidnet-backend

# Restart backend service
docker compose up -d traidnet-backend

# Verify workers are running
docker exec traidnet-backend supervisorctl status

# Check memory usage after 5 minutes
docker stats --no-stream
```

## Additional Optimization Recommendations

### 3. PHP-FPM Configuration (Optional)
If still high after queue worker reduction, optimize PHP-FPM:

**File**: `backend/docker/php-fpm.conf` (create if doesn't exist)

```ini
[www]
pm = dynamic
pm.max_children = 10          ; Reduced from default 50
pm.start_servers = 2          ; Reduced from default 5
pm.min_spare_servers = 1      ; Reduced from default 2
pm.max_spare_servers = 3      ; Reduced from default 10
pm.max_requests = 500         ; Restart workers after 500 requests
```

**Expected Savings**: ~100-150 MiB

### 4. OPcache Optimization (Already Enabled)
Ensure OPcache is configured in `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0  ; Disable in production
opcache.save_comments=1
opcache.fast_shutdown=1
```

### 5. Disable Unused Extensions
Check loaded extensions:
```bash
docker exec traidnet-backend php -m
```

Disable unused ones in `php.ini` to save ~5-10 MiB per extension.

### 6. Laravel Optimizations (Already Applied)
```bash
# Run these in production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 7. Database Query Optimization
- Use `select()` to limit columns
- Add indexes to frequently queried columns
- Use eager loading to prevent N+1 queries

### 8. Reduce Log Verbosity
**File**: `backend/config/logging.php`

```php
'level' => env('LOG_LEVEL', 'warning'), // Changed from 'debug'
```

## Monitoring

### Check Memory Usage
```bash
# Overall container stats
docker stats --no-stream

# Detailed process breakdown
docker exec traidnet-backend ps aux --sort=-%mem | head -20

# PHP-FPM status
docker exec traidnet-backend curl http://localhost/fpm-status

# Queue worker count
docker exec traidnet-backend supervisorctl status | grep RUNNING | wc -l
```

### Expected Results After Optimization

| Component | Memory Usage |
|-----------|--------------|
| PHP-FPM (5 workers) | ~150-200 MiB |
| Queue Workers (10) | ~200-250 MiB |
| Supervisor | ~10 MiB |
| Other | ~40-50 MiB |
| **TOTAL** | **~400-510 MiB** |

## Performance Impact

### Queue Processing
- **Before**: 17 workers processing jobs in parallel
- **After**: 10 workers processing jobs in parallel
- **Impact**: Minimal - most queues have low traffic
- **Critical queues** (payments, provisioning) still have 2 workers each

### Response Time
- No impact on API response times
- PHP-FPM still handles web requests efficiently
- Queue jobs may take slightly longer during peak times

## Scaling Strategy

### When to Scale Up
If you notice:
- Queue jobs backing up (check `jobs` table)
- Slow dashboard updates
- Payment processing delays

### How to Scale
1. **Horizontal**: Add more backend containers
2. **Vertical**: Increase specific queue workers:
   ```ini
   # For high payment volume
   [program:laravel-queue-payments]
   numprocs=3  ; Increase from 2
   ```

## Rollback Plan

If issues occur, revert changes:

```bash
# Restore original configuration
git checkout backend/supervisor/laravel-queue.conf

# Rebuild and restart
docker compose build traidnet-backend
docker compose up -d traidnet-backend
```

## Summary

✅ **Reduced queue workers from 17 to 10** (-41% workers)
✅ **Expected memory reduction: ~280-350 MiB**
✅ **Target memory usage: ~400 MiB** (down from 679 MiB)
✅ **No impact on critical functionality**
✅ **Easy to scale up if needed**

---

**Last Updated**: 2025-10-07
**Version**: 1.0.0
