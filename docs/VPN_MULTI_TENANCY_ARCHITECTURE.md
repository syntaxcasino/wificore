# VPN Multi-Tenancy Architecture
## Schema-Based Isolation for 100K+ Tenants
**Date**: December 6, 2025 - 10:30 PM

---

## 🎯 **Objective**: Strict Multi-Tenancy for VPN Data

All tenant-specific VPN data MUST be isolated in tenant schemas to ensure:
- **Data Isolation**: Complete separation between tenants
- **Security**: No cross-tenant data leaks
- **Compliance**: Meet data privacy requirements
- **Scalability**: Support 100K+ tenants

---

## 🏗️ **Schema Architecture**

### **PUBLIC SCHEMA** (System-Level Coordination):
```
public.tenants                    ← Tenant registry
public.tenant_vpn_tunnels         ← System-level VPN tunnel coordination
public.radius_user_schema_mapping ← Username to schema mapping
```

**Why `tenant_vpn_tunnels` in PUBLIC?**
- Coordinates WireGuard interface allocation across ALL tenants
- Prevents port/interface conflicts
- System-level resource management
- Does NOT contain tenant-specific data (only metadata)

### **TENANT SCHEMAS** (Tenant-Specific Data):
```
tenant_xxx.routers                ← Tenant's routers
tenant_xxx.vpn_configurations     ← Tenant's VPN configs (MOVED from public)
tenant_xxx.vpn_subnet_allocations ← Tenant's subnet allocations (MOVED from public)
tenant_xxx.radcheck               ← Tenant's RADIUS auth
tenant_xxx.radreply               ← Tenant's RADIUS attributes
tenant_xxx.radacct                ← Tenant's RADIUS accounting
```

---

## 📊 **Table Distribution**

### **Before (INCORRECT - Multi-Tenancy Violation)**:
```
PUBLIC SCHEMA:
├─ tenants
├─ tenant_vpn_tunnels
├─ vpn_configurations        ❌ WRONG! Contains tenant data
├─ vpn_subnet_allocations    ❌ WRONG! Contains tenant data
└─ routers                   ❌ WRONG! Contains tenant data
```

### **After (CORRECT - Strict Multi-Tenancy)**:
```
PUBLIC SCHEMA:
├─ tenants                   ✅ System-level
└─ tenant_vpn_tunnels        ✅ System-level coordination only

TENANT SCHEMA (tenant_default):
├─ routers                   ✅ Tenant-specific
├─ vpn_configurations        ✅ Tenant-specific (MOVED)
├─ vpn_subnet_allocations    ✅ Tenant-specific (MOVED)
├─ radcheck                  ✅ Tenant-specific
└─ radreply                  ✅ Tenant-specific

TENANT SCHEMA (tenant_acme):
├─ routers                   ✅ Tenant-specific
├─ vpn_configurations        ✅ Tenant-specific (MOVED)
├─ vpn_subnet_allocations    ✅ Tenant-specific (MOVED)
├─ radcheck                  ✅ Tenant-specific
└─ radreply                  ✅ Tenant-specific
```

---

## 🔄 **Migration Strategy**

### **Migration File**:
`2025_12_06_000010_implement_schema_based_multitenancy_for_vpn.php`

### **Migration Steps**:

1. **Create VPN tables in each tenant schema**
   - `vpn_configurations`
   - `vpn_subnet_allocations`

2. **Migrate existing data from public to tenant schemas**
   - Move `vpn_configurations` WHERE `tenant_id` = X to `tenant_X.vpn_configurations`
   - Move `vpn_subnet_allocations` WHERE `tenant_id` = X to `tenant_X.vpn_subnet_allocations`

3. **Clean up public schema**
   - DELETE all rows from `public.vpn_configurations`
   - DELETE all rows from `public.vpn_subnet_allocations`
   - KEEP `public.tenant_vpn_tunnels` (system-level)

4. **Update foreign keys**
   - `tenant_X.vpn_configurations.router_id` → `tenant_X.routers.id`
   - `tenant_X.vpn_configurations.tenant_vpn_tunnel_id` → `public.tenant_vpn_tunnels.id`

---

## 🔐 **Data Access Pattern**

### **Before Query (WRONG)**:
```php
// Queries public schema - can see ALL tenants' data!
$vpnConfig = VpnConfiguration::where('tenant_id', $tenantId)->first();
```

