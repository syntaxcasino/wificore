# Schema-Based Multi-Tenancy Architecture

**Date**: December 2, 2025  
**Status**: ✅ **IMPLEMENTED**

---

## 🎯 **Overview**

This document describes the **proper schema-based multi-tenancy** architecture for the WiFi Hotspot Management System. Each tenant has its own PostgreSQL schema containing:

- **RADIUS tables** (`radcheck`, `radreply`, `radacct`, `radpostauth`, etc.)
- **Tenant-specific data** (users, packages, subscriptions, routers, etc.)
- **Complete data isolation** from other tenants

---

## 📊 **Architecture Diagram**

```
┌─────────────────────────────────────────────────────────────┐
│                     PostgreSQL Database                      │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ public       │  │ tenant_abc   │  │ tenant_xyz   │      │
│  │ schema       │  │ schema       │  │ schema       │      │
│  ├──────────────┤  ├──────────────┤  ├──────────────┤      │
│  │ • tenants    │  │ • radcheck   │  │ • radcheck   │      │
│  │ • users      │  │ • radreply   │  │ • radreply   │      │
│  │ • migrations │  │ • radacct    │  │ • radacct    │      │
│  │ • radcheck*  │  │ • radpostauth│  │ • radpostauth│      │
│  │   (sysadmin) │  │ • packages   │  │ • packages   │      │
│  │ • mapping    │  │ • routers    │  │ • routers    │      │
│  │   table      │  │ • sessions   │  │ • sessions   │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔑 **Key Principles**

### **1. Data Isolation**

- **Each tenant has a dedicated PostgreSQL schema**
- **RADIUS tables are in tenant schemas** (not public)
- **System admins** have RADIUS entries in `public` schema
- **Tenant users** have RADIUS entries in their tenant schema

### **2. Schema Switching**

- **TenantContext service** manages `search_path`
- **Automatic schema switching** based on authenticated user
- **PostgreSQL functions** for FreeRADIUS schema-aware queries

### **3. RADIUS Integration**

- **FreeRADIUS** queries correct schema using PostgreSQL functions
- **Schema mapping table** (`radius_user_schema_mapping`) for quick lookups
- **Accounting data** stored in tenant-specific `radacct` tables

---

## 📁 **Database Schema Structure**

### **Public Schema** (System-Wide)

```sql
-- System tables (always in public schema)
public.tenants
public.users
public.migrations
public.failed_jobs
public.jobs

-- System admin RADIUS tables (only for system admins)
public.radcheck
public.radreply
public.radacct
public.radpostauth

-- Schema mapping table (for quick lookups)
public.radius_user_schema_mapping (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    schema_name VARCHAR(63) NOT NULL,
    tenant_id UUID NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
)
```

### **Tenant Schema** (Per Tenant)

```sql
-- Each tenant schema contains:
tenant_abc.radcheck
tenant_abc.radreply
tenant_abc.radgroupcheck
tenant_abc.radgroupreply
tenant_abc.radusergroup
tenant_abc.radacct
tenant_abc.radpostauth

-- Tenant-specific tables
tenant_abc.packages
tenant_abc.routers
tenant_abc.subscriptions
tenant_abc.payments
tenant_abc.sessions
tenant_abc.hotspot_users
```

---

## 🔧 **Implementation Components**

### **1. Migrations**

#### **`2025_12_02_000001_implement_schema_based_multitenancy_for_radius.php`**

- Creates RADIUS tables in each tenant schema
- Migrates existing RADIUS data to tenant schemas
- Cleans up public schema (keeps only system admin entries)

#### **`2025_12_02_000002_create_radius_schema_switching_function.php`**

- Creates PostgreSQL functions for schema-aware queries
- `get_user_schema(username)` - Returns user's schema
- `radius_check_password(username, password)` - Checks credentials
- `radius_get_reply(username)` - Gets reply attributes
- `radius_accounting_start(...)` - Logs accounting data

### **2. Services**

#### **`TenantContext` Service**

```php
// Set tenant context
$tenantContext->setTenant($tenant);

// Run code in tenant context
$tenantContext->runInTenantContext($tenant, function() {
    // RADIUS operations here use tenant schema
    DB::table('radcheck')->insert([...]);
});

// Run code in system context
$tenantContext->runInSystemContext(function() {
    // Operations here use public schema
});
```

#### **`RadiusService` Service**

```php
// Create RADIUS user (tenant-aware)
$radiusService->createUser($username, $password);
// Uses current search_path (tenant schema)

// Update password (tenant-aware)
$radiusService->updatePassword($username, $newPassword);

// Delete user (tenant-aware)
$radiusService->deleteUser($username);
```

### **3. Jobs**

#### **`CreateUserJob`**

- System admins → RADIUS entry in `public` schema
- Tenant users → RADIUS entry in tenant schema (using `TenantContext`)

#### **`CreateTenantJob`**

- Creates tenant schema
- Creates RADIUS tables in tenant schema
- Creates admin user with RADIUS entry in tenant schema

#### **`UpdatePasswordJob`**

- Updates password in tenant-specific `radcheck` table

---

## 🚀 **Usage Examples**

### **Creating a Tenant User**

```php
// In controller or job
$tenantContext = app(\App\Services\TenantContext::class);
$tenant = Tenant::find($tenantId);

// Create user in public schema
$user = User::create([
    'tenant_id' => $tenant->id,
    'username' => 'john',
    'password' => Hash::make('password'),
    'role' => 'admin',
]);

// Create RADIUS entry in tenant schema
$tenantContext->runInTenantContext($tenant, function() use ($user) {
    DB::table('radcheck')->insert([
        'username' => $user->username,
        'attribute' => 'Cleartext-Password',
        'op' => ':=',
        'value' => 'password',
    ]);
});
```

### **Querying Tenant Data**

```php
// Set tenant context
$tenantContext->setTenant($tenant);

