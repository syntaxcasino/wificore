# Multi-Tenancy Quick Start Guide

## For Developers

This guide helps you quickly understand and work with the new schema-based multi-tenancy system.

---

## 🚀 Quick Overview

**What Changed**: Added schema-based multi-tenancy infrastructure  
**Impact**: ✅ **ZERO** - All existing code works as before  
**Mode**: Hybrid (supports both old and new approaches)  

---

## 📋 Key Concepts

### 1. Tenant Context
Every request has a **tenant context** that determines which database schema to use.

```php
// Get current tenant
$tenant = app(TenantContext::class)->getTenant();

// Check if in tenant context
$isInTenantContext = app(TenantContext::class)->isInTenantContext();

// Get schema name
$schemaName = app(TenantContext::class)->getSchemaName();
```

### 2. Two Types of Tenants

#### Legacy Tenants (Existing)
- `schema_created = false`
- Data in public schema
- Uses `tenant_id` filtering
- **Your existing code works unchanged**

#### Schema-Based Tenants (New)
- `schema_created = true`
- Data in tenant schema (e.g., `tenant_abc`)
- PostgreSQL `search_path` handles isolation
- **No tenant_id filtering needed**

---

## 🔧 Common Tasks

### Working with Tenant Data

#### ✅ GOOD - Let Middleware Handle It
```php
// In a controller with SetTenantContext middleware
public function index()
{
    // Middleware already set tenant context
    // Just query normally
    $routers = Router::all();
    
    // For legacy tenants: Queries public.routers WHERE tenant_id = ?
    // For schema tenants: Queries tenant_abc.routers (all rows)
    
    return response()->json($routers);
}
```

#### ❌ BAD - Manual Filtering (Old Way)
```php
// Don't do this anymore
public function index()
{
    $tenantId = auth()->user()->tenant_id;
    $routers = Router::where('tenant_id', $tenantId)->get();
}
```

### Running Code in Tenant Context

```php
use App\Services\TenantContext;

// System admin accessing tenant data
public function getTenantReport($tenantId)
{
    $tenant = Tenant::findOrFail($tenantId);
    
    return app(TenantContext::class)->runInTenantContext($tenant, function() {
        return [
            'routers' => Router::count(),
            'packages' => Package::count(),
            'users' => HotspotUser::count(),
        ];
    });
    // Context automatically cleared after callback
}
```

### Creating a New Tenant

```php
use App\Services\TenantSchemaManager;

public function createTenant(Request $request)
{
    DB::beginTransaction();
    
    try {
        // 1. Create tenant record
        $tenant = Tenant::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'schema_name' => 'tenant_' . $request->slug,
            'email' => $request->email,
        ]);
        
        // 2. Create tenant schema (if enabled)
        if (config('multitenancy.auto_create_schema')) {
            app(TenantSchemaManager::class)->createSchema($tenant);
            // This will:
            // - Create PostgreSQL schema
            // - Run tenant migrations
            // - Seed initial data (if enabled)
        }
        
        // 3. Create admin user
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->admin_name,
            'username' => $request->admin_username,
            'email' => $request->admin_email,
            'password' => Hash::make($request->admin_password),
            'role' => 'tenant_admin',
        ]);
        
        DB::commit();
        
        return response()->json(['tenant' => $tenant, 'user' => $user]);
    } catch (\Exception $e) {
        DB::rollBack();
        throw $e;
    }
}
```

---

## 🎯 Best Practices

### DO ✅

1. **Trust the Middleware**
   ```php
   // Middleware sets tenant context automatically
   public function index()
   {
       return Router::all(); // ✅ Correct
   }
   ```

2. **Use TenantContext Service**
   ```php
   // For system admin operations
   app(TenantContext::class)->runInTenantContext($tenant, function() {
       // Your code here
   });
   ```

3. **Check Tenant Status**
   ```php
   if ($tenant->schema_created) {
       // Schema-based tenant
   } else {
       // Legacy tenant
   }
   ```

### DON'T ❌

1. **Manual tenant_id Filtering**
   ```php
   // ❌ Don't do this
   Router::where('tenant_id', $tenantId)->get();
   
   // ✅ Do this instead
   Router::all(); // Middleware handles it
   ```

2. **Hardcode Schema Names**
   ```php
   // ❌ Don't do this
   DB::table('tenant_abc.routers')->get();
   
   // ✅ Do this instead
   app(TenantContext::class)->runInTenantContext($tenant, function() {
       return Router::all();
   });
   ```

3. **Forget to Clear Context**
   ```php
   // ❌ Don't do this
   app(TenantContext::class)->setTenant($tenant);
   // ... do stuff ...
   // Forgot to clear!
   
   // ✅ Do this instead
   app(TenantContext::class)->runInTenantContext($tenant, function() {
       // ... do stuff ...
   }); // Automatically cleared
   ```

---

## 🔍 Debugging

### Check Current Context

```php
// In any controller or service
$tenantContext = app(TenantContext::class);

Log::info('Tenant Context Debug', [
    'has_tenant' => $tenantContext->hasTenant(),
    'tenant_id' => $tenantContext->getTenantId(),
    'schema_name' => $tenantContext->getSchemaName(),
    'search_path' => $tenantContext->getCurrentSearchPath(),
    'is_in_tenant_context' => $tenantContext->isInTenantContext(),
]);
```

### Check Database Schema

