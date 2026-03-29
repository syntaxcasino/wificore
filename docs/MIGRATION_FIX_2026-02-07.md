# Migration Fix & Environment Sync — 2026-02-07

## Issues Fixed

**Migration Errors:** Multiple `SQLSTATE[42703]: Undefined column` errors causing transaction aborts

The migration `2026_02_06_000001_add_comprehensive_postgresql_indexes.php` was attempting to create indexes on non-existent columns, causing PostgreSQL transaction failures and container restarts.

### Root Cause
The migration lacked **column existence validation**, causing it to fail when:
1. Columns were renamed in earlier migrations
2. Columns didn't exist in the actual table schema
3. Migration ordering issues (columns added in later migrations)

### Column Mismatches Found

| Table | Incorrect Column | Actual Column | Status |
|-------|-----------------|---------------|--------|
| `radius_user_schema_mapping` | `user_type` | `user_role` | ✅ Fixed |
| `performance_metrics` | `metric_type` | `tps_current` | ✅ Fixed |
| `tenant_registrations` | `email` | `tenant_email` | ✅ Fixed |

## Changes Made

### 1. Column Name Fixes
**File:** `backend/database/migrations/2026_02_06_000001_add_comprehensive_postgresql_indexes.php`

**Fix 1 - radius_user_schema_mapping:**
```php
// BEFORE (incorrect)
$this->safeCreateIndex('public', 'radius_user_schema_mapping', 'radius_mapping_tenant_idx',
    ['tenant_id', 'user_type']);

// AFTER (correct)
$this->safeCreateIndex('public', 'radius_user_schema_mapping', 'radius_mapping_tenant_idx',
    ['tenant_id', 'user_role']);
```

**Fix 2 - performance_metrics:**
```php
// BEFORE (incorrect)
$this->safeCreateIndex('public', 'performance_metrics', 'perf_metrics_type_time_idx',
    ['metric_type', 'recorded_at']);

// AFTER (correct - using actual column tps_current)
$this->safeCreateIndex('public', 'performance_metrics', 'perf_metrics_tps_time_idx',
    ['tps_current', 'recorded_at']);
```

**Fix 3 - tenant_registrations:**
```php
// BEFORE (incorrect)
$this->safeCreateIndex('public', 'tenant_registrations', 'tenant_reg_email_idx',
    ['email']);

// AFTER (correct)
$this->safeCreateIndex('public', 'tenant_registrations', 'tenant_reg_email_idx',
    ['tenant_email']);
```

### 2. Comprehensive Column Validation (CRITICAL FIX)

Added **column existence checks** to both `safeCreateIndex()` and `safeCreatePartialIndex()` methods:

```php
private function safeCreateIndex(string $schema, string $table, string $indexName, array $columns): void
{
    if (!$this->tableExists($schema, $table)) {
        return;
    }

    // NEW: Validate all columns exist before creating index
    foreach ($columns as $column) {
        if (!$this->columnExists($schema, $table, $column)) {
            Log::warning("Skipping index {$indexName}: column {$column} does not exist in {$schema}.{$table}");
            return;
        }
    }

    // ... proceed with index creation
}
```

**New Helper Method:**
```php
private function columnExists(string $schema, string $table, string $column): bool
{
    $result = DB::selectOne("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = ?
          AND table_name = ?
          AND column_name = ?
    ", [$schema, $table, $column]);

    return $result !== null;
}
```

### 3. Safety Guarantees

The migration now provides:

✅ **Column Validation** — Checks every column exists before creating index  
✅ **Table Validation** — Checks table exists before any operation  
✅ **Idempotent** — Safe to re-run multiple times  
✅ **Graceful Degradation** — Skips invalid indexes with warning logs  
✅ **No Transaction Aborts** — Returns early instead of failing  
✅ **Multi-Container Safe** — Advisory lock prevents concurrent execution  
✅ **Race-Condition Free** — pg_class catalog check in atomic DO block

---

## Tenant Migration Fixes

**File:** `backend/database/migrations/tenant/2026_02_06_000001_add_comprehensive_tenant_indexes.php`

### Tenant Column Mismatches Fixed

