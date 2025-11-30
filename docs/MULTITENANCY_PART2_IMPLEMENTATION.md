# Multi-Tenancy Architecture - Part 2: Implementation Details

## Table of Contents
1. [Tenant Registration Flow](#tenant-registration-flow)
2. [Schema Creation Process](#schema-creation-process)
3. [Migration Management](#migration-management)
4. [User Creation & RADIUS Setup](#user-creation--radius-setup)
5. [Code Examples](#code-examples)

---

## Tenant Registration Flow

### Complete Registration Process

```
┌──────────────────────────────────────────────────────────────┐
│ 1. Frontend: Tenant Registration Form                        │
│    - Tenant name, slug, admin details                        │
│    - Email, phone, password                                  │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 2. Backend: TenantRegistrationController::register()         │
│    - Validate input data                                     │
│    - Check slug uniqueness                                   │
│    - Begin database transaction                              │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 3. Create Tenant Record (public.tenants)                     │
│    - Generate UUID                                           │
│    - Set schema_name = "tenant_{slug}"                       │
│    - Save to database                                        │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 4. Create Tenant Schema (TenantSchemaManager)                │
│    - CREATE SCHEMA tenant_{slug}                             │
│    - GRANT permissions to database user                      │
│    - Mark schema_created = true                              │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 5. Run Tenant Migrations                                     │
│    - SET search_path TO tenant_{slug}, public                │
│    - Run migrations from database/migrations/tenant/         │
│    - Create all tenant tables                                │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 6. Create Admin User (public.users)                          │
│    - Generate UUID                                           │
│    - Hash password                                           │
│    - Set role = 'tenant_admin'                               │
│    - Link to tenant via tenant_id                            │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 7. Setup RADIUS Authentication                               │
│    - Add to tenant's radcheck table (password)               │
│    - Add to tenant's radreply table (Tenant-ID)              │
│    - Add to public.radius_user_schema_mapping                │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 8. Commit Transaction & Return Success                       │
│    - Return tenant details + admin credentials               │
└──────────────────────────────────────────────────────────────┘
```

### Code Implementation

**File**: `backend/app/Http/Controllers/Api/TenantRegistrationController.php`

```php
public function register(Request $request)
{
    // 1. Validate input
    $validator = Validator::make($request->all(), [
        'tenant_name' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:tenants,slug|regex:/^[a-z0-9-]+$/',
        'admin_name' => 'required|string|max:255',
        'admin_username' => 'required|string|max:255|unique:users,username',
        'admin_email' => 'required|email|unique:users,email',
        'admin_password' => 'required|string|min:8|confirmed',
        'phone_number' => 'nullable|string|max:50',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();
    
    try {
        // 2. Create tenant record
        $tenant = Tenant::create([
            'name' => $request->tenant_name,
            'slug' => $request->slug,
            'schema_name' => 'tenant_' . $request->slug,
            'email' => $request->admin_email,
            'phone' => $request->phone_number,
            'is_active' => true,
        ]);

        // 3. Create tenant schema
        $schemaManager = app(TenantSchemaManager::class);
        $schemaManager->createSchema($tenant);

        // 4. Create admin user
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->admin_name,
            'username' => $request->admin_username,
            'email' => $request->admin_email,
            'password' => Hash::make($request->admin_password),
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        // 5. Setup RADIUS authentication
        $this->setupRADIUSAuth(
            $request->admin_username,
            $request->admin_password,
            $tenant
        );

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Tenant registered successfully',
            'data' => [
                'tenant' => $tenant,
                'admin_user' => $user->makeVisible(['username']),
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Tenant registration failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Registration failed: ' . $e->getMessage()
        ], 500);
    }
}

protected function setupRADIUSAuth($username, $password, $tenant)
{
    $schemaName = $tenant->schema_name;
    
    // Switch to tenant schema
    DB::statement("SET search_path TO {$schemaName}, public");
    
    // Add to tenant's radcheck
    DB::table('radcheck')->insert([
        'username' => $username,
        'attribute' => 'Cleartext-Password',
        'op' => ':=',
        'value' => $password,
    ]);
    
    // Add to tenant's radreply
    DB::table('radreply')->insert([
        'username' => $username,
        'attribute' => 'Tenant-ID',
        'op' => ':=',
        'value' => $schemaName,
    ]);
    
    // Switch to public schema
    DB::statement("SET search_path TO public");
    
    // Add to radius_user_schema_mapping
    DB::table('radius_user_schema_mapping')->insert([
        'username' => $username,
        'schema_name' => $schemaName,
        'tenant_id' => $tenant->id,
        'user_role' => 'tenant_admin',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
```

---

## Schema Creation Process

### TenantSchemaManager Implementation

**File**: `backend/app/Services/TenantSchemaManager.php`

```php
public function createSchema(Tenant $tenant): bool
{
    try {
        $schemaName = $tenant->schema_name;

        // 1. Validate schema name
        if (!$this->isValidSchemaName($schemaName)) {
            throw new Exception("Invalid schema name: {$schemaName}");
        }

        // 2. Check if schema already exists
        if ($this->schemaExists($schemaName)) {
            Log::warning("Schema already exists: {$schemaName}");
            return true;
        }

        Log::info("Creating schema for tenant: {$tenant->name} ({$schemaName})");

        // 3. Create the schema
        DB::statement("CREATE SCHEMA IF NOT EXISTS {$schemaName}");

        // 4. Grant permissions
        $dbUser = config('database.connections.pgsql.username');
        DB::statement("GRANT ALL ON SCHEMA {$schemaName} TO {$dbUser}");
        DB::statement("GRANT ALL ON ALL TABLES IN SCHEMA {$schemaName} TO {$dbUser}");
        DB::statement("GRANT ALL ON ALL SEQUENCES IN SCHEMA {$schemaName} TO {$dbUser}");

        // 5. Set default privileges for future objects
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schemaName} GRANT ALL ON TABLES TO {$dbUser}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schemaName} GRANT ALL ON SEQUENCES TO {$dbUser}");

        // 6. Update tenant record
        $tenant->update([
            'schema_created' => true,
            'schema_created_at' => now()
        ]);

        Log::info("Schema created successfully: {$schemaName}");

        // 7. Run migrations if auto-migrate is enabled
        if (config('multitenancy.auto_migrate_schema', true)) {
            $this->runMigrations($tenant);
        }

        // 8. Seed data if auto-seed is enabled
        if (config('multitenancy.auto_seed_schema', false)) {
            $this->seedData($tenant);
        }

        return true;
    } catch (Exception $e) {
        Log::error("Failed to create schema for tenant {$tenant->name}: " . $e->getMessage());
        throw $e;
    }
}

public function schemaExists(string $schemaName): bool
{
    $result = DB::selectOne(
        "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
        [$schemaName]
    );
    
    return $result->exists;
}

protected function isValidSchemaName(string $schemaName): bool
{
    // Schema name must be alphanumeric with underscores, max 63 chars
    return preg_match('/^[a-z0-9_]{1,63}$/', $schemaName) === 1;
}
```

---

## Migration Management

### Migration Directory Structure

```
backend/database/migrations/
├── 0001_01_01_000000_create_tenants_table.php        # System migration
├── 0001_01_01_000001_create_users_table.php          # System migration
├── 2025_11_19_000000_add_schema_to_tenants.php       # System migration
├── 2025_11_22_000030_create_radius_user_schema_mapping.php  # System
└── tenant/                                            # Tenant migrations
    ├── 2025_01_01_000001_create_tenant_farmers_table.php
    ├── 2025_01_01_000009_create_tenant_departments_table.php
    ├── 2025_01_01_000011_create_tenant_employees_table.php
    ├── 2025_01_01_000027_create_tenant_radius_tables.php
    └── ...
```

### Running Tenant Migrations

**Method 1: Automatic (during schema creation)**
```php
// In TenantSchemaManager::createSchema()
if (config('multitenancy.auto_migrate_schema', true)) {
    $this->runMigrations($tenant);
}
```

**Method 2: Manual via Artisan Command**
```bash
# Migrate specific tenant
php artisan tenant:migrate {tenant_id}

# Migrate all tenants
php artisan tenant:migrate --all

# Rollback tenant migrations
php artisan tenant:migrate:rollback {tenant_id}
```

**Method 3: Programmatic**
```php
public function runMigrations(Tenant $tenant): bool
{
    try {
        $schemaName = $tenant->schema_name;

        Log::info("Running migrations for tenant: {$tenant->name} ({$schemaName})");

        // Set search path to tenant schema
        DB::statement("SET search_path TO {$schemaName}, {$this->systemSchema}");

        // Run tenant-specific migrations
        $migrationPath = database_path('migrations/tenant');
        
        if (File::exists($migrationPath)) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
        }

        // Reset search path
        DB::statement("SET search_path TO {$this->systemSchema}");

        Log::info("Migrations completed for tenant: {$tenant->name}");

        return true;
    } catch (Exception $e) {
        Log::error("Failed to run migrations for tenant {$tenant->name}: " . $e->getMessage());
        throw $e;
    }
}
```

### Creating Tenant-Specific Migrations

**Example**: Create `employees` table migration

**File**: `database/migrations/tenant/2025_01_01_000011_create_tenant_employees_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // This will create the table in the current search_path (tenant schema)
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('employee_number', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 100)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('employment_type', 50)->default('full_time');
            $table->decimal('salary', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('position_id')->references('id')->on('positions')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
```

**Important Notes**:
- No need to specify schema name in migration
- Search path is set before running migrations
- Foreign keys reference tables in the same schema
- Use `Schema::create()` as normal

---

## User Creation & RADIUS Setup

### Employee Creation Flow

```
┌──────────────────────────────────────────────────────────────┐
│ 1. Admin Creates Employee via API                            │
│    POST /api/admin/employees                                 │
│    - Employee details + create_user_account: true            │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 2. EmployeeController::store()                               │
│    - Validate input                                          │
│    - Begin transaction                                       │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 3. Create User Record (public.users)                         │
│    - Generate username (auto or provided)                    │
│    - Generate password (auto or provided)                    │
│    - Set role = 'employee'                                   │
│    - Link to tenant                                          │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 4. Create Employee Record (tenant.employees)                 │
│    - Link to user via user_id                                │
│    - Set department and position                             │
│    - Store employee details                                  │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 5. Setup RADIUS Authentication                               │
│    - Add to tenant.radcheck (password)                       │
│    - Add to tenant.radreply (Tenant-ID = schema_name)        │
│    - Add to public.radius_user_schema_mapping                │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 6. Send Credentials (optional)                               │
│    - Email or SMS with username/password                     │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ 7. Commit Transaction & Return Success                       │
└──────────────────────────────────────────────────────────────┘
```

### Code Implementation

**File**: `backend/app/Http/Controllers/Api/EmployeeController.php`

```php
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'first_name' => 'required|string|max:100',
        'last_name' => 'required|string|max:100',
        'email' => 'nullable|email|unique:employees,email',
        'phone_number' => 'required|string|max:20',
        'department_id' => 'required|uuid|exists:departments,id',
        'position_id' => 'required|uuid|exists:positions,id',
        'hire_date' => 'required|date',
        'employment_type' => 'required|in:full_time,part_time,contract',
        'salary' => 'nullable|numeric|min:0',
        'create_user_account' => 'nullable|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();

    try {
        $employeeData = $validator->validated();
        $createAccount = $employeeData['create_user_account'] ?? false;
        unset($employeeData['create_user_account']);

        // Generate employee number
        $employeeData['employee_number'] = $this->generateEmployeeNumber();

        $user = null;
        $generatedPassword = null;

        // Create user account if requested
        if ($createAccount) {
            $username = $this->generateUsername();
            $generatedPassword = Str::random(12);

            $user = User::create([
                'tenant_id' => auth()->user()->tenant_id,
                'name' => $employeeData['first_name'] . ' ' . $employeeData['last_name'],
                'username' => $username,
                'email' => $employeeData['email'] ?? $username . '@employee.local',
                'password' => Hash::make($generatedPassword),
                'role' => 'employee',
                'phone_number' => $employeeData['phone_number'],
                'is_active' => true,
            ]);

            $employeeData['user_id'] = $user->id;

            // Setup RADIUS authentication
            $this->setupEmployeeRADIUS($username, $generatedPassword);
        }

        // Create employee record
        $employee = Employee::create($employeeData);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully',
            'data' => [
                'employee' => $employee->load(['department', 'position', 'user']),
                'credentials' => $createAccount ? [
                    'username' => $user->username,
                    'password' => $generatedPassword,
                ] : null,
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Employee creation failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to create employee: ' . $e->getMessage()
        ], 500);
    }
}

protected function setupEmployeeRADIUS($username, $password)
{
    $tenant = auth()->user()->tenant;
    $schemaName = $tenant->schema_name;
    
    // Get current search path
    $currentSearchPath = DB::selectOne("SHOW search_path")->search_path;
    
    // Add to tenant's radcheck (already in tenant schema)
    DB::table('radcheck')->updateOrInsert(
        ['username' => $username, 'attribute' => 'Cleartext-Password'],
        ['op' => ':=', 'value' => $password]
    );
    
    // Add to tenant's radreply
    DB::table('radreply')->updateOrInsert(
        ['username' => $username, 'attribute' => 'Tenant-ID'],
        ['op' => ':=', 'value' => $schemaName]
    );
    
    // Switch to public schema for mapping
    DB::statement("SET search_path TO public");
    
    // Add to radius_user_schema_mapping
    DB::table('radius_user_schema_mapping')->updateOrInsert(
        ['username' => $username],
        [
            'schema_name' => $schemaName,
            'tenant_id' => $tenant->id,
            'user_role' => 'employee',
            'is_active' => true,
            'updated_at' => now(),
            'created_at' => now(),
        ]
    );
    
    // Restore search path
    DB::statement("SET search_path TO {$currentSearchPath}");
}
```

---

## Code Examples

### Example 1: Query Tenant Data

```php
// In a controller with SetTenantContext middleware

public function index(Request $request)
{
    // Middleware has already set tenant context
    // All queries automatically use tenant schema
    
    $employees = Employee::with(['department', 'position'])
        ->where('is_active', true)
        ->paginate(15);
    
    // This queries: tenant_abc.employees
    // Joins with: tenant_abc.departments, tenant_abc.positions
    
    return response()->json($employees);
}
```

### Example 2: Cross-Schema Query

```php
// Query user (public schema) and employee (tenant schema)

public function show($id)
{
    // Tenant context is set, so Employee uses tenant schema
    $employee = Employee::with(['department', 'position'])->findOrFail($id);
    
    // User is in public schema, accessible via relationship
    $user = $employee->user;  // Queries public.users
    
    return response()->json([
        'employee' => $employee,
        'user' => $user,
    ]);
}
```

### Example 3: Manual Tenant Context Switching

```php
// System admin accessing multiple tenants

public function getTenantReport(Request $request)
{
    $tenantId = $request->input('tenant_id');
    $tenant = Tenant::findOrFail($tenantId);
    
    // Run code in tenant context
    $data = app(TenantContext::class)->runInTenantContext($tenant, function() {
        return [
            'employees_count' => Employee::count(),
            'farmers_count' => Farmer::count(),
            'collections_today' => MilkCollection::whereDate('created_at', today())->count(),
        ];
    });
    
    // Context automatically cleared after callback
    
    return response()->json($data);
}
```

### Example 4: Bulk Operations Across Tenants

```php
// System admin: Run operation for all tenants

public function updateAllTenantSchemas()
{
    $tenants = Tenant::where('is_active', true)->get();
    $results = [];
    
    foreach ($tenants as $tenant) {
        try {
            app(TenantContext::class)->runInTenantContext($tenant, function() use ($tenant) {
                // Run migrations
                Artisan::call('migrate', [
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);
                
                Log::info("Migrations completed for tenant: {$tenant->name}");
            });
            
            $results[$tenant->id] = 'success';
        } catch (\Exception $e) {
            $results[$tenant->id] = 'failed: ' . $e->getMessage();
            Log::error("Failed to update tenant {$tenant->name}: " . $e->getMessage());
        }
    }
    
    return response()->json(['results' => $results]);
}
```

---

## Next Steps

Continue to:
- **Part 3**: [RADIUS Integration](./MULTITENANCY_PART3_RADIUS.md)
- **Part 4**: [Best Practices](./MULTITENANCY_PART4_BEST_PRACTICES.md)

---

**Document Version**: 1.0  
**Last Updated**: November 30, 2025
