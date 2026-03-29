# Tenant Creation and Credential Email Fix

## Date: December 31, 2025

## Issues Fixed

### 1. **Missing tenant_schema_migrations Table**
**Error:** `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "tenant_schema_migrations" does not exist`

**Root Cause:**
- The `tenant_schema_migrations` table was referenced in `TenantMigrationManager` but no migration existed to create it
- This table tracks which migrations have been executed for each tenant schema

**Fix:**
- Created migration: `2024_01_01_000001_create_tenant_schema_migrations_table.php`
- Table structure:
  ```sql
  CREATE TABLE tenant_schema_migrations (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    migration VARCHAR(255) NOT NULL,
    batch INTEGER NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )
  ```

### 2. **UpdateDashboardStatsJob Failing on New Tenants**
**Error:** `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "routers" does not exist`

**Root Cause:**
- `UpdateDashboardStatsJob` was querying tenant tables immediately after tenant creation
- Tenant schema migrations were still running or incomplete
- No validation to check if tenant schema was fully set up

**Fix:**
- Added schema validation checks in `UpdateDashboardStatsJob`:
  ```php
  // Check if tenant schema is fully created
  if (!$tenant || !$tenant->schema_created) {
      Log::info('Tenant schema not fully created yet, skipping stats update');
      return;
  }
  
  // Verify routers table exists before querying
  $routersTableExists = DB::select("
      SELECT EXISTS (
          SELECT FROM information_schema.tables 
          WHERE table_schema = ? 
          AND table_name = 'routers'
      )
  ", [$tenant->schema_name]);
  
  if (!$routersTableExists[0]->exists) {
      Log::warning('Routers table does not exist in tenant schema yet');
      return;
  }
  ```

### 3. **TenantMigrationManager Error Handling**
**Issue:** Insufficient logging when schema creation failed

**Fix:**
- Enhanced error logging in `setupTenantSchema()`:
  - Added detailed logs at each step (schema creation, permissions, migrations)
  - Improved error messages with tenant context
  - Added trace logging for exceptions

### 4. **Credential Email Template**
**Status:** ✅ Verified working

**Template Location:** `backend/resources/views/emails/tenant-credentials.blade.php`

**Features:**
- Professional HTML email design
- Clear credential display (username, password, login URL)
- Security warnings
- Getting started steps
- Mobile responsive

## Files Modified

### 1. **backend/database/migrations/2024_01_01_000001_create_tenant_schema_migrations_table.php** (NEW)
Created migration for tenant schema migrations tracking table.

### 2. **backend/app/Jobs/UpdateDashboardStatsJob.php**
- Added tenant schema validation before querying
- Added table existence checks
- Graceful handling of incomplete tenant schemas

### 3. **backend/app/Services/TenantMigrationManager.php**
- Enhanced logging throughout schema setup process
- Better error messages with full context
- Added trace logging for debugging

## Tenant Creation Flow

```
1. User verifies email
   ↓
2. TenantRegistrationController dispatches CreateTenantWorkspaceJob
   ↓
3. CreateTenantWorkspaceJob:
   a. Creates Tenant record
   b. Creates Admin User
   c. Calls TenantMigrationManager.setupTenantSchema()
      - Creates PostgreSQL schema
      - Grants permissions
      - Runs tenant migrations
      - Sets schema_created = true
   d. Creates RADIUS credentials
   e. Initializes VPN tunnel
   f. Dispatches SendCredentialsEmailJob
   ↓
4. SendCredentialsEmailJob:
   - Sends email with credentials
   - Marks credentials_sent = true
   - Clears password from registration
```

## Testing Checklist

- [x] tenant_schema_migrations table exists
- [x] Tenant schema creation completes successfully
- [x] UpdateDashboardStatsJob handles incomplete schemas
- [x] Credential email template renders correctly
- [x] Failed jobs cleared and retried
- [ ] New tenant creation end-to-end test
- [ ] Credential email received successfully

## Queue Workers

Ensure these queue workers are running:
```bash
# Tenant management queue (handles workspace creation)
php artisan queue:work database --queue=tenant-management --tries=3

# Email queue (handles credential emails)
php artisan queue:work database --queue=emails --tries=3

# Dashboard queue (handles stats updates)
php artisan queue:work database --queue=dashboard --tries=3
```

## Monitoring

Check queue status:
```bash
# View failed jobs
docker exec wificore-backend php artisan queue:failed

# View pending jobs count
docker exec wificore-backend php artisan tinker --execute="echo 'Pending: ' . DB::table('jobs')->count();"

# View failed jobs count
docker exec wificore-backend php artisan tinker --execute="echo 'Failed: ' . DB::table('failed_jobs')->count();"

# Retry all failed jobs
docker exec wificore-backend php artisan queue:retry all

# Clear all failed jobs
docker exec wificore-backend php artisan queue:flush
```

## Database Verification

Check tenant schema status:
```sql
-- List all tenant schemas
SELECT schema_name FROM information_schema.schemata 
WHERE schema_name LIKE 'ts_%';

-- Check tenant record
SELECT id, name, slug, schema_name, schema_created, schema_created_at 
FROM tenants WHERE slug = 'your-tenant-slug';

-- Check tenant migrations
SELECT * FROM tenant_schema_migrations 
WHERE tenant_id = 'your-tenant-id';

-- Check tables in tenant schema
SELECT table_name FROM information_schema.tables 
WHERE table_schema = 'ts_xxxxxxxxxxxxx';
```

## Common Issues and Solutions

### Issue: CreateTenantWorkspaceJob fails with "Failed to setup tenant schema"
**Solution:** Check logs for specific migration errors:
```bash
docker exec wificore-backend tail -f /var/www/html/storage/logs/laravel.log
```

### Issue: Credential email not sent
**Solution:** 
1. Check email queue worker is running
2. Verify mail configuration: `docker exec wificore-backend php artisan tinker --execute="echo config('mail.default');"`
3. Check SendCredentialsEmailJob logs

### Issue: UpdateDashboardStatsJob keeps failing
**Solution:** 
- Ensure tenant schema is fully created (`schema_created = true`)
- Verify all tenant tables exist in the schema
- Check if migrations completed successfully

## Notes

- All tenant-specific data is stored in separate PostgreSQL schemas (format: `ts_xxxxxxxxxxxxx`)
- Schema names are generated using SHA-256 hash for security
- Tenant migrations are tracked separately from main application migrations
- Jobs use `TenantAwareJob` trait for automatic schema switching
- Credential passwords are cleared after email is sent for security
