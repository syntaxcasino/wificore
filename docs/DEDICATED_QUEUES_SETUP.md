# Dedicated Queues - Complete Setup

## 🎯 Queue Strategy

Each type of task now has its own dedicated queue for easier troubleshooting, monitoring, and scaling.

## 📊 Queue Structure

### Hotspot Queues (New)

#### 1. **hotspot-sms** 
**Purpose:** SMS credential delivery  
**Priority:** High (5)  
**Workers:** 2  
**Configuration:**
- Sleep: 1 second
- Tries: 3
- Timeout: 30 seconds
- Max time: 1 hour

**Jobs:**
- `SendCredentialsSMSJob`

**Log Files:**
- `/var/www/html/storage/logs/hotspot-sms-queue.log`
- `/var/www/html/storage/logs/hotspot-sms-queue-error.log`

#### 2. **hotspot-sessions**
**Purpose:** Session management (disconnect, expiry)  
**Priority:** Medium (10)  
**Workers:** 2  
**Configuration:**
- Sleep: 2 seconds
- Tries: 3
- Timeout: 60 seconds
- Max time: 1 hour

**Jobs:**
- `DisconnectHotspotUserJob`
- `CheckExpiredSessionsJob`

**Log Files:**
- `/var/www/html/storage/logs/hotspot-sessions-queue.log`
- `/var/www/html/storage/logs/hotspot-sessions-queue-error.log`

#### 3. **hotspot-accounting**
**Purpose:** RADIUS accounting sync  
**Priority:** Low (15)  
**Workers:** 1  
**Configuration:**
- Sleep: 5 seconds
- Tries: 2
- Timeout: 120 seconds
- Max time: 1 hour

**Jobs:**
- `SyncRadiusAccountingJob`

**Log Files:**
- `/var/www/html/storage/logs/hotspot-accounting-queue.log`
- `/var/www/html/storage/logs/hotspot-accounting-queue-error.log`

### Existing Queues

#### 4. **default**
**Purpose:** General tasks  
**Priority:** Medium (5)  
**Workers:** 1

#### 5. **payments**
**Purpose:** Payment processing (vouchers)  
**Priority:** High (5)  
**Workers:** 2

#### 6. **router-checks**
**Purpose:** Router health checks  
**Priority:** Medium (10)  
**Workers:** 1

#### 7. **router-data**
**Purpose:** Router live data fetching  
**Priority:** Low (20)  
**Workers:** 4

#### 8. **provisioning**
**Purpose:** Router provisioning  
**Priority:** Medium (10)  
**Workers:** 3

#### 9. **dashboard**
**Purpose:** Dashboard stats updates  
**Priority:** Low (15)  
**Workers:** 1

#### 10. **log-rotation**
**Purpose:** Log file rotation  
**Priority:** Lowest (30)  
**Workers:** 1

## 🔄 Job to Queue Mapping

### Hotspot Jobs

| Job | Queue | Priority | Workers |
|-----|-------|----------|---------|
| SendCredentialsSMSJob | hotspot-sms | High | 2 |
| DisconnectHotspotUserJob | hotspot-sessions | Medium | 2 |
| CheckExpiredSessionsJob | hotspot-sessions | Medium | 2 |
| SyncRadiusAccountingJob | hotspot-accounting | Low | 1 |

### Code Examples

**SMS Job:**
```php
SendCredentialsSMSJob::dispatch($hotspotUserId)
    ->onQueue('hotspot-sms');
```

**Disconnect Job:**
```php
DisconnectHotspotUserJob::dispatch($sessionId, $reason)
    ->onQueue('hotspot-sessions');
```

**Scheduled Jobs:**
```php
// Check expired sessions
Schedule::job(new CheckExpiredSessionsJob)
    ->everyMinute()
    ->onQueue('hotspot-sessions');

// Sync accounting
Schedule::job(new SyncRadiusAccountingJob)
    ->everyFiveMinutes()
    ->onQueue('hotspot-accounting');
```

## 📁 Supervisor Configuration

**File:** `backend/supervisor/laravel-queue.conf`

### Hotspot SMS Worker
```ini
[program:laravel-queue-hotspot-sms]
command=/usr/local/bin/php /var/www/html/artisan queue:work database --queue=hotspot-sms --sleep=1 --tries=3 --timeout=30 --max-time=3600
numprocs=2
priority=5
stdout_logfile=/var/www/html/storage/logs/hotspot-sms-queue.log
```

### Hotspot Sessions Worker
```ini
[program:laravel-queue-hotspot-sessions]
command=/usr/local/bin/php /var/www/html/artisan queue:work database --queue=hotspot-sessions --sleep=2 --tries=3 --timeout=60 --max-time=3600
numprocs=2
priority=10
stdout_logfile=/var/www/html/storage/logs/hotspot-sessions-queue.log
```

### Hotspot Accounting Worker
```ini
[program:laravel-queue-hotspot-accounting]
command=/usr/local/bin/php /var/www/html/artisan queue:work database --queue=hotspot-accounting --sleep=5 --tries=2 --timeout=120 --max-time=3600
numprocs=1
priority=15
stdout_logfile=/var/www/html/storage/logs/hotspot-accounting-queue.log
```

## 🚀 Deployment

### 1. Update Supervisor Configuration

