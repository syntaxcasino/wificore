# Event-Based Architecture - WiFi Hotspot System

## 🎯 **Architecture Overview**

This system follows an **event-driven architecture** where all operations (except router registration) are processed asynchronously through Laravel events and queued jobs.

---

## ✅ **Event-Based Operations**

### **1. Payment Processing**
**Flow**: M-Pesa Callback → Event Dispatch → Async Jobs

```php
// PaymentController::callback()
if ($status === 'completed') {
    // 1. Broadcast event
    broadcast(new PaymentCompleted($payment))->toOthers();
    
    // 2. Dispatch hotspot user creation (async)
    CreateHotspotUserJob::dispatch($payment, $package)
        ->onQueue('hotspot-provisioning');
    
    // 3. Dispatch subscription reconnection (async)
    if ($subscription && $subscription->isDisconnected()) {
        ReconnectSubscriptionJob::dispatch($payment, $subscription)
            ->onQueue('subscription-reconnection');
    }
    
    // 4. Dispatch voucher creation (async)
    ProcessPaymentJob::dispatch($payment)
        ->onQueue('payments');
}
```

**Benefits**:
- ✅ Fast callback response (< 100ms)
- ✅ No timeout risks
- ✅ Automatic retries on failure
- ✅ Scalable processing

---

### **2. Hotspot User Provisioning**
**Event**: `HotspotUserProvisionRequested`  
**Job**: `CreateHotspotUserJob`  
**Queue**: `hotspot-provisioning`

**Operations** (all async):
1. Create `HotspotUser` record
2. Insert RADIUS authentication (`radcheck`)
3. Insert RADIUS attributes (`radreply`)
4. Create `HotspotCredential` for SMS
5. Create `RadiusSession` record
6. Cache credentials for auto-login
7. Dispatch SMS job
8. Broadcast `HotspotUserCreated` event

---

### **3. Subscription Reconnection**
**Event**: `SubscriptionReconnectionRequested`  
**Job**: `ReconnectSubscriptionJob`  
**Queue**: `subscription-reconnection`

**Operations** (all async):
1. Update subscription status
2. Update RADIUS authentication
3. Reconnect user session
4. Log reconnection

---

### **4. Dashboard Stats Updates**
**Job**: `UpdateDashboardStatsJob`  
**Queue**: `dashboard`

**Triggered by**:
- Dashboard page load (cached)
- Manual refresh request
- Background scheduler (every 5 minutes)

---

### **5. Router Provisioning**
**Job**: `RouterProvisioningJob`  
**Queue**: `router-provisioning`

**Operations** (all async):
1. Connect to MikroTik API
2. Configure hotspot settings
3. Create user profiles
4. Set up firewall rules
5. Broadcast progress events

---

### **6. Background Jobs (Scheduled)**

All scheduled jobs run asynchronously:

| Job | Schedule | Queue | Purpose |
|-----|----------|-------|---------|
| `CheckExpiredSessionsJob` | Every 5 min | `session-management` | Disconnect expired users |
| `CheckExpiredSubscriptionsJob` | Every 10 min | `subscription-management` | Handle expired subscriptions |
| `CheckRoutersJob` | Every 2 min | `router-monitoring` | Monitor router status |
| `CollectSystemMetricsJob` | Every 1 min | `metrics` | Collect system metrics |
| `ProcessGracePeriodJob` | Daily | `grace-period` | Process grace period users |
| `RotateLogs` | Daily | `maintenance` | Rotate log files |
| `SendPaymentRemindersJob` | Daily | `notifications` | Send payment reminders |

---

## ❌ **Synchronous Operations (Exceptions)**

### **Router Registration ONLY**

```php
// RouterController::store()
public function store(Request $request)
{
    // SYNCHRONOUS - User needs immediate response
    $router = Router::create([
        'name' => $request->name,
        'ip_address' => $ipAddress,
        'username' => $username,
        'password' => Crypt::encrypt($password),
        'config_token' => $configToken,
        'status' => 'pending',
    ]);
    
    // Generate connectivity script synchronously
    $connectivityScript = $this->generateConnectivityScript($router);
    
    return response()->json([
        'connectivity_script' => $connectivityScript,
        // ... router details
    ]);
}
```

**Why Synchronous?**
- User needs immediate connectivity script
- Script generation is fast (< 50ms)
- No external API calls
- No risk of timeout

---

## 📊 **Event Flow Diagram**

```
┌─────────────────┐
│  M-Pesa Callback│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ PaymentController│
│   ::callback()  │
└────────┬────────┘
         │
         ├──────────────────────────────────┐
         │                                  │
         ▼                                  ▼
┌─────────────────┐              ┌─────────────────┐
│ CreateHotspot   │              │ Reconnect       │
│ UserJob         │              │ SubscriptionJob │
│ (async)         │              │ (async)         │
└────────┬────────┘              └────────┬────────┘
         │                                │
         ├────────┬────────┬──────────┐  │
         ▼        ▼        ▼          ▼  ▼
    ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
    │ Create │ │ RADIUS │ │  SMS   │ │ Update │
    │  User  │ │  Auth  │ │  Job   │ │ Status │
    └────────┘ └────────┘ └────────┘ └────────┘
```

