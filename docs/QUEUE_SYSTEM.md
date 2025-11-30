# Queue System Documentation

## Overview
The WiFi Hotspot Management System uses a queue-based architecture for processing payments and user provisioning to handle high concurrency and ensure system reliability.

**Date:** 2025-10-04  
**Status:** ✅ Production Ready

---

## Architecture

### Queue Flow

```
M-Pesa Callback → Payment Record Updated → Job Dispatched to Queue
                                                    ↓
                                          Queue Worker Processes Job
                                                    ↓
                                    ┌───────────────┴───────────────┐
                                    ↓                               ↓
                          Process Payment Job              Provision MikroTik Job
                                    ↓                               ↓
                          ┌─────────┴─────────┐          ┌─────────┴─────────┐
                          ↓                   ↓          ↓                   ↓
                    Find/Create User    Create RADIUS   Add User to      Update User
                    Create Subscription    Entry        MikroTik         in MikroTik
                          ↓                   ↓          ↓                   ↓
                    Broadcast Event    Broadcast Event  Broadcast Event
                    (Admin Only)       (Admin Only)     (Admin Only)
```

---

## Queue Configuration

### Dedicated Queues

1. **payments** - Payment processing (4 workers)
   - Priority: High
   - Timeout: 120 seconds
   - Retries: 3 attempts
   - Backoff: 10s, 30s, 60s

2. **provisioning** - MikroTik provisioning (3 workers)
   - Priority: Medium
   - Timeout: 60 seconds
   - Retries: 5 attempts
   - Backoff: 5s, 15s, 30s, 60s, 120s

3. **default** - General tasks (2 workers)
   - Priority: Normal
   - Timeout: 90 seconds
   - Retries: 3 attempts

4. **router-checks** - Router status monitoring (2 workers)
5. **router-data** - Router data collection (3 workers)
6. **log-rotation** - Log management (1 worker)

### Worker Configuration

**File:** `backend/supervisor/laravel-queue.conf`

```ini
# Payment Queue - High Priority
[program:laravel-queue-payments]
command=/usr/local/bin/php /var/www/html/artisan queue:work database --queue=payments --sleep=3 --tries=3 --timeout=120
numprocs=4  # 4 concurrent workers
priority=5  # High priority
```

---

## Jobs

### 1. ProcessPaymentJob

**Purpose:** Process completed payments and provision users

**Queue:** `payments`

**Responsibilities:**
- Find or create hotspot user by phone number
- Create user subscription
- Generate MikroTik credentials
- Create RADIUS authentication entry
- Dispatch MikroTik provisioning job
- Broadcast success/failure events to admins

**Retry Strategy:**
- Attempts: 3
- Backoff: 10s → 30s → 60s
- On final failure: Mark payment as failed, broadcast failure event

**Example:**
```php
ProcessPaymentJob::dispatch($payment)
    ->onQueue('payments')
    ->delay(now()->addSeconds(2));
```

**Tags:**
- `payment:{payment_id}`
- `phone:{phone_number}`
- `package:{package_id}`

---

### 2. ProvisionUserInMikroTikJob

**Purpose:** Provision user in MikroTik router

**Queue:** `provisioning`

**Responsibilities:**
- Connect to MikroTik router via API
- Check if user already exists
- Create or update hotspot user
- Set bandwidth limits and time limits
- Broadcast success/failure events to admins

**Retry Strategy:**
- Attempts: 5
- Backoff: 5s → 15s → 30s → 60s → 120s
- On final failure: Broadcast failure event (user can still authenticate via RADIUS)

**Example:**
```php
ProvisionUserInMikroTikJob::dispatch($subscription, $routerId)
    ->onQueue('provisioning')
    ->delay(now()->addSeconds(5));
```

**Tags:**
- `subscription:{subscription_id}`
- `router:{router_id}`
- `user:{user_id}`

---

## Events (Admin Notifications)

