# Queue System - Quick Reference

## 🚀 Quick Start

### Check Queue Status
```bash
docker exec traidnet-backend supervisorctl status
```

### Monitor Queue Jobs
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT queue, COUNT(*) FROM jobs GROUP BY queue;"
```

### View Queue Logs
```bash
# Payments
docker exec traidnet-backend tail -f /var/www/html/storage/logs/payments-queue.log

# Provisioning
docker exec traidnet-backend tail -f /var/www/html/storage/logs/provisioning-queue.log
```

---

## 🔧 Common Commands

### Restart Queue Workers
```bash
# All workers
docker exec traidnet-backend supervisorctl restart laravel-queues:*

# Specific queue
docker exec traidnet-backend supervisorctl restart laravel-queue-payments:*
```

### Failed Jobs
```bash
# List failed jobs
docker exec traidnet-backend php artisan queue:failed

# Retry specific job
docker exec traidnet-backend php artisan queue:retry {job_id}

# Retry all failed jobs
docker exec traidnet-backend php artisan queue:retry all

# Clear failed jobs
docker exec traidnet-backend php artisan queue:flush
```

### Manual Processing
```bash
# Process one job from payments queue
docker exec traidnet-backend php artisan queue:work database --queue=payments --once

# Process jobs for 60 seconds
docker exec traidnet-backend php artisan queue:work database --queue=payments --max-time=60
```

---

## 📊 Queue Configuration

| Queue | Workers | Timeout | Retries | Priority |
|-------|---------|---------|---------|----------|
| payments | 4 | 120s | 3 | High |
| provisioning | 3 | 60s | 5 | Medium |
| default | 2 | 90s | 3 | Normal |
| router-checks | 2 | 120s | 3 | Normal |
| router-data | 3 | 60s | 3 | Low |
| log-rotation | 1 | 30s | 1 | Low |

---

## 📡 Admin Notifications

### Event Types

| Event | Description |
|-------|-------------|
| `payment.processed` | Payment successfully processed |
| `payment.failed` | Payment processing failed |
| `user.provisioned` | User provisioned in MikroTik |
| `provisioning.failed` | MikroTik provisioning failed |

### Subscribe (Frontend)
```javascript
window.Echo.private('admin-notifications')
  .listen('.payment.processed', (e) => console.log(e))
  .listen('.payment.failed', (e) => console.error(e))
  .listen('.user.provisioned', (e) => console.log(e))
  .listen('.provisioning.failed', (e) => console.warn(e));
```

---

## 🐛 Troubleshooting

### Workers Not Running
```bash
supervisorctl reread
supervisorctl update
supervisorctl restart laravel-queues:*
```

### Jobs Stuck
```bash
# Check worker logs
tail -100 /var/www/html/storage/logs/payments-queue.log

# Process manually
php artisan queue:work database --queue=payments --once
```

### High Memory Usage
```bash
# Restart workers (they auto-restart every 2 hours)
supervisorctl restart laravel-queues:*
```

---

## 📈 Monitoring Queries

### Pending Jobs by Queue
```sql
SELECT queue, COUNT(*) as pending 
FROM jobs 
GROUP BY queue;
```

### Failed Jobs (Last 24h)
```sql
SELECT queue, exception, failed_at 
FROM failed_jobs 
WHERE failed_at > NOW() - INTERVAL '24 hours'
ORDER BY failed_at DESC;
```

### Job Processing Rate
```sql
SELECT 
  DATE_TRUNC('hour', created_at) as hour,
  COUNT(*) as jobs_processed
FROM job_batches
WHERE created_at > NOW() - INTERVAL '24 hours'
GROUP BY hour
ORDER BY hour DESC;
```

---

## 🎯 Performance Tips

1. **Monitor queue depth** - Keep < 100 pending jobs
2. **Check failed jobs daily** - Investigate patterns
3. **Review logs weekly** - Identify bottlenecks
4. **Scale workers** - Increase `numprocs` if needed
5. **Use Redis** - For better performance at scale

---

## 📚 Documentation

- **Full Guide:** `docs/QUEUE_SYSTEM.md`
- **Implementation:** `docs/QUEUE_IMPLEMENTATION_SUMMARY.md`
- **User Roles:** `docs/USER_ROLES_AND_FLOW.md`
- **Troubleshooting:** `docs/TROUBLESHOOTING_GUIDE.md`

---

**Quick Help:** `docker exec traidnet-backend php artisan queue:work --help`
