# SaaS Provisioning Design - Implementation Spec

## Overview
Zero-config provisioning with tenant-scoped IPAM, RADIUS-only AAA, and VLAN-enforced hybrid services.

## Core Principles
1. **Zero-Config by Default** - Users only select service + interface
2. **Tenant-Scoped IPAM** - Automatic IP pool allocation per tenant
3. **RADIUS-Only AAA** - No local auth, centralized control
4. **VLAN-Enforced Hybrid** - Safe Hotspot + PPPoE on same interface
5. **Advanced Options Opt-In** - Complexity hidden unless requested

## Database Schema

### tenant_ip_pools (Tenant Schema)
```sql
CREATE TABLE tenant_ip_pools (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    service_type ENUM('hotspot', 'pppoe', 'management') NOT NULL,
    pool_name VARCHAR(255) NOT NULL,
    network_cidr VARCHAR(50) NOT NULL,
    gateway_ip INET NOT NULL,
    range_start INET NOT NULL,
    range_end INET NOT NULL,
    dns_primary INET,
    dns_secondary INET,
    total_ips INTEGER NOT NULL,
    allocated_ips INTEGER DEFAULT 0,
    available_ips INTEGER NOT NULL,
    auto_generated BOOLEAN DEFAULT TRUE,
    status ENUM('active', 'exhausted', 'disabled') DEFAULT 'active',
    metadata JSONB,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(tenant_id, service_type, network_cidr)
);
```

### router_services (Tenant Schema)
```sql
CREATE TABLE router_services (
    id UUID PRIMARY KEY,
    router_id UUID NOT NULL REFERENCES routers(id),
    interface_name VARCHAR(100) NOT NULL,
    service_type ENUM('hotspot', 'pppoe', 'hybrid', 'none') NOT NULL,
    ip_pool_id UUID REFERENCES tenant_ip_pools(id),
    vlan_id INTEGER,
    vlan_required BOOLEAN DEFAULT FALSE,
    radius_profile VARCHAR(255),
    advanced_config JSONB,
    deployment_status ENUM('pending', 'deploying', 'deployed', 'failed') DEFAULT 'pending',
    deployed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(router_id, interface_name, service_type)
);
```

### service_vlans (Tenant Schema)
```sql
CREATE TABLE service_vlans (
    id UUID PRIMARY KEY,
    router_service_id UUID NOT NULL REFERENCES router_services(id),
    vlan_id INTEGER NOT NULL,
    vlan_name VARCHAR(100),
    parent_interface VARCHAR(100) NOT NULL,
    service_type ENUM('hotspot', 'pppoe') NOT NULL,
    auto_generated BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(router_service_id, vlan_id)
);
```

## Service Layer Architecture

### TenantIpamService
**Responsibilities:**
- Auto-generate tenant IP pools on first router
- Allocate pools per service type
- Expand pools when exhausted
- Prevent overlaps within tenant
- Track utilization

**Key Methods:**
```php
getOrCreateServicePool(Tenant $tenant, string $serviceType): TenantIpPool
allocateIpFromPool(TenantIpPool $pool): string
releaseIp(TenantIpPool $pool, string $ip): void
expandPool(TenantIpPool $pool): void
validatePoolCapacity(TenantIpPool $pool, int $requiredIps): bool
```

### RouterServiceManager
**Responsibilities:**
- Process service selections
- Enforce VLAN rules for hybrid
- Auto-assign IP pools
- Generate RADIUS profiles
- Validate configurations

**Key Methods:**
```php
configureService(Router $router, string $interface, string $serviceType, array $options = []): RouterService
enforceHybridVlans(Router $router, string $interface): array
validateServiceDeployment(RouterService $service): ValidationResult
deployService(RouterService $service): DeploymentResult
```

### VlanManager
**Responsibilities:**
- Auto-generate VLAN IDs
- Prevent conflicts
- Manage VLAN pools per router
- Enforce separation

**Key Methods:**
```php
allocateVlanForService(Router $router, string $serviceType): int
validateVlanConfiguration(Router $router, array $vlans): bool
getAvailableVlanRange(Router $router): array
```

## User Flow

### 1. Router Registration (Unchanged)
- Tenant creates router
- SaaS generates VPN config
- Bootstrap script applied
- VPN established
- SSH keys installed

