# Multi-Tenant RADIUS Architecture

## Overview

This document describes the schema-based multi-tenant RADIUS authentication architecture implemented in the WiFi Hotspot Management System.

## Architecture Principles

### 1. **Complete Data Isolation**
- Each tenant has their own PostgreSQL schema (e.g., `ts_abc123`)
- All tenant data, including RADIUS tables, reside in the tenant schema
- No cross-tenant data leaking is possible

### 2. **Schema-Based Multi-Tenancy**
```
Public Schema:
├── users (all users, with tenant_id)
├── tenants (tenant registry)
├── radius_user_schema_mapping (username → schema mapping)
└── radcheck/radreply (ONLY for system admins)

Tenant Schema (ts_abc123):
├── radcheck (tenant's RADIUS credentials)
├── radreply (tenant's RADIUS attributes)
├── radacct (tenant's accounting data)
├── radpostauth (tenant's auth logs)
└── [all other tenant tables]
```

### 3. **AAA (Authentication, Authorization, Accounting)**
- **ALL users** (system admins, tenant admins, hotspot users) authenticate via FreeRADIUS
- System admins use public schema RADIUS tables
- Tenant users use their tenant schema RADIUS tables

## Authentication Flow

### Tenant User Login

```
1. User visits: https://acme.example.com/login
   └─> Subdomain "acme" extracted

2. Backend identifies tenant from subdomain
   └─> Finds tenant with subdomain="acme"
   └─> Gets tenant schema: "ts_abc123"

3. User lookup (without tenant scope)
   └─> Finds user by username/email
   └─> Validates user belongs to identified tenant

4. RADIUS Authentication (Schema-Aware)
   └─> SET search_path TO ts_abc123, public
   └─> Query radcheck in ts_abc123 schema
   └─> Authenticate credentials
   └─> Return radreply attributes (including Tenant-ID)

5. Token Generation
   └─> Create Sanctum token with tenant context
   └─> Return user data + tenant info
```

### System Admin Login

```
1. User visits: https://example.com/login (no subdomain)
   └─> No tenant identified

2. User lookup
   └─> Finds system admin user

3. RADIUS Authentication (Public Schema)
   └─> Query radcheck in public schema
   └─> Authenticate credentials

4. Token Generation
   └─> Create Sanctum token with system admin abilities
```

## Database Schema

### Public Schema Tables

#### `users`
```sql
CREATE TABLE users (
    id UUID PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    username VARCHAR(255) UNIQUE,
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    role VARCHAR(50), -- system_admin, admin, hotspot_user
    ...
);
```

#### `tenants`
```sql
CREATE TABLE tenants (
    id UUID PRIMARY KEY,
    name VARCHAR(255),
    slug VARCHAR(255) UNIQUE,
    subdomain VARCHAR(255) UNIQUE,
    schema_name VARCHAR(255) UNIQUE,
    custom_domain VARCHAR(255),
    is_active BOOLEAN,
    ...
);
```

#### `radius_user_schema_mapping`
```sql
CREATE TABLE radius_user_schema_mapping (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE,
    schema_name VARCHAR(255),
    tenant_id UUID REFERENCES tenants(id),
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Tenant Schema Tables

Each tenant schema contains:

#### `radcheck` (Authentication Credentials)
```sql
CREATE TABLE radcheck (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64),
    attribute VARCHAR(64), -- e.g., 'Cleartext-Password'
    op CHAR(2), -- ':=' for assignment
    value VARCHAR(253), -- password or other value
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### `radreply` (Authorization Attributes)
```sql
CREATE TABLE radreply (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64),
    attribute VARCHAR(64), -- e.g., 'Tenant-ID', 'Service-Type'
    op CHAR(2), -- ':=' for assignment
    value VARCHAR(253),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Example radreply entries:**
```sql
-- Tenant identification
INSERT INTO radreply (username, attribute, op, value)
VALUES ('john.doe', 'Tenant-ID', ':=', 'ts_abc123');

-- Service type
INSERT INTO radreply (username, attribute, op, value)
VALUES ('john.doe', 'Service-Type', ':=', 'Administrative-User');
```

## Code Implementation

### RadiusService (Schema-Aware)

```php
class RadiusService
{
    /**
     * Authenticate user with tenant schema context
     */
    public function authenticate(
        string $username, 
        string $password, 
        ?string $tenantSchemaName = null
    ): bool {
        try {
            // Set tenant schema context
            if ($tenantSchemaName) {
                DB::statement("SET search_path TO {$tenantSchemaName}, public");
            }
            
            // Authenticate via RADIUS
            $result = $this->radius->accessRequest($username, $password);
            
            return $result === true;
        } finally {
            // Reset to public schema
            if ($tenantSchemaName) {
                DB::statement("SET search_path TO public");
            }
        }
    }
    
    /**
     * Create RADIUS user in tenant schema
     */
    public function createUser(
        string $username, 
        string $password, 
        ?string $tenantSchemaName = null
    ): bool {
        try {
            if ($tenantSchemaName) {
                DB::statement("SET search_path TO {$tenantSchemaName}, public");
            }
            
            // Insert into radcheck
            DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
            ]);
            
            // Insert Tenant-ID into radreply
            DB::table('radreply')->insert([
                'username' => $username,
                'attribute' => 'Tenant-ID',
                'op' => ':=',
                'value' => $tenantSchemaName,
            ]);
            
