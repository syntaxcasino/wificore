# Fix PPPoE Portal Login Issue

## Problem
PPPoE users cannot log in to the portal, getting "User not found" errors for accounts like TRAP00001, TRAP00003.

## Root Cause
The PPPoE portal login uses `findTenantByAccountNumber()` which matches the account prefix with the tenant's `account_prefix` field. If no tenant has the "TRAP" prefix, the lookup fails.

## Solution Steps

### 1. Check and Fix Tenant Account Prefix

Run this command in production to check and fix the tenant prefix:

```bash
# In production environment
docker compose -f docker-compose.production.yml exec backend php artisan tinker

# Then run:
$tenant = DB::table('tenants')->where('account_prefix', 'TRAP')->first();
if (!$tenant) {
    $tenant = DB::table('tenants')->where('is_active', true)->whereNull('account_prefix')->first();
    if ($tenant) {
        DB::table('tenants')->where('id', $tenant->id)->update(['account_prefix' => 'TRAP']);
        echo "Updated tenant {$tenant->name} with TRAP prefix\n";
    } else {
        echo "No tenant found to update with TRAP prefix\n";
    }
} else {
    echo "Tenant with TRAP prefix already exists: {$tenant->name}\n";
}
```

### 2. Verify PPPoE Users Exist

Check if the PPPoE users exist in the correct tenant schema:

```sql
-- Replace 'tenant_schema_name' with actual schema name
SELECT * FROM tenant_schema_name.pppoe_users 
WHERE account_number = 'TRAP00001' OR username = 'TRAP00001';
```

### 3. If Users Don't Exist, Create Them

If the PPPoE users don't exist, they need to be created in the correct tenant schema with the proper account numbering.

## Alternative: Dynamic Prefix Mapping

If multiple tenants need to use TRAP prefix, modify the `findTenantByAccountNumber` method to be more flexible:

```php
// In PppoePortalController.php
private function findTenantByAccountNumber(string $accountNumber): ?Tenant
{
    $normalized = strtoupper(trim($accountNumber));
    if ($normalized === '') {
        return null;
    }

    // Special handling for TRAP prefix - map to default tenant
    if (str_starts_with($normalized, 'TRAP')) {
        return Tenant::query()
            ->where('is_active', true)
            ->whereNotNull('schema_name')
            ->orderBy('created_at') // Get first/oldest tenant
            ->first(['id', 'schema_name', 'account_prefix']);
    }

    return Tenant::query()
        ->where('is_active', true)
        ->whereNotNull('schema_name')
        ->whereNotNull('account_prefix')
        ->whereRaw('? LIKE UPPER(account_prefix) || \'%\'', [$normalized])
        ->orderByRaw('LENGTH(account_prefix) DESC')
        ->first(['id', 'schema_name', 'account_prefix']);
}
```

## Testing

After applying the fix, test the portal login with:
- Account Number: TRAP00001
- Password: [the correct portal password]

The login should now work and return a successful authentication token.