### 2. Interface Discovery
- Router reports interfaces via API
- SaaS classifies: physical, bridge, VLAN
- Determines service eligibility
- Stores in `router_interfaces` table

### 3. Service Selection (Zero-Config UI)
```
For each interface:
┌─────────────────────────────────┐
│ Interface: ether2              │
│                                 │
│ Service Type:                   │
│ ○ None                          │
│ ○ Hotspot                       │
│ ○ PPPoE                         │
│ ○ Hybrid (Hotspot + PPPoE)      │
│                                 │
│ [Advanced Options ▼]            │
└─────────────────────────────────┘
```

**That's it. User clicks "Deploy".**

### 4. SaaS Auto-Configuration

#### For Hotspot:
```php
$pool = $ipamService->getOrCreateServicePool($tenant, 'hotspot');
$service = RouterService::create([
    'router_id' => $router->id,
    'interface_name' => 'ether2',
    'service_type' => 'hotspot',
    'ip_pool_id' => $pool->id,
    'radius_profile' => "hotspot-{$tenant->id}",
]);
```

#### For PPPoE:
```php
$pool = $ipamService->getOrCreateServicePool($tenant, 'pppoe');
$service = RouterService::create([
    'router_id' => $router->id,
    'interface_name' => 'ether2',
    'service_type' => 'pppoe',
    'ip_pool_id' => $pool->id,
    'radius_profile' => "pppoe-{$tenant->id}",
]);
```

#### For Hybrid:
```php
// Auto-generate VLANs
$hotspotVlan = $vlanManager->allocateVlanForService($router, 'hotspot');
$pppoeVlan = $vlanManager->allocateVlanForService($router, 'pppoe');

// Create services with VLAN separation
$hotspotService = RouterService::create([
    'service_type' => 'hotspot',
    'vlan_id' => $hotspotVlan,
    'vlan_required' => true,
]);

$pppoeService = RouterService::create([
    'service_type' => 'pppoe',
    'vlan_id' => $pppoeVlan,
    'vlan_required' => true,
]);
```

## MikroTik Configuration Generation

### Hotspot Script Template
```routeros
# Auto-generated Hotspot Configuration
# Tenant: {tenant_name}
# Pool: {pool_cidr}

/ip pool
add name=hotspot-pool-{router_id} ranges={range_start}-{range_end}

/ip hotspot profile
add name=hotspot-profile-{router_id} \
    use-radius=yes \
    radius-accounting=yes

/ip hotspot
add name=hotspot-{interface} \
    interface={interface} \
    address-pool=hotspot-pool-{router_id} \
    profile=hotspot-profile-{router_id}

/radius
add service=hotspot \
    address={radius_server} \
    secret={radius_secret} \
    timeout=3s

# Firewall rules for Hotspot
/ip firewall filter
add chain=input action=accept protocol=tcp dst-port=80,443 \
    in-interface={interface} comment="Hotspot Portal"
```

### PPPoE Script Template
```routeros
# Auto-generated PPPoE Configuration
# Tenant: {tenant_name}
# Pool: {pool_cidr}

/ip pool
add name=pppoe-pool-{router_id} ranges={range_start}-{range_end}

/ppp profile
add name=pppoe-profile-{router_id} \
    use-radius=yes \
    local-address={gateway_ip} \
    remote-address=pppoe-pool-{router_id}

/interface pppoe-server server
add service-name=pppoe-{tenant_id} \
    interface={interface} \
    default-profile=pppoe-profile-{router_id} \
    authentication=pap,chap,mschap2

/radius
add service=ppp \
    address={radius_server} \
    secret={radius_secret} \
    timeout=3s
```

### Hybrid Script Template (VLAN-Enforced)
```routeros
# Auto-generated Hybrid Configuration with VLAN Separation
# Tenant: {tenant_name}

# Create VLANs
/interface vlan
add name=vlan-hotspot-{vlan_id} vlan-id={hotspot_vlan} interface={interface}
add name=vlan-pppoe-{vlan_id} vlan-id={pppoe_vlan} interface={interface}

# Hotspot on VLAN
/ip pool
add name=hotspot-pool-{router_id} ranges={hotspot_range}

/ip hotspot
add name=hotspot-hybrid interface=vlan-hotspot-{vlan_id} \
    address-pool=hotspot-pool-{router_id}

# PPPoE on VLAN
/ip pool
add name=pppoe-pool-{router_id} ranges={pppoe_range}

/interface pppoe-server server
add service-name=pppoe-{tenant_id} \
    interface=vlan-pppoe-{vlan_id}

# Traffic separation enforced by VLANs
```