// All queries now use tenant schema
$packages = DB::table('packages')->get(); // tenant_abc.packages
$sessions = DB::table('radacct')->get(); // tenant_abc.radacct

// Clear context
$tenantContext->clearTenant();
```

### **FreeRADIUS Authentication**

FreeRADIUS uses PostgreSQL functions to automatically query the correct schema:

```sql
-- FreeRADIUS authorization query
SELECT * FROM radius_check_password('john', 'password');
-- Automatically queries tenant_abc.radcheck

-- FreeRADIUS reply query
SELECT * FROM radius_get_reply('john');
-- Automatically queries tenant_abc.radreply
```

---

## 🔍 **Verification**

### **Check User's Schema**

```sql
-- Get user's schema
SELECT get_user_schema('john');
-- Returns: tenant_abc

-- Check RADIUS entry
SET search_path TO tenant_abc, public;
SELECT * FROM radcheck WHERE username = 'john';
```

### **List All Tenant Schemas**

```sql
SELECT 
    t.name AS tenant_name,
    t.schema_name,
    t.schema_created,
    COUNT(DISTINCT r.username) AS radius_users
FROM tenants t
LEFT JOIN LATERAL (
    SELECT username 
    FROM information_schema.tables ist
    WHERE ist.table_schema = t.schema_name
    AND ist.table_name = 'radcheck'
) r ON true
GROUP BY t.id, t.name, t.schema_name, t.schema_created;
```

### **Verify RADIUS Data Isolation**

```bash
# List RADIUS users in public schema (system admins only)
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT username, attribute, value 
    FROM public.radcheck 
    ORDER BY username;
"

# List RADIUS users in tenant schema
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SET search_path TO tenant_abc, public;
    SELECT username, attribute, value 
    FROM radcheck 
    ORDER BY username;
"
```

---

## 📝 **Migration Steps**

### **1. Run Migrations**

```bash
cd backend
php artisan migrate
```

This will:
- Create RADIUS tables in all tenant schemas
- Migrate existing RADIUS data to tenant schemas
- Create PostgreSQL functions for schema switching

### **2. Verify Migration**

```bash
# Check if RADIUS tables exist in tenant schemas
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT 
        schemaname, 
        tablename 
    FROM pg_tables 
    WHERE tablename IN ('radcheck', 'radreply', 'radacct')
    AND schemaname != 'public'
    ORDER BY schemaname, tablename;
"
```

### **3. Test Authentication**

```bash
# Test system admin authentication (public schema)
radtest sysadmin Admin@123 localhost 0 testing123

# Test tenant user authentication (tenant schema)
radtest xuxu Pa$$w0rd! localhost 0 testing123
```

---

## 🛡️ **Security Considerations**

### **1. Schema Isolation**

- Each tenant can **only access their own schema**
- PostgreSQL `search_path` ensures queries go to correct schema
- No cross-tenant data leakage

### **2. RADIUS Security**

- RADIUS credentials stored in tenant-specific tables
- System admins isolated in public schema
- Schema mapping table for quick lookups

### **3. SQL Injection Prevention**

- Schema names validated with regex: `^[a-z0-9_]{1,63}$`
- PostgreSQL functions use `SECURITY DEFINER`
- Parameterized queries throughout

---

## 🔧 **Troubleshooting**

### **Issue: RADIUS authentication fails**

**Solution**:
```sql
-- Check if user exists in correct schema
SELECT get_user_schema('username');

-- Verify RADIUS entry
SET search_path TO tenant_abc, public;
SELECT * FROM radcheck WHERE username = 'username';
```

### **Issue: User created in wrong schema**

**Solution**:
```php
// Always use TenantContext when creating RADIUS entries
$tenantContext->runInTenantContext($tenant, function() {
    DB::table('radcheck')->insert([...]);
});
```

### **Issue: FreeRADIUS can't find user**

**Solution**:
```bash
# Check FreeRADIUS SQL queries
docker exec traidnet-freeradius radiusd -X

# Verify PostgreSQL functions exist
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT proname FROM pg_proc 
    WHERE proname LIKE 'radius_%' 
    OR proname = 'get_user_schema';
"
```

---

## 📊 **Performance Considerations**

### **1. Schema Mapping Table**

- Indexed on `username` for fast lookups
- Cached in application layer
- Updated when user is created/deleted

### **2. Search Path**

- Set once per request
- Minimal overhead
- Automatic fallback to public schema

### **3. RADIUS Queries**

- PostgreSQL functions compiled once
- Efficient schema switching
- Indexed RADIUS tables

---

## 🎉 **Benefits**

✅ **Complete Data Isolation** - Each tenant's data is physically separated  
✅ **RADIUS Multi-Tenancy** - RADIUS users isolated per tenant  
✅ **Scalability** - Easy to add new tenants  
✅ **Security** - No cross-tenant data access  
✅ **Performance** - Efficient schema switching  
✅ **Compliance** - Meets data residency requirements  

---

## 📚 **References**

- [PostgreSQL Schemas Documentation](https://www.postgresql.org/docs/current/ddl-schemas.html)
- [FreeRADIUS SQL Module](https://wiki.freeradius.org/modules/Rlm_sql)
- [Laravel Multi-Tenancy](https://laravel.com/docs/master/database#multiple-database-connections)

---

## 🔄 **Next Steps**

1. ✅ Run migrations to create tenant RADIUS tables
2. ✅ Update FreeRADIUS configuration to use PostgreSQL functions
3. ✅ Test authentication for both system admins and tenant users
4. ✅ Verify data isolation between tenants
5. ✅ Update documentation and training materials

---

**Status**: Ready for deployment! 🚀