```sql
-- Show current search path
SHOW search_path;

-- List all schemas
SELECT schema_name 
FROM information_schema.schemata 
WHERE schema_name LIKE 'tenant_%';

-- Check tenant's schema status
SELECT id, name, slug, schema_name, schema_created 
FROM tenants;

-- Check RADIUS mapping
SELECT username, schema_name, tenant_id, user_role 
FROM radius_user_schema_mapping;
```

### Common Issues

#### Issue: "Tenant not found"
```php
// Check if tenant exists
$tenant = Tenant::find($tenantId);
if (!$tenant) {
    // Tenant doesn't exist
}
```

#### Issue: "Table doesn't exist"
```php
// Check if tenant has schema
if ($tenant->schema_created) {
    // Schema exists, table should be there
    // Maybe migrations not run?
} else {
    // Legacy tenant, use public schema
}
```

#### Issue: "Wrong tenant data"
```php
// Check search path
$searchPath = DB::selectOne("SHOW search_path")->search_path;
Log::info("Current search path: {$searchPath}");

// Should be: tenant_abc, public (for schema-based)
// Or: public (for legacy)
```

---

## 📚 Reference

### Services

#### TenantContext
**Location**: `app/Services/TenantContext.php`  
**Purpose**: Manage tenant context and search_path  
**Inject**: `app(TenantContext::class)` or constructor injection

#### TenantSchemaManager
**Location**: `app/Services/TenantSchemaManager.php`  
**Purpose**: Manage schema lifecycle  
**Inject**: `app(TenantSchemaManager::class)` or constructor injection

### Config

**File**: `config/multitenancy.php`

```php
// Get config values
$mode = config('multitenancy.mode'); // 'hybrid'
$systemTables = config('multitenancy.system_tables');
$tenantTables = config('multitenancy.tenant_tables');
$autoCreate = config('multitenancy.auto_create_schema');
```

### Middleware

**File**: `app/Http/Middleware/SetTenantContext.php`  
**Applied**: Automatically on authenticated routes  
**Behavior**: Sets tenant context based on user's tenant_id

---

## 🧪 Testing

### Unit Test Example

```php
use App\Services\TenantContext;
use App\Models\Tenant;

public function test_can_set_tenant_context()
{
    $tenant = Tenant::factory()->create([
        'schema_name' => 'test_schema',
        'schema_created' => true,
    ]);
    
    $tenantContext = app(TenantContext::class);
    $tenantContext->setTenant($tenant);
    
    $this->assertEquals($tenant->id, $tenantContext->getTenantId());
    $this->assertTrue($tenantContext->isInTenantContext());
    
    $tenantContext->clearTenant();
    
    $this->assertFalse($tenantContext->isInTenantContext());
}
```

### Feature Test Example

```php
public function test_tenant_data_isolation()
{
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();
    
    $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);
    $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);
    
    // Create router for tenant1
    $this->actingAs($user1, 'sanctum')
         ->postJson('/api/admin/routers', [
             'name' => 'Router 1',
             // ... other fields
         ])
         ->assertStatus(201);
    
    // Tenant2 should not see tenant1's router
    $response = $this->actingAs($user2, 'sanctum')
                     ->getJson('/api/admin/routers')
                     ->assertStatus(200);
    
    $this->assertCount(0, $response->json('data'));
}
```

---

## 🚨 Important Notes

### For Existing Code

✅ **Your existing code works unchanged**  
✅ **No need to modify controllers**  
✅ **No need to modify models**  
✅ **No need to modify services**  

The middleware handles everything automatically.

### For New Code

When writing new code:
1. Don't manually filter by `tenant_id`
2. Trust the middleware to set context
3. Use `TenantContext` service for system admin operations
4. Use `runInTenantContext()` for cross-tenant operations

### For System Admins

System admins (`role = 'system_admin'`) always use public schema:
- Can see all tenants
- No tenant context set
- Must explicitly set context to access tenant data

```php
// System admin accessing tenant data
if (auth()->user()->role === 'system_admin') {
    $tenant = Tenant::find($tenantId);
    
    return app(TenantContext::class)->runInTenantContext($tenant, function() {
        return Router::all(); // Tenant's routers
    });
}
```

---

## 📞 Need Help?

1. **Check Logs**: `backend/storage/logs/laravel.log`
2. **Check RADIUS**: `docker logs traidnet-freeradius`
3. **Check Database**: `docker exec traidnet-postgres psql -U admin -d wifi_hotspot`
4. **Read Docs**: `/docs/MULTITENANCY_*.md`
5. **Review Code**: Check service implementations

---

## 🎓 Learn More

- [Implementation Plan](./MULTITENANCY_IMPLEMENTATION_PLAN.md)
- [Phase 1 Complete](./MULTITENANCY_PHASE1_COMPLETE.md)
- [Review Summary](./MULTITENANCY_REVIEW_SUMMARY.md)
- [Architecture Overview](./docs/MULTITENANCY_PART1_OVERVIEW.md)
- [Implementation Guide](./docs/MULTITENANCY_PART2_IMPLEMENTATION.md)
- [RADIUS Integration](./docs/MULTITENANCY_PART3_RADIUS.md)
- [Best Practices](./docs/MULTITENANCY_PART4_BEST_PRACTICES.md)

---

**Quick Start Version**: 1.0  
**Last Updated**: November 30, 2025  
**Status**: Phase 1 Complete