### **After Query (CORRECT)**:
```php
// 1. Switch to tenant schema
DB::statement("SET search_path TO {$schemaName}, public");

// 2. Query tenant-specific table
$vpnConfig = VpnConfiguration::first(); // Only sees THIS tenant's data

// 3. Restore search path
DB::statement("SET search_path TO public");
```

---

## 📋 **Table Schemas**

### **PUBLIC: tenant_vpn_tunnels** (System Coordination):
```sql
CREATE TABLE public.tenant_vpn_tunnels (
    id BIGSERIAL PRIMARY KEY,
    tenant_id UUID UNIQUE NOT NULL,
    interface_name VARCHAR(10) UNIQUE,  -- wg0, wg1, wg2 (for container mode)
    server_private_key TEXT,
    server_public_key TEXT,
    server_ip INET,                     -- 10.X.0.1
    subnet_cidr VARCHAR(20),            -- 10.X.0.0/16
    listen_port INT,                    -- 51830, 51821 (for container mode)
    status VARCHAR(20),
    connected_peers INT DEFAULT 0,
    bytes_received BIGINT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE
);
```

**Note**: For host-based WireGuard (100K+ tenants):
- `interface_name` = 'wg0' for ALL tenants
- `listen_port` = 51830 for ALL tenants
- Peers identified by `client_public_key` in `vpn_configurations`

### **TENANT: vpn_configurations** (Tenant-Specific):
```sql
CREATE TABLE tenant_xxx.vpn_configurations (
    id BIGSERIAL PRIMARY KEY,
    router_id UUID,                     -- FK to tenant_xxx.routers
    tenant_vpn_tunnel_id BIGINT,        -- FK to public.tenant_vpn_tunnels
    
    -- WireGuard Configuration
    server_public_key TEXT,
    server_private_key TEXT,
    client_public_key TEXT,             -- Unique peer identifier
    client_private_key TEXT,
    preshared_key VARCHAR(255),
    
    -- Network Configuration
    server_ip INET,                     -- 10.8.0.1 (same for all)
    client_ip INET,                     -- 10.X.Y.Z (unique per router)
    subnet_cidr VARCHAR(20),            -- 10.X.0.0/16
    listen_port INT DEFAULT 51830,      -- 51830 (same for all)
    
    -- Server Endpoint
    server_endpoint VARCHAR(255),       -- vpn.example.com:51830
    server_public_ip VARCHAR(255),
    
    -- Connection Status
    status VARCHAR(20) DEFAULT 'pending',
    last_handshake_at TIMESTAMP,
    rx_bytes BIGINT DEFAULT 0,
    tx_bytes BIGINT DEFAULT 0,
    
    -- Configuration Scripts
    mikrotik_script TEXT,
    linux_script TEXT,
    
    -- Metadata
    interface_name VARCHAR(50) DEFAULT 'wg0',
    keepalive_interval INT DEFAULT 25,
    allowed_ips JSON,
    dns_servers JSON,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP,
    
    FOREIGN KEY (router_id) REFERENCES tenant_xxx.routers(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_vpn_tunnel_id) REFERENCES public.tenant_vpn_tunnels(id) ON DELETE CASCADE
);
```

### **TENANT: vpn_subnet_allocations** (Tenant-Specific):
```sql
CREATE TABLE tenant_xxx.vpn_subnet_allocations (
    id BIGSERIAL PRIMARY KEY,
    
    -- Subnet allocation
    subnet_cidr VARCHAR(20),            -- 10.X.0.0/16
    subnet_octet_2 INT UNIQUE,          -- X in 10.X.0.0
    gateway_ip INET,                    -- 10.X.0.1
    range_start INET,                   -- 10.X.1.1
    range_end INET,                     -- 10.X.255.254
    
    -- Usage tracking
    total_ips INT DEFAULT 65534,
    allocated_ips INT DEFAULT 0,
    available_ips INT DEFAULT 65534,
    
    -- Status
    status VARCHAR(20) DEFAULT 'active',
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## 🔧 **Service Updates**

### **TenantVpnTunnelService** (System-Level):
```php
// Operates on public.tenant_vpn_tunnels
class TenantVpnTunnelService
{
    public function getOrCreateTenantTunnel(string $tenantId): TenantVpnTunnel
    {
        // Query public schema
        return TenantVpnTunnel::where('tenant_id', $tenantId)
            ->firstOrCreate([...]);
    }
}
```

### **VpnService** (Tenant-Specific):
```php
// Operates on tenant_xxx.vpn_configurations
class VpnService
{
    public function createVpnConfiguration(Router $router): VpnConfiguration
    {
        // Get tenant schema
        $tenant = $router->tenant;
        $schemaName = $tenant->schema_name;
        
        // Switch to tenant schema
        DB::statement("SET search_path TO {$schemaName}, public");
        
        // Create VPN config in tenant schema
        $vpnConfig = VpnConfiguration::create([...]);
        
        // Restore search path
        DB::statement("SET search_path TO public");
        
        return $vpnConfig;
    }
}
```

---

## 🚀 **Benefits**

### **1. Complete Data Isolation**:
```
Tenant A queries: SELECT * FROM vpn_configurations
→ Only sees Tenant A's VPN configs

