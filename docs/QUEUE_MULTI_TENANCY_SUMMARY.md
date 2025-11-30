# Queue Multi-Tenancy - Critical Issue & Solution

## 🚨 Critical Issue Discovered

**Your queue system is NOT tenant-aware!**

### The Problem

Currently, when jobs are dispatched, they process data across **ALL tenants** without isolation. This means:

❌ **CheckExpiredSubscriptionsJob** - Processes subscriptions from all tenants together  
❌ **SendPaymentRemindersJob** - Could send reminders to wrong tenant's users  
❌ **ProcessPaymentJob** - Could provision resources for wrong tenant  
❌ **All other jobs** - No tenant isolation in background processing

### The Risk

- **Data Leaks**: Jobs could access/modify data from other tenants
- **Wrong Actions**: Could disconnect wrong tenant's users
- **Mixed Data**: Statistics and reports could mix tenant data
- **Security Breach**: Violates multi-tenancy isolation

---

## ✅ Solution Provided

### 1. TenantAwareJob Trait

**Location**: `backend/app/Traits/TenantAwareJob.php`

**Features**:
- Stores tenant context with the job
- Executes job within tenant scope
- Validates tenant is active before processing
- Adds tenant tags for monitoring
- Automatically gets tenant from authenticated user

### 2. Updated Job Example

**Location**: `backend/app/Jobs/CheckExpiredSubscriptionsJob_UPDATED_EXAMPLE.php`

Shows exactly how to update existing jobs to be tenant-aware.

### 3. Test Suite

**Location**: `backend/tests/Feature/TenantAwareJobsTest.php`

Comprehensive tests to verify tenant isolation in jobs.

### 4. Complete Guide

**Location**: `QUEUE_MULTI_TENANCY_GUIDE.md`

Full documentation on implementing tenant-aware jobs.

---

## 🔧 How to Fix (Quick Guide)

### Step 1: Add Trait to Job

```php
use App\Traits\TenantAwareJob;

class YourJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;  // ✅ ADD THIS
}
```

### Step 2: Update Constructor

```php
public function __construct($tenantId = null)  // ✅ ADD PARAMETER
{
    $this->setTenantContext($tenantId);  // ✅ SET CONTEXT
    $this->onQueue('your-queue');
}
```

### Step 3: Wrap Handle Method

```php
public function handle(): void
{
    $this->executeInTenantContext(function() {  // ✅ WRAP IN CONTEXT
        // Your job logic here
        // All queries now scoped to tenant!
    });
}
```

### Step 4: Update Dispatches

```php
// When dispatching
YourJob::dispatch()
    ->setTenantContext(auth()->user()->tenant_id);  // ✅ SET TENANT
```

### Step 5: Update Scheduled Commands

```php
// In Kernel.php
$schedule->call(function () {
    $tenants = Tenant::active()->get();
    
    foreach ($tenants as $tenant) {
        YourJob::dispatch()
            ->setTenantContext($tenant->id);  // ✅ DISPATCH PER TENANT
    }
})->hourly();
```

---

## 📋 Jobs That Need Updating

### Critical Priority (Update Immediately)

1. ✅ **CheckExpiredSubscriptionsJob** - Example provided
2. ⚠️ **SendPaymentRemindersJob** - Update needed
3. ⚠️ **ProcessPaymentJob** - Update needed
4. ⚠️ **DisconnectUserJob** - Update needed
5. ⚠️ **ProcessGracePeriodJob** - Update needed

### High Priority

6. ⚠️ **CheckRoutersJob**
7. ⚠️ **RouterProvisioningJob**
8. ⚠️ **ProvisionUserInMikroTikJob**
9. ⚠️ **DisconnectHotspotUserJob**
10. ⚠️ **ReconnectUserJob**

### Medium Priority

11. ⚠️ **SyncAccessPointStatusJob**
12. ⚠️ **UpdateVpnStatusJob**
13. ⚠️ **RouterProbingJob**
14. ⚠️ **SendCredentialsSMSJob**

### Low Priority (Can be system-wide)

15. **UpdateDashboardStatsJob** - Consider making system-wide
16. **SyncRadiusAccountingJob**
17. **CheckExpiredSessionsJob**

---

## 🧪 Testing

### Run Tests

```bash
php artisan test --filter TenantAwareJobsTest
```

### Manual Testing

```php
// Test tenant isolation
$tenant1 = Tenant::first();
$tenant2 = Tenant::skip(1)->first();

// Dispatch for tenant 1
YourJob::dispatch()->setTenantContext($tenant1->id);

// Verify only tenant 1's data was processed
// Verify tenant 2's data was NOT touched
```

---

## ⚠️ Important Warnings

### 1. Never Dispatch Without Tenant Context

```php
// ❌ WRONG - Processes all tenants!
CheckExpiredSubscriptionsJob::dispatch();

// ✅ CORRECT - Processes specific tenant
CheckExpiredSubscriptionsJob::dispatch()
    ->setTenantContext($tenantId);
```

