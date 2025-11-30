# Account Suspension/Unsuspension Event Broadcasting

**Date**: Oct 28, 2025, 1:59 PM  
**Status**: ✅ **IMPLEMENTED**  
**Feature**: Real-time notifications for account suspension and unsuspension

---

## 🎯 **Overview**

Real-time WebSocket events are now broadcast when accounts are suspended or unsuspended, allowing:
- **Tenant Admins** to receive immediate alerts about security events in their organization
- **System Admins** to monitor all platform-wide security events
- **Automated Dashboards** to display real-time security alerts

---

## 📡 **Events Created**

### 1. **AccountSuspended Event**

**File**: `app/Events/AccountSuspended.php`

**Triggered When**: User account is suspended after 5 failed login attempts

**Broadcast Channels**:
- `tenant.{tenantId}.security-alerts` - For tenant admins
- `system.admin.security-alerts` - For system admins

**Event Data**:
```json
{
  "user": {
    "id": "uuid",
    "username": "john.doe",
    "name": "John Doe",
    "email": "john@example.com",
    "role": "admin",
    "tenant_id": "tenant-uuid"
  },
  "suspended_until": "2025-10-28T14:30:00+00:00",
  "reason": "Too many failed login attempts",
  "ip_address": "192.168.1.100",
  "timestamp": "2025-10-28T14:00:00+00:00",
  "severity": "warning",
  "message": "Account 'john.doe' has been suspended until 2025-10-28T14:30:00+00:00. Reason: Too many failed login attempts"
}
```

---

### 2. **AccountUnsuspended Event**

**File**: `app/Events/AccountUnsuspended.php`

**Triggered When**: 
- Suspension period expires (auto-unsuspension job)
- User successfully logs in after suspension period

**Broadcast Channels**:
- `tenant.{tenantId}.security-alerts` - For tenant admins
- `system.admin.security-alerts` - For system admins

**Event Data**:
```json
{
  "user": {
    "id": "uuid",
    "username": "john.doe",
    "name": "John Doe",
    "email": "john@example.com",
    "role": "admin",
    "tenant_id": "tenant-uuid"
  },
  "was_suspended_until": "2025-10-28T14:30:00+00:00",
  "suspension_reason": "Too many failed login attempts",
  "timestamp": "2025-10-28T14:30:05+00:00",
  "severity": "info",
  "message": "Account 'john.doe' has been unsuspended and can now login."
}
```

---

## 🔐 **Channel Authorization**

### Tenant Security Alerts Channel

**Channel**: `tenant.{tenantId}.security-alerts`

**Authorization** (`routes/channels.php`):
```php
Broadcast::channel('tenant.{tenantId}.security-alerts', function ($user, $tenantId) {
    // System admins can access all
    if ($user->isSystemAdmin()) {
        return true;
    }
    // Tenant admins can only access their tenant's security alerts
    return $user->isAdmin() && $user->tenant_id === $tenantId;
});
```

**Who Can Listen**:
- ✅ System admins (all tenants)
- ✅ Tenant admins (their own tenant only)
- ❌ Regular users (no access)

---

### System Admin Security Alerts Channel

**Channel**: `system.admin.security-alerts`

**Authorization** (`routes/channels.php`):
```php
Broadcast::channel('system.admin.security-alerts', function ($user) {
    // Only system admins can access this channel
    return $user->isSystemAdmin();
});
```

**Who Can Listen**:
- ✅ System admins only
- ❌ Tenant admins (no access)
- ❌ Regular users (no access)

---

## 🔄 **Event Flow**

### Suspension Flow

```
User fails login 5 times
        ↓
Account suspended (30 min)
        ↓
AccountSuspended event dispatched
        ↓
    Broadcast to:
    ├─ tenant.{tenantId}.security-alerts
    └─ system.admin.security-alerts
        ↓
Real-time notification received by:
    ├─ Tenant Admin Dashboard
    └─ System Admin Dashboard
```

### Unsuspension Flow

```
Suspension period expires
        ↓
UnsuspendExpiredAccountsJob runs (every 5 min)
        ↓
Account unsuspended
        ↓
AccountUnsuspended event dispatched
        ↓
    Broadcast to:
    ├─ tenant.{tenantId}.security-alerts
    └─ system.admin.security-alerts
        ↓
Real-time notification received by:
    ├─ Tenant Admin Dashboard
    └─ System Admin Dashboard
```

---

## 💻 **Frontend Integration**

### Listening to Events (Vue.js)

#### Tenant Admin Dashboard

```javascript
import Echo from 'laravel-echo'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const tenantId = authStore.user.tenant_id

// Subscribe to tenant security alerts
Echo.private(`tenant.${tenantId}.security-alerts`)
  .listen('.account.suspended', (event) => {
    console.log('Account suspended:', event)
    
    // Show notification
    showNotification({
      type: 'warning',
      title: 'Account Suspended',
      message: event.message,
      duration: 10000
    })
    
    // Update security dashboard
    updateSecurityAlerts(event)
  })
  .listen('.account.unsuspended', (event) => {
    console.log('Account unsuspended:', event)
    
    // Show notification
    showNotification({
      type: 'info',
      title: 'Account Unsuspended',
      message: event.message,
      duration: 5000
    })
    
    // Update security dashboard
    updateSecurityAlerts(event)
  })
```

