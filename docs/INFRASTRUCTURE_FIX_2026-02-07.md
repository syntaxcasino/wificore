# Critical Infrastructure Fix — 2026-02-07

## Issues Fixed

### 1. PostgreSQL Migration Index Conflict (`SQLSTATE[23505]`)

**Error:** `duplicate key value violates unique constraint "pg_class_relname_nsp_index"`

**Root Cause:** `CREATE INDEX IF NOT EXISTS` has a race condition in PostgreSQL. The `IF NOT EXISTS` check and the catalog insertion are not atomic across concurrent sessions. When two containers run migrations simultaneously (or a container restarts mid-migration), the second session's `CREATE INDEX` hits a duplicate key violation in `pg_class`.

Contributing factors:
- PgBouncer transaction pooling can split `Schema::hasTable()` and `DB::statement()` onto different backend connections
- `statement_timeout = 30000` (30s) can kill long index creation, leaving ghost entries in `pg_class`
- Entrypoint silently continued after migration failure, causing partial schema state

**Fix Applied:**
- **PL/pgSQL `DO $$ ... $$` blocks** with `pg_class` catalog check + `EXCEPTION WHEN duplicate_table` handler in both public and tenant migrations
- **Advisory locks** (`pg_advisory_lock`) at the migration level to serialize concurrent execution
- **`SET LOCAL statement_timeout = '300s'`** inside migrations to prevent timeout during index creation
- **`tableExists()` via `pg_class`** instead of `Schema::hasTable()` to bypass PgBouncer search_path issues

**Files Changed:**
- `backend/database/migrations/2026_02_06_000001_add_comprehensive_postgresql_indexes.php`
- `backend/database/migrations/tenant/2026_02_06_000001_add_comprehensive_tenant_indexes.php`

### 2. Supervisor EACCES (`spawnerr: unknown error making dispatchers`)

**Error:** `EACCES` when starting php-fpm and laravel-scheduler

**Root Cause:** `supervisord.conf` had `user=www-data` in the `[supervisord]` section, causing supervisord to drop privileges to www-data immediately. As www-data, it cannot:
- Start php-fpm (needs root to manage worker processes)
- Create dispatcher pipes for root-owned processes
- The `user=root` in php-fpm.conf was silently ignored

Additionally, `laravel-scheduler.conf` was missing a `user=` directive entirely.

**Fix Applied:**
- Removed `user=www-data` from `[supervisord]` section — supervisord now runs as root
- Moved socket/pid/log paths from `/tmp` to `/var/run` and `/var/log/supervisor`
- Added `user=www-data` to `laravel-scheduler.conf`
- php-fpm stays `user=root` (it manages its own privilege dropping via pool config)
- All queue workers already had `user=www-data` — unchanged

**Files Changed:**
- `backend/supervisor/supervisord.conf`
- `backend/supervisor/laravel-scheduler.conf`
- `backend/supervisor/php-fpm.conf`

### 3. PHP-FPM Configuration

**Issue:** Missing explicit `user`/`group` directives and log paths.

**Fix Applied:**
- Added `user = www-data` and `group = www-data` to pool config
- Added `listen = 9000` explicitly
- Added `slowlog` for performance debugging
- Removed `--fpm-config` flag from supervisor command (uses default config loading order)

**Files Changed:**
- `backend/docker/php-fpm-custom.conf`

### 4. Entrypoint — Fail-Fast + Advisory Lock Migrations

**Issue:** Migration failure caused "Continuing without migrations…" — silent schema drift.

**Fix Applied:**
- **Fail-fast:** Migration failure now causes `exit 1` — container will not start with incomplete schema
- **Advisory lock** (`pg_try_advisory_lock(999999)`) ensures only one container runs migrations; others wait
- **Direct PostgreSQL connection** for DDL operations (bypasses PgBouncer via `DB_DIRECT_HOST`)
- **Pre-creates ALL log files** (supervisor queue logs, php-fpm logs, scheduler log) as www-data
- DB connection failure is now also fatal (`exit 1`)

**Files Changed:**
- `backend/docker/entrypoint.sh`
- `backend/Dockerfile`

### 5. PostgreSQL Configuration

**Issue:** `postgresql.conf` file existed but was not mounted/applied. Actual config came from `-c` flags in docker-compose. Config warning from potential duplicate parameters.