### 2. Update Scheduled Commands

```php
// ❌ WRONG - Runs once for all tenants
$schedule->job(new CheckExpiredSubscriptionsJob)->hourly();

// ✅ CORRECT - Runs once per tenant
$schedule->call(function () {
    foreach (Tenant::active()->get() as $tenant) {
        CheckExpiredSubscriptionsJob::dispatch()
            ->setTenantContext($tenant->id);
    }
})->hourly();
```

### 3. Queue Workers

Restart queue workers after updating jobs:

```bash
php artisan queue:restart
```

---

## 📊 Monitoring

### Horizon Tags

Jobs now include tenant tags for easy monitoring:

```bash
# View jobs for specific tenant
php artisan horizon:list --tag=tenant:uuid-here

# Monitor failed jobs per tenant
php artisan horizon:failed --tag=tenant:uuid-here
```

### Logging

All job logs now include `tenant_id`:

```php
Log::info('Job started', [
    'tenant_id' => $this->tenantId,
    'job' => get_class($this),
]);
```

---

## 🎯 Implementation Checklist

### Phase 1: Preparation
- [x] Create `TenantAwareJob` trait
- [x] Create example updated job
- [x] Create test suite
- [x] Document implementation

### Phase 2: Update Jobs (Your Task)
- [ ] Update `CheckExpiredSubscriptionsJob`
- [ ] Update `SendPaymentRemindersJob`
- [ ] Update `ProcessPaymentJob`
- [ ] Update `DisconnectUserJob`
- [ ] Update `ProcessGracePeriodJob`
- [ ] Update remaining 13 jobs

### Phase 3: Update Dispatches
- [ ] Update all `dispatch()` calls in controllers
- [ ] Update all `dispatch()` calls in services
- [ ] Update scheduled commands in `Kernel.php`

### Phase 4: Testing
- [ ] Run test suite
- [ ] Manual testing per tenant
- [ ] Verify no cross-tenant data access
- [ ] Monitor queue in production

### Phase 5: Deployment
- [ ] Deploy changes
- [ ] Restart queue workers
- [ ] Monitor for issues
- [ ] Verify tenant isolation

---

## 📚 Files Created

1. **TenantAwareJob.php** - The trait (ready to use)
2. **CheckExpiredSubscriptionsJob_UPDATED_EXAMPLE.php** - Example implementation
3. **TenantAwareJobsTest.php** - Test suite
4. **QUEUE_MULTI_TENANCY_GUIDE.md** - Complete guide
5. **QUEUE_MULTI_TENANCY_SUMMARY.md** - This file

---

## 🚀 Next Steps

### Immediate Action Required

1. **Review** the example job: `CheckExpiredSubscriptionsJob_UPDATED_EXAMPLE.php`
2. **Update** all 18 jobs using the same pattern
3. **Test** each job after updating
4. **Update** all dispatch calls
5. **Deploy** to production

### Estimated Time

- **Per Job**: 10-15 minutes
- **Total Jobs**: 18
- **Total Time**: 3-4 hours
- **Testing**: 1-2 hours
- **Deployment**: 1 hour

**Total Estimate**: 5-7 hours

---

## 💡 Pro Tips

### 1. Update Jobs in Batches

Group by priority and update in batches:
- Batch 1: Critical (5 jobs) - Deploy immediately
- Batch 2: High (5 jobs) - Deploy next day
- Batch 3: Medium (4 jobs) - Deploy within week
- Batch 4: Low (4 jobs) - Deploy when convenient

### 2. Test Thoroughly

After each batch:
```bash
php artisan test --filter TenantAwareJobsTest
php artisan queue:work --once  # Test one job
```

### 3. Monitor Closely

After deployment:
- Watch Horizon dashboard
- Check logs for tenant_id
- Verify no cross-tenant access
- Monitor failed jobs

---

## 🆘 Support

### If Jobs Fail

1. Check tenant exists: `Tenant::find($tenantId)`
2. Check tenant is active: `$tenant->isActive()`
3. Check logs for tenant_id
4. Verify dispatch includes tenant context

### If Data Leaks Occur

1. Immediately stop queue workers: `php artisan queue:restart`
2. Review job implementation
3. Verify `executeInTenantContext()` is used
4. Check all queries are scoped

### If Performance Issues

1. Consider batching jobs per tenant
2. Use different queues per tenant
3. Monitor queue depth per tenant
4. Optimize job logic

---

## ✅ Summary

**Current State**: ❌ Queue jobs are NOT tenant-aware  
**Risk Level**: 🔴 **CRITICAL** - Data leaks possible  
**Solution**: ✅ `TenantAwareJob` trait provided  
**Action Required**: Update 18 jobs  
**Priority**: 🔴 **URGENT** - Fix before production  
**Time Estimate**: 5-7 hours  

---

**Status**: ⚠️ **ACTION REQUIRED**  
**Priority**: 🔴 **CRITICAL**  
**Deadline**: Before production deployment  
**Owner**: Development Team
