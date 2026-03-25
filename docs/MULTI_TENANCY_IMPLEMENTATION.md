# Multi-Tenancy Implementation - Complete Guide
## Based on Livestock-Management System

**Date**: December 6, 2025  
**Status**: Implementation in Progress

---

## 🎯 **Overview**

This document details the complete multi-tenancy implementation for wifi-hotspot, replicated from the working livestock-management system.

### **Key Principles from Livestock-Management**

1. ✅ **Schema-Based Isolation**: Each tenant gets their own PostgreSQL schema
2. ✅ **Automatic Schema Creation**: Schemas created automatically when tenant is registered
3. ✅ **Automatic Migrations**: Tenant migrations run automatically on schema creation
4. ✅ **RADIUS Per Tenant**: Each tenant has their own RADIUS tables (radcheck, radreply, etc.)
5. ✅ **Secure Schema Names**: Generated using hash (e.g., `ts_abc123def456`)
6. ✅ **Password Support**: Any characters allowed in passwords (special chars, spaces, etc.)

---

## 📁 **Files Created/Modified**

### **1. Configuration**
- ✅ `backend/config/multitenancy.php` - Updated to match livestock-management

### **2. Migrations**
- ✅ `backend/database/migrations/tenant/2025_01_01_000001_create_tenant_radius_tables.php`

### **3. Services** (To be created)
- ⏳ `backend/app/Services/TenantSchemaManager.php`
- ⏳ `backend/app/Services/TenantMigrationManager.php`

### **4. Model Updates** (To be modified)
- ⏳ `backend/app/Models/Tenant.php` - Add boot events

### **5. Password Service** (To be updated)
- ⏳ `backend/app/Services/PasswordService.php` - Fix RADIUS integration

---

## 🔧 **Implementation Steps**

### **Step 1: Schema Name Generation** ✅

**Problem**: Current system uses hyphens in schema names (e.g., `tenant_eaque-duis-quasi-rep`)  
**Solution**: Use hash-based secure names with underscores only

```php
// In TenantMigrationManager.php
public static function generateSecureSchemaName(string $tenantSlug): string
{
    // Create a hash-based schema name that's hard to guess
    $hash = hash('sha256', $tenantSlug . config('app.key') . 'tenant_schema_salt');
    
    // Take first 12 characters and prefix with 'ts_' (tenant schema)
    $schemaName = 'ts_' . substr($hash, 0, 12);
    
    // Ensure it's a valid PostgreSQL identifier (lowercase, no hyphens)
    return strtolower($schemaName);
}
```

**Example Output**: `ts_e026335a3233`

---

### **Step 2: Automatic Schema Creation** ⏳

**How It Works in Livestock-Management**:

1. **Tenant Model Boot Event**: When a tenant is created, the `created` event fires
2. **TenantMigrationManager**: Automatically creates schema and runs migrations
3. **Schema Permissions**: Grants all permissions to database user
4. **Migration Tracking**: Records which migrations have been run per tenant

```php
// In Tenant.php model
protected static function boot()
{
    parent::boot();

    static::creating(function ($tenant) {
        if (empty($tenant->id)) {
            $tenant->id = Str::uuid();
        }

        // Generate secure schema name if not set
        if (empty($tenant->schema_name)) {
            $tenant->schema_name = \App\Services\TenantMigrationManager::generateSecureSchemaName($tenant->slug);
        }
    });

    static::created(function ($tenant) {
        // Auto-create tenant schema and run migrations
        $migrationManager = app(\App\Services\TenantMigrationManager::class);

        if ($migrationManager->setupTenantSchema($tenant)) {
            $shouldAutoSeed = config('multitenancy.auto_seed_schema', false);

            if ($shouldAutoSeed) {
                $seedWithTestData = config('multitenancy.seed_with_test_data')
                    ?? app()->environment(['local', 'development', 'testing']);

                $migrationManager->seedTenantSchema($tenant, (bool) $seedWithTestData);
            }
        }
    });
}
```

---

### **Step 3: Tenant RADIUS Tables** ✅

**Created**: `backend/database/migrations/tenant/2025_01_01_000001_create_tenant_radius_tables.php`

**Key Points**:
- Uses `bigIncrements` for `id` columns (matches FreeRADIUS expectations)
- Creates all 8 RADIUS tables: radcheck, radreply, radacct, radpostauth, etc.
- Adds proper indexes for performance
- Seeds default RADIUS groups

---

### **Step 4: Password Service Integration** ⏳

**How Passwords Are Added to RADIUS** (from livestock-management):

