# PostgreSQL 18 Boolean Type Compatibility Fix

## Problem

PostgreSQL 18 introduced strict type checking that rejects `boolean = integer` comparisons. When Laravel uses PDO with emulated prepares enabled, PHP sends boolean values as integers (0/1), causing this error:

```
SQLSTATE[42883]: Undefined function: 7 ERROR: operator does not exist: boolean = integer
LINE 1: select * from "tenants" where "is_active" = 1 and "tenants"....
HINT: No operator matches the given name and argument types. You might need to add explicit type casts.
```

## Root Cause

1. **PDO Emulated Prepares**: When `PDO::ATTR_EMULATE_PREPARES => true`, PHP converts boolean values to integers (true → 1, false → 0) before sending to PostgreSQL
2. **PostgreSQL 18 Strict Typing**: PostgreSQL 18 enforces strict type matching and doesn't automatically cast integers to booleans in comparisons
3. **Widespread Impact**: Affects ALL queries with boolean columns (`is_active`, `is_public`, `is_enabled`, etc.)

## Solution

### 1. Disable PDO Emulated Prepares

**File**: `backend/config/database.php`

```php
'options' => [
    // CRITICAL: Disable prepared statement emulation for PostgreSQL 18 compatibility
    // PostgreSQL 18 has strict type checking and emulated prepares send integers
    // for boolean values, causing "operator does not exist: boolean = integer" errors
    // PgBouncer session pooling mode is required when this is false
    PDO::ATTR_EMULATE_PREPARES => false,
    // ... other options
],
```

### 2. Switch PgBouncer to Session Mode

**File**: `pgbouncer/pgbouncer.ini`

```ini
# Connection pooling settings
# Session mode required for PostgreSQL 18 with native prepared statements
pool_mode = session
```

**Why Session Mode?**
- Native prepared statements (when `ATTR_EMULATE_PREPARES => false`) require the same database connection throughout the session
- Transaction mode reuses connections across different clients, breaking prepared statements
- Session mode dedicates one server connection per client connection

## Deployment Steps

### Automated Fix (Recommended)

```bash
cd /opt/wificore
chmod +x scripts/fix-postgresql18-boolean-issue.sh
./scripts/fix-postgresql18-boolean-issue.sh
```

### Manual Fix

```bash
# 1. Stop backend
docker compose -f docker-compose.production.yml stop wificore-backend

# 2. Update database config
docker cp backend/config/database.php wificore-backend:/var/www/html/config/database.php

# 3. Update PgBouncer configs
docker cp pgbouncer/pgbouncer.ini wificore-pgbouncer:/etc/pgbouncer/pgbouncer.ini
docker cp pgbouncer/pgbouncer.ini wificore-pgbouncer-read:/etc/pgbouncer/pgbouncer.ini

# 4. Restart PgBouncer
docker compose -f docker-compose.production.yml restart wificore-pgbouncer wificore-pgbouncer-read

# 5. Start backend
docker compose -f docker-compose.production.yml start wificore-backend

# 6. Verify
docker logs -f wificore-backend
```

## Verification

### Test Boolean Queries

```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker
```

```php
// Should work without errors
App\Models\Tenant::where('is_active', true)->count();
App\Models\Package::where('is_public', false)->count();
App\Models\Router::where('is_enabled', true)->first();
```

### Check Logs

```bash
# Should see no more "boolean = integer" errors
docker compose -f docker-compose.production.yml exec wificore-backend tail -f storage/logs/laravel.log
```

## Performance Impact

### Session Mode vs Transaction Mode

| Aspect | Transaction Mode | Session Mode |
|--------|------------------|--------------|
| Connection Reuse | High (across clients) | Medium (per client) |
| Prepared Statements | Not preserved | Preserved |
| Memory Usage | Lower | Slightly higher |
| PostgreSQL 18 | ❌ Incompatible | ✅ Compatible |

**Impact**: Minimal performance difference for most workloads. Session mode uses slightly more server connections but is required for PostgreSQL 18 compatibility.

## Affected Models

All models with boolean columns are affected:

- `Tenant` (is_active)
- `Package` (is_public, is_enabled)
- `Router` (is_enabled, is_active)
- `User` (is_active, is_verified)
- `HotspotSession` (is_active)
- And many more...

## Rollback

If issues occur, revert to emulated prepares (not recommended):

```php
// backend/config/database.php
PDO::ATTR_EMULATE_PREPARES => true,
```

```ini
# pgbouncer/pgbouncer.ini
pool_mode = transaction
```

Then restart services. **Note**: This will bring back the boolean=integer errors.

## Alternative Solutions (Not Recommended)

1. **Cast in Queries**: `where('is_active', DB::raw('true'))` - requires changing thousands of queries
2. **Custom Operator**: Create PostgreSQL operator - dangerous and non-standard
3. **Downgrade PostgreSQL**: Not viable for production

## References

- [PostgreSQL 18 Release Notes](https://www.postgresql.org/docs/18/release-18.html)
- [PDO Prepared Statements](https://www.php.net/manual/en/pdo.prepared-statements.php)
- [PgBouncer Pooling Modes](https://www.pgbouncer.org/config.html#pool_mode)

## Status

- ✅ Fix implemented
- ✅ Tested on production
- ✅ Documentation complete
- ✅ Deployment script ready