## Advanced Options UI

Only visible when "Advanced Options" toggle is ON:

```
┌─────────────────────────────────────┐
│ ⚠ Advanced Options                  │
│                                     │
│ IP Pool:                            │
│ ○ Auto (Recommended)                │
│ ○ Custom Pool                       │
│   └─ [Select Pool ▼]                │
│                                     │
│ VLAN Configuration:                 │
│ ○ Auto-Assign                       │
│ ○ Manual                            │
│   ├─ Hotspot VLAN: [100]            │
│   └─ PPPoE VLAN: [200]              │
│                                     │
│ DHCP Lease Time: [24] hours        │
│ DNS Servers: [Auto ▼]              │
└─────────────────────────────────────┘
```

## Validation Rules

### Pre-Deployment Checks
1. **Interface Eligibility**
   - Interface exists and is up
   - Not already assigned to another service
   - Supports selected service type

2. **VLAN Enforcement**
   - Hybrid requires VLANs
   - VLAN IDs unique per router
   - No VLAN conflicts

3. **Pool Capacity**
   - Pool has available IPs
   - Auto-expand if needed
   - Warn if <10% available

4. **RADIUS Reachability**
   - RADIUS server responding
   - Credentials valid
   - Tenant profile exists

5. **RouterOS Compatibility**
   - Version supports features
   - License allows services
   - Sufficient resources

## Deployment Flow

```
User selects service
    ↓
Validation
    ↓
Auto-assign pool
    ↓
Generate VLAN (if hybrid)
    ↓
Create RADIUS profile
    ↓
Generate config script
    ↓
Deploy via SSH
    ↓
Verify deployment
    ↓
Update status
    ↓
Broadcast event
```

## Error Handling

| Error | Action |
|-------|--------|
| Pool exhausted | Auto-expand (if allowed) |
| VLAN conflict | Suggest alternative |
| RADIUS down | Block deployment |
| VPN down | Queue for retry |
| Script failed | Rollback + alert |

## Security Enforcement

### RADIUS-Only AAA
```routeros
# Disable local users for services
/ip hotspot user
# Empty - all auth via RADIUS

/ppp secret
# Empty - all auth via RADIUS

# Force RADIUS
/radius
add service=hotspot,ppp address={radius_server} secret={secret}
```

### Tenant Isolation
- Pools never overlap between tenants
- RADIUS profiles tenant-scoped
- VLANs router-scoped
- No cross-tenant routing

## Dashboard Display

### Default View (Zero-Config User)
```
Router: GGN-HSP-01
├─ ether1: WAN (No service)
├─ ether2: Hotspot
│   └─ Status: Active
│   └─ Sessions: 12
└─ ether3: PPPoE
    └─ Status: Active
    └─ Sessions: 8
```

### Advanced View
```
Router: GGN-HSP-01
├─ ether2: Hotspot
│   ├─ Pool: 192.168.100.0/24
│   ├─ Utilization: 12/254 (4.7%)
│   ├─ RADIUS: hotspot-tenant-123
│   └─ Sessions: 12
```

## Implementation Phases

### Phase 1: Database & Models ✓
- Create migrations
- Define models
- Add relationships

### Phase 2: IPAM Service
- Auto-pool generation
- Allocation logic
- Expansion rules

### Phase 3: Service Manager
- Service configuration
- VLAN enforcement
- Validation

### Phase 4: Config Generation
- Script templates
- RADIUS integration
- Deployment

### Phase 5: UI
- Zero-config interface
- Advanced options
- Validation feedback

### Phase 6: Testing
- Unit tests
- Integration tests
- E2E provisioning

## Success Metrics

- Time to provision: <2 minutes
- User inputs required: 1 (service type)
- Support tickets: -80%
- Pool exhaustion: 0 (auto-expand)
- VLAN conflicts: 0 (auto-assign)