All events broadcast to the **private** `admin-notifications` channel.

### 1. PaymentProcessed

**Triggered:** After successful payment processing

**Data:**
```json
{
  "type": "payment_processed",
  "payment": {
    "id": 123,
    "amount": 100.00,
    "phone_number": "+254712345678",
    "transaction_id": "ABC123",
    "package": "Normal 1 Hour"
  },
  "user": {
    "id": 45,
    "username": "hs_712345678",
    "phone_number": "+254712345678",
    "is_new": true
  },
  "subscription": {
    "id": 67,
    "start_time": "2025-10-04T12:00:00Z",
    "end_time": "2025-10-04T13:00:00Z",
    "status": "active"
  },
  "credentials": {
    "username": "user_254712345678",
    "password": "abc12345"
  },
  "timestamp": "2025-10-04T12:00:05Z"
}
```

---

### 2. PaymentFailed

**Triggered:** After payment processing fails permanently

**Data:**
```json
{
  "type": "payment_failed",
  "payment": {
    "id": 123,
    "amount": 100.00,
    "phone_number": "+254712345678",
    "transaction_id": "ABC123",
    "package": "Normal 1 Hour"
  },
  "error": "Database connection timeout",
  "timestamp": "2025-10-04T12:00:05Z"
}
```

---

### 3. UserProvisioned

**Triggered:** After successful MikroTik provisioning

**Data:**
```json
{
  "type": "user_provisioned",
  "subscription": {
    "id": 67,
    "user_id": 45,
    "username": "user_254712345678",
    "package": "Normal 1 Hour",
    "end_time": "2025-10-04T13:00:00Z"
  },
  "router": {
    "id": 1,
    "name": "Main Office Router",
    "ip_address": "192.168.100.30"
  },
  "timestamp": "2025-10-04T12:00:10Z"
}
```

---

### 4. ProvisioningFailed

**Triggered:** After MikroTik provisioning fails permanently

**Data:**
```json
{
  "type": "provisioning_failed",
  "subscription": {
    "id": 67,
    "user_id": 45,
    "username": "user_254712345678",
    "package": "Normal 1 Hour"
  },
  "router_id": 1,
  "error": "Connection timeout to router",
  "timestamp": "2025-10-04T12:00:10Z"
}
```

---

## Performance Optimizations

### 1. Multiple Workers per Queue

**Payments Queue:** 4 workers
- Handles high concurrency during peak hours
- Each worker processes jobs independently
- Automatic load balancing

**Provisioning Queue:** 3 workers
- Parallel MikroTik provisioning
- Reduces bottleneck for router API calls

### 2. Queue Priorities

```
payments (priority 5) > provisioning (priority 10) > default (priority 15)
```

Critical payment processing gets priority over other tasks.

### 3. Job Delays

```php
// Payment processing - 2 second delay
ProcessPaymentJob::dispatch($payment)->delay(now()->addSeconds(2));

// MikroTik provisioning - 5 second delay
ProvisionUserInMikroTikJob::dispatch($subscription, $routerId)->delay(now()->addSeconds(5));
```

**Benefits:**
- Ensures database transactions are committed
- Prevents race conditions
- Allows RADIUS entries to propagate

### 4. After Commit

```php
// config/queue.php
'after_commit' => true
```

Jobs are only dispatched after database transaction commits, ensuring data consistency.

### 5. Exponential Backoff

```php
public $backoff = [10, 30, 60]; // Payments
public $backoff = [5, 15, 30, 60, 120]; // Provisioning
```

Gradually increasing delays between retries prevent overwhelming external services.

### 6. Job Timeouts

- Payments: 120 seconds
- Provisioning: 60 seconds
- Prevents stuck jobs from blocking workers

### 7. Max Exceptions

```php
public $maxExceptions = 3;
```

Jobs fail after 3 unhandled exceptions, preventing infinite retry loops.

---

## Monitoring

### Queue Status

