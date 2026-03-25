# Partition Automation Without pg_cron

## Overview

This document explains how to automate PostgreSQL partition maintenance without using `pg_cron`, which adds ~200MB to the Docker image size due to clang/LLVM dependencies.

## Why Not pg_cron?

- **Size Impact**: pg_cron requires clang and LLVM for bitcode generation (~200MB overhead)
- **Alpine Limitation**: Building pg_cron on Alpine is complex and significantly increases image size
- **Better Alternatives**: Multiple lightweight alternatives available

## Trade-offs

| Approach | Image Size | Complexity | Reliability |
|----------|-----------|------------|-------------|
| **pg_cron** | +200MB | Low | High |
| **Laravel Scheduler** | +0MB | Low | High |
| **Host Cron** | +0MB | Medium | High |
| **Supervisor** | +0MB | Low | Medium |

## Automation Options

### Option 1: Laravel Scheduler (Recommended) ⭐

**Advantages:**
- No additional dependencies
- Built into Laravel
- Easy to monitor and debug
- Works in all environments

**Setup:**

The scheduler is already configured in `routes/console.php`:

```php
Schedule::command('partitions:maintain')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
```

**Ensure Laravel scheduler is running:**

```bash
# The scheduler is already running via Supervisor
# Check supervisor/laravel-scheduler.conf
```

**Manual execution:**
```bash
docker exec wificore-backend php artisan partitions:maintain
```

**Dry run (see what would be done):**
```bash
docker exec wificore-backend php artisan partitions:maintain --dry-run
```

**For specific tenant:**
```bash
docker exec wificore-backend php artisan partitions:maintain --schema=ts_123
```

---

### Option 2: Host Cron Job

**Advantages:**
- Independent of application
- Runs even if container restarts
- Simple and reliable

**Setup:**

1. Make the script executable:
```bash
chmod +x postgres/scripts/maintain-partitions.sh
```

2. Add to host crontab:
```bash
# Edit crontab
crontab -e

# Add this line (runs daily at 2 AM)
0 2 * * * /opt/wificore/postgres/scripts/maintain-partitions.sh >> /var/log/partition-maintenance.log 2>&1
```

3. Or use the Laravel command via Docker:
```bash
# Add to host crontab
0 2 * * * docker exec wificore-backend php artisan partitions:maintain >> /var/log/partition-maintenance.log 2>&1
```

---

### Option 3: Supervisor Periodic Task

**Advantages:**
- Runs inside container
- Managed by Supervisor
- Automatic restart on failure

**Setup:**

The configuration is already in `backend/supervisor/partition-maintenance.conf`:

```ini
[program:partition-maintenance]
command=/bin/sh -c "while true; do sleep 86400; php /var/www/html/artisan partitions:maintain; done"
```

**Enable it:**

1. Copy to supervisor config directory (already done in Dockerfile)
2. Restart supervisor:
```bash
docker exec wificore-backend supervisorctl reread
docker exec wificore-backend supervisorctl update
docker exec wificore-backend supervisorctl start partition-maintenance
```

**Check status:**
```bash
docker exec wificore-backend supervisorctl status partition-maintenance
```

---

### Option 4: Kubernetes CronJob

**For Kubernetes deployments:**

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: partition-maintenance
spec:
  schedule: "0 2 * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: partition-maintenance
            image: kja2aro/wificore:wificore-backend
            command:
            - php
            - artisan
            - partitions:maintain
          restartPolicy: OnFailure
```

---

## Monitoring

### Check Partition Status

```sql
-- View all partitioned tables
SELECT * FROM partition_info;

-- View partition configuration
SELECT 
    parent_table,
    partition_interval,
    premake,
    retention,
    (SELECT count(*) FROM pg_inherits WHERE inhparent = parent_table::regclass) as partition_count
FROM partman.part_config
ORDER BY parent_table;
```

### Laravel Command Output

```bash
# View partition statistics
docker exec wificore-backend php artisan partitions:maintain

