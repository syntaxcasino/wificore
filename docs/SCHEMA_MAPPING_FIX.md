# Schema Mapping Fix for Multi-Tenant Authentication

## Issue Description

**Error:** `SCHEMA_MAPPING_MISSING` - User account not properly configured

**Root Cause:** The `radius_user_schema_mapping` table entries were not being created when tenant users were registered. This table is critical for the schema-based multi-tenancy authentication system, as it maps usernames to their respective tenant schemas.

## How Multi-Tenant Authentication Works

1. User attempts to login with username/password
2. System looks up username in `radius_user_schema_mapping` table (in public schema)
3. Retrieves the tenant's schema name from the mapping
4. Authenticates user via RADIUS using the correct tenant schema
5. Returns authentication result

**Without the schema mapping**, the authentication fails with `SCHEMA_MAPPING_MISSING` error.

---

## Files Modified

### 1. `backend/app/Jobs/CreateTenantWorkspaceJob.php`

**Added:** Schema mapping creation after user creation

```php
// CRITICAL: Create schema mapping for multi-tenant authentication
// This is required for RADIUS authentication to work
DB::table('radius_user_schema_mapping')->insert([
    'username' => $username,
    'schema_name' => $tenant->schema_name,
    'tenant_id' => $tenant->id,
    'is_active' => true,
    'created_at' => now(),
    'updated_at' => now(),
]);
```

### 2. `backend/app/Jobs/CreateUserJob.php`

**Added:** Schema mapping creation for tenant users

```php
// CRITICAL: Create schema mapping for multi-tenant authentication
DB::table('radius_user_schema_mapping')->insert([
    'username' => $user->username,
    'schema_name' => $tenant->schema_name,
    'tenant_id' => $tenant->id,
    'is_active' => true,
    'created_at' => now(),
    'updated_at' => now(),
]);
```

### 3. `backend/app/Console/Commands/FixSchemaMappings.php` (NEW)

**Created:** Command to fix missing schema mappings for existing users

---

## Fix for Existing Tenants

Run this command to create schema mappings for all existing tenant users:

```bash
# Fix all tenants
docker exec -it wificore-backend php artisan tenants:fix-schema-mappings

# Fix specific tenant
docker exec -it wificore-backend php artisan tenants:fix-schema-mappings --tenant-id=1
```

**What it does:**
- Scans all tenants (or specific tenant)
- Checks each user for existing schema mapping
- Creates missing mappings
- Reports summary of fixed/skipped/errors

---

## Verification

After running the fix command, verify the mappings:

```sql
-- Check schema mappings
SELECT 
    id,
    username,
    schema_name,
    tenant_id,
    is_active,
    created_at
FROM radius_user_schema_mapping
ORDER BY created_at DESC;

-- Check for users without mappings
SELECT 
    u.id,
    u.username,
    u.tenant_id,
    t.schema_name,
    CASE 
        WHEN m.id IS NULL THEN 'MISSING'
        ELSE 'EXISTS'
    END as mapping_status
FROM users u
LEFT JOIN tenants t ON u.tenant_id = t.id
LEFT JOIN radius_user_schema_mapping m ON u.username = m.username
WHERE u.tenant_id IS NOT NULL
ORDER BY mapping_status DESC, u.created_at DESC;
```

---

## Testing Login

After fixing schema mappings, test the login flow:

1. **Navigate to login page:**
   ```
   https://wificore.traidsolutions.com/login
   ```

2. **Login with tenant admin credentials:**
   - Username: (from credentials email)
   - Password: (from credentials email)

3. **Expected result:**
   - Successful authentication
   - Redirect to tenant dashboard
   - No `SCHEMA_MAPPING_MISSING` error

---

## Schema Mapping Table Structure

