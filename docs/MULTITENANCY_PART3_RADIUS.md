# Multi-Tenancy Architecture - Part 3: RADIUS Integration

## Table of Contents
1. [RADIUS Multi-Tenancy Overview](#radius-multi-tenancy-overview)
2. [Schema-Based RADIUS Architecture](#schema-based-radius-architecture)
3. [Authentication Flow](#authentication-flow)
4. [Implementation Details](#implementation-details)
5. [Troubleshooting](#troubleshooting)

---

## RADIUS Multi-Tenancy Overview

### Why RADIUS in Multi-Tenant System?

**FreeRADIUS** (Remote Authentication Dial-In User Service) provides:
1. **Centralized Authentication**: Single authentication point for all users
2. **AAA Services**: Authentication, Authorization, Accounting
3. **Protocol Support**: Standard RADIUS protocol
4. **Scalability**: Handles thousands of authentication requests
5. **Audit Trail**: Complete logging of authentication attempts

### Multi-Tenant RADIUS Challenge

**Problem**: How does FreeRADIUS know which tenant schema to query for credentials?

**Solution**: Three-tier architecture:
1. **Public Schema**: `radius_user_schema_mapping` (username → schema mapping)
2. **Tenant Schema**: `radcheck` + `radreply` (credentials per tenant)
3. **Custom Dictionary**: `Tenant-ID` attribute for schema identification

---

## Schema-Based RADIUS Architecture

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    LOGIN REQUEST                                 │
│  Username: "employee001"                                         │
│  Password: "SecurePass123"                                       │
└────────────────────┬────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│              FREERADIUS SERVER                                   │
│  1. Receives authentication request                              │
│  2. Queries radius_user_schema_mapping (PUBLIC schema)           │
│     SELECT schema_name FROM radius_user_schema_mapping           │
│     WHERE username = 'employee001'                               │
│     → Result: 'tenant_abc_coop'                                  │
└────────────────────┬────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│  3. Set PostgreSQL search_path                                   │
│     SET search_path TO tenant_abc_coop, public                   │
└────────────────────┬────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│  4. Query radcheck (TENANT schema)                               │
│     SELECT value FROM radcheck                                   │
│     WHERE username = 'employee001'                               │
│       AND attribute = 'Cleartext-Password'                       │
│     → Result: 'SecurePass123'                                    │
└────────────────────┬────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│  5. Compare passwords                                            │
│     Submitted: 'SecurePass123'                                   │
│     Stored:    'SecurePass123'                                   │
│     → Match: TRUE                                                │
└────────────────────┬────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│  6. Query radreply (TENANT schema)                               │
│     SELECT attribute, value FROM radreply                        │
│     WHERE username = 'employee001'                               │
│     → Result: Tenant-ID = 'tenant_abc_coop'                      │
└────────────────────┬────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│  7. Return Access-Accept                                         │
│     - Tenant-ID: tenant_abc_coop                                 │
│     - Session-Timeout: 3600                                      │
│     - Other attributes...                                        │
└─────────────────────────────────────────────────────────────────┘
```

### Database Schema Structure

#### Public Schema Tables

**1. radius_user_schema_mapping**
```sql
CREATE TABLE public.radius_user_schema_mapping (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    schema_name VARCHAR(64) NOT NULL,
    tenant_id UUID REFERENCES tenants(id),
    user_role VARCHAR(32),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Critical indexes for performance
CREATE INDEX idx_radius_mapping_username ON radius_user_schema_mapping(username);
CREATE INDEX idx_radius_mapping_schema ON radius_user_schema_mapping(schema_name);
CREATE INDEX idx_radius_mapping_active ON radius_user_schema_mapping(username, is_active);
```

**Purpose**: 
- Maps username to tenant schema BEFORE authentication
- Queried first by FreeRADIUS to determine which schema to use
- Must be in public schema (accessible without tenant context)

**Example Data**:
```sql
INSERT INTO radius_user_schema_mapping VALUES
(1, 'admin001', 'tenant_abc_coop', 'uuid-tenant-1', 'tenant_admin', true, NOW(), NOW()),
(2, 'employee001', 'tenant_abc_coop', 'uuid-tenant-1', 'employee', true, NOW(), NOW()),
(3, 'farmer001', 'tenant_abc_coop', 'uuid-tenant-1', 'farmer', true, NOW(), NOW()),
(4, 'admin002', 'tenant_xyz_farm', 'uuid-tenant-2', 'tenant_admin', true, NOW(), NOW());
```

#### Tenant Schema Tables

**2. radcheck (per tenant)**
```sql
-- In tenant_abc_coop schema
CREATE TABLE tenant_abc_coop.radcheck (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) DEFAULT ':=',
    value VARCHAR(253) NOT NULL
);

CREATE INDEX idx_radcheck_username ON radcheck(username);
CREATE INDEX idx_radcheck_username_attr ON radcheck(username, attribute);
```

**Purpose**: Stores user credentials (passwords) per tenant

**Example Data**:
```sql
INSERT INTO tenant_abc_coop.radcheck VALUES
(1, 'admin001', 'Cleartext-Password', ':=', 'AdminPass123'),
(2, 'employee001', 'Cleartext-Password', ':=', 'EmpPass456'),
(3, 'farmer001', 'Cleartext-Password', ':=', 'FarmerPass789');
```

**3. radreply (per tenant)**
```sql
-- In tenant_abc_coop schema
CREATE TABLE tenant_abc_coop.radreply (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) DEFAULT ':=',
    value VARCHAR(253) NOT NULL
);

CREATE INDEX idx_radreply_username ON radreply(username);
```

**Purpose**: Stores reply attributes returned after successful authentication

**Example Data**:
```sql
INSERT INTO tenant_abc_coop.radreply VALUES
(1, 'admin001', 'Tenant-ID', ':=', 'tenant_abc_coop'),
(2, 'employee001', 'Tenant-ID', ':=', 'tenant_abc_coop'),
(3, 'farmer001', 'Tenant-ID', ':=', 'tenant_abc_coop');
```

---

## Authentication Flow

### Complete Authentication Sequence

```
┌──────────────────────────────────────────────────────────────┐
│ STEP 1: User Submits Login                                   │
│ Frontend → Backend: POST /api/login                          │
│ { username: "employee001", password: "SecurePass123" }       │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 2: Backend Calls RadiusService                          │
│ RadiusService::authenticate($username, $password)            │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 3: RADIUS Request Sent                                  │
│ Protocol: RADIUS Access-Request                              │
│ Attributes:                                                  │
│   - User-Name: employee001                                   │
│   - User-Password: SecurePass123 (encrypted)                 │
│   - NAS-IP-Address: 172.20.255.254                          │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 4: FreeRADIUS Processes Request                         │
│ a) Query radius_user_schema_mapping                          │
│    SELECT schema_name FROM radius_user_schema_mapping        │
│    WHERE username = 'employee001' AND is_active = true       │
│    → Result: 'tenant_abc_coop'                               │
│                                                              │
│ b) Set search_path                                           │
│    SET search_path TO tenant_abc_coop, public                │
│                                                              │
│ c) Query radcheck                                            │
│    SELECT value FROM radcheck                                │
│    WHERE username = 'employee001'                            │
│      AND attribute = 'Cleartext-Password'                    │
│    → Result: 'SecurePass123'                                 │
│                                                              │
│ d) Verify password                                           │
│    Compare submitted vs stored                               │
│    → Match: TRUE                                             │
│                                                              │
│ e) Query radreply                                            │
│    SELECT attribute, value FROM radreply                     │
│    WHERE username = 'employee001'                            │
│    → Result: Tenant-ID = 'tenant_abc_coop'                   │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 5: RADIUS Response                                      │
│ Protocol: RADIUS Access-Accept                               │
│ Attributes:                                                  │
│   - Tenant-ID: tenant_abc_coop                               │
│   - Session-Timeout: 3600                                    │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 6: Backend Processes RADIUS Response                    │
│ RadiusService returns: ['success' => true, 'tenant_id' => ...] │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 7: Backend Fetches User Data                            │
│ a) Query public.users                                        │
│    SELECT * FROM users WHERE username = 'employee001'        │
│    → Get user_id, tenant_id, role                            │
│                                                              │
│ b) Set tenant context                                        │
│    TenantContext::setTenantById($user->tenant_id)            │
│    SET search_path TO tenant_abc_coop, public                │
│                                                              │
│ c) Query tenant.employees                                    │
│    SELECT * FROM employees WHERE user_id = $user->id         │
│    → Get employee details, department, position              │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 8: Generate JWT Token                                   │
│ Sanctum::createToken($user, 'auth-token', $abilities)       │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 9: Return Login Response                                │
│ {                                                            │
│   "success": true,                                           │
│   "data": {                                                  │
│     "user": { ... },                                         │
│     "employee": { ... },                                     │
│     "department": { ... },                                   │
│     "token": "...",                                          │
│     "dashboard_route": "/employee/it/dashboard"              │
│   }                                                          │
│ }                                                            │
└──────────────────────────────────────────────────────────────┘
```

---

## Implementation Details

### 1. Custom RADIUS Dictionary

**File**: `freeradius/dictionary`

```
#
# Custom attributes for multi-tenant livestock management system
#
ATTRIBUTE	Tenant-ID		3100	string
```

**Docker Volume Mount** (`docker-compose.yml`):
```yaml
services:
  traidnet-freeradius:
    volumes:
      - ./freeradius/dictionary:/opt/etc/raddb/dictionary