#### System Admin Dashboard

```javascript
// Subscribe to system-wide security alerts
Echo.private('system.admin.security-alerts')
  .listen('.account.suspended', (event) => {
    console.log('Platform-wide suspension:', event)
    
    // Show notification with tenant info
    showNotification({
      type: 'warning',
      title: 'Account Suspended',
      message: `${event.user.username} (${event.user.tenant_id}) - ${event.message}`,
      duration: 10000
    })
    
    // Update system security dashboard
    updateSystemSecurityAlerts(event)
  })
  .listen('.account.unsuspended', (event) => {
    console.log('Platform-wide unsuspension:', event)
    
    // Show notification
    showNotification({
      type: 'info',
      title: 'Account Unsuspended',
      message: `${event.user.username} (${event.user.tenant_id}) - ${event.message}`,
      duration: 5000
    })
    
    // Update system security dashboard
    updateSystemSecurityAlerts(event)
  })
```

---

## 🧪 **Testing**

### Test 1: Suspension Event

```bash
# Terminal 1: Monitor Laravel logs
docker-compose logs -f traidnet-backend | grep "Account suspended"

# Terminal 2: Trigger suspension (5 failed logins)
for i in {1..5}; do
  curl -X POST http://localhost/api/login \
    -H "Content-Type: application/json" \
    -d '{"username":"testuser","password":"wrongpassword"}'
  echo ""
done

# Expected:
# - Account suspended after 5th attempt
# - AccountSuspended event broadcast
# - Real-time notification in tenant admin dashboard
# - Real-time notification in system admin dashboard
```

### Test 2: Unsuspension Event

```bash
# Terminal 1: Monitor Laravel logs
docker-compose logs -f traidnet-backend | grep "Account unsuspended"

# Terminal 2: Wait for auto-unsuspension (or manually update DB)
# Wait 30 minutes or:
docker exec -it traidnet-postgres psql -U postgres -d traidnet -c \
  "UPDATE users SET suspended_until = NOW() - INTERVAL '1 minute' WHERE username = 'testuser';"

# Terminal 3: Trigger unsuspension job manually
docker exec traidnet-backend php artisan queue:work --once

# Expected:
# - Account unsuspended
# - AccountUnsuspended event broadcast
# - Real-time notification in tenant admin dashboard
# - Real-time notification in system admin dashboard
```

### Test 3: Channel Authorization

```javascript
// Test as Tenant Admin
Echo.private('tenant.OTHER_TENANT_ID.security-alerts')
  .listen('.account.suspended', (event) => {
    console.log('Should NOT receive this')
  })

// Expected: 403 Forbidden (cannot access other tenant's channel)

// Test as Regular User
Echo.private('system.admin.security-alerts')
  .listen('.account.suspended', (event) => {
    console.log('Should NOT receive this')
  })

// Expected: 403 Forbidden (not a system admin)
```

---

## 📊 **Security Dashboard Components**

### Tenant Admin Security Dashboard

**Recommended Components**:

1. **Security Alerts Feed**
   - Real-time list of suspension/unsuspension events
   - Filter by date, user, severity
   - Export to CSV

2. **Suspended Accounts Widget**
   - Count of currently suspended accounts
   - List with time remaining
   - Manual unsuspend button (admin override)

3. **Failed Login Attempts Chart**
   - Graph of failed attempts over time
   - Identify patterns and potential attacks
   - Alert threshold indicators

4. **Security Timeline**
   - Chronological view of all security events
   - Suspension, unsuspension, failed logins
   - IP address tracking

---

### System Admin Security Dashboard

**Recommended Components**:

1. **Platform-Wide Security Feed**
   - All tenant security events
   - Filter by tenant, user, event type
   - Real-time updates

2. **Tenant Security Overview**
   - Suspended accounts per tenant
   - Failed login attempts per tenant
   - Security score per tenant

3. **Attack Detection**
   - DDoS attempts blocked
   - Brute force patterns detected
   - Suspicious IP addresses

4. **Security Metrics**
   - Total suspensions (24h, 7d, 30d)
   - Average suspension duration
   - Most targeted accounts
   - Peak attack times

---

## 🔔 **Notification Examples**

### Suspension Notification (Toast)

```javascript
{
  type: 'warning',
  icon: '⚠️',
  title: 'Account Suspended',
  message: 'User john.doe has been suspended due to too many failed login attempts.',
  details: {
    username: 'john.doe',
    suspendedUntil: '2025-10-28 14:30:00',
    ipAddress: '192.168.1.100',
    reason: 'Too many failed login attempts'
  },
  actions: [
    { label: 'View Details', action: 'viewSecurityLog' },
    { label: 'Dismiss', action: 'dismiss' }
  ],
  duration: 10000 // 10 seconds
}
```

