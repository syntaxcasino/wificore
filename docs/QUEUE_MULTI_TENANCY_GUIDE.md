# Queue Multi-Tenancy Implementation Guide

## ⚠️ Critical Issue Identified

**Current Status**: Queue jobs are **NOT tenant-aware** by default. This means:
- ❌ Jobs process data across ALL tenants
- ❌ No tenant isolation in background processing
- ❌ Potential data leaks through queue workers

## ✅ Solution Implemented

### 1. TenantAwareJob Trait

Created `App\Traits\TenantAwareJob` to make jobs tenant-aware.

**Features:**
- Stores tenant context with the job
- Executes job within tenant scope
- Validates tenant is active
- Adds tenant tags for monitoring

### 2. How to Update Existing Jobs

#### Before (Not Tenant-Aware)
```php
class CheckExpiredSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SubscriptionManager $manager): void
    {
        // This queries ALL tenants!
        $subscriptions = $manager->getExpiredSubscriptions();
        // ...
    }
}
```

#### After (Tenant-Aware)
```php
class CheckExpiredSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;  // Add this

    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);  // Set tenant context
        $this->onQueue('payment-checks');
    }

    public function handle(SubscriptionManager $manager): void
    {
        // Execute within tenant context
        $this->executeInTenantContext(function() use ($manager) {
            // Now queries are scoped to this tenant only!
            $subscriptions = $manager->getExpiredSubscriptions();
            // ...
        });
    }
}
```

### 3. Dispatching Tenant-Aware Jobs

#### Option A: Dispatch for Current Tenant
```php
// In controller or service
CheckExpiredSubscriptionsJob::dispatch()
    ->setTenantContext(auth()->user()->tenant_id);
```

#### Option B: Dispatch for Specific Tenant
```php
// Process for specific tenant
CheckExpiredSubscriptionsJob::dispatch()
    ->setTenantContext($tenantId);
```

#### Option C: Dispatch for All Tenants (System Admin)
```php
// In scheduled command
$tenants = Tenant::active()->get();

foreach ($tenants as $tenant) {
    CheckExpiredSubscriptionsJob::dispatch()
        ->setTenantContext($tenant->id);
}
```

---

## 📋 Jobs That Need Updating

### High Priority (Data Sensitive)

1. **CheckExpiredSubscriptionsJob** ⚠️
   - Processes subscriptions across tenants
   - **Risk**: Could disconnect wrong tenant's users
   - **Action**: Add TenantAwareJob trait

2. **SendPaymentRemindersJob** ⚠️
   - Sends reminders to users
   - **Risk**: Could send reminders to wrong tenant's users
   - **Action**: Add TenantAwareJob trait

3. **ProcessPaymentJob** ⚠️
   - Processes payments
   - **Risk**: Could provision wrong tenant's resources
   - **Action**: Add TenantAwareJob trait

4. **DisconnectUserJob** ⚠️
   - Disconnects users from service
   - **Risk**: Could disconnect wrong tenant's users
   - **Action**: Add TenantAwareJob trait

5. **ProcessGracePeriodJob** ⚠️
   - Manages grace periods
   - **Risk**: Cross-tenant grace period handling
   - **Action**: Add TenantAwareJob trait

### Medium Priority (Resource Management)

6. **CheckRoutersJob**
   - Checks router status
   - **Risk**: Could affect wrong tenant's routers
   - **Action**: Add TenantAwareJob trait

7. **RouterProvisioningJob**
   - Provisions routers
   - **Risk**: Cross-tenant provisioning
   - **Action**: Add TenantAwareJob trait

8. **SyncAccessPointStatusJob**
   - Syncs AP status
   - **Risk**: Cross-tenant AP management
   - **Action**: Add TenantAwareJob trait

9. **UpdateVpnStatusJob**
   - Updates VPN status
   - **Risk**: Cross-tenant VPN updates
   - **Action**: Add TenantAwareJob trait

### Low Priority (Monitoring/Stats)

10. **UpdateDashboardStatsJob**
    - Updates dashboard statistics
    - **Risk**: Mixed tenant statistics
    - **Action**: Add TenantAwareJob trait OR make system-wide

11. **SyncRadiusAccountingJob**
    - Syncs RADIUS data
    - **Risk**: Cross-tenant accounting
    - **Action**: Add TenantAwareJob trait

12. **CheckExpiredSessionsJob**
    - Checks expired sessions
    - **Risk**: Cross-tenant session management
    - **Action**: Add TenantAwareJob trait

---

## 🔧 Implementation Steps

### Step 1: Update Job Classes

For each job, add the trait and update constructor:

```php
<?php

namespace App\Jobs;

use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class YourJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;  // Add this

    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('your-queue');
    }

    public function handle(): void
    {
        $this->executeInTenantContext(function() {
            // Your job logic here
            // All queries are now scoped to tenant
        });
    }
}
```

### Step 2: Update Scheduled Commands

Update `app/Console/Kernel.php` to dispatch jobs per tenant:

```php
protected function schedule(Schedule $schedule): void
{
    // Check expired subscriptions for each tenant
    $schedule->call(function () {
        $tenants = \App\Models\Tenant::active()->get();
        
        foreach ($tenants as $tenant) {
            \App\Jobs\CheckExpiredSubscriptionsJob::dispatch()
                ->setTenantContext($tenant->id);
        }
    })->hourly();

    // Send payment reminders for each tenant
    $schedule->call(function () {
        $tenants = \App\Models\Tenant::active()->get();
        
        foreach ($tenants as $tenant) {
            \App\Jobs\SendPaymentRemindersJob::dispatch()
                ->setTenantContext($tenant->id);
        }
    })->daily();
}
```

