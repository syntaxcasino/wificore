# Migration Schema Analysis and Gap Identification

## Date: December 31, 2025

## Current State Analysis

### Public Schema Tables (Correct)
These tables contain system-wide or cross-tenant data and should remain in public schema:

✅ **tenants** - Tenant registry
✅ **users** - All users (with tenant_id for scoping)
✅ **cache** - System cache
✅ **jobs** - Queue jobs
✅ **failed_jobs** - Failed queue jobs
✅ **tenant_schema_migrations** - Tracks tenant migrations
✅ **tenant_registrations** - Tenant registration workflow
✅ **personal_access_tokens** - API tokens (Sanctum)
✅ **system_logs** - System-wide logs
✅ **packages** - Package definitions (with tenant_id, shared across tenants)
✅ **performance_metrics** - System performance tracking
✅ **radius_core_tables** (radcheck, radreply, radgroupcheck, radgroupreply, radusergroup) - Public RADIUS for authentication
✅ **radius_user_schema_mapping** - Maps RADIUS users to tenant schemas
✅ **tenant_vpn_tunnels** - VPN tunnel allocation (global resource)
✅ **system_metrics_tables** - System health monitoring
✅ **mpesa_transaction_maps** - M-Pesa transaction tracking (public)

### Tenant Schema Tables (Correct)
These tables contain tenant-specific data and are correctly in tenant schema:

✅ **routers** - Tenant's routers
✅ **access_points** - Tenant's access points
✅ **ap_active_sessions** - AP session tracking
✅ **router_vpn_configs** - Router VPN configurations
✅ **router_configs** - Router configurations
✅ **router_services** - Router services
✅ **vpn_configurations** - Tenant VPN configs
✅ **wireguard_peers** - WireGuard peers
✅ **package_router** - Package-router assignments
✅ **payments** - Tenant payment records
✅ **user_subscriptions** - User subscription records
✅ **payment_reminders** - Payment reminder tracking
✅ **service_control_logs** - Service control audit logs
✅ **hotspot_users** - Hotspot user accounts
✅ **hotspot_sessions** - Hotspot session tracking
✅ **user_sessions** - User session records
✅ **vouchers** - Voucher codes
✅ **radius_sessions** - RADIUS session tracking
✅ **hotspot_credentials** - Hotspot credentials
✅ **session_disconnections** - Session disconnect logs
✅ **data_usage_logs** - Data usage tracking
✅ **todos** - Todo items
✅ **departments** - HR departments
✅ **positions** - HR positions
✅ **employees** - HR employee records
✅ **expenses** - Expense tracking
✅ **revenues** - Revenue tracking
✅ **tenant_radius_tables** (radacct, radpostauth) - Tenant-specific RADIUS accounting

---

## ⚠️ IDENTIFIED GAPS AND ISSUES

### 1. **GenieACS Integration - MISSING TABLES**

**Issue:** GenieACS service exists but has NO database tables for tracking devices, presets, or provisioning status.

**Required Tables (Should be in TENANT schema):**