```

**Why Needed**:
- FreeRADIUS doesn't recognize custom attributes by default
- `Tenant-ID` is our custom attribute to return schema name
- Must be defined in dictionary file

### 2. RADIUS SQL Configuration

**File**: `freeradius/sql/main/postgresql/queries.conf`

**Schema Lookup Function**:
```sql
-- Function to get tenant schema from username
CREATE OR REPLACE FUNCTION get_tenant_schema(username_param VARCHAR)
RETURNS VARCHAR AS $$
DECLARE
    schema_name_result VARCHAR;
BEGIN
    SELECT schema_name INTO schema_name_result
    FROM public.radius_user_schema_mapping
    WHERE username = username_param
      AND is_active = true
    LIMIT 1;
    
    RETURN schema_name_result;
END;
$$ LANGUAGE plpgsql;
```

**Modified Authorize Query**:
```sql
authorize_check_query = "\
    DO $$ \
    DECLARE \
        tenant_schema VARCHAR; \
    BEGIN \
        -- Get tenant schema \
        SELECT get_tenant_schema('%{SQL-User-Name}') INTO tenant_schema; \
        \
        IF tenant_schema IS NOT NULL THEN \
            -- Set search path to tenant schema \
            EXECUTE 'SET search_path TO ' || tenant_schema || ', public'; \
        END IF; \
    END $$; \
    \
    SELECT id, username, attribute, value, op \
    FROM radcheck \
    WHERE username = '%{SQL-User-Name}' \
    ORDER BY id"
