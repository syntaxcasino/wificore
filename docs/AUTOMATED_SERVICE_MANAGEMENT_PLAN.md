# Automated Service Management System - Payment-Based Control

**Date:** 2025-10-11 08:25  
**Status:** 📋 **FEASIBILITY ANALYSIS & DESIGN**

---

## 🎯 Objectives

### **Core Requirements:**
1. ✅ Auto-disconnect PPPoE/Hotspot users on payment failure
2. ✅ Auto-reconnect users after successful payment
3. ✅ RADIUS-based service control (AAA)
4. ✅ Queue-based task processing
5. ✅ Periodic payment status checks
6. ✅ Pre-expiry notifications (email, SMS, in-app)
7. ✅ Grace period support
8. ✅ Payment reminder system

---

## 🔍 Feasibility Analysis

### **1. RADIUS-Based Service Control** ✅ **FEASIBLE**

**Control Mechanisms:**

**A. User Account Status in RADIUS**
```sql
-- To DISABLE user
UPDATE radcheck SET value='Reject' 
WHERE username='user@example.com' AND attribute='Auth-Type';

-- To ENABLE user
UPDATE radcheck SET value='Accept' 
WHERE username='user@example.com' AND attribute='Auth-Type';
```

**B. Session Termination via RADIUS CoA**
- Send CoA Disconnect-Request to terminate active sessions
- Requires RADIUS CoA port (3799)

**Verdict:** ✅ **FULLY FEASIBLE**

---

### **2. Queue System** ✅ **FEASIBLE**

**Queue Structure:**
```
service-control (High Priority)
├── DisconnectUserJob
├── ReconnectUserJob
└── TerminateSessionJob

notifications (Medium Priority)
├── SendPaymentReminderJob
├── SendExpiryWarningJob
└── SendDisconnectionNoticeJob

payment-checks (Low Priority)
├── CheckExpiredSubscriptionsJob
├── CheckPaymentDueDatesJob
└── ProcessGracePeriodJob
```

**Verdict:** ✅ **FULLY FEASIBLE**

---

### **3. Periodic Checks** ✅ **FEASIBLE**

**Laravel Scheduler:**
- Check expired subscriptions: Every 5 minutes
- Payment reminders: Daily at 9 AM
- Grace period processing: Every 30 minutes

**Verdict:** ✅ **FULLY FEASIBLE**

---

## 🏗️ Database Schema

### **Update `user_subscriptions`**
```sql
ALTER TABLE user_subscriptions 
ADD COLUMN next_payment_date DATE,
ADD COLUMN grace_period_days INT DEFAULT 3,
ADD COLUMN grace_period_ends_at TIMESTAMP,
ADD COLUMN auto_renew BOOLEAN DEFAULT false,
ADD COLUMN disconnected_at TIMESTAMP,
ADD COLUMN disconnection_reason VARCHAR(255),
ADD COLUMN last_reminder_sent_at TIMESTAMP,
ADD COLUMN reminder_count INT DEFAULT 0;
```

### **New Table: `service_control_logs`**
```sql
CREATE TABLE service_control_logs (
    id UUID PRIMARY KEY,
    user_id UUID,
    subscription_id UUID,
    action VARCHAR(50), -- 'disconnect', 'reconnect'
    reason VARCHAR(255),
    status VARCHAR(20), -- 'pending', 'completed', 'failed'
    radius_response JSON,
    executed_at TIMESTAMP,
    created_at TIMESTAMP
);
```

### **New Table: `payment_reminders`**
```sql
CREATE TABLE payment_reminders (
    id UUID PRIMARY KEY,
    user_id UUID,
    subscription_id UUID,
    reminder_type VARCHAR(50),
    days_before_due INT,
    sent_at TIMESTAMP,
    channel VARCHAR(20), -- 'email', 'sms', 'in_app'
    status VARCHAR(20),
    created_at TIMESTAMP
);
```

---

## 📋 Implementation Phases

### **Phase 1: RADIUS Integration** (Day 1)
1. Create RADIUSServiceController
2. Implement disconnect/reconnect methods
3. Implement CoA support
4. Test RADIUS control

### **Phase 2: Queue Jobs** (Day 2)
1. Create DisconnectUserJob
2. Create ReconnectUserJob
3. Create CheckExpiredSubscriptionsJob
4. Create SendPaymentRemindersJob
5. Configure queue workers

### **Phase 3: Subscription Manager** (Day 2-3)
1. Create SubscriptionManager service
2. Implement grace period logic
3. Implement auto-renewal logic
4. Add payment tracking

### **Phase 4: Notifications** (Day 3)
1. Create notification classes
2. Implement email notifications
3. Implement SMS notifications
4. Implement in-app notifications

### **Phase 5: Scheduled Tasks** (Day 4)
1. Configure Laravel scheduler
2. Set up periodic checks
3. Test automation
4. Monitor and optimize

### **Phase 6: Testing & Deployment** (Day 5)
1. Unit tests
2. Integration tests
3. E2E testing
4. Production deployment

---

## 🎯 Key Features

### **1. Auto-Disconnection**
- Triggered on subscription expiry
- Grace period support (configurable days)
- RADIUS-based immediate disconnection
- Notification sent to user

### **2. Auto-Reconnection**
- Triggered on successful payment
- RADIUS account re-enabled
- Welcome back notification
- Service restored immediately

### **3. Payment Reminders**
- 7 days before due date
- 3 days before due date
- 1 day before due date
- On due date
- Multi-channel (email, SMS, in-app)

### **4. Grace Period**
- Configurable per package
- Warning notifications during grace period
- Final warning before disconnection
- Automatic disconnection after grace period

---

## 🚀 Queue Workers

**Production Setup:**
```bash
# Start queue workers
php artisan queue:work --queue=service-control,notifications,payment-checks
```

**Supervisor Configuration:**
```ini
[program:hotspot-queue-worker]
command=php artisan queue:work --queue=service-control,notifications,payment-checks
autostart=true
autorestart=true
numprocs=4
```

---

## 📊 Success Metrics

- ✅ Disconnection within 5 minutes of expiry
- ✅ Reconnection within 1 minute of payment
- ✅ 95%+ notification delivery rate
- ✅ Zero false disconnections
- ✅ Queue processing < 30 seconds

---

**Ready for Implementation!**

**Next:** Start Phase 1 - RADIUS Integration

---

**Prepared By:** Cascade AI  
**Date:** 2025-10-11 08:25  
**Status:** 📋 READY TO IMPLEMENT