### Step 3: Update Job Dispatches in Controllers

```php
// Before
ProcessPaymentJob::dispatch($payment);

// After
ProcessPaymentJob::dispatch($payment)
    ->setTenantContext(auth()->user()->tenant_id);
```

---

## 🧪 Testing Tenant-Aware Jobs

### Test Case 1: Job Isolation

```php
public function test_job_only_processes_tenant_data()
{
    // Create two tenants
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    // Create subscriptions for each
    $sub1 = UserSubscription::factory()->create(['tenant_id' => $tenant1->id]);
    $sub2 = UserSubscription::factory()->create(['tenant_id' => $tenant2->id]);

    // Dispatch job for tenant 1
    CheckExpiredSubscriptionsJob::dispatch()
        ->setTenantContext($tenant1->id);

    // Job should only process tenant 1's subscriptions
    // Verify tenant 2's data is untouched
}
```

### Test Case 2: System-Wide Jobs

```php
public function test_system_admin_can_process_all_tenants()
{
    $tenants = Tenant::factory()->count(3)->create();

    foreach ($tenants as $tenant) {
        CheckExpiredSubscriptionsJob::dispatch()
            ->setTenantContext($tenant->id);
    }

    // Verify all tenants processed separately
}
```

---

## 🚨 Critical Warnings

### 1. Never Dispatch Without Tenant Context

❌ **WRONG:**
```php
// This will process ALL tenants!
CheckExpiredSubscriptionsJob::dispatch();
```

✅ **CORRECT:**
```php
// Always specify tenant
CheckExpiredSubscriptionsJob::dispatch()
    ->setTenantContext($tenantId);
```

### 2. System-Wide Jobs Need Special Handling

For jobs that genuinely need to process all tenants:

```php
class SystemWideMaintenanceJob implements ShouldQueue
{
    // Don't use TenantAwareJob trait
    
    public function handle(): void
    {
        // Explicitly iterate tenants
        $tenants = Tenant::active()->get();
        
        foreach ($tenants as $tenant) {
            // Process each tenant separately
            $this->processTenant($tenant);
        }
    }
    
    private function processTenant(Tenant $tenant): void
    {
        // Set auth context for tenant scoping
        Auth::setUser(new User([
            'tenant_id' => $tenant->id,
            'role' => 'admin'
        ]));
        
        try {
            // Do work
        } finally {
            Auth::logout();
        }
    }
}
```

### 3. Queue Worker Configuration

Ensure queue workers don't cache tenant context:

```bash
# Restart workers after tenant changes
php artisan queue:restart

# Or use horizon with auto-restart
php artisan horizon
```

---

## 📊 Monitoring Tenant-Aware Jobs

### Horizon Tags

Jobs now include tenant tags:

```php
public function tags(): array
{
    return [
        'tenant:' . $this->tenantId,
        'payment:' . $this->payment->id,
    ];
}
```

### Monitor by Tenant

```bash
# View jobs for specific tenant
php artisan horizon:list --tag=tenant:uuid-here
```

### Metrics Per Tenant

```php
// In system admin dashboard
$tenantJobStats = DB::table('jobs')
    ->where('payload', 'like', '%tenant:' . $tenantId . '%')
    ->count();
```

---

## 🔄 Migration Strategy

### Phase 1: Add Trait (No Breaking Changes)
1. Add `TenantAwareJob` trait to all jobs
2. Update constructors to accept `$tenantId`
3. Wrap handle() logic in `executeInTenantContext()`
4. **Deploy** - Jobs still work, but not yet tenant-scoped

### Phase 2: Update Dispatches
1. Update all `dispatch()` calls to include tenant context
2. Update scheduled commands to iterate tenants
3. **Test thoroughly**
4. **Deploy**

### Phase 3: Enforce Tenant Context
1. Make `$tenantId` required in constructors
2. Throw exception if tenant context missing
3. **Deploy**

---

## ✅ Checklist

- [ ] Add `TenantAwareJob` trait to all jobs
- [ ] Update job constructors
- [ ] Wrap handle() logic in `executeInTenantContext()`
- [ ] Update all dispatch() calls
- [ ] Update scheduled commands
- [ ] Add tests for tenant isolation
- [ ] Update queue monitoring
- [ ] Document queue behavior
- [ ] Train team on tenant-aware jobs

---

## 📚 Additional Resources

- **Trait Location**: `app/Traits/TenantAwareJob.php`
- **Example Jobs**: See updated job files
- **Tests**: `tests/Feature/TenantAwareJobsTest.php` (to be created)
- **Monitoring**: Laravel Horizon with tenant tags

---

## 🎯 Summary

**Current State**: ❌ Jobs are NOT tenant-aware
**Required Action**: ✅ Add `TenantAwareJob` trait to all jobs
**Priority**: 🔴 **CRITICAL** - Must fix before production
**Estimated Time**: 2-4 hours to update all jobs
**Risk**: High - Data leaks possible without this fix

---

**Status**: ⚠️ **ACTION REQUIRED**  
**Priority**: 🔴 **CRITICAL**  
**Next Step**: Update all jobs with `TenantAwareJob` trait