```

**Modified Reply Query**:
```sql
authorize_reply_query = "\
    SELECT id, username, attribute, value, op \
    FROM radreply \
    WHERE username = '%{SQL-User-Name}' \
    ORDER BY id"
```

### 3. User Creation with RADIUS Setup

**Complete Implementation**:

```php
// File: app/Http/Controllers/Api/EmployeeController.php

protected function setupEmployeeRADIUS($username, $password)
{
    $tenant = auth()->user()->tenant;
    $schemaName = $tenant->schema_name;
    
    // Save current search path
    $currentSearchPath = DB::selectOne("SHOW search_path")->search_path;
    
    try {
        // STEP 1: Add to tenant's radcheck (password)
        // Already in tenant schema due to middleware
        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $username,
                'attribute' => 'Cleartext-Password'
            ],
            [
                'op' => ':=',
                'value' => $password
            ]
        );
        
        Log::info("Added to radcheck in tenant schema", [
            'username' => $username,
            'schema' => $schemaName
        ]);
        
        // STEP 2: Add to tenant's radreply (Tenant-ID attribute)
        DB::table('radreply')->updateOrInsert(
            [
                'username' => $username,
                'attribute' => 'Tenant-ID'
            ],
            [
                'op' => ':=',
                'value' => $schemaName
            ]
        );
        
        Log::info("Added to radreply in tenant schema", [
            'username' => $username,
            'tenant_id' => $schemaName
        ]);
        
        // STEP 3: Switch to public schema
        DB::statement("SET search_path TO public");
        
        // STEP 4: Add to radius_user_schema_mapping
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
        
        Log::info("Added to radius_user_schema_mapping", [
            'username' => $username,
            'schema_name' => $schemaName
        ]);
        
    } finally {
        // STEP 5: Restore original search path
        DB::statement("SET search_path TO {$currentSearchPath}");
    }
}
```

### 4. RadiusService Implementation

**File**: `app/Services/RadiusService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class RadiusService
{
    protected $radiusHost;
    protected $radiusPort;
    protected $radiusSecret;

    public function __construct()
    {
        $this->radiusHost = config('radius.host', '127.0.0.1');
        $this->radiusPort = config('radius.port', 1812);
        $this->radiusSecret = config('radius.secret', 'testing123');
    }

    /**
     * Authenticate user via RADIUS
     */
    public function authenticate(string $username, string $password): array
    {
        try {
            Log::info("RADIUS: Attempting authentication for user: {$username}");

            // Create RADIUS request
            $radius = radius_auth_open();
            
            if (!radius_add_server(
                $radius,
                $this->radiusHost,
                $this->radiusPort,
                $this->radiusSecret,
                3,  // timeout
                3   // max tries
            )) {
                throw new \Exception("Failed to add RADIUS server");
            }

            // Create Access-Request packet
            if (!radius_create_request($radius, RADIUS_ACCESS_REQUEST)) {
                throw new \Exception("Failed to create RADIUS request");
            }

            // Add attributes
            radius_put_string($radius, RADIUS_USER_NAME, $username);
            radius_put_string($radius, RADIUS_USER_PASSWORD, $password);
            radius_put_addr($radius, RADIUS_NAS_IP_ADDRESS, '172.20.255.254');

            // Send request
            $result = radius_send_request($radius);

            if ($result === RADIUS_ACCESS_ACCEPT) {
                Log::info("RADIUS: Authentication successful for user: {$username}");
                
                // Extract reply attributes
                $tenantId = null;
                while ($attr = radius_get_attr($radius)) {
                    if ($attr['attr'] == 'Tenant-ID') {
                        $tenantId = $attr['data'];
                        break;
                    }
                }
                
                return [
                    'success' => true,
                    'tenant_id' => $tenantId,
                ];
            } else if ($result === RADIUS_ACCESS_REJECT) {
                Log::warning("RADIUS: Authentication failed for user: {$username}");
                return ['success' => false, 'message' => 'Invalid credentials'];
            } else {
                throw new \Exception("RADIUS server error");
            }

        } catch (\Exception $e) {
            Log::error("RADIUS: Error authenticating user {$username}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Authentication service error'];
        }
    }
}
```

---

## Troubleshooting

### Common Issues

#### Issue 1: "Unknown attribute Tenant-ID"

**Symptom**:
```
sql: ERROR: Failed to create the pair: Unknown name "Tenant-ID"
```

**Cause**: Custom dictionary not loaded

**Solution**:
1. Verify dictionary file exists: `freeradius/dictionary`
2. Check Docker volume mount in `docker-compose.yml`
3. Restart FreeRADIUS container:
   ```bash
   docker-compose restart traidnet-freeradius
   ```
4. Verify dictionary loaded:
   ```bash
   docker exec traidnet-freeradius cat /opt/etc/raddb/dictionary | grep Tenant-ID
   ```

#### Issue 2: Authentication fails with 401

**Symptom**: Login returns 401 Unauthorized

**Cause**: Missing `radius_user_schema_mapping` entry

**Solution**:
```sql
-- Check if mapping exists
SELECT * FROM radius_user_schema_mapping WHERE username = 'YOUR_USERNAME';

-- If missing, add it
INSERT INTO radius_user_schema_mapping 
(username, schema_name, tenant_id, user_role, is_active, created_at, updated_at)
VALUES 
('YOUR_USERNAME', 'YOUR_TENANT_SCHEMA', 'YOUR_TENANT_ID', 'employee', true, NOW(), NOW());
```

#### Issue 3: Wrong tenant data returned

**Symptom**: User sees another tenant's data

**Cause**: Incorrect schema in `radius_user_schema_mapping`

**Solution**:
```sql
-- Verify correct schema
SELECT u.username, u.tenant_id, t.schema_name, m.schema_name as mapped_schema
FROM users u
JOIN tenants t ON u.tenant_id = t.id
LEFT JOIN radius_user_schema_mapping m ON u.username = m.username
WHERE u.username = 'YOUR_USERNAME';

-- Update if incorrect
UPDATE radius_user_schema_mapping
SET schema_name = 'CORRECT_SCHEMA'
WHERE username = 'YOUR_USERNAME';
```

#### Issue 4: FreeRADIUS can't connect to PostgreSQL

**Symptom**:
```
rlm_sql (sql): Opening additional connection (0), 1 of 32 pending slots used
rlm_sql_postgresql: Couldn't connect to database
```

**Cause**: Database connection settings incorrect

**Solution**:
1. Check `freeradius/sql/main/postgresql/queries.conf`
2. Verify database credentials match `.env`
3. Ensure PostgreSQL allows connections from FreeRADIUS container
4. Check Docker network connectivity

### Debugging Commands

**Check RADIUS logs**:
```bash
docker logs traidnet-freeradius --tail 50
```

**Test RADIUS authentication**:
```bash
docker exec traidnet-freeradius radtest employee001 password123 localhost 0 testing123
```

**Check schema mapping**:
```sql
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT username, schema_name, user_role, is_active 
FROM radius_user_schema_mapping 
ORDER BY created_at DESC 
LIMIT 10;
"
```

**Verify tenant credentials**:
```sql
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SET search_path TO tenant_abc_coop, public;
SELECT username, attribute, value FROM radcheck;
"
```

---

## Next Steps

Continue to:
- **Part 4**: [Best Practices](./MULTITENANCY_PART4_BEST_PRACTICES.md)

---

**Document Version**: 1.0  
**Last Updated**: November 30, 2025