#### a) `genieacs_devices` - Track TR-069 devices
```sql
CREATE TABLE genieacs_devices (
    id UUID PRIMARY KEY,
    device_id VARCHAR(255) UNIQUE NOT NULL, -- GenieACS device ID (OUI-ProductClass-Serial)
    access_point_id UUID REFERENCES access_points(id),
    serial_number VARCHAR(255),
    mac_address VARCHAR(255),
    manufacturer VARCHAR(255),
    model VARCHAR(255),
    software_version VARCHAR(255),
    hardware_version VARCHAR(255),
    ip_address INET,
    connection_status VARCHAR(50), -- online, offline, error
    last_inform TIMESTAMP,
    last_boot TIMESTAMP,
    tags JSONB, -- Store GenieACS tags
    parameters JSONB, -- Store device parameters
    provisioning_status VARCHAR(50), -- pending, provisioned, failed
    provisioned_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### b) `genieacs_presets` - Track provisioning presets
```sql
CREATE TABLE genieacs_presets (
    id UUID PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    device_id VARCHAR(255), -- Target device
    weight INTEGER DEFAULT 10,
    precondition JSONB,
    configurations JSONB, -- Preset configurations
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### c) `genieacs_tasks` - Track device tasks
```sql
CREATE TABLE genieacs_tasks (
    id UUID PRIMARY KEY,
    device_id VARCHAR(255) NOT NULL,
    task_name VARCHAR(100), -- reboot, factoryReset, download, etc.
    parameters JSONB,
    status VARCHAR(50), -- pending, running, completed, failed
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### 2. **AccessPoint Model Issues**

**Issue:** `AccessPoint` model has `tenant_id` in fillable but table is in tenant schema (no tenant_id column needed).

**File:** `backend/app/Models/AccessPoint.php`
**Line 52:** `'tenant_id',` in fillable array

**Fix:** Remove `tenant_id` from fillable array since table is schema-scoped.

### 3. **AccessPointManager Service Issues**

**Issue:** `AccessPointManager::addAccessPoint()` tries to set `tenant_id` which doesn't exist in tenant schema.

**File:** `backend/app/Services/AccessPointManager.php`
**Line 52:** `'tenant_id' => $router->tenant_id,`

**Fix:** Remove tenant_id assignment.

### 4. **GenieACS Service Not Tenant-Aware**

**Issue:** `GenieACSService` doesn't extend `TenantAwareService` and doesn't use tenant context.

**Impact:** 
- No automatic tenant isolation
- Cannot track which tenant owns which device
- Potential cross-tenant data leakage

**Fix:** Make `GenieACSService` tenant-aware and add tenant context to all operations.

### 5. **Missing GenieACS Configuration**

**Issue:** No environment variables or configuration for GenieACS endpoints.

**Required in `.env.production`:**
```env
GENIEACS_NBI_URL=http://wificore-genieacs-nbi:7557
GENIEACS_UI_URL=http://wificore-genieacs-ui:3000
GENIEACS_FS_URL=http://wificore-genieacs-fs:7567
GENIEACS_CWMP_URL=http://wificore-genieacs-cwmp:7547
```

### 6. **Missing GenieACS Docker Services**

**Issue:** No GenieACS containers defined in docker-compose files.

**Required Services:**
- `wificore-genieacs-cwmp` - TR-069 ACS server
- `wificore-genieacs-nbi` - Northbound Interface API
- `wificore-genieacs-fs` - File Server for firmware
- `wificore-genieacs-ui` - Web UI
- `wificore-genieacs-mongodb` - MongoDB for GenieACS data

### 7. **Missing Router Service Tables**

**Issue:** `RouterService` model exists but no clear migration for `router_services` table in tenant schema.

**Status:** Need to verify if this table exists in tenant migrations.

### 8. **Package Model Has BelongsToTenant But Table in Public Schema**

**Issue:** `Package` model uses `BelongsToTenant` trait but `packages` table is in public schema with `tenant_id`.

**Analysis:** This is actually CORRECT - packages are defined per tenant but stored in public schema for easy cross-tenant queries and sharing. The `BelongsToTenant` trait ensures proper scoping.

**Status:** ✅ No action needed.

---

## RECOMMENDED ACTIONS

### Priority 1: GenieACS Database Tables
1. Create migration for `genieacs_devices` table in tenant schema
2. Create migration for `genieacs_presets` table in tenant schema  
3. Create migration for `genieacs_tasks` table in tenant schema

### Priority 2: Fix AccessPoint Issues
1. Remove `tenant_id` from `AccessPoint` model fillable array
2. Remove `tenant_id` assignment in `AccessPointManager::addAccessPoint()`

### Priority 3: Make GenieACS Tenant-Aware
1. Extend `GenieACSService` from `TenantAwareService`
2. Add tenant context to all GenieACS operations
3. Create `GenieACSDevice` model with proper relationships

### Priority 4: GenieACS Infrastructure
1. Add GenieACS services to docker-compose files
2. Add GenieACS configuration to .env files
3. Create GenieACS initialization scripts

### Priority 5: Documentation
1. Document GenieACS integration architecture
2. Document TR-069 device onboarding flow
3. Create GenieACS troubleshooting guide

---

## VERIFICATION CHECKLIST

- [ ] All tenant-specific tables are in `database/migrations/tenant/`
- [ ] All system-wide tables are in `database/migrations/`
- [ ] GenieACS tables created in tenant schema
- [ ] AccessPoint model fixed (no tenant_id)
- [ ] AccessPointManager fixed (no tenant_id assignment)
- [ ] GenieACS service is tenant-aware
- [ ] GenieACS Docker services configured
- [ ] GenieACS environment variables set
- [ ] All models have correct trait usage
- [ ] All migrations tested

---

## NOTES

- Schema-based multi-tenancy is correctly implemented
- Tenant isolation is working via PostgreSQL schemas
- RADIUS integration properly split between public (auth) and tenant (accounting)
- VPN tunnel allocation is correctly in public schema (global resource)
- Payment tracking is correctly in tenant schema