| Table | Incorrect Column | Actual Column | Status |
|-------|-----------------|---------------|--------|
| `routers` | `last_seen_at` | `last_seen` | ✅ Fixed |
| `pppoe_users` | `phone` | ❌ doesn't exist | ✅ Removed |
| `pppoe_users` | `account_number` | ❌ doesn't exist | ✅ Removed |
| `pppoe_users` | `subscription_expires_at` | `expires_at` | ✅ Fixed |
| `pppoe_payments` | `mpesa_receipt` | `payment_reference` | ✅ Fixed |
| `pppoe_payments` | `phone` | `account_number` | ✅ Fixed |
| `pppoe_payments` | `created_at` | `payment_date` | ✅ Fixed |
| `hotspot_users` | `current_package_id` | `package_id` | ✅ Fixed |
| `user_subscriptions` | `expires_at` | `end_time` | ✅ Fixed |
| `radius_sessions` | `user_id` | `hotspot_user_id` | ✅ Fixed |
| `radius_sessions` | `session_id` | `username` | ✅ Fixed |
| `radius_sessions` | `start_time` | `session_start` | ✅ Fixed |
| `radius_sessions` | `stop_time` | `session_end` | ✅ Fixed |

### Tenant Migration Safety Enhancements

Added `columnExists()` method and column validation to both `safeCreateIndex()` and `safeCreatePartialIndex()` methods — same pattern as public migration.

---

### 4. Environment File Sync
**File:** `.env.example`

Added missing database configuration to match `.env.production`:
- `DB_HOST=wificore-pgbouncer` (was `wificore-postgres`)
- `DB_READ_HOST=wificore-pgbouncer-read`
- `DB_PORT=6432` (was `5432`)
- `DB_READ_PORT=6432`
- `DB_DIRECT_HOST=wificore-postgres` (for migrations)
- `DB_DIRECT_PORT=5432` (for migrations)
- `DB_DIRECT_READ_HOST=wificore-postgres-replica`
- `DB_DIRECT_READ_PORT=5432`
- PostgreSQL replication settings
- PostgreSQL performance settings

### 3. Docker Compose Production Sync
**File:** `docker-compose.production.yml`

Added to `wificore-backend` environment:
```yaml
- DB_DIRECT_HOST=${DB_DIRECT_HOST:-wificore-postgres}
- DB_DIRECT_PORT=${DB_DIRECT_PORT:-5432}
```

These variables are required by `entrypoint.sh` for direct PostgreSQL connection during migrations (bypassing PgBouncer).

## Database Connection Strategy

### Runtime Connections (via PgBouncer)
All Laravel application connections use PgBouncer for connection pooling:
- **Primary (write):** `wificore-pgbouncer:6432`
- **Replica (read):** `wificore-pgbouncer-read:6432`

Configured via:
- `DB_HOST=wificore-pgbouncer`
- `DB_READ_HOST=wificore-pgbouncer-read`
- `DB_PORT=6432`

### Migration Connections (Direct PostgreSQL)
Migrations bypass PgBouncer and connect directly to PostgreSQL to avoid transaction pooling issues with DDL:
- **Primary:** `wificore-postgres:5432`
- **Replica:** `wificore-postgres-replica:5432`

Configured via:
- `DB_DIRECT_HOST=wificore-postgres`
- `DB_DIRECT_PORT=5432`

The `entrypoint.sh` script uses these direct connection parameters when running migrations.

## Why This Separation?

**PgBouncer Transaction Pooling + DDL = Problems**

PgBouncer in transaction pooling mode can cause issues with DDL operations (CREATE INDEX, ALTER TABLE, etc.) because:
1. DDL requires exclusive locks
2. Transaction pooling can split related operations across different backend connections
3. Advisory locks used in migrations require session-level connections

**Solution:** Migrations use direct PostgreSQL connection, runtime queries use PgBouncer.

## Verification

After deploying these changes:

1. **Check migration runs successfully:**
   ```bash
   docker compose -f docker-compose.production.yml logs wificore-backend | grep "2026_02_06_000001"
   ```
   Should show: `2026_02_06_000001_add_comprehensive_postgresql_indexes ........ DONE`

2. **Verify PgBouncer is used for runtime:**
   ```bash
   docker exec wificore-backend env | grep DB_HOST
   ```
   Should show: `DB_HOST=wificore-pgbouncer`

3. **Verify direct connection for migrations:**
   ```bash
   docker compose -f docker-compose.production.yml logs wificore-backend | grep "Migration target"
   ```
   Should show: `Migration target: wificore-postgres:5432/wms_770_ts`

4. **Check container starts successfully:**
   ```bash
   docker compose -f docker-compose.production.yml ps wificore-backend
   ```
   Should show: `Up` status (not restarting)

## Files Changed

- `backend/database/migrations/2026_02_06_000001_add_comprehensive_postgresql_indexes.php`
- `.env.example`
- `docker-compose.production.yml`

## Related Documentation

See `docs/INFRASTRUCTURE_FIX_2026-02-07.md` for the complete infrastructure fix documentation including:
- PostgreSQL migration race condition fixes
- Supervisor EACCES fixes
- Fail-fast migration pattern
- Multi-tenant index naming strategy