```php
protected function addToFreeRadius(string $username, string $password, ?string $tenantId): void
{
    try {
        // Get tenant schema name
        $tenant = \App\Models\Tenant::find($tenantId);
        $schemaName = $tenant->schema_name;
        
        // Get current schema
        $currentSchemaResult = DB::select("SELECT current_schema()");
        $currentSchema = $currentSchemaResult[0]->current_schema ?? 'public';
        
        // STEP 1: Switch to TENANT schema for RADIUS tables
        DB::statement("SET search_path TO {$schemaName}, public");
        
        // STEP 2: Add to radcheck table (authentication)
        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $username,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => $password,  // ANY characters supported!
            ]
        );
        
        // STEP 3: Add to radreply table (authorization)
        DB::table('radreply')->updateOrInsert(
            [
                'username' => $username,
                'attribute' => 'Tenant-ID',
            ],
            [
                'op' => ':=',
                'value' => $schemaName,  // Use schema_name, not tenant_id
            ]
        );
        
        // STEP 4: Switch to PUBLIC schema for radius_user_schema_mapping
        DB::statement("SET search_path TO public");
        
        // STEP 5: Add mapping (used by login to find tenant schema)
        DB::table('radius_user_schema_mapping')->updateOrInsert(
            ['username' => $username],
            [
                'schema_name' => $schemaName,
                'tenant_id' => $tenantId,
                'user_role' => 'admin',  // or 'employee', 'hotspot_user'
                'is_active' => true,
                'updated_at' => now(),
            ]
        );
        
        // STEP 6: Restore previous schema
        DB::statement("SET search_path TO {$currentSchema}, public");
        
    } catch (\Exception $e) {
        \Log::error("Failed to add user to FreeRADIUS: {$e->getMessage()}");
        throw new \Exception("Failed to add user to FreeRADIUS: {$e->getMessage()}");
    }
}
```

**Critical Points**:
1. ✅ RADIUS credentials stored in **TENANT schema** (data isolation)
2. ✅ Schema mapping stored in **PUBLIC schema** (used before tenant context)
3. ✅ Password can contain **ANY characters** (no escaping issues)
4. ✅ Uses `updateOrInsert` to handle updates gracefully

---

### **Step 5: Fix Existing Tenants** ⏳

**Problem**: Existing tenants have invalid schema names with hyphens

**Solution**: Run migration to fix schema names and create missing schemas

```sql
-- Fix schema names (replace hyphens with underscores)
UPDATE tenants 
SET schema_name = REPLACE(schema_name, '-', '_')
WHERE schema_name LIKE '%-%';

-- Update radius_user_schema_mapping
UPDATE radius_user_schema_mapping 
SET schema_name = REPLACE(schema_name, '-', '_')
WHERE schema_name LIKE '%-%';
```

Then run artisan command to create missing schemas:
```bash
php artisan tenant:create-schemas
```

---

## 🔑 **Key Differences from Current Implementation**

| Aspect | Current (Broken) | Livestock-Management (Working) |
|--------|------------------|--------------------------------|
| **Schema Names** | `tenant_eaque-duis-quasi-rep` (hyphens) | `ts_e026335a3233` (hash, no hyphens) |
| **Schema Creation** | Manual | Automatic on tenant creation |
| **RADIUS Tables** | In public schema | In tenant schema (isolated) |
| **Migrations** | Manual | Automatic via boot events |
| **Password Handling** | Escaping issues | Direct insert, any characters |
| **Schema Mapping** | Inconsistent | Always in public schema |

---

## 📊 **Database Architecture**

### **Public Schema (System-Wide)**
```
public/
├── tenants
├── users
├── radius_user_schema_mapping  ← Maps username to tenant schema
├── migrations
├── jobs
└── ... (other system tables)
```

### **Tenant Schema (Per Tenant)**
```
ts_abc123def456/  ← Tenant A
├── radcheck       ← Tenant A's RADIUS credentials
├── radreply       ← Tenant A's RADIUS attributes
├── radacct        ← Tenant A's accounting
├── routers        ← Tenant A's routers
├── packages       ← Tenant A's packages
└── ... (other tenant tables)

ts_xyz789ghi012/  ← Tenant B
├── radcheck       ← Tenant B's RADIUS credentials (isolated!)
├── radreply
└── ...
```

---

## 🚀 **Testing Plan**

### **1. Test Schema Creation**
```bash
# Create a new tenant
POST /api/system/tenants
{
  "name": "Test Tenant",
  "slug": "test-tenant",
  "subdomain": "test"
}

# Verify schema was created
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\dn"

# Should see: ts_xxxxxxxxxxxx
```

### **2. Test User Creation**
```bash
# Create a tenant admin
POST /api/system/tenants/{tenant_id}/users
{
  "name": "Test Admin",
  "email": "admin@test.com",
  "password": "Pa$$w0rd!@#$%",  # Special characters!
  "role": "admin"
}

# Verify RADIUS credentials
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SET search_path TO ts_xxxxxxxxxxxx, public;
SELECT username, attribute, value FROM radcheck;
"
```

### **3. Test Login**
```bash
# Login with the new user
POST /api/login
{
  "username": "admin@test.com",
  "password": "Pa$$w0rd!@#$%"
}

# Should return 200 OK with token
```

---

## 📝 **Next Steps**

1. ✅ Create `TenantSchemaManager.php` service
2. ✅ Create `TenantMigrationManager.php` service
3. ✅ Update `Tenant.php` model with boot events
4. ✅ Update `PasswordService.php` with proper RADIUS integration
5. ✅ Create artisan command to fix existing tenants
6. ✅ Test with new tenant creation
7. ✅ Test with existing tenant (xuxu)
8. ✅ Verify login works for all user types

---

## 🔗 **References**

- **Livestock-Management**: `D:\traidnet\livestock-management`
- **Config**: `backend/config/multitenancy.php`
- **Services**: `backend/app/Services/TenantSchemaManager.php`
- **Migrations**: `backend/database/migrations/tenant/`
- **Password Service**: `backend/app/Services/PasswordService.php`

---

**Status**: Ready for Service Implementation  
**Next**: Create TenantSchemaManager and TenantMigrationManager services