**Fix Applied:**
- Documented `postgresql.conf` as **reference file only** (not mounted)
- Added production safety timeouts to docker-compose command flags:
  - `statement_timeout=30000` (30s — prevents runaway queries)
  - `idle_in_transaction_session_timeout=300000` (5min — kills idle transactions holding locks)
  - `lock_timeout=10000` (10s — fail fast on DDL lock contention)
- Synced changes to `docker-compose.production.yml`

**Files Changed:**
- `postgres/postgresql.conf`
- `docker-compose.yml`
- `docker-compose.production.yml`

---

## Multi-Tenant Index Naming Strategy

### Problem
In schema-based multi-tenancy, each tenant schema has its own indexes. PostgreSQL index names must be unique **within a schema** (enforced by `pg_class_relname_nsp_index`). Two risks:
1. **Same index name in different schemas** — this is SAFE (PostgreSQL allows it)
2. **Same index name in the same schema** — this causes the `SQLSTATE[23505]` error

### Safe Pattern (Implemented)
```php
// 1. Advisory lock prevents concurrent migration execution
DB::statement('SELECT pg_advisory_lock(' . self::ADVISORY_LOCK_KEY . ')');

// 2. PL/pgSQL DO block with pg_class check — single atomic statement
DB::statement("
    DO \$\$
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = '{$indexName}'
              AND n.nspname = '{$schema}'
        ) THEN
            CREATE INDEX \"{$indexName}\" ON \"{$schema}\".\"{$table}\" ({$columnList});
        END IF;
    EXCEPTION WHEN duplicate_table THEN
        NULL;  -- Another session raced us — safe to ignore
    END;
    \$\$;
");

// 3. Advisory lock released in finally block
DB::statement('SELECT pg_advisory_unlock(' . self::ADVISORY_LOCK_KEY . ')');
```

### Naming Convention
- **Public schema:** `{table}_{purpose}_idx` (e.g., `tenants_subscription_status_idx`)
- **Tenant schema:** Same pattern — safe because each tenant has its own schema namespace
- **Advisory lock keys:** Unique per migration file (`206000001` for public, `206000002` for tenant)
- **Tenant lock key formula:** `ADVISORY_LOCK_KEY + crc32(schema) % 100000` — unique per tenant schema

### Rules for Future Migrations
1. **NEVER use `CREATE INDEX IF NOT EXISTS`** directly — it has a race condition
2. **ALWAYS use the `safeCreateIndex()` helper** with PL/pgSQL `DO $$ ... $$` blocks
3. **ALWAYS use `tableExists()` via `pg_class`** instead of `Schema::hasTable()` (PgBouncer safe)
4. **ALWAYS acquire an advisory lock** if the migration creates indexes
5. **ALWAYS use `SET LOCAL statement_timeout = '300s'`** for index creation migrations
6. **Run DDL migrations via direct PostgreSQL** (bypass PgBouncer) — the entrypoint handles this

---

## Environment Variables

### New Variables (add to `.env` / `.env.production`)
```bash
# Direct PostgreSQL host for DDL operations (bypass PgBouncer)
# The entrypoint uses this for migrations to avoid transaction pooling issues
DB_DIRECT_HOST=wificore-postgres
DB_DIRECT_PORT=5432
```

These are already referenced in the docker-compose.yml backend environment section.

---

## Verification Checklist

After deploying these changes:

1. **Rebuild containers:** `docker-compose up --build -d`
2. **Check supervisord starts as root:** `docker exec wificore-backend ps aux | grep supervisord`
3. **Check php-fpm runs:** `docker exec wificore-backend supervisorctl status php-fpm`
4. **Check queue workers run as www-data:** `docker exec wificore-backend ps aux | grep queue:work`
5. **Check migration lock:** `docker logs wificore-backend 2>&1 | grep "Migration lock"`
6. **Check no EACCES errors:** `docker logs wificore-backend 2>&1 | grep -i eacces` (should be empty)
7. **Check PostgreSQL timeouts applied:** `docker exec wificore-postgres psql -U admin -d wms_770_ts -c "SHOW statement_timeout; SHOW idle_in_transaction_session_timeout; SHOW lock_timeout;"`