---

## 🔧 **Queue Configuration**

### **Queue Priorities** (High to Low)

1. **`hotspot-provisioning`** - Hotspot user creation (critical)
2. **`subscription-reconnection`** - User reconnection (critical)
3. **`payments`** - Payment processing
4. **`hotspot-sms`** - SMS notifications
5. **`router-provisioning`** - Router setup
6. **`dashboard`** - Dashboard stats
7. **`session-management`** - Session cleanup
8. **`subscription-management`** - Subscription checks
9. **`router-monitoring`** - Router health checks
10. **`metrics`** - System metrics
11. **`notifications`** - General notifications
12. **`maintenance`** - Log rotation, cleanup

### **Supervisor Configuration**

```ini
[program:laravel-queue-hotspot]
command=php /var/www/html/artisan queue:work database --queue=hotspot-provisioning,subscription-reconnection --tries=3 --timeout=90
numprocs=3
priority=1

[program:laravel-queue-payments]
command=php /var/www/html/artisan queue:work database --queue=payments,hotspot-sms --tries=3 --timeout=60
numprocs=2
priority=2

[program:laravel-queue-general]
command=php /var/www/html/artisan queue:work database --queue=default,dashboard,session-management --tries=3 --timeout=60
numprocs=2
priority=3
```

---

## 🚨 **Critical Rules**

### **DO ✅**
1. ✅ Always dispatch jobs for database operations
2. ✅ Use events for cross-cutting concerns
3. ✅ Keep controllers thin (dispatch only)
4. ✅ Use appropriate queues for priority
5. ✅ Implement retry logic in jobs
6. ✅ Log all async operations
7. ✅ Broadcast events for real-time updates

### **DON'T ❌**
1. ❌ Never perform DB operations in controllers (except router registration)
2. ❌ Never call external APIs synchronously
3. ❌ Never block callback responses
4. ❌ Never use `sync` queue in production
5. ❌ Never skip error handling in jobs
6. ❌ Never forget to log job execution
7. ❌ Never dispatch jobs without queue specification

---

## 📝 **Events & Jobs Reference**

### **Events**
| Event | Purpose | Listeners |
|-------|---------|-----------|
| `PaymentCompleted` | Payment successful | Broadcast to dashboard |
| `HotspotUserCreated` | User provisioned | Broadcast to dashboard |
| `HotspotUserProvisionRequested` | Trigger provisioning | `CreateHotspotUserJob` |
| `SubscriptionReconnectionRequested` | Trigger reconnection | `ReconnectSubscriptionJob` |
| `RouterConnected` | Router online | Broadcast to dashboard |
| `RouterStatusUpdated` | Router status change | Broadcast to dashboard |
| `SessionExpired` | Session ended | Broadcast to dashboard |
| `AccountSuspended` | Account suspended | Broadcast to dashboard |
| `AccountUnsuspended` | Account reactivated | Broadcast to dashboard |

### **Jobs**
| Job | Queue | Timeout | Retries | Purpose |
|-----|-------|---------|---------|---------|
| `CreateHotspotUserJob` | hotspot-provisioning | 90s | 3 | Create hotspot user |
| `ReconnectSubscriptionJob` | subscription-reconnection | 60s | 3 | Reconnect subscription |
| `ProcessPaymentJob` | payments | 60s | 3 | Process payment |
| `SendCredentialsSMSJob` | hotspot-sms | 30s | 3 | Send SMS |
| `RouterProvisioningJob` | router-provisioning | 120s | 2 | Provision router |
| `UpdateDashboardStatsJob` | dashboard | 30s | 1 | Update stats |

---

## 🔍 **Monitoring & Debugging**

### **Check Queue Status**
```bash
# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### **Monitor Queue Workers**
```bash
# Check supervisor status
docker exec traidnet-backend supervisorctl status

# View queue logs
docker logs traidnet-backend --tail=100 -f | grep "queue"
```

### **Debug Job Execution**
```bash
# Enable queue logging
LOG_LEVEL=debug

# View job processing
tail -f storage/logs/laravel.log | grep "Job"
```

---

## 🎉 **Benefits of Event-Based Architecture**

1. **Scalability** - Process thousands of payments concurrently
2. **Reliability** - Automatic retries on failure
3. **Performance** - Fast response times (< 100ms)
4. **Maintainability** - Clear separation of concerns
5. **Monitoring** - Easy to track job execution
6. **Testing** - Jobs can be tested independently
7. **Resilience** - System continues even if jobs fail

---

## 📚 **Further Reading**

- [Laravel Queues Documentation](https://laravel.com/docs/queues)
- [Laravel Events Documentation](https://laravel.com/docs/events)
- [Supervisor Documentation](http://supervisord.org/)
- [Redis Queue Driver](https://laravel.com/docs/redis)

---

**Last Updated**: November 30, 2025  
**Architecture Version**: 2.0 (Fully Event-Based)