```bash
# Copy new configuration
sudo cp backend/supervisor/laravel-queue.conf /etc/supervisor/conf.d/

# Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update

# Start new workers
sudo supervisorctl start laravel-queue-hotspot-sms:*
sudo supervisorctl start laravel-queue-hotspot-sessions:*
sudo supervisorctl start laravel-queue-hotspot-accounting:*
```

### 2. Verify Workers Running

```bash
# Check all workers
sudo supervisorctl status

# Should see:
# laravel-queue-hotspot-sms:laravel-queue-hotspot-sms_00    RUNNING
# laravel-queue-hotspot-sms:laravel-queue-hotspot-sms_01    RUNNING
# laravel-queue-hotspot-sessions:laravel-queue-hotspot-sessions_00    RUNNING
# laravel-queue-hotspot-sessions:laravel-queue-hotspot-sessions_01    RUNNING
# laravel-queue-hotspot-accounting:laravel-queue-hotspot-accounting_00    RUNNING
```

### 3. Monitor Logs

```bash
# SMS queue
tail -f storage/logs/hotspot-sms-queue.log

# Sessions queue
tail -f storage/logs/hotspot-sessions-queue.log

# Accounting queue
tail -f storage/logs/hotspot-accounting-queue.log
```

## 🔍 Troubleshooting

### Check Queue Status

```bash
# View jobs in specific queue
php artisan queue:monitor hotspot-sms hotspot-sessions hotspot-accounting

# View failed jobs
php artisan queue:failed

# Retry failed jobs for specific queue
php artisan queue:retry --queue=hotspot-sms
```

### Monitor Queue Depth

```sql
-- Check pending jobs by queue
SELECT queue, COUNT(*) as pending_jobs 
FROM jobs 
GROUP BY queue;

-- Check failed jobs by queue
SELECT queue, COUNT(*) as failed_jobs 
FROM failed_jobs 
GROUP BY queue;
```

### Restart Specific Queue

```bash
# Restart SMS queue only
sudo supervisorctl restart laravel-queue-hotspot-sms:*

# Restart sessions queue only
sudo supervisorctl restart laravel-queue-hotspot-sessions:*

# Restart accounting queue only
sudo supervisorctl restart laravel-queue-hotspot-accounting:*
```

### View Queue Logs

```bash
# Last 100 lines of SMS queue
tail -n 100 storage/logs/hotspot-sms-queue.log

# Follow SMS queue in real-time
tail -f storage/logs/hotspot-sms-queue.log

# Search for errors
grep "ERROR" storage/logs/hotspot-sms-queue-error.log

# Count errors in last hour
grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" storage/logs/hotspot-sms-queue-error.log | wc -l
```

## 📊 Performance Tuning

### Adjust Workers Based on Load

**High SMS Volume:**
```ini
# Increase SMS workers
numprocs=4  # from 2
```

**High Session Activity:**
```ini
# Increase session workers
numprocs=3  # from 2
```

**Heavy Accounting Load:**
```ini
# Increase accounting workers
numprocs=2  # from 1
```

### Adjust Sleep Times

**For faster processing:**
```bash
--sleep=0  # Process immediately (high CPU)
```

**For lower CPU usage:**
```bash
--sleep=5  # Wait 5 seconds between jobs
```

### Adjust Timeouts

**For long-running jobs:**
```bash
--timeout=300  # 5 minutes
```

**For quick jobs:**
```bash
--timeout=30  # 30 seconds
```

## 🎯 Benefits of Dedicated Queues

### 1. **Easier Troubleshooting**
- Separate logs for each task type
- Isolate issues quickly
- Clear error tracking

### 2. **Better Monitoring**
- Monitor specific queue depths
- Track performance per queue
- Identify bottlenecks

### 3. **Independent Scaling**
- Scale SMS workers independently
- Adjust session workers based on load
- Optimize resource usage

### 4. **Priority Management**
- High priority for SMS (user-facing)
- Medium priority for sessions
- Low priority for accounting

### 5. **Fault Isolation**
- SMS issues don't affect sessions
- Accounting problems don't block SMS
- Independent failure handling

## 📈 Monitoring Commands

### Queue Statistics

```bash
# Count jobs per queue
php artisan tinker
>>> DB::table('jobs')->select('queue', DB::raw('count(*) as total'))->groupBy('queue')->get();

# Average wait time per queue
>>> DB::table('jobs')->select('queue', DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, NOW())) as avg_wait'))->groupBy('queue')->get();
```

### Worker Health

```bash
# Check if workers are processing
sudo supervisorctl tail -f laravel-queue-hotspot-sms

# Check worker memory usage
ps aux | grep "queue:work" | grep "hotspot-sms"

# Check worker uptime
sudo supervisorctl status | grep hotspot
```

## ✅ Summary

**Total Queues:** 10 (3 new hotspot queues)  
**Hotspot Workers:** 5 (2+2+1)  
**Dedicated Logs:** 3 sets  
**Priority Levels:** 3 (High, Medium, Low)  

**Hotspot Queues:**
- ✅ hotspot-sms (2 workers, high priority)
- ✅ hotspot-sessions (2 workers, medium priority)
- ✅ hotspot-accounting (1 worker, low priority)

**Benefits:**
- ✅ Easier troubleshooting
- ✅ Better monitoring
- ✅ Independent scaling
- ✅ Fault isolation
- ✅ Clear separation of concerns

---

**Configuration:** Complete  
**Supervisor:** Updated  
**Jobs:** Assigned to queues  
**Ready for:** Production 🚀