```bash
# Check queue jobs
docker exec traidnet-backend php artisan queue:monitor

# View failed jobs
docker exec traidnet-backend php artisan queue:failed

# Retry failed job
docker exec traidnet-backend php artisan queue:retry {job_id}

# Retry all failed jobs
docker exec traidnet-backend php artisan queue:retry all

# Clear failed jobs
docker exec traidnet-backend php artisan queue:flush
```

### Worker Status

```bash
# Check supervisor status
docker exec traidnet-backend supervisorctl status

# Restart all queue workers
docker exec traidnet-backend supervisorctl restart laravel-queues:*

# Restart specific queue
docker exec traidnet-backend supervisorctl restart laravel-queue-payments:*
```

### Queue Logs

```bash
# Payment queue logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/payments-queue.log

# Provisioning queue logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/provisioning-queue.log

# Laravel logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log
```

---

## Database Queries

### Check Pending Jobs

```sql
SELECT 
    queue,
    COUNT(*) as pending_jobs,
    MIN(available_at) as oldest_job
FROM jobs
GROUP BY queue;
```

### Check Failed Jobs

```sql
SELECT 
    id,
    queue,
    exception,
    failed_at
FROM failed_jobs
ORDER BY failed_at DESC
LIMIT 10;
```

### Job Statistics

```sql
-- Jobs processed in last hour
SELECT 
    queue,
    COUNT(*) as jobs_processed
FROM job_batches
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY queue;
```

---

## Frontend Integration

### Subscribe to Admin Notifications

```javascript
// Admin dashboard component
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Initialize Echo
window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost: import.meta.env.VITE_PUSHER_HOST,
    wsPort: import.meta.env.VITE_PUSHER_PORT,
    forceTLS: false,
    disableStats: true,
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            Authorization: `Bearer ${localStorage.getItem('authToken')}`
        }
    }
});

// Subscribe to admin notifications (admin users only)
window.Echo.private('admin-notifications')
    .listen('.payment.processed', (event) => {
        console.log('Payment processed:', event);
        showNotification('success', `New user: ${event.user.username}`);
        updateDashboard();
    })
    .listen('.payment.failed', (event) => {
        console.log('Payment failed:', event);
        showNotification('error', `Payment failed: ${event.error}`);
    })
    .listen('.user.provisioned', (event) => {
        console.log('User provisioned:', event);
        showNotification('info', `User provisioned on ${event.router.name}`);
    })
    .listen('.provisioning.failed', (event) => {
        console.log('Provisioning failed:', event);
        showNotification('warning', `Provisioning failed: ${event.error}`);
    });
```

### Notification Component

```vue
<template>
  <div class="notifications">
    <div v-for="notification in notifications" :key="notification.id" 
         :class="['notification', notification.type]">
      <span class="icon">{{ getIcon(notification.type) }}</span>
      <div class="content">
        <h4>{{ notification.title }}</h4>
        <p>{{ notification.message }}</p>
        <small>{{ formatTime(notification.timestamp) }}</small>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      notifications: []
    };
  },
  mounted() {
    this.subscribeToAdminNotifications();
  },
  methods: {
    subscribeToAdminNotifications() {
      window.Echo.private('admin-notifications')
        .listen('.payment.processed', (event) => {
          this.addNotification({
            type: 'success',
            title: 'Payment Processed',
            message: `New user ${event.user.username} - ${event.payment.package}`,
            timestamp: event.timestamp
          });
        })
        .listen('.payment.failed', (event) => {
          this.addNotification({
            type: 'error',
            title: 'Payment Failed',
            message: event.error,
            timestamp: event.timestamp
          });
        });
    },
    addNotification(notification) {
      notification.id = Date.now();
      this.notifications.unshift(notification);
      
      // Auto-remove after 10 seconds
      setTimeout(() => {
        this.removeNotification(notification.id);
      }, 10000);
    },
    removeNotification(id) {
      const index = this.notifications.findIndex(n => n.id === id);
      if (index > -1) {
        this.notifications.splice(index, 1);
      }
    }
  }
};
</script>
```