### Unsuspension Notification (Toast)

```javascript
{
  type: 'info',
  icon: '✅',
  title: 'Account Unsuspended',
  message: 'User john.doe can now login again.',
  details: {
    username: 'john.doe',
    wasSuspendedUntil: '2025-10-28 14:30:00',
    unsuspendedAt: '2025-10-28 14:30:05'
  },
  actions: [
    { label: 'Dismiss', action: 'dismiss' }
  ],
  duration: 5000 // 5 seconds
}
```

---

## 📝 **Implementation Checklist**

### Backend
- [x] Create `AccountSuspended` event
- [x] Create `AccountUnsuspended` event
- [x] Broadcast suspension event in `UnifiedAuthController`
- [x] Broadcast unsuspension event in `UnsuspendExpiredAccountsJob`
- [x] Add channel authorization for `tenant.{tenantId}.security-alerts`
- [x] Add channel authorization for `system.admin.security-alerts`
- [x] Test event broadcasting

### Frontend (To Do)
- [ ] Create security alerts listener in tenant dashboard
- [ ] Create security alerts listener in system admin dashboard
- [ ] Implement toast notifications for events
- [ ] Create security dashboard component
- [ ] Add suspended accounts widget
- [ ] Add failed login attempts chart
- [ ] Test real-time notifications

---

## 🔍 **Monitoring & Debugging**

### Check if Events are Broadcasting

```bash
# Monitor Soketi logs
docker-compose logs -f traidnet-soketi

# Monitor Laravel logs
docker-compose logs -f traidnet-backend | grep "Broadcasting"

# Monitor Redis (event queue)
docker exec -it traidnet-redis redis-cli
> MONITOR
```

### Verify Channel Subscriptions

```bash
# Check active channels in Soketi
curl http://localhost:6001/apps/traidnet-app/channels

# Check specific channel
curl http://localhost:6001/apps/traidnet-app/channels/private-tenant.TENANT_ID.security-alerts
```

### Debug Event Payload

```php
// In AccountSuspended event
public function broadcastWith(): array
{
    \Log::info('Broadcasting AccountSuspended', [
        'user_id' => $this->user->id,
        'tenant_id' => $this->tenantId,
        'channels' => $this->broadcastOn()
    ]);
    
    return [/* ... */];
}
```

---

## 🚀 **Benefits**

### For Tenant Admins:
- ✅ **Immediate Awareness**: Know instantly when accounts are suspended
- ✅ **Proactive Security**: Identify potential security threats early
- ✅ **User Support**: Quickly assist users who are locked out
- ✅ **Audit Trail**: Real-time security event logging

### For System Admins:
- ✅ **Platform Monitoring**: See all security events across all tenants
- ✅ **Threat Detection**: Identify coordinated attacks across tenants
- ✅ **Compliance**: Real-time security event tracking for audits
- ✅ **Performance**: Monitor suspension patterns and adjust thresholds

### For Users:
- ✅ **Transparency**: Understand why account was suspended
- ✅ **Clarity**: Know exactly when they can login again
- ✅ **Security**: Protected from unauthorized access attempts

---

## 📚 **Related Files**

### Backend:
- `app/Events/AccountSuspended.php` - Suspension event
- `app/Events/AccountUnsuspended.php` - Unsuspension event
- `app/Http/Controllers/Api/UnifiedAuthController.php` - Broadcasts suspension
- `app/Jobs/UnsuspendExpiredAccountsJob.php` - Broadcasts unsuspension
- `routes/channels.php` - Channel authorization
- `app/Traits/BroadcastsToTenant.php` - Tenant broadcasting trait

### Documentation:
- `RATE_LIMITING_AND_SECURITY.md` - Rate limiting & suspension
- `SUSPENSION_EVENTS_BROADCASTING.md` - This document
- `SECURITY_AUDIT_REPORT.md` - Security audit

---

## ✅ **Summary**

**Events Implemented**:
1. ✅ `AccountSuspended` - Broadcast when account suspended
2. ✅ `AccountUnsuspended` - Broadcast when account unsuspended

**Channels Created**:
1. ✅ `tenant.{tenantId}.security-alerts` - Tenant-specific alerts
2. ✅ `system.admin.security-alerts` - Platform-wide alerts

**Authorization**:
- ✅ Tenant admins can only see their tenant's events
- ✅ System admins can see all events
- ✅ Regular users have no access

**Real-Time Notifications**:
- ✅ Suspension events broadcast immediately
- ✅ Unsuspension events broadcast when job runs
- ✅ Events include full context (user, reason, timestamp, IP)

---

**Status**: ✅ **PRODUCTION READY**  
**Last Updated**: Oct 28, 2025, 1:59 PM  
**Broadcasting**: 🔴 **LIVE**