Tenant B queries: SELECT * FROM vpn_configurations
→ Only sees Tenant B's VPN configs

NO WAY for Tenant A to see Tenant B's data!
```

### **2. Security**:
- **No SQL injection cross-tenant leaks**: Even if SQL injection occurs, attacker only sees their own tenant's data
- **No application-level filtering needed**: Database enforces isolation
- **Audit trail**: Each tenant schema is isolated

### **3. Scalability**:
- **100K+ tenants**: Each tenant has own schema
- **Independent backups**: Backup/restore per tenant
- **Performance**: Smaller tables per tenant = faster queries

### **4. Compliance**:
- **GDPR**: Easy to delete all tenant data (DROP SCHEMA)
- **Data residency**: Can move tenant schema to different region
- **Audit**: Clear separation of tenant data

---

## 📝 **Deployment Steps**

### **1. Run Migration**:
```bash
# Stop containers
docker-compose down -v

# Start containers
docker-compose up -d

# Migration runs automatically
# Checks logs
docker logs traidnet-backend --tail 100
```

### **2. Verify Migration**:
```sql
-- Check public schema (should be empty)
SELECT COUNT(*) FROM public.vpn_configurations;  -- Should be 0
SELECT COUNT(*) FROM public.vpn_subnet_allocations;  -- Should be 0

-- Check tenant schema (should have data)
SET search_path TO tenant_default, public;
SELECT COUNT(*) FROM vpn_configurations;  -- Should have tenant's data
SELECT COUNT(*) FROM vpn_subnet_allocations;  -- Should have tenant's data
```

### **3. Update Application Code**:
- Models use tenant-aware queries
- Services switch to tenant schema before queries
- Controllers validate tenant context

---

## ⚠️ **Important Notes**

### **DO NOT**:
- ❌ Store tenant-specific data in public schema
- ❌ Query across tenant schemas
- ❌ Use `tenant_id` filtering in tenant schema tables (redundant)

### **DO**:
- ✅ Store system-level coordination data in public schema
- ✅ Store tenant-specific data in tenant schemas
- ✅ Switch to tenant schema before queries
- ✅ Use foreign keys to public schema for system-level references

---

## 🔍 **Troubleshooting**

### **Issue**: "Table not found"
```
Solution: Ensure search_path is set correctly
DB::statement("SET search_path TO {$schemaName}, public");
```

### **Issue**: "Foreign key violation"
```
Solution: Check if referencing table exists in correct schema
- tenant_xxx.vpn_configurations.router_id → tenant_xxx.routers.id ✅
- tenant_xxx.vpn_configurations.tenant_vpn_tunnel_id → public.tenant_vpn_tunnels.id ✅
```

### **Issue**: "Duplicate key violation"
```
Solution: Check if data already exists in tenant schema
- Migration should use ON CONFLICT DO NOTHING
```

---

## ✅ **Summary**

### **Architecture**:
```
PUBLIC SCHEMA:
└─ tenant_vpn_tunnels (system coordination)

TENANT SCHEMAS:
├─ vpn_configurations (tenant data)
└─ vpn_subnet_allocations (tenant data)
```

### **Benefits**:
- ✅ Complete data isolation
- ✅ Enhanced security
- ✅ Scalability to 100K+ tenants
- ✅ Compliance with data privacy regulations

### **Migration**:
- ✅ Automated migration script
- ✅ Data preserved during migration
- ✅ Rollback support

---

**Status**: ✅ **READY FOR DEPLOYMENT**