---

## Troubleshooting

### Issue: Jobs Not Processing

**Check:**
```bash
# Are workers running?
docker exec traidnet-backend supervisorctl status

# Are there jobs in the queue?
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) FROM jobs;"

# Check worker logs
docker exec traidnet-backend tail -f /var/www/html/storage/logs/payments-queue.log
```

**Solution:**
```bash
# Restart queue workers
docker exec traidnet-backend supervisorctl restart laravel-queues:*
```

---

### Issue: Jobs Failing

**Check:**
```bash
# View failed jobs
docker exec traidnet-backend php artisan queue:failed

# Check error logs
docker exec traidnet-backend tail -100 /var/www/html/storage/logs/laravel.log
```

**Solution:**
```bash
# Retry specific failed job
docker exec traidnet-backend php artisan queue:retry {job_id}

# Retry all failed jobs
docker exec traidnet-backend php artisan queue:retry all
```

---

### Issue: Events Not Broadcasting

**Check:**
```bash
# Is Soketi running?
docker ps | grep soketi

# Check Soketi logs
docker logs traidnet-soketi --tail 50

# Test broadcasting
docker exec traidnet-backend php artisan tinker
>>> broadcast(new App\Events\PaymentProcessed($payment, $user, $subscription, $credentials));
```

**Solution:**
```bash
# Restart Soketi
docker-compose restart traidnet-soketi

# Check frontend Echo configuration
# Ensure auth endpoint is correct
```

---

## Performance Metrics

### Expected Throughput

- **Payments:** 240 jobs/hour (4 workers × 60 jobs/hour)
- **Provisioning:** 180 jobs/hour (3 workers × 60 jobs/hour)

### Latency

- **Payment Processing:** 2-5 seconds (including delays)
- **MikroTik Provisioning:** 5-10 seconds (including delays)
- **Total Time (Payment → WiFi Access):** 7-15 seconds

### Scalability

**Horizontal Scaling:**
- Increase `numprocs` in supervisor config
- Add more backend containers with load balancer

**Vertical Scaling:**
- Increase worker timeout for complex operations
- Optimize database queries
- Use Redis instead of database queue driver

---

## Best Practices

### 1. Always Use Queues for External Services
```php
// ✅ Good - Queued
ProvisionUserInMikroTikJob::dispatch($subscription, $routerId);

// ❌ Bad - Synchronous
$this->mikrotikService->provisionUser($subscription);
```

### 2. Use Appropriate Retry Strategies
```php
// Critical operations - more retries
public $tries = 5;
public $backoff = [5, 15, 30, 60, 120];

// Non-critical operations - fewer retries
public $tries = 3;
public $backoff = [10, 30, 60];
```

### 3. Tag Jobs for Monitoring
```php
public function tags(): array
{
    return [
        'payment:' . $this->payment->id,
        'user:' . $this->payment->phone_number,
    ];
}
```

### 4. Broadcast Events for Admin Visibility
```php
broadcast(new PaymentProcessed($payment, $user, $subscription, $credentials))
    ->toOthers();
```

### 5. Log Important Steps
```php
Log::info('Payment processing started', [
    'payment_id' => $this->payment->id,
    'attempt' => $this->attempts(),
]);
```

---

## Conclusion

The queue-based architecture provides:

✅ **High Concurrency** - Handle multiple payments simultaneously  
✅ **Reliability** - Automatic retries with exponential backoff  
✅ **Scalability** - Easy to add more workers  
✅ **Visibility** - Real-time admin notifications  
✅ **Fault Tolerance** - Failed jobs don't block the system  
✅ **Performance** - Non-blocking payment processing  

The system can handle **hundreds of concurrent users** with proper worker configuration and monitoring.

---

**Last Updated:** 2025-10-04  
**Version:** 1.0