# Output example:
# Starting partition maintenance...
# ✓ Partition maintenance completed
# ✓ Database statistics updated (ANALYZE)
# 
# Partition Statistics:
# +--------+-------------------+------------+-----------------+
# | Schema | Table             | Total Size | Partition Count |
# +--------+-------------------+------------+-----------------+
# | ts_123 | radacct           | 2.5 GB     | 90              |
# | ts_123 | radpostauth       | 450 MB     | 90              |
# | ts_123 | water_transactions| 1.2 GB     | 90              |
# +--------+-------------------+------------+-----------------+
```

### Logs

```bash
# Laravel logs
docker exec wificore-backend tail -f storage/logs/laravel.log | grep partition

# Supervisor logs (if using Option 3)
docker exec wificore-backend tail -f /var/log/supervisor/partition-maintenance.log

# Host cron logs (if using Option 2)
tail -f /var/log/partition-maintenance.log
```

---

## Manual Maintenance

If automation fails or you need to run maintenance immediately:

```bash
# Via Laravel command
docker exec wificore-backend php artisan partitions:maintain

# Via PostgreSQL directly
docker exec wificore-postgres psql -U admin -d wms_770_ts -c "SELECT partman.run_maintenance_proc();"
```

---

## What Maintenance Does

1. **Creates new partitions** - Pre-creates partitions based on `premake` setting (7 days ahead)
2. **Drops old partitions** - Removes partitions older than retention period (90 days)
3. **Updates statistics** - Runs ANALYZE for query optimization
4. **Logs activity** - Records maintenance actions

---

## Partition Configuration

Default settings in `postgres/partitioning-setup.sql`:

```sql
- Interval: 1 day (daily partitions)
- Pre-make: 7 days ahead
- Retention: 90 days
- Tables: radacct, radpostauth, water_transactions, jobs
```

To modify:
```sql
UPDATE partman.part_config 
SET 
    premake = 14,           -- Create 14 days ahead
    retention = '180 days'  -- Keep 6 months
WHERE parent_table = 'public.radacct';
```

---

## Troubleshooting

### Partitions not being created

```bash
# Check if maintenance is running
docker exec wificore-backend supervisorctl status

# Run manually to see errors
docker exec wificore-backend php artisan partitions:maintain
```

### Old partitions not being dropped

```sql
-- Check retention settings
SELECT parent_table, retention, retention_keep_table 
FROM partman.part_config;

-- Force drop old partitions
SELECT partman.run_maintenance_proc();
```

### Scheduler not running

```bash
# Check Laravel scheduler
docker exec wificore-backend php artisan schedule:list

# Check supervisor
docker exec wificore-backend supervisorctl status laravel-scheduler
```

---

## Recommended Approach

**For Production**: Use **Option 1 (Laravel Scheduler)** + **Option 2 (Host Cron as backup)**

This provides:
- Primary automation via Laravel (monitored, logged, integrated)
- Backup automation via host cron (independent, reliable)
- Zero additional image size
- Easy monitoring and debugging

**Setup both:**

1. Laravel scheduler is already configured (runs via Supervisor)
2. Add host cron as backup:
```bash
0 2 * * * docker exec wificore-backend php artisan partitions:maintain >> /var/log/partition-maintenance.log 2>&1
```

This ensures partitions are maintained even if the Laravel scheduler fails.

---

## Comparison with pg_cron

| Feature | pg_cron | Laravel Scheduler |
|---------|---------|-------------------|
| Image Size | +200MB | +0MB |
| Setup Complexity | Medium | Low |
| Monitoring | PostgreSQL logs | Laravel logs + Horizon |
| Debugging | SQL only | Full Laravel tooling |
| Flexibility | Limited | High (PHP code) |
| Dependencies | clang, LLVM | None |
| Alpine Support | Difficult | Native |

**Conclusion**: Laravel Scheduler provides better integration, monitoring, and flexibility with zero size overhead.
