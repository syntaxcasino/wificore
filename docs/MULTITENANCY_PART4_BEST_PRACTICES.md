# Multi-Tenancy Architecture - Part 4: Best Practices & Guidelines

## Table of Contents
1. [Development Best Practices](#development-best-practices)
2. [Security Guidelines](#security-guidelines)
3. [Performance Optimization](#performance-optimization)
4. [Testing Strategies](#testing-strategies)
5. [Deployment Checklist](#deployment-checklist)
6. [Monitoring & Maintenance](#monitoring--maintenance)

---

## Development Best Practices

### 1. Always Use Tenant Context

**❌ BAD - Manual tenant_id filtering**:
```php
// DON'T DO THIS
public function index(Request $request)
{
    $tenantId = auth()->user()->tenant_id;
    $employees = Employee::where('tenant_id', $tenantId)->get();
}
```

**✅ GOOD - Let middleware handle context**:
```php
// DO THIS
public function index(Request $request)
{
    // Middleware already set tenant context
    // No need to filter by tenant_id
    $employees = Employee::all();
}
```

**Why**: 
- Cleaner code
- Less error-prone
- Automatic isolation
- Easier to maintain

### 2. Use TenantContext Service for System Admin Operations

**✅ GOOD - Explicit context management**:
```php
public function getTenantReport($tenantId)
{
    $tenant = Tenant::findOrFail($tenantId);
    
    return app(TenantContext::class)->runInTenantContext($tenant, function() {
        return [
            'employees' => Employee::count(),
            'farmers' => Farmer::count(),
            'collections' => MilkCollection::whereDate('created_at', today())->count(),
        ];
    });
    
    // Context automatically cleared after callback
}
```

### 3. Model Relationships Across Schemas

**Cross-Schema Relationships**:
```php
// Employee model (tenant schema)
class Employee extends Model
{
    // Relationship to User (public schema)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    // Relationship to Department (same tenant schema)
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}

// User model (public schema)
class User extends Model
{
    // Relationship to Employee (tenant schema)
    // This will work when tenant context is set
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }
}
```

### 4. Migration Naming Convention

**System Migrations**: `database/migrations/`
```
0001_01_01_000000_create_tenants_table.php
0001_01_01_000001_create_users_table.php
2025_11_19_000000_add_schema_to_tenants.php
```

**Tenant Migrations**: `database/migrations/tenant/`
```
2025_01_01_000001_create_tenant_farmers_table.php
2025_01_01_000009_create_tenant_departments_table.php
2025_01_01_000011_create_tenant_employees_table.php
```

**Naming Rules**:
- Prefix tenant migrations with `tenant_` in filename
- Use descriptive names
- Keep chronological order

### 5. Seeding Tenant Data

**Tenant Seeder Example**:
```php
// database/seeders/TenantSeeder.php
class TenantSeeder extends Seeder
{
    public function run()
    {
        $tenant = Tenant::where('slug', 'default')->first();
        
        if (!$tenant) {
            return;
        }
        
        // Run in tenant context
        app(TenantContext::class)->runInTenantContext($tenant, function() {
            // Seed departments
            $hr = Department::create([
                'name' => 'Human Resources',
                'code' => 'HR',
                'status' => 'active',
            ]);
            
            $it = Department::create([
                'name' => 'IT Department',
                'code' => 'IT',
                'status' => 'active',
            ]);
            
            // Seed positions
            Position::create([
                'title' => 'HR Manager',
                'department_id' => $hr->id,
            ]);
            
            Position::create([
                'title' => 'System Administrator',
                'department_id' => $it->id,
            ]);
        });
    }
}
```

### 6. API Response Structure

**Consistent Response Format**:
```php
// Success response
return response()->json([
    'success' => true,
    'message' => 'Operation successful',
    'data' => $data,
], 200);

// Error response
return response()->json([
    'success' => false,
    'message' => 'Operation failed',
    'errors' => $errors,
], 422);
```

### 7. Logging Best Practices

**Include Tenant Context in Logs**:
```php
Log::info('Employee created', [
    'tenant_id' => auth()->user()->tenant_id,
    'tenant_schema' => app(TenantContext::class)->getTenant()?->schema_name,
    'user_id' => auth()->id(),
    'employee_id' => $employee->id,
]);
```

---

## Security Guidelines

### 1. Tenant Isolation Verification

**Always verify tenant ownership**:
```php
public function update(Request $request, $id)
{
    // Middleware sets tenant context, but double-check for sensitive operations
    $employee = Employee::findOrFail($id);
    
    // Verify employee belongs to current tenant
    if ($employee->user && $employee->user->tenant_id !== auth()->user()->tenant_id) {
        abort(403, 'Unauthorized access to employee data');
    }
    
    // Proceed with update
    $employee->update($request->validated());
}
```

### 2. RADIUS Password Security

**Never store plaintext passwords in application**:
```php
// ❌ BAD
$user = User::create([
    'password' => $request->password,  // Plaintext!
]);

// ✅ GOOD
$user = User::create([
    'password' => Hash::make($request->password),  // Hashed
]);

// RADIUS uses plaintext for authentication, but:
// 1. Only stored in tenant schema (isolated)
// 2. Only accessible via RADIUS protocol
// 3. Transmitted encrypted over network
```

### 3. Schema Name Validation

**Prevent SQL injection in schema names**:
```php
protected function isValidSchemaName(string $schemaName): bool
{
    // Only allow alphanumeric and underscores, max 63 chars
    return preg_match('/^[a-z0-9_]{1,63}$/', $schemaName) === 1;
}

public function setSearchPath(string $schemaName): void
{
    if (!$this->isValidSchemaName($schemaName)) {
        throw new Exception("Invalid schema name: {$schemaName}");
    }
    
    // Safe to use in SQL
    DB::statement("SET search_path TO {$schemaName}, public");
}
```

### 4. API Authentication

**Use Sanctum tokens with abilities**:
```php
// Create token with role-based abilities
$abilities = match($user->role) {
    'system_admin' => ['*'],
    'tenant_admin' => ['tenant:*'],
    'employee' => ['profile:read', 'profile:update', 'todos:*'],
    'farmer' => ['profile:read', 'collections:read'],
    default => ['profile:read'],
};

$token = $user->createToken('auth-token', $abilities)->plainTextToken;
```

### 5. Tenant Suspension Handling

**Check tenant status before operations**:
```php
// In SetTenantContext middleware
if ($tenant && !$tenant->isActive()) {
    return response()->json([
        'success' => false,
        'message' => 'Tenant account is suspended. Please contact support.',
        'suspended_at' => $tenant->suspended_at,
        'suspension_reason' => $tenant->suspension_reason,
    ], 403);
}
```

---

## Performance Optimization

### 1. Connection Pooling

**PostgreSQL Configuration**:
```ini
# postgresql.conf
max_connections = 200
shared_buffers = 256MB
effective_cache_size = 1GB
```

**Laravel Configuration**:
```php
// config/database.php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
    'options' => [
        PDO::ATTR_PERSISTENT => true,  // Connection pooling
    ],
],
```

### 2. Caching Tenant Context

**Cache tenant objects**:
```php
protected function getTenantFromCache(string $tenantId): ?Tenant
{
    $cacheKey = config('multitenancy.cache.prefix') . $tenantId;
    $ttl = config('multitenancy.cache.ttl', 3600);
    
    return Cache::remember($cacheKey, $ttl, function() use ($tenantId) {
        return Tenant::find($tenantId);
    });
}

protected function cacheTenant(Tenant $tenant): void
{
    $cacheKey = config('multitenancy.cache.prefix') . $tenant->id;
    $ttl = config('multitenancy.cache.ttl', 3600);
    
    Cache::put($cacheKey, $tenant, $ttl);
}
```

### 3. Eager Loading Relationships

**❌ BAD - N+1 Query Problem**:
```php
$employees = Employee::all();
foreach ($employees as $employee) {
    echo $employee->department->name;  // Query per employee!
}
```

**✅ GOOD - Eager Loading**:
```php
$employees = Employee::with(['department', 'position'])->get();
foreach ($employees as $employee) {
    echo $employee->department->name;  // No additional queries
}
```

### 4. Index Optimization

**Critical Indexes**:
```sql
-- Public schema
CREATE INDEX idx_users_tenant_id ON users(tenant_id);
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_radius_mapping_username ON radius_user_schema_mapping(username);

-- Tenant schema (create in migration)
CREATE INDEX idx_employees_user_id ON employees(user_id);
CREATE INDEX idx_employees_department_id ON employees(department_id);
CREATE INDEX idx_farmers_user_id ON farmers(user_id);
CREATE INDEX idx_milk_collections_farmer_id ON milk_collections(farmer_id);
CREATE INDEX idx_milk_collections_date ON milk_collections(collection_date);
```

### 5. Query Optimization

**Use select() to limit columns**:
```php
// Only fetch needed columns
$employees = Employee::select('id', 'employee_number', 'first_name', 'last_name')
    ->with(['department:id,name,code'])
    ->get();
```

**Use pagination**:
```php
// Don't load all records at once
$employees = Employee::with(['department', 'position'])
    ->paginate(15);
```

---

## Testing Strategies

### 1. Unit Tests for Tenant Context

```php
// tests/Unit/TenantContextTest.php
class TenantContextTest extends TestCase
{
    public function test_can_set_tenant_context()
    {
        $tenant = Tenant::factory()->create();
        $tenantContext = app(TenantContext::class);
        
        $tenantContext->setTenant($tenant);
        
        $this->assertEquals($tenant->id, $tenantContext->getTenant()->id);
    }
    
    public function test_can_clear_tenant_context()
    {
        $tenant = Tenant::factory()->create();
        $tenantContext = app(TenantContext::class);
        
        $tenantContext->setTenant($tenant);
        $tenantContext->clearTenant();
        
        $this->assertNull($tenantContext->getTenant());
    }
    
    public function test_search_path_is_set_correctly()
    {
        $tenant = Tenant::factory()->create(['schema_name' => 'test_schema']);
        $tenantContext = app(TenantContext::class);
        
        $tenantContext->setTenant($tenant);
        
        $searchPath = DB::selectOne("SHOW search_path")->search_path;
        $this->assertStringContainsString('test_schema', $searchPath);
    }
}
```

### 2. Feature Tests for Multi-Tenancy

```php
// tests/Feature/MultiTenancyTest.php
class MultiTenancyTest extends TestCase
{
    public function test_tenant_data_isolation()
    {
        // Create two tenants
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        
        // Create users for each tenant
        $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);
        
        // Create employees in each tenant schema
        app(TenantContext::class)->runInTenantContext($tenant1, function() use ($user1) {
            Employee::factory()->create(['user_id' => $user1->id]);
        });
        
        app(TenantContext::class)->runInTenantContext($tenant2, function() use ($user2) {
            Employee::factory()->create(['user_id' => $user2->id]);
        });
        
        // Verify isolation: Tenant 1 can only see their employees
        app(TenantContext::class)->runInTenantContext($tenant1, function() {
            $this->assertEquals(1, Employee::count());
        });
        
        // Verify isolation: Tenant 2 can only see their employees
        app(TenantContext::class)->runInTenantContext($tenant2, function() {
            $this->assertEquals(1, Employee::count());
        });
    }
    
    public function test_cannot_access_other_tenant_data()
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        
        $user1 = User::factory()->create(['tenant_id' => $tenant1->id, 'role' => 'tenant_admin']);
        
        // Create employee in tenant2
        $employee2Id = app(TenantContext::class)->runInTenantContext($tenant2, function() {
            return Employee::factory()->create()->id;
        });
        
        // Try to access tenant2 employee as tenant1 user
        $response = $this->actingAs($user1, 'sanctum')
            ->getJson("/api/admin/employees/{$employee2Id}");
        
        $response->assertStatus(404);  // Not found (not in tenant1 schema)
    }
}
```

### 3. RADIUS Authentication Tests

```php
// tests/Feature/RadiusAuthTest.php
class RadiusAuthTest extends TestCase
{
    public function test_radius_authentication_with_schema_mapping()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'username' => 'testuser',
            'role' => 'employee',
        ]);
        
        // Setup RADIUS credentials
        app(TenantContext::class)->runInTenantContext($tenant, function() use ($user) {
            DB::table('radcheck')->insert([
                'username' => $user->username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => 'testpass123',
            ]);
        });
        
        // Add schema mapping
        DB::table('radius_user_schema_mapping')->insert([
            'username' => $user->username,
            'schema_name' => $tenant->schema_name,
            'tenant_id' => $tenant->id,
            'user_role' => 'employee',
            'is_active' => true,
        ]);
        
        // Test authentication
        $response = $this->postJson('/api/login', [
            'username' => 'testuser',
            'password' => 'testpass123',
        ]);
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user',
                    'token',
                    'dashboard_route',
                ],
            ]);
    }
}
```

---

## Deployment Checklist

### Pre-Deployment

- [ ] Run all tests: `php artisan test`
- [ ] Check code quality: `php artisan insights`
- [ ] Verify migrations: `php artisan migrate:status`
- [ ] Review tenant migrations: `ls database/migrations/tenant/`
- [ ] Check environment variables in `.env`
- [ ] Verify RADIUS dictionary file exists
- [ ] Test RADIUS authentication locally
- [ ] Backup production database
- [ ] Review recent code changes
- [ ] Update documentation

### Deployment Steps

1. **Backup Current System**
   ```bash
   # Backup database
   docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup_$(date +%Y%m%d).sql
   
   # Backup tenant schemas
   for schema in $(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"); do
       docker exec traidnet-postgres pg_dump -U admin -n $schema wifi_hotspot > backup_${schema}_$(date +%Y%m%d).sql
   done
   ```

2. **Pull Latest Code**
   ```bash
   git pull origin main
   ```

3. **Update Dependencies**
   ```bash
   docker exec traidnet-backend composer install --no-dev --optimize-autoloader
   ```

4. **Run Migrations**
   ```bash
   # System migrations
   docker exec traidnet-backend php artisan migrate --force
   
   # Tenant migrations
   docker exec traidnet-backend php artisan tenant:migrate --all
   ```

5. **Clear Caches**
   ```bash
   docker exec traidnet-backend php artisan cache:clear
   docker exec traidnet-backend php artisan config:clear
   docker exec traidnet-backend php artisan route:clear
   docker exec traidnet-backend php artisan view:clear
   ```

6. **Rebuild Containers**
   ```bash
   docker-compose up -d --build
   ```

7. **Verify Services**
   ```bash
   docker-compose ps
   docker logs traidnet-backend --tail 50
   docker logs traidnet-freeradius --tail 50
   ```

### Post-Deployment

- [ ] Test login for each role (system_admin, tenant_admin, employee, farmer)
- [ ] Verify tenant data isolation
- [ ] Check RADIUS authentication
- [ ] Test critical workflows (employee creation, farmer creation, milk collection)
- [ ] Monitor error logs
- [ ] Verify real-time notifications
- [ ] Check database connections
- [ ] Monitor system performance
- [ ] Update status page

---

## Monitoring & Maintenance

### 1. Health Checks

**System Health Endpoint**:
```php
// app/Http/Controllers/Api/HealthController.php
public function check()
{
    $checks = [
        'database' => $this->checkDatabase(),
        'redis' => $this->checkRedis(),
        'radius' => $this->checkRadius(),
        'tenants' => $this->checkTenants(),
    ];
    
    $healthy = collect($checks)->every(fn($check) => $check['status'] === 'ok');
    
    return response()->json([
        'status' => $healthy ? 'healthy' : 'degraded',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
}

protected function checkDatabase(): array
{
    try {
        DB::connection()->getPdo();
        return ['status' => 'ok', 'message' => 'Database connected'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

protected function checkTenants(): array
{
    try {
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('is_active', true)->count();
        $suspendedTenants = Tenant::where('is_suspended', true)->count();
        
        return [
            'status' => 'ok',
            'total' => $totalTenants,
            'active' => $activeTenants,
            'suspended' => $suspendedTenants,
        ];
    } catch (\Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
```

### 2. Schema Size Monitoring

**Monitor tenant schema sizes**:
```sql
-- Query to get schema sizes
SELECT 
    schema_name,
    pg_size_pretty(SUM(pg_total_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename)))::bigint) as size
FROM pg_tables
WHERE schemaname LIKE 'tenant_%'
GROUP BY schema_name
ORDER BY SUM(pg_total_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename))) DESC;
```

**Automated monitoring**:
```php
// app/Console/Commands/MonitorTenantSchemas.php
public function handle()
{
    $tenants = Tenant::where('is_active', true)->get();
    
    foreach ($tenants as $tenant) {
        $size = $this->getSchemaSize($tenant->schema_name);
        
        // Update tenant record
        $tenant->update(['database_size' => $size]);
        
        // Alert if size exceeds limit
        $maxSize = config('multitenancy.limits.max_size_mb', 10240);
        if ($size > $maxSize * 1024 * 1024) {
            $this->alert("Tenant {$tenant->name} exceeded size limit: {$size} bytes");
        }
    }
}
```

### 3. Backup Strategy

**Automated Backups**:
```bash
#!/bin/bash
# backup-tenants.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backups/tenants"

# Backup all tenant schemas
for schema in $(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'"); do
    echo "Backing up schema: $schema"
    docker exec traidnet-postgres pg_dump -U admin -n $schema wifi_hotspot | gzip > "${BACKUP_DIR}/${schema}_${DATE}.sql.gz"
done

# Cleanup old backups (keep last 30 days)
find ${BACKUP_DIR} -name "*.sql.gz" -mtime +30 -delete
```

**Schedule in cron**:
```cron
0 2 * * * /path/to/backup-tenants.sh
```

### 4. Log Monitoring

**Key logs to monitor**:
```bash
# Application logs
tail -f backend/storage/logs/laravel.log | grep -E "ERROR|CRITICAL|tenant"

# RADIUS logs
docker logs -f traidnet-freeradius | grep -E "Access-Reject|Failed"

# PostgreSQL logs
docker logs -f traidnet-postgres | grep -E "ERROR|FATAL"
```

### 5. Performance Metrics

**Track key metrics**:
- Average response time per endpoint
- Database query count per request
- Tenant schema sizes
- Active connections per tenant
- RADIUS authentication success rate
- Failed login attempts
- Cache hit rate

---

## Summary

### Key Takeaways

1. **Always use tenant context** - Never manually filter by tenant_id
2. **Validate schema names** - Prevent SQL injection
3. **Cache tenant objects** - Improve performance
4. **Test isolation** - Verify tenants can't access each other's data
5. **Monitor schema sizes** - Prevent runaway growth
6. **Backup regularly** - Per-tenant backups for easy restore
7. **Log with context** - Include tenant info in all logs
8. **Use eager loading** - Avoid N+1 queries
9. **Index strategically** - Optimize common queries
10. **Document everything** - Keep architecture docs updated

### Common Pitfalls to Avoid

- ❌ Forgetting to add RADIUS schema mapping
- ❌ Not clearing tenant context after operations
- ❌ Hardcoding schema names in queries
- ❌ Skipping tenant isolation tests
- ❌ Not monitoring schema sizes
- ❌ Ignoring failed RADIUS authentications
- ❌ Not backing up tenant schemas separately
- ❌ Mixing system and tenant tables in same schema

---

## Additional Resources

- [PostgreSQL Schema Documentation](https://www.postgresql.org/docs/current/ddl-schemas.html)
- [Laravel Multi-Tenancy Packages](https://github.com/stancl/tenancy)
- [FreeRADIUS Documentation](https://freeradius.org/documentation/)
- [Docker Compose Best Practices](https://docs.docker.com/compose/production/)

---

**Document Version**: 1.0  
**Last Updated**: November 30, 2025  
**Maintained By**: System Architecture Team
