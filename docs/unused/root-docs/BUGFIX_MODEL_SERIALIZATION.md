# Model Serialization Fix - DeployRouterServiceJob

## Problem
The `DeployRouterServiceJob` was using the `SerializesModels` trait, which caused serialization errors when queuing jobs in a multi-tenant schema-based architecture.

## Error Message
```
Method Illuminate\Database\Schema\Blueprint::inet does not exist.
```

Additionally, model serialization issues were occurring when the job tried to serialize `RouterService` models across different tenant schemas.

## Root Cause

### 1. SerializesModels Trait Issue
The job was using `SerializesModels` trait:
```php
class DeployRouterServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;
```

**Problem:** When Laravel serializes models with `SerializesModels`, it stores model state that can become stale or invalid when:
- The model exists in a tenant schema
- The job is executed in a different schema context
- Schema switching occurs between job dispatch and execution

### 2. Migration inet() Column Type
The migration used `inet()` method which doesn't exist in Laravel's Schema Builder:
```php
$table->inet('gateway_ip');
```

**Problem:** Laravel doesn't provide a native `inet()` column type method. This is PostgreSQL-specific and must be defined differently.

## Solution

### 1. Remove SerializesModels Trait
**File:** `backend/app/Jobs/DeployRouterServiceJob.php`

**Before:**
```php
class DeployRouterServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;
```

**After:**
```php
class DeployRouterServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;
```

**Why this works:**
- Job already accepts IDs (`serviceId`, `tenantId`) in constructor
- Models are loaded fresh inside `executeInTenantContext()`
- No model state is serialized to the queue
- Follows the established pattern from other tenant-aware jobs

### 2. Replace inet() with string()
**File:** `backend/database/migrations/tenant/2026_01_07_000001_create_tenant_ip_pools_table.php`

**Before:**
```php
$table->inet('gateway_ip');
$table->inet('range_start');
$table->inet('range_end');
$table->inet('dns_primary')->nullable();
$table->inet('dns_secondary')->nullable();
```

**After:**
```php
$table->string('gateway_ip', 45);
$table->string('range_start', 45);
$table->string('range_end', 45);
$table->string('dns_primary', 45)->nullable();
$table->string('dns_secondary', 45)->nullable();
```

**Why this works:**
- `string(45)` accommodates both IPv4 (15 chars) and IPv6 (39 chars) addresses
- Application-level validation ensures IP format correctness
- PostgreSQL can still index and query these efficiently
- Avoids dependency on database-specific column types

## Pattern to Follow

### For All Tenant-Aware Jobs:

1. **Accept IDs, not Models:**
```php
protected int $serviceId;
protected string $tenantId;

public function __construct(int $serviceId, string $tenantId)
{
    $this->serviceId = $serviceId;
    $this->tenantId = $tenantId;
}
```

2. **Load Models Inside Tenant Context:**
```php
public function handle(): void
{
    $this->executeInTenantContext(function () {
        $service = RouterService::with(['router', 'ipPool', 'vlans'])
            ->find($this->serviceId);
        
        // Work with fresh model
    });
}
```

3. **Never Use SerializesModels:**
```php
// ❌ BAD
use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

// ✅ GOOD
use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;
```

## Jobs Following This Pattern

The following jobs already implement this pattern correctly:
- `DisconnectUserJob`
- `ReconnectUserJob`
- `FetchRouterLiveData`
- `RouterProvisioningJob`
- `RouterProbingJob`
- `DeployRouterServiceJob` (now fixed)

## Testing

After applying this fix:

1. **Clear Failed Jobs:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan queue:flush
```

2. **Restart Queue Workers:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl restart all
```

3. **Run Migrations:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan migrate --path=database/migrations/tenant --force
```

4. **Test Service Deployment:**
- Create a router service via API
- Deploy the service
- Verify job executes without serialization errors

## Verification

Check logs for successful execution:
```bash
docker compose -f docker-compose.production.yml exec wificore-backend cat storage/logs/laravel.log | grep "DeployRouterServiceJob"
```

Expected output:
```
[timestamp] production.INFO: Starting service deployment {"service_id":X,"router_id":Y,"service_type":"hotspot"}
[timestamp] production.INFO: Service deployed successfully {"service_id":X,"router_id":Y}
```

## Related User Rules

This fix adheres to:
- **User Rule #1:** All jobs should be tenant aware
- **User Rule #6:** Always do an in-depth end-to-end check before editing code
- **User Rule #7:** Do not modify any working feature

## References

- Memory: `ce8b5c34-41c2-483d-84da-fa2f80fbd046` - Refactored critical background jobs pattern
- Commit: `936b347` - Fix model serialization and inet column type
- Documentation: `ZERO_CONFIG_PROVISIONING.md`

---

**Status:** ✅ Fixed and Deployed
**Date:** January 7, 2026
**Impact:** Critical - Enables zero-config service deployment