            return true;
        } finally {
            if ($tenantSchemaName) {
                DB::statement("SET search_path TO public");
            }
        }
    }
}
```

### UnifiedAuthController (Tenant-Aware Login)

```php
class UnifiedAuthController
{
    public function login(Request $request)
    {
        // 1. Extract subdomain
        $subdomain = $this->extractSubdomain($request->getHost());
        
        // 2. Find tenant by subdomain
        $tenant = Tenant::where('subdomain', $subdomain)->first();
        
        // 3. Find user
        $user = User::where('username', $request->username)->first();
        
        // 4. Validate user belongs to tenant (if tenant identified)
        if ($tenant && $user->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        
        // 5. Get tenant schema
        $tenantSchemaName = $user->tenant?->schema_name;
        
        // 6. Authenticate via RADIUS with schema context
        $authenticated = $this->radiusService->authenticate(
            $user->username,
            $request->password,
            $tenantSchemaName
        );
        
        if (!$authenticated) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }
        
        // 7. Generate token and return
        $token = $user->createToken('auth-token')->plainTextToken;
        
        return response()->json([
            'token' => $token,
            'user' => $user,
            'tenant' => $user->tenant,
        ]);
    }
}
```

## FreeRADIUS Configuration

### SQL Module (`freeradius/sql`)

```
sql {
    driver = "rlm_sql_postgresql"
    server = "traidnet-postgres"
    radius_db = "wifi_hotspot"
    
    # Schema-based multi-tenancy support
    # FreeRADIUS queries will use the search_path set by Laravel
    # before the authentication request
    
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    ...
}
```

### Custom Dictionary (`freeradius/dictionary`)

```
# Custom attributes for multi-tenant system
ATTRIBUTE	Tenant-ID		3100	string
```

This attribute is returned in Access-Accept responses to identify the tenant.

## Security Considerations

### 1. **Subdomain Validation**
- Users MUST login via their tenant's subdomain
- Cross-tenant login attempts are blocked
- System admins cannot login via tenant subdomains

### 2. **Schema Isolation**
- PostgreSQL search_path ensures queries only access tenant schema
- No SQL injection can access other tenant data
- Each tenant's RADIUS credentials are completely isolated

### 3. **Password Storage**
- Passwords stored as `Cleartext-Password` in radcheck (RADIUS requirement)
- Database-level encryption recommended for production
- Regular password rotation enforced

### 4. **Rate Limiting**
- Login attempts rate-limited per IP
- Failed login tracking per user
- Automatic account suspension after threshold

## Troubleshooting

### Login Fails with "Invalid credentials"

1. **Check user exists in database:**
   ```sql
   SELECT * FROM users WHERE username = 'john.doe';
   ```

2. **Check tenant schema exists:**
   ```sql
   SELECT schema_name FROM information_schema.schemata 
   WHERE schema_name = 'ts_abc123';
   ```

3. **Check radcheck entry exists in tenant schema:**
   ```sql
   SET search_path TO ts_abc123, public;
   SELECT * FROM radcheck WHERE username = 'john.doe';
   ```

4. **Check FreeRADIUS logs:**
   ```bash
   docker logs traidnet-freeradius --tail=100
   ```

### User created but RADIUS authentication fails

1. **Ensure RADIUS entry was created:**
   ```sql
   SET search_path TO ts_abc123, public;
   SELECT * FROM radcheck WHERE username = 'john.doe';
   SELECT * FROM radreply WHERE username = 'john.doe';
   ```

2. **Run migration to ensure entries:**
   ```bash
   php artisan migrate --path=database/migrations/2025_12_06_000001_ensure_tenant_radius_entries_with_tenant_id.php
   ```

### Subdomain not recognized

1. **Check tenant subdomain configuration:**
   ```sql
   SELECT id, name, subdomain, schema_name FROM tenants;
   ```

2. **Verify DNS/hosts file:**
   ```
   127.0.0.1 acme.localhost
   ```

3. **Check middleware is applied:**
   - `IdentifyTenantFromSubdomain` middleware should be in route group

## Migration Guide

### Adding New Tenant

1. **Create tenant record:**
   ```php
   $tenant = Tenant::create([
       'name' => 'ACME Corp',
       'slug' => 'acme',
       'subdomain' => 'acme',
       'schema_name' => 'ts_' . Str::random(8),
   ]);
   ```

2. **Create tenant schema:**
   ```sql
   CREATE SCHEMA ts_abc123;
   ```

3. **Run tenant migrations:**
   ```bash
   php artisan tenants:migrate --tenant=ts_abc123
   ```

4. **Create tenant admin:**
   ```php
   $user = User::create([
       'tenant_id' => $tenant->id,
       'username' => 'admin',
       'email' => 'admin@acme.com',
       'password' => Hash::make('password'),
       'role' => 'admin',
   ]);
   ```

5. **Create RADIUS entries:**
   ```php
   DB::statement("SET search_path TO ts_abc123, public");
   
   DB::table('radcheck')->insert([
       'username' => 'admin',
       'attribute' => 'Cleartext-Password',
       'op' => ':=',
       'value' => 'password',
   ]);
   
   DB::table('radreply')->insert([
       'username' => 'admin',
       'attribute' => 'Tenant-ID',
       'op' => ':=',
       'value' => 'ts_abc123',
   ]);
   ```

## Best Practices

1. **Always use subdomain for tenant login**
2. **Never hardcode schema names**
3. **Always reset search_path after tenant operations**
4. **Use transactions for multi-table operations**
5. **Log all RADIUS authentication attempts**
6. **Monitor failed login attempts**
7. **Regularly audit RADIUS entries**
8. **Implement password rotation policies**

## References

- [FreeRADIUS SQL Module Documentation](https://wiki.freeradius.org/modules/Rlm_sql)
- [PostgreSQL Schema Documentation](https://www.postgresql.org/docs/current/ddl-schemas.html)
- [Laravel Multi-Tenancy](https://laravel.com/docs/multi-tenancy)
