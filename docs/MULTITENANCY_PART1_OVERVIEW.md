# Multi-Tenancy Architecture - Part 1: Overview & Core Concepts

## Table of Contents
1. [Introduction](#introduction)
2. [Architecture Overview](#architecture-overview)
3. [Schema-Based Multi-Tenancy](#schema-based-multi-tenancy)
4. [Core Components](#core-components)
5. [Database Schema Design](#database-schema-design)

---

## Introduction

This document describes the **schema-based multi-tenancy architecture** used in the Livestock Management System. This architecture provides **complete data isolation** between tenants while maintaining **optimal performance** and **scalability**.

### What is Multi-Tenancy?

Multi-tenancy is a software architecture where a **single instance** of the application serves **multiple customers (tenants)**. Each tenant's data is isolated and invisible to other tenants.

### Why Schema-Based Multi-Tenancy?

We chose **PostgreSQL schema-based isolation** over other approaches for these reasons:

| Approach | Pros | Cons | Our Choice |
|----------|------|------|------------|
| **Separate Databases** | Complete isolation | High resource overhead, difficult to manage | ❌ |
| **Shared Tables with tenant_id** | Simple, low overhead | Data leakage risk, complex queries | ❌ |
| **Schema-Based** | Strong isolation, good performance, manageable | Moderate complexity | ✅ **SELECTED** |

---

## Architecture Overview

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        APPLICATION LAYER                         │
│  Laravel Backend + Vue.js Frontend + FreeRADIUS + Soketi        │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    TENANT CONTEXT LAYER                          │
│  TenantContext Service + SetTenantContext Middleware             │
│  Identifies tenant → Sets PostgreSQL search_path                 │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    DATABASE LAYER (PostgreSQL)                   │
│                                                                   │
│  ┌──────────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │  PUBLIC SCHEMA   │  │  TENANT 1    │  │  TENANT 2    │      │
│  │  (System-wide)   │  │  SCHEMA      │  │  SCHEMA      │      │
│  │                  │  │              │  │              │      │
│  │  - users         │  │  - employees │  │  - employees │      │
│  │  - tenants       │  │  - farmers   │  │  - farmers   │      │
│  │  - radius_user_  │  │  - animals   │  │  - animals   │      │
│  │    schema_mapping│  │  - radcheck  │  │  - radcheck  │      │
│  │  - subscriptions │  │  - radreply  │  │  - radreply  │      │
│  └──────────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────────┘
```

### Request Flow

```
1. User Login Request
   ↓
2. FreeRADIUS Authentication
   ├─ Check radius_user_schema_mapping (public) → Find tenant schema
   ├─ Query tenant's radcheck table → Verify password
   └─ Return authentication result
   ↓
3. Backend Receives Auth Success
   ├─ Fetch user from public.users
   ├─ Identify tenant_id
   └─ Set PostgreSQL search_path to tenant schema
   ↓
4. SetTenantContext Middleware
   ├─ Verify user is authenticated
   ├─ Get user's tenant_id
   ├─ Set search_path: "tenant_schema, public"
   └─ All queries now use tenant schema first
   ↓
5. Application Logic Executes
   ├─ Models query tenant-specific tables automatically
   ├─ No need to filter by tenant_id in queries
   └─ Complete data isolation guaranteed
   ↓
6. Response Returned
   ↓
7. Middleware Terminate
   └─ Clear tenant context (reset search_path to public)
```

---

## Schema-Based Multi-Tenancy

### What is a PostgreSQL Schema?

A **schema** is a namespace within a PostgreSQL database. Think of it as a **folder** that contains tables, views, functions, etc.

```sql
-- Database: wifi_hotspot
--   ├─ Schema: public (system-wide tables)
--   ├─ Schema: tenant_default (Tenant 1 tables)
--   ├─ Schema: ts_abc123 (Tenant 2 tables)
--   └─ Schema: ts_xyz789 (Tenant 3 tables)
```

### Search Path Mechanism

PostgreSQL uses `search_path` to determine which schema to query:

```sql
-- Default: Only public schema
SET search_path TO public;
SELECT * FROM users;  -- Queries public.users

-- Tenant context: Tenant schema first, then public
SET search_path TO tenant_abc123, public;
SELECT * FROM employees;  -- Queries tenant_abc123.employees
SELECT * FROM users;      -- Queries public.users (not in tenant schema)
```

### Benefits

1. **Data Isolation**: Each tenant's data is physically separated
2. **Performance**: No tenant_id filtering needed in queries
3. **Security**: Impossible to accidentally query another tenant's data
4. **Scalability**: Can move tenant schemas to different databases
5. **Backup/Restore**: Can backup/restore individual tenants
6. **Testing**: Easy to create test tenants and delete them

---

## Core Components

### 1. Configuration File

**Location**: `backend/config/multitenancy.php`

**Key Settings**:
```php
return [
    // Multi-tenancy mode (schema-based)
    'mode' => 'schema',
    
    // System schema name
    'system_schema' => 'public',
    
    // Tenant schema prefix
    'tenant_schema_prefix' => 'tenant_',
    
    // System tables (in public schema)
    'system_tables' => [
        'tenants', 'users', 'subscriptions', 'payments',
        'radcheck', 'radreply', 'radius_user_schema_mapping'
    ],
    
    // Tenant tables (in tenant schemas)
    'tenant_tables' => [
        'employees', 'departments', 'farmers', 'animals',
        'milk_collections', 'radcheck', 'radreply'
    ],
    
    // Auto-create schema on tenant registration
    'auto_create_schema' => true,
    
    // Auto-run migrations on schema creation
    'auto_migrate_schema' => true,
];
```

### 2. Tenant Model

**Location**: `backend/app/Models/Tenant.php`

**Key Fields**:
```php
class Tenant extends Model
{
    protected $fillable = [
        'name',              // Tenant name (e.g., "ABC Cooperative")
        'slug',              // Unique slug (e.g., "abc-coop")
        'schema_name',       // PostgreSQL schema (e.g., "tenant_abc_coop")
        'email',             // Contact email
        'is_active',         // Active status
        'is_suspended',      // Suspension status
        'schema_created',    // Schema creation flag
        'schema_created_at', // Schema creation timestamp
    ];
}
```

### 3. TenantContext Service

**Location**: `backend/app/Services/TenantContext.php`

**Purpose**: Manages the current tenant context and PostgreSQL search_path

**Key Methods**:
```php
class TenantContext
{
    // Set tenant context by tenant object
    public function setTenant(?Tenant $tenant): void;
    
    // Set tenant context by tenant ID
    public function setTenantById(string $tenantId): void;
    
    // Set tenant context by user
    public function setTenantByUser(User $user): void;
    
    // Get current tenant
    public function getTenant(): ?Tenant;
    
    // Clear tenant context (reset to public schema)
    public function clearTenant(): void;
    
    // Run code in tenant context
    public function runInTenantContext(Tenant $tenant, callable $callback);
}
```

**Usage Example**:
```php
// Inject service
protected $tenantContext;

public function __construct(TenantContext $tenantContext)
{
    $this->tenantContext = $tenantContext;
}

// Set tenant context
$this->tenantContext->setTenantById($tenantId);

// Now all queries use tenant schema
$employees = Employee::all(); // Queries tenant_abc.employees

// Clear context when done
$this->tenantContext->clearTenant();
```

### 4. SetTenantContext Middleware

**Location**: `backend/app/Http/Middleware/SetTenantContext.php`

**Purpose**: Automatically sets tenant context for authenticated requests

**Flow**:
```php
public function handle(Request $request, Closure $next): Response
{
    // 1. Check if user is authenticated
    if ($request->user()) {
        $user = $request->user();
        
        // 2. System admins use public schema
        if ($user->role === 'system_admin') {
            $this->tenantContext->clearTenant();
            return $next($request);
        }
        
        // 3. Regular users: set tenant context
        if ($user->tenant_id) {
            $tenant = Tenant::find($user->tenant_id);
            
            // 4. Verify tenant is active
            if (!$tenant->isActive()) {
                return response()->json(['message' => 'Tenant suspended'], 403);
            }
            
            // 5. Set tenant context
            $this->tenantContext->setTenantByUser($user);
        }
    } else {
        // 6. No user: use public schema
        $this->tenantContext->clearTenant();
    }
    
    return $next($request);
}

public function terminate(Request $request, Response $response): void
{
    // 7. Clear context after request completes
    $this->tenantContext->clearTenant();
}
```

### 5. TenantSchemaManager Service

**Location**: `backend/app/Services/TenantSchemaManager.php`

**Purpose**: Manages tenant schema lifecycle (create, drop, backup, migrate)

**Key Methods**:
```php
class TenantSchemaManager
{
    // Create a new tenant schema
    public function createSchema(Tenant $tenant): bool;
    
    // Drop a tenant schema
    public function dropSchema(Tenant $tenant, bool $cascade = true): bool;
    
    // Run migrations for tenant schema
    public function runMigrations(Tenant $tenant): bool;
    
    // Seed data for tenant schema
    public function seedData(Tenant $tenant): bool;
    
    // Backup tenant schema
    public function backupSchema(Tenant $tenant): string;
    
    // Restore tenant schema from backup
    public function restoreSchema(Tenant $tenant, string $backupFile): bool;
    
    // Check if schema exists
    public function schemaExists(string $schemaName): bool;
    
    // Get schema size
    public function getSchemaSize(string $schemaName): int;
}
```

---

## Database Schema Design

### Public Schema (System-Wide)

**Purpose**: Contains tables shared across all tenants

**Tables**:

#### 1. `tenants` Table
```sql
CREATE TABLE tenants (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    schema_name VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    is_suspended BOOLEAN DEFAULT FALSE,
    suspended_at TIMESTAMP,
    schema_created BOOLEAN DEFAULT FALSE,
    schema_created_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### 2. `users` Table
```sql
CREATE TABLE users (
    id UUID PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    name VARCHAR(255) NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    phone_number VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### 3. `radius_user_schema_mapping` Table
```sql
CREATE TABLE radius_user_schema_mapping (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    schema_name VARCHAR(64) NOT NULL,
    tenant_id UUID REFERENCES tenants(id),
    user_role VARCHAR(32),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Critical for FreeRADIUS to find tenant schema
CREATE INDEX idx_username ON radius_user_schema_mapping(username);
CREATE INDEX idx_schema_name ON radius_user_schema_mapping(schema_name);
```

**Why This Table Exists**:
- FreeRADIUS needs to know which tenant schema to query
- This mapping is checked BEFORE tenant context is established
- Maps username → tenant schema → radcheck/radreply tables

### Tenant Schema (Per-Tenant)

**Purpose**: Contains tenant-specific data

**Schema Naming**: `tenant_{slug}` or `ts_{short_hash}`
- Examples: `tenant_abc_coop`, `ts_a1b2c3d4`

**Tables** (examples):

#### 1. `employees` Table
```sql
-- In tenant schema (e.g., tenant_abc_coop.employees)
CREATE TABLE employees (
    id UUID PRIMARY KEY,
    user_id UUID,  -- References public.users
    employee_number VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    department_id UUID,
    position_id UUID,
    hire_date DATE,
    salary DECIMAL(12,2),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### 2. `departments` Table
```sql
CREATE TABLE departments (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    status VARCHAR(50) DEFAULT 'active',
    employee_count INTEGER DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### 3. `farmers` Table
```sql
CREATE TABLE farmers (
    id UUID PRIMARY KEY,
    user_id UUID,  -- References public.users
    farmer_code VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    farmer_group_id UUID,
    farm_name VARCHAR(255),
    location TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### 4. `radcheck` Table (Tenant RADIUS Credentials)
```sql
CREATE TABLE radcheck (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) DEFAULT ':=',
    value VARCHAR(253) NOT NULL
);

-- Each tenant has their own radcheck table
-- Credentials are isolated per tenant
```

#### 5. `radreply` Table (Tenant RADIUS Attributes)
```sql
CREATE TABLE radreply (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) DEFAULT ':=',
    value VARCHAR(253) NOT NULL
);

-- Stores Tenant-ID attribute for schema identification
```

---

## Schema Isolation Guarantees

### 1. Query Isolation

When tenant context is set:
```php
// Context: tenant_abc_coop
Employee::all();  // SELECT * FROM tenant_abc_coop.employees
User::all();      // SELECT * FROM public.users
```

### 2. No Cross-Tenant Queries

```php
// Tenant A context
$tenantContext->setTenantById($tenantA->id);
$employees = Employee::all();  // Only Tenant A employees

// Switch to Tenant B
$tenantContext->setTenantById($tenantB->id);
$employees = Employee::all();  // Only Tenant B employees

// No way to accidentally query Tenant A data while in Tenant B context
```

### 3. System Admin Access

```php
// System admin can access any tenant
$tenantContext->runInTenantContext($tenant, function() {
    $employees = Employee::all();  // Tenant's employees
});

// Context automatically cleared after callback
```

---

## Next Steps

Continue to:
- **Part 2**: [Implementation Details](./MULTITENANCY_PART2_IMPLEMENTATION.md)
- **Part 3**: [RADIUS Integration](./MULTITENANCY_PART3_RADIUS.md)
- **Part 4**: [Best Practices](./MULTITENANCY_PART4_BEST_PRACTICES.md)

---

**Document Version**: 1.0  
**Last Updated**: November 30, 2025  
**Author**: System Architecture Team