```sql
CREATE TABLE radius_user_schema_mapping (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    schema_name VARCHAR(64) NOT NULL,
    tenant_id BIGINT,
    user_role VARCHAR(50),
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX idx_radius_mapping_username ON radius_user_schema_mapping(username);
CREATE INDEX idx_radius_mapping_active ON radius_user_schema_mapping(is_active);
CREATE INDEX idx_radius_mapping_tenant ON radius_user_schema_mapping(tenant_id);
```

---

## When Schema Mappings Are Created

Schema mappings should be created in these scenarios:

1. **New Tenant Registration** (`CreateTenantWorkspaceJob`)
   - When tenant admin user is created
   - Mapping: admin username → tenant schema

2. **New Tenant Creation** (`CreateTenantJob`)
   - When tenant and admin are created via API
   - Mapping: admin username → tenant schema

3. **New User Creation** (`CreateUserJob`)
   - When new tenant user is created
   - Mapping: user username → tenant schema

4. **System Admin Creation** (`SystemAdminSeeder`)
   - When system admin is created
   - Mapping: admin username → public schema

---

## Important Notes

1. **Schema mappings are in public schema**
   - They must be accessible before tenant context is established
   - FreeRADIUS queries this table first to determine which schema to use

2. **One mapping per username**
   - Username must be unique across all tenants
   - Enforced by UNIQUE constraint on username column

3. **Active flag**
   - `is_active = true` means user can authenticate
   - Set to `false` to disable authentication without deleting user

4. **Cascade deletion**
   - When tenant is deleted, all its user mappings are deleted
   - Enforced by `ON DELETE CASCADE` foreign key

---

## Troubleshooting

### Error: "SCHEMA_MAPPING_MISSING"

**Solution:**
```bash
docker exec -it wificore-backend php artisan tenants:fix-schema-mappings
```

### Error: "SCHEMA_MISMATCH"

**Cause:** Schema name in mapping doesn't match tenant's schema name

**Solution:**
```sql
-- Update mapping to match tenant schema
UPDATE radius_user_schema_mapping m
SET schema_name = t.schema_name
FROM users u
JOIN tenants t ON u.tenant_id = t.id
WHERE m.username = u.username
AND m.schema_name != t.schema_name;
```

### User can't login after tenant schema rename

**Solution:**
```bash
# Update schema mappings after schema rename
docker exec -it wificore-backend php artisan tenants:fix-schema-mappings --tenant-id=X
```

---

## Deployment Steps

1. **Pull latest code:**
   ```bash
   cd /path/to/wificore
   git pull origin main
   ```

2. **Rebuild backend container:**
   ```bash
   cd backend
   docker-compose down
   docker-compose up -d --build
   ```

3. **Run schema mapping fix:**
   ```bash
   docker exec -it wificore-backend php artisan tenants:fix-schema-mappings
   ```

4. **Verify mappings:**
   ```bash
   docker exec -it wificore-backend php artisan tinker
   >>> DB::table('radius_user_schema_mapping')->count()
   >>> DB::table('radius_user_schema_mapping')->get()
   ```

5. **Test login:**
   - Login with existing tenant credentials
   - Verify successful authentication
   - Check dashboard access

---

## Prevention

To prevent this issue in the future:

1. **Always use job classes** for user creation
   - `CreateTenantWorkspaceJob`
   - `CreateUserJob`
   - `CreateTenantJob`

2. **Never create users directly** without schema mapping

3. **Add tests** to verify schema mapping creation

4. **Monitor logs** for `SCHEMA_MAPPING_MISSING` errors

---

## Related Files

- `app/Http/Controllers/Api/UnifiedAuthController.php` - Login validation
- `app/Jobs/CreateTenantWorkspaceJob.php` - Tenant registration
- `app/Jobs/CreateUserJob.php` - User creation
- `app/Jobs/CreateTenantJob.php` - Tenant creation
- `app/Console/Commands/FixSchemaMappings.php` - Fix command
- `database/migrations/2025_11_30_000002_create_radius_user_schema_mapping_table.php` - Table migration

---

*Last Updated: December 21, 2025*
