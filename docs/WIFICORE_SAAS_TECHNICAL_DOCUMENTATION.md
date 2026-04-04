# Traidnet/WifiCore SaaS - Technical Documentation

## Table of Contents

1. [Core Architecture](#1-core-architecture)
2. [Multi-Tenancy](#2-multi-tenancy)
3. [PPPoE Implementation](#3-pppoe-implementation)
4. [Hotspot Implementation](#4-hotspot-implementation)
5. [Hybrid Provisioning](#5-hybrid-provisioning)
6. [RADIUS Integration](#6-radius-integration)
7. [Device Driver Architecture](#7-device-driver-architecture)
8. [Deployment & Provisioning](#8-deployment--provisioning)
9. [Low-End Device Support](#9-low-end-device-support)
10. [Security Features](#10-security-features)
11. [Monitoring & Observability](#11-monitoring--observability)
12. [Billing & Payments](#12-billing--payments)
13. [Queue & Background Jobs](#13-queue--background-jobs)
14. [API Architecture](#14-api-architecture)
15. [Operational Features](#15-operational-features)
16. [Improvements Feasibility Analysis](#16-improvements-feasibility-analysis)

---

## 1. Core Architecture

### 1.1 Technology Stack

| Component | Technology | Purpose |
|-----------|------------|---------|
| **Backend** | Laravel 11 (PHP 8.2+) | Primary application framework |
| **Provisioning Service** | Go 1.21+ | Network segmentation and secure provisioning |
| **Frontend** | Vue 3 + Vite | Single Page Application |
| **Database** | PostgreSQL 15+ | Primary data store with schema-per-tenant |
| **Cache** | Redis 7+ | Session management, caching, queue backend |
| **Message Queue** | Laravel Queues (Redis) | Background job processing |
| **Real-time** | Soketi/Laravel Echo | WebSocket event broadcasting |
| **Monitoring** | Prometheus + Grafana | Metrics collection and visualization |
| **Containerization** | Docker + Docker Compose | Development and deployment |

### 1.2 System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              CLIENT LAYER                                    │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Web App    │  │  Mobile App  │  │  Router API  │  │  Webhooks    │      │
│  │   (Vue 3)    │  │    (PWA)     │  │   (MikroTik) │  │   (External) │      │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘      │
└─────────┼─────────────────┼─────────────────┼─────────────────┼──────────────┘
          │                 │                 │                 │
          ▼                 ▼                 ▼                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           API GATEWAY LAYER                                │
│                    Nginx + Laravel (PHP-FPM 8.2+)                           │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  Authentication (JWT/OAuth2)  │  Rate Limiting  │  Request Routing   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         APPLICATION LAYER                                  │
│                                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────┐ │
│  │ Tenant Context  │  │ Router Services │  │   Provisioning Services     │ │
│  │   (Scoped)      │  │   (MikroTik)    │  │   (SSH/REST API)            │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────────┘ │
│                                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────┐ │
│  │  RADIUS Service │  │  Billing Service│  │   Queue Workers             │ │
│  │  (FreeRADIUS)   │  │  (M-Pesa/Card)  │  │   (Redis/Soketi)            │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DATA LAYER                                       │
│                                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────┐ │
│  │ PostgreSQL      │  │     Redis       │  │   FreeRADIUS DB             │ │
│  │ (Multi-schema   │  │   (Sessions,    │  │   (radcheck, radreply,      │ │
│  │  per-tenant)    │  │    Cache, Queue)│  │    radacct)                 │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         NETWORK LAYER                                      │
│                                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────┐ │
│  │  WireGuard VPN  │  │   MikroTik      │  │   Access Points             │ │
│  │  (Management)   │  │   Routers       │  │   (Multi-vendor)            │ │
│  │  (Controller)   │  │   (PPPoE/Hotspot)│  │   (CAPsMAN/GenieACS)       │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.3 Key Architectural Principles

**1. Tenant Isolation**: Each tenant operates within its own PostgreSQL schema, ensuring complete data separation between ISPs.

**2. Service-Oriented Design**: Core functionality is encapsulated in dedicated service classes:
- `RouterServiceManager` - Service lifecycle management
- `MikrotikProvisioningService` - Router configuration
- `RADIUSServiceController` - Authentication and accounting
- `TenantIpamService` - IP address management

**3. Event-Driven Architecture**: Real-time updates via Laravel Events and WebSockets:
- `RouterProvisioningProgress` - Deployment status
- `ProvisioningFailed` - Error handling
- `RouterConnected` - Connection events

**4. Multi-Transport Communication**: Supports both SSH (RSC scripts) and REST API for router communication with automatic fallback.

---

## 2. Multi-Tenancy

### 2.1 Architecture Overview

WifiCore implements **Schema-per-Tenant** multi-tenancy, providing complete data isolation while maintaining operational efficiency.

```php
// Tenant Context Service
class TenantContext
{
    private ?Tenant $tenant = null;
    
    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        // Set PostgreSQL search path to tenant schema
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
    }
}
```

### 2.2 Tenant Lifecycle

**1. Tenant Registration**:
```php
// Automatic schema creation
CREATE SCHEMA tenant_12345;
SET search_path TO tenant_12345, public;
-- Run migrations in tenant context
```

**2. Database Isolation**:
- Each tenant has isolated tables (users, routers, subscribers)
- Shared `public` schema for tenant registry and global settings
- Row-level security policies for additional protection

**3. VPN Isolation**:
```php
// Each tenant gets dedicated WireGuard subnet
$vpnSubnet = "10.{tenant_id}.0.0/16";
// Routers connect via VPN for secure management
```

### 2.3 Tenant Scoping

All queries are automatically scoped to the current tenant:

```php
// Global scope applied to all models
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $tenant = app(TenantContext::class)->getTenant();
        if ($tenant) {
            $builder->where('tenant_id', $tenant->id);
        }
    }
}
```

### 2.4 Resource Quotas

| Resource | Default Limit | Configurable |
|----------|--------------|--------------|
| Routers per tenant | 100 | Yes |
| Subscribers per router | 500 | Yes |
| Concurrent sessions | 1000 | Yes |
| IP pools per tenant | 50 | Yes |
| Bandwidth profiles | 100 | Yes |

### 2.5 Improvements

**Recommended Enhancements**:
1. **Tenant-Level Caching**: Add tenant-specific cache prefixes to prevent cross-tenant cache pollution
2. **Connection Pooling**: Implement per-tenant database connection pools for high-load scenarios
3. **Schema Sharding**: For 1000+ tenants, implement schema sharding across multiple PostgreSQL instances
4. **Tenant Health Monitoring**: Track per-tenant resource usage and alert on quota violations

---

## 3. PPPoE Implementation

### 3.1 Architecture

The PPPoE implementation uses a **RADIUS-backed authentication model** with local secret caching for resilience.

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Subscriber │────▶│   RADIUS    │────▶│  MikroTik   │
│  (Portal)   │     │  (FreeRADIUS)│     │ PPPoE Server│
└─────────────┘     └─────────────┘     └─────────────┘
                            │                   │
                            ▼                   ▼
                    ┌─────────────┐     ┌─────────────┐
                    │   radcheck  │     │ PPP Profile │
                    │   radreply  │     │ (Rate Limit)│
                    │   radacct   │     │             │
                    └─────────────┘     └─────────────┘
```

### 3.2 Core Components

**PPPoEService** (`/backend/app/Services/MikroTik/PPPoEService.php`):

```php
class PPPoEService extends BaseMikroTikService
{
    public function generateConfig(array $interfaces, string $routerId, array $options): string
    {
        // Key features:
        // 1. Bridge-based multi-interface aggregation
        // 2. RADIUS authentication with interim accounting
        // 3. Interface-list based firewall rules (secure by default)
        // 4. Automatic MTU/MRU optimization
    }
}
```

### 3.3 Configuration Generation

**Zero-Config PPPoE Script** (`ZeroConfigPPPoEGenerator`):

```php
$script = [
    // 1. Bridge creation for multi-interface aggregation
    ":do { /interface bridge add name=\"{$bridgeName}\" ... }",
    
    // 2. Interface validation and binding
    ":if ([:len [/interface find name=\"{$iface}\"]] = 0) do={ :error ... }",
    
    // 3. IP Pool configuration
    "/ip pool add name={$poolName} ranges={$ipPool}",
    
    // 4. PPP Profile with RADIUS integration
    "/ppp profile add name={$profileName} 
        local-address={$gateway} 
        remote-address={$poolName}
        dns-server={$dnsServers}
        interface-list=PPPOE-ACTIVE",
    
    // 5. PPPoE Server on bridge
    "/interface pppoe-server server add 
        service-name={$serviceName}
        interface={$bridgeName}
        default-profile={$profileName}
        authentication=chap,mschap2
        one-session-per-host=yes",
    
    // 6. RADIUS configuration
    "/radius add service=ppp address={$radiusIp} secret={$radiusSecret}",
    "/ppp aaa set use-radius=yes accounting=yes interim-update=5m",
    
    // 7. Firewall rules (interface-list based)
    "/ip firewall filter add chain=forward 
        in-interface-list=PPPOE-ACTIVE 
        out-interface-list=WAN action=accept",
    
    // 8. NAT masquerade (interface-list based)
    "/ip firewall nat add chain=srcnat 
        in-interface-list=PPPOE-ACTIVE 
        action=masquerade"
];
```

### 3.4 Security Model

**Critical Security Principles**:

1. **Interface-List Based Rules**: Only authenticated PPPoE sessions (via `interface-list=PPPOE-ACTIVE`) can access WAN
2. **Bridge Traffic Blocking**: All traffic from the bridge is dropped unless authenticated
3. **Anti-Spoofing**: NAT uses `in-interface-list` not `src-address`, preventing IP spoofing
4. **Single Session Per Host**: `one-session-per-host=yes` prevents account sharing

### 3.5 Session Management

**RADIUS Accounting Flow**:

```
Subscriber Connects
    ↓
MikroTik → Access-Request → FreeRADIUS
    ↓
FreeRADIUS queries radcheck (username/password)
    ↓
Accept → MikroTik creates dynamic interface
    ↓
Interface auto-joins PPPOE-ACTIVE list
    ↓
Accounting-Start sent to RADIUS
    ↓
Interim-Update every 5 minutes (data usage)
    ↓
Accounting-Stop on disconnect
```

### 3.6 Improvements

**Recommended Enhancements**:

1. **CoA (Change of Authorization) Support**:
```php
// Implement dynamic bandwidth changes without disconnect
public function sendCoARequest(string $username, array $attributes): bool
{
    // Send Disconnect-Request or CoA-Request to RADIUS
    // Attributes: Mikrotik-Rate-Limit, Session-Timeout
}
```

2. **PPPoE Session Mirroring**:
```php
// Monitor sessions without interrupting service
public function enableSessionMirroring(Router $router): void
{
    // Mirror PPPoE traffic to analysis port
}
```

3. **Backup RADIUS**:
```php
// Configure secondary RADIUS for failover
"/radius add service=ppp address={$backupRadiusIp} secret={$secret} backup=yes"
```

---

## 4. Hotspot Implementation

### 4.1 Architecture

The Hotspot implementation uses a **centralized captive portal** with RADIUS authentication and external redirection.

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Client    │────▶│  MikroTik   │────▶│   Portal    │
│   Device    │     │   Hotspot   │     │   (Vue SPA) │
└─────────────┘     └─────────────┘     └─────────────┘
                            │                   │
                            ▼                   ▼
                    ┌─────────────┐     ┌─────────────┐
                    │   RADIUS    │◄────│   Billing   │
                    │ (Auth/Acct) │     │   Engine    │
                    └─────────────┘     └─────────────┘
```

### 4.2 Core Components

**HotspotService** (`/backend/app/Services/MikroTik/HotspotService.php`):

```php
class HotspotService extends BaseMikroTikService
{
    public function generateConfig(array $interfaces, string $routerId, array $options): string
    {
        // Key features:
        // 1. Bridge-based interface aggregation
        // 2. External captive portal redirect
        // 3. RADIUS authentication
        // 4. Tier-based firewall optimization
    }
}
```

### 4.3 Configuration Structure

**Zero-Config Hotspot Script** (`ZeroConfigHotspotGenerator`):

```php
$script = [
    // 1. Bridge Setup
    "/interface bridge add name={$bridgeName}",
    "/interface bridge port add bridge={$bridgeName} interface={$iface}",
    
    // 2. IP Configuration
    "/ip address add address={$gateway}/24 interface={$bridgeName}",
    "/ip pool add name={$poolName} ranges={$poolRange}",
    
    // 3. DHCP Server
    "/ip dhcp-server add name={$dhcpName} interface={$bridgeName} 
        address-pool={$poolName} lease-time=1h",
    "/ip dhcp-server network add address={$network} gateway={$gateway}",
    
    // 4. Hotspot Profile
    "/ip hotspot profile add name={$profileName} 
        hotspot-address={$gateway}
        login-by=http-chap,mac-cookie,http-pap
        use-radius=yes
        html-directory=hotspot",
    
    // 5. Captive Portal Redirect
    "/file set hotspot/login.html contents=\"<meta http-equiv=refresh content=0;url={$portalUrl}>\"",
    
    // 6. Hotspot Server
    "/ip hotspot add name={$serverName} interface={$bridgeName} 
        profile={$profileName} address-pool={$poolName}",
    
    // 7. RADIUS
    "/radius add service=hotspot address={$radiusIp} secret={$radiusSecret}",
    
    // 8. Walled Garden (allow portal access)
    "/ip hotspot walled-garden add dst-host={$portalHost} action=allow",
    
    // 9. Firewall (tier-based)
    "/ip firewall filter add chain=forward in-interface={$bridgeName} 
        action=drop comment=\"DROP-UNAUTH\"",
    "/ip firewall filter add chain=forward in-interface={$bridgeName} 
        hotspot=auth action=accept comment=\"ALLOW-AUTH\"",
];
```

### 4.4 Tier-Based Optimization

**Low-End Device Rules (hAP lite)**:
```rsc
/ip firewall filter add chain=forward in-interface={$iface} action=drop place-before=0
/ip firewall filter add chain=forward in-interface={$iface} hotspot=auth out-interface-list=WAN action=accept place-before=0
/ip firewall filter add chain=forward in-interface={$iface} connection-state=established,related action=accept place-before=0
/ip firewall filter add chain=forward in-interface={$iface} connection-state=invalid action=drop place-before=0
```

**Full Rules (High-End Devices)**:
- Same core rules plus:
- ICMP allowance
- DNS port allowance
- Connection rate limiting

### 4.5 Session States

**Hotspot States**:

| State | Description | Firewall Action |
|-------|-------------|-----------------|
| `unknown` | Not yet authenticated | DROP |
| `auth` | Authenticated via RADIUS | ACCEPT |
| `hotspot` | Hotspot related traffic | Conditional |
| `bypassed` | MAC bypass enabled | ACCEPT |

### 4.6 MAC Cookie Authentication

**Seamless Re-authentication**:
```rsc
/ip hotspot user profile set default-hotspot add-mac-cookie=yes
/ip hotspot user profile set default-hotspot mac-cookie-timeout=3d
```

### 4.7 Improvements

**Recommended Enhancements**:

1. **Band Steering Integration**:
```php
// Direct dual-band clients to optimal frequency
public function configureBandSteering(Router $router): void
{
    // Add rules to move clients between 2.4G and 5G
}
```

2. **Smart Queue Integration**:
```php
// Per-user bandwidth management
/ip queue tree add name="hs-client-{$mac}" parent=global 
    packet-mark={$mark} limit-at={$up}/{$down}
```

3. **Social Login Support**:
```php
// OAuth integration for captive portal
public function enableSocialAuth(string $provider): void
{
    // Google/Facebook OAuth via walled garden
}
```

---

## 5. Hybrid Provisioning

### 5.1 Architecture

Hybrid mode enables **simultaneous PPPoE and Hotspot** on the same physical interface with VLAN separation.

```
┌─────────────────────────────────────────────────────────────┐
│                    Physical Interface                        │
│                         (ether2)                             │
└─────────────────────┬───────────────────────────────────────┘
                      │
        ┌─────────────┴─────────────┐
        │                           │
        ▼                           ▼
┌───────────────┐         ┌───────────────┐
│   VLAN 100    │         │   VLAN 200    │
│  (PPPoE Only) │         │ (Hotspot Only)│
└───────┬───────┘         └───────┬───────┘
        │                         │
        ▼                         ▼
┌───────────────┐         ┌───────────────┐
│  PPPoE Server │         │  Hotspot      │
│  (RADIUS)     │         │  (Captive)    │
└───────────────┘         └───────────────┘
```

### 5.2 Configuration Flow

**RouterServiceManager** (`configureHybridService`):

```php
private function configureHybridService(Router $router, string $interface, array $options): RouterService
{
    // 1. Allocate VLANs (auto or manual)
    $hotspotVlan = $this->vlanManager->allocateVlanForService($router, 'hotspot');
    $pppoeVlan = $this->vlanManager->allocateVlanForService($router, 'pppoe');
    
    // 2. Get or create IP pools
    $hotspotPool = $this->ipamService->getOrCreateServicePool($tenant, 'hotspot');
    $pppoePool = $this->ipamService->getOrCreateServicePool($tenant, 'pppoe');
    
    // 3. Create hybrid service record
    $service = RouterService::create([
        'service_type' => RouterService::TYPE_HYBRID,
        'advanced_config' => [
            'hotspot_pool_id' => $hotspotPool->id,
            'pppoe_pool_id' => $pppoePool->id,
            'hotspot_vlan' => $hotspotVlan,
            'pppoe_vlan' => $pppoeVlan,
        ]
    ]);
    
    // 4. Create VLAN records
    $this->vlanManager->createServiceVlan($service, $hotspotVlan, $interface, 'hotspot');
    $this->vlanManager->createServiceVlan($service, $pppoeVlan, $interface, 'pppoe');
}
```

### 5.3 Zero-Config Hybrid Generator

**Script Generation** (`ZeroConfigHybridGenerator`):

```rsc
# 1. VLAN Creation
/interface vlan add name=vlan-pppoe-100 vlan-id=100 interface=ether2
/interface vlan add name=vlan-hotspot-200 vlan-id=200 interface=ether2

# 2. PPPoE on VLAN 100
/interface pppoe-server server add service-name=hybrid-pppoe interface=vlan-pppoe-100

# 3. Hotspot on VLAN 200
/ip hotspot add name=hybrid-hotspot interface=vlan-hotspot-200

# 4. Shared RADIUS
/radius add service=ppp,hotspot address=10.8.0.1 secret=testing123
```

### 5.4 Bridge Mode (No VLAN)

For simple deployments without managed switches:

```php
if ($bridgeMode) {
    // Both services share the same bridge
    // Traffic separation via service binding only
    $service->vlan_required = false;
}
```

### 5.5 Improvements

**Recommended Enhancements**:

1. **Auto-VLAN Negotiation**:
```php
// Automatically detect and configure VLANs
public function autoConfigureVlans(Router $router): void
{
    // Detect switch capabilities
    // Negotiate VLAN IDs with upstream
}
```

2. **Traffic Steering**:
```php
// Route traffic based on service type
/ip route add dst-address=0.0.0.0/0 gateway=pppoe-gateway routing-mark=pppoe
/ip route add dst-address=0.0.0.0/0 gateway=hotspot-gateway routing-mark=hotspot
```

3. **Service Priority**:
```php
// QoS prioritization per service
/queue tree add name=pppoe-priority parent=global priority=1
/queue tree add name=hotspot-priority parent=global priority=8
```

---

## 6. RADIUS Integration

### 6.1 Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      FreeRADIUS 3.x                             │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐              │
│  │   mod_sql   │  │ mod_sqlcounter│  │  mod_rest  │              │
│  │  (PostgreSQL)│  │  (Quotas)   │  │  (API Call) │              │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘              │
│         │                │                │                     │
│         └────────────────┴────────────────┘                     │
│                          │                                      │
│                   ┌──────┴──────┐                              │
│                   │  rlm_perl   │  ← Custom authorization logic  │
│                   └─────────────┘                              │
└─────────────────────────────────────────────────────────────────┘
           │                    │                    │
           ▼                    ▼                    ▼
    ┌─────────────┐      ┌─────────────┐      ┌─────────────┐
    │   radcheck  │      │   radreply  │      │   radacct   │
    │ (passwords)│      │ (attributes)│      │ (accounting)│
    └─────────────┘      └─────────────┘      └─────────────┘
```

### 6.2 Database Schema (Per-Tenant)

**radcheck** (Authentication):
```sql
CREATE TABLE radcheck (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL,
    INDEX idx_username (username)
);

-- Example entries:
-- username | attribute    | op | value
-- johndoe  | Cleartext-Password | := | secret123
-- johndoe  | Auth-Type    | := | Accept (or Reject to block)
```

**radreply** (Authorization Attributes):
```sql
CREATE TABLE radreply (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op VARCHAR(2) NOT NULL,
    value VARCHAR(253) NOT NULL
);

-- Example entries:
-- username | attribute           | op | value
-- johndoe  | Mikrotik-Rate-Limit | := | 10M/10M
-- johndoe  | Session-Timeout     | := | 3600
-- johndoe  | Idle-Timeout        | := | 600
```

**radacct** (Accounting):
```sql
CREATE TABLE radacct (
    radacctid BIGSERIAL PRIMARY KEY,
    acctsessionid VARCHAR(64) NOT NULL,
    username VARCHAR(64) NOT NULL,
    nasipaddress INET NOT NULL,
    acctstarttime TIMESTAMP,
    acctstoptime TIMESTAMP,
    acctsessiontime INTEGER,
    acctinputoctets BIGINT,
    acctoutputoctets BIGINT,
    callingstationid VARCHAR(50), -- MAC address
    framedipaddress INET -- Assigned IP
);
```

### 6.3 RADIUS Service Controller

**Key Operations** (`RADIUSServiceController.php`):

```php
class RADIUSServiceController
{
    /**
     * Suspend user access (used for billing suspension)
     */
    public function suspendUser(User $user): bool
    {
        // Set Auth-Type := Reject
        DB::table('radcheck')->updateOrInsert(
            ['username' => $user->username, 'attribute' => 'Auth-Type'],
            ['op' => ':=', 'value' => 'Reject']
        );
    }
    
    /**
     * Reconnect user after payment
     */
    public function reconnectUser(User $user): bool
    {
        // Remove forced Auth-Type to allow normal authentication
        DB::table('radcheck')
            ->where('username', $user->username)
            ->where('attribute', 'Auth-Type')
            ->delete();
    }
    
    /**
     * Get real-time session data
     */
    public function getActiveSession(string $username): ?object
    {
        return DB::table('radacct')
            ->where('username', $username)
            ->whereNull('acctstoptime')
            ->first();
    }
}
```

### 6.4 SQLCounter Modules

**Quota Enforcement**:
```sql
-- quotalimit counter (data usage)
sqlcounter quotalimit {
    sql_module_instance = sql
    dialect = ${modules.sql.dialect}
    counter_name = Max-Volume
    check_name = Max-Data
    reply_name = Mikrotik-Total-Limit
    key = User-Name
    reset = never
}

-- accessperiod counter (time since first login)
sqlcounter accessperiod {
    counter_name = Max-Access-Period-Time
    check_name = Access-Period
    key = User-Name
    reset = never
}
```

### 6.5 Improvements

**Recommended Enhancements**:

1. **Dynamic VLAN Assignment**:
```php
// Return VLAN ID in Access-Accept
radreply.attribute = 'Tunnel-Private-Group-Id';
radreply.value = $user->assigned_vlan_id;
```

2. **Bulk Session Operations**:
```php
// Disconnect multiple users (maintenance window)
public function bulkDisconnect(array $usernames): void
{
    // Send CoA Disconnect-Request for each
}
```

3. **Real-time Analytics**:
```php
// Stream radacct to analytics pipeline
public function enableAccountingStream(): void
{
    // Kafka/Redis Stream integration
}
```

---

## 7. Device Driver Architecture

### 7.1 Service Pattern

The system uses a **generator-based service pattern** for device configuration:

```php
// Service Hierarchy
BaseMikroTikService (abstract)
├── HotspotService
├── PPPoEService
└── HybridService

// Generator Hierarchy
ZeroConfigHotspotGenerator
ZeroConfigPPPoEGenerator
ZeroConfigHybridGenerator
```

### 7.2 Base Service Features

**BaseMikroTikService** (`/backend/app/Services/MikroTik/BaseMikroTikService.php`):

```php
abstract class BaseMikroTikService
{
    // Interface validation
    protected function validateInterface(string $interface): string;
    
    // IP pool validation
    protected function validateIpPool(string $pool): string;
    
    // WAN interface resolution
    protected function resolveWanInterface(?string $wanInterface): string;
    
    // Gateway calculation from pool
    protected function getGatewayFromPool(string $pool): string;
    
    // Script header/footer generation
    protected function generateHeader(string $title, string $routerId): array;
    protected function generateFooter(): array;
}
```

### 7.3 Generator Pattern

**Zero-Config Generation Flow**:

```php
class ZeroConfigPPPoEGenerator
{
    public function generate(RouterService $service): string
    {
        // 1. Extract service configuration
        $interfaces = $this->normalizeInterfaces($service);
        $pool = $service->ipPool;
        $router = $service->router;
        
        // 2. Detect router tier (hAP lite vs high-end)
        $isLowEnd = RouterResourceManager::getRouterTierByModel($router->model) === 'low_end';
        
        // 3. Generate tier-optimized script
        $script = [];
        $script = array_merge($script, $this->generateBridgeSetup($params));
        $script = array_merge($script, $this->generatePoolSetup($params));
        $script = array_merge($script, $this->generateFirewallRules($params, $isLowEnd));
        
        // 4. Return RouterOS Script (RSC format)
        return implode("\n", $script);
    }
}
```

### 7.4 Configuration Templates

**Service Template System** (`ServiceTemplateService.php`):

```php
class ServiceTemplateService
{
    // Predefined configurations for common scenarios
    const TEMPLATE_HOTSPOT_CAFE = 'hotspot_cafe';
    const TEMPLATE_PPPOE_RESIDENTIAL = 'pppoe_residential';
    const TEMPLATE_HYBRID_OFFICE = 'hybrid_office';
    
    public function applyTemplate(string $templateId, Router $router): array
    {
        // Return predefined configuration array
    }
}
```

### 7.5 API Configurators

**REST API Fallback** (`PppoeApiConfigurator.php`):

```php
class PppoeApiConfigurator
{
    public function configure(): array
    {
        // Use MikroTik REST API instead of SSH/RSC
        // For routers with API-SSL enabled
    }
    
    public function verify(): array
    {
        // Verify configuration via API queries
    }
}
```

### 7.6 Improvements

**Recommended Enhancements**:

1. **Configuration Versioning**:
```php
// Track configuration changes
class RouterConfigVersion
{
    public function createSnapshot(Router $router): string;
    public function restoreVersion(Router $router, string $versionId): bool;
}
```

2. **Configuration Diff**:
```php
// Show what will change before deployment
public function generateDiff(Router $router, string $newConfig): array
{
    // Compare running-config with proposed config
}
```

3. **Multi-Vendor Support**:
```php
// Abstract interface for non-MikroTik devices
interface RouterDriverInterface
{
    public function generateConfig(array $params): string;
    public function validateConfig(string $config): bool;
    public function applyConfig(string $config): bool;
}
```

---

## 8. Deployment & Provisioning

### 8.1 Deployment Methods

**1. SSH with RSC Scripts (Primary)**:
```php
$ssh = new SshExecutor($router, 30);
$ssh->connect();
$ssh->exec($rscScript);
```

**2. REST API (Fallback)**:
```php
$apiService = new MikroTikRestApiService($router);
$configurator = new PppoeApiConfigurator($apiService, $serviceId, $config);
$result = $configurator->configure();
```

**3. Provisioning Service (Secure)**:
```php
// Separate Go service for network segmentation
$client = new ProvisioningServiceClient();
$client->deploy($router, $config);
```

### 8.2 Deployment Job Flow

**DeployRouterServiceJob** (`/backend/app/Jobs/DeployRouterServiceJob.php`):

```php
class DeployRouterServiceJob implements ShouldQueue
{
    public $timeout = 300;
    public $backoff = [15, 30, 60]; // Exponential backoff
    public $maxExceptions = 3;
    
    public function handle(): void
    {
        // Phase 1: Generate configuration (in transaction)
        $deployData = $this->executeInTenantContext(function () {
            $service = RouterService::with(['router', 'ipPool'])->find($this->serviceId);
            $config = $this->generateConfiguration($service);
            return compact('service', 'config');
        });
        
        // Phase 2: Acquire lock and deploy (outside transaction)
        $lock = Cache::lock("deploy_router_{$router->id}", 60);
        if (!$lock->get()) {
            $this->release(10); // Retry later
            return;
        }
        
        try {
            // SSH deployment
            $provisioningService = app(MikrotikProvisioningService::class);
            $result = $provisioningService->applyConfigs($router, $config, false);
        } finally {
            $lock->release();
        }
        
        // Phase 3: Update status (new transaction)
        $this->executeInTenantContext(function () use ($service, $result) {
            $service->update([
                'deployment_status' => RouterService::DEPLOYMENT_DEPLOYED,
                'status' => RouterService::STATUS_ACTIVE,
                'deployed_at' => now(),
            ]);
        });
    }
}
```

### 8.3 Progress Broadcasting

**Real-time Updates**:
```php
private function broadcastServiceProgress(RouterService $service, string $stage, float $progress, string $message): void
{
    broadcast(new RouterProvisioningProgress(
        $service->router_id,
        "service_deploy_{$stage}",
        $progress,
        $message,
        ['service_id' => $service->id]
    ))->toOthers();
}
```

### 8.4 Verification Process

**Post-Deployment Checks**:
```php
public function verifyHotspotDeployment(Router $router): array
{
    $ssh = new SshExecutor($router, 10);
    $ssh->connect();
    
    $checks = [
        'hotspot_server' => $ssh->exec('/ip hotspot print detail'),
        'hotspot_profile' => $ssh->exec('/ip hotspot profile print detail'),
        'radius' => $ssh->exec('/radius print detail'),
        'ip_pool' => $ssh->exec('/ip pool print detail'),
        'dhcp_server' => $ssh->exec('/ip dhcp-server print detail'),
    ];
    
    return $checks;
}
```

### 8.5 Rollback Capability

**Configuration Backup**:
```php
public function backupConfiguration(Router $router): string
{
    $ssh = new SshExecutor($router);
    $ssh->connect();
    
    // Export running configuration
    $backup = $ssh->exec('/export compact');
    
    // Store in database
    RouterConfig::create([
        'router_id' => $router->id,
        'config_text' => $backup,
        'created_at' => now(),
    ]);
    
    return $backup;
}
```

### 8.6 Improvements

**Recommended Enhancements**:

1. **Canary Deployments**:
```php
// Deploy to subset of routers first
public function canaryDeploy(array $routers, string $config, int $percentage): void
{
    // Deploy to $percentage% of routers
    // Monitor for errors
    // Rollback if failure rate > threshold
}
```

2. **Configuration Drift Detection**:
```php
// Detect manual changes on routers
public function detectDrift(Router $router): array
{
    $running = $this->getRunningConfig($router);
    $expected = $this->getExpectedConfig($router);
    return $this->calculateDiff($running, $expected);
}
```

3. **Blue/Green Deployment**:
```php
// Maintain two configuration sets
public function blueGreenDeploy(Router $router): void
{
    // Deploy new config as "blue"
    // Test connectivity
    // Switch traffic to "blue"
    // Keep "green" as rollback
}
```

---

## 9. Low-End Device Support

### 9.1 Router Tier Classification

**RouterResourceManager** (`/backend/app/Services/RouterResourceManager.php`):

```php
class RouterResourceManager
{
    const TIER_LOW_END = 'low_end';
    const TIER_MID_RANGE = 'mid_range';
    const TIER_HIGH_END = 'high_end';
    
    public static function getRouterTierByModel(string $model): string
    {
        $lowEndModels = [
            'hAP lite', 'hAP lite TC', 'hAP mini',
            'mAP', 'cAP lite', 'wAP', 'wAP 60G',
            'LHG 5', 'SXT Lite5', 'SXTsq 5',
        ];
        
        foreach ($lowEndModels as $lowEnd) {
            if (stripos($model, $lowEnd) !== false) {
                return self::TIER_LOW_END;
            }
        }
        
        return self::TIER_HIGH_END;
    }
}
```

### 9.2 Optimization Strategies

**1. Delayed Execution**:
```php
// Add 500ms delays between sections for hAP lite
private function addLowEndDelays(array $script): array
{
    $delayed = [];
    foreach ($script as $line) {
        $delayed[] = $line;
        if (!$this->isComment($line)) {
            $delayed[] = ":delay 500ms"; // Prevent CPU overload
        }
    }
    return $delayed;
}
```

**2. Minimal Firewall Rules**:
```php
// Low-end: ~7 rules vs Full: ~15 rules
private function generateFirewallRules(array $params, bool $isLowEnd): array
{
    if ($isLowEnd) {
        return [
            // Essential rules only
            "/ip firewall filter add chain=forward action=drop", // Block unauth
            "/ip firewall filter add chain=forward hotspot=auth action=accept", // Allow auth
            "/ip firewall filter add chain=forward connection-state=established,related action=accept",
            "/ip firewall filter add chain=forward connection-state=invalid action=drop",
        ];
    }
    
    // Full rule set for high-end devices
    return [...];
}
```

**3. Batch Interface Processing**:
```php
// Process interfaces in batches of 2 for low-end devices
private function batchInterfaces(array $interfaces, bool $isLowEnd): array
{
    if ($isLowEnd && count($interfaces) > 2) {
        return array_chunk($interfaces, 2);
    }
    return [$interfaces];
}
```

### 9.3 Memory-Conscious Configurations

**Connection Tracking Limits**:
```rsc
# Low-end: Reduce connection tracking
/ip firewall connection tracking set enabled=yes 
    total-max-entries=16384 
    tcp-established-timeout=1d
```

**DNS Cache**:
```rsc
# Low-end: Limit DNS cache size
/ip dns set cache-size=2048
```

### 9.4 CPU Load Management

**Script Execution Timing**:
```rsc
# Add delays between CPU-intensive operations
/interface bridge port add bridge=br0 interface=ether2
:delay 300ms
/interface bridge port add bridge=br0 interface=ether3
:delay 300ms
/interface bridge port add bridge=br0 interface=ether4
```

### 9.5 Improvements

**Recommended Enhancements**:

1. **Dynamic Resource Monitoring**:
```php
// Monitor router CPU/memory during deployment
public function monitorResources(Router $router): Generator
{
    while ($deploying) {
        $usage = $this->getResourceUsage($router);
        if ($usage['cpu'] > 80) {
            yield 'pause'; // Slow down deployment
        }
    }
}
```

2. **Automatic Tier Detection**:
```php
// Detect router capabilities dynamically
public function detectCapabilities(Router $router): array
{
    $ssh = new SshExecutor($router);
    $system = $ssh->exec('/system resource print');
    
    return [
        'total_memory' => $this->parseMemory($system),
        'cpu_count' => $this->parseCpuCount($system),
        'board_name' => $this->parseBoardName($system),
    ];
}
```

3. **Configuration Sizing**:
```php
// Scale configuration based on available resources
public function sizeConfiguration(Router $router, array $baseConfig): array
{
    $capabilities = $this->detectCapabilities($router);
    
    if ($capabilities['total_memory'] < 32) { // MB
        return $this->reduceConfigSize($baseConfig);
    }
    
    return $baseConfig;
}
```

---

## 10. Security Features

### 10.1 Network Security

**Firewall Architecture**:
```rsc
# Input Chain - Management Protection
/ip firewall filter add chain=input 
    protocol=tcp dst-port=22,8291,8728 
    src-address={$management_subnet} 
    action=accept 
    comment="Allow Management"

/ip firewall filter add chain=input 
    protocol=tcp dst-port=22,8291,8728 
    action=drop 
    comment="Drop External Management"

# Forward Chain - Authentication Enforcement
/ip firewall filter add chain=forward 
    in-interface-list=PPPOE-ACTIVE 
    out-interface-list=WAN 
    action=accept 
    comment="Allow Authenticated"

/ip firewall filter add chain=forward 
    in-interface={$bridgeName} 
    action=drop 
    comment="Block Unauthenticated"
```

### 10.2 Router Hardening

**RouterHardeningService** (`/backend/app/Services/MikroTik/RouterHardeningService.php`):

```php
class RouterHardeningService
{
    public function applyHardening(Router $router): void
    {
        $ssh = new SshExecutor($router);
        $ssh->connect();
        
        // Disable unused services
        $ssh->exec('/ip service disable telnet,ftp,www,api');
        
        // Enable strong SSH
        $ssh->exec('/ip ssh set strong-crypto=yes');
        
        // Disable neighbor discovery on WAN
        $ssh->exec('/ip neighbor discovery-settings set discover-interface-list=LAN');
        
        // Enable port knocking (optional)
        $this->configurePortKnocking($ssh);
    }
}
```

### 10.3 Access Control

**Management ACL**:
```rsc
# Allow management only from VPN subnet
/ip firewall filter add chain=input 
    protocol=tcp dst-port=22,8291,8728,8729 
    src-address=10.8.0.0/16 
    action=accept 
    place-before=0

# Drop all other management attempts
/ip firewall filter add chain=input 
    protocol=tcp dst-port=22,8291,8728,8729 
    action=drop 
    place-before=0
```

### 10.4 SSH Security

**Key-Based Authentication**:
```php
class SshKeyRotationService
{
    public function generateKeyPair(): array
    {
        // Generate Ed25519 key pair
        $privateKey = ssh_keygen(['type' => 'ed25519']);
        $publicKey = $privateKey->getPublicKey();
        
        return compact('privateKey', 'publicKey');
    }
    
    public function rotateKeys(Router $router): void
    {
        // Generate new keys
        $keys = $this->generateKeyPair();
        
        // Add new public key to router
        $ssh->exec("/user ssh-keys add user=admin public-key-file={$keys['publicKey']}");
        
        // Remove old keys after grace period
        // ...
    }
}
```

### 10.5 Audit Logging

**Security Event Tracking**:
```php
class AuditLogService
{
    public function logSecurityEvent(string $event, array $context): void
    {
        AuditLog::create([
            'tenant_id' => $this->getTenantId(),
            'event_type' => 'security',
            'event_name' => $event,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'context' => json_encode($context),
            'severity' => $this->calculateSeverity($event),
        ]);
    }
}
```

### 10.6 Improvements

**Recommended Enhancements**:

1. **Intrusion Detection**:
```php
// Monitor for brute force attempts
public function detectBruteForce(Router $router): void
{
    $logs = $ssh->exec('/log print where topics~"login"');
    // Analyze for multiple failed attempts
    // Trigger alerts and auto-block IPs
}
```

2. **Certificate Management**:
```php
// Auto-renew SSL certificates for API-SSL
class CertificateManager
{
    public function autoRenew(Router $router): void
    {
        // Check certificate expiry
        // Request new certificate from Let's Encrypt
        // Deploy to router
    }
}
```

3. **Zero Trust Networking**:
```php
// Implement mTLS between all services
public function enableZeroTrust(): void
{
    // Certificate-based service authentication
    // No implicit trust based on network location
}
```

---

## 11. Monitoring & Observability

### 11.1 Metrics Collection

**Prometheus Integration**:
```php
class SystemMetricsService
{
    public function collectRouterMetrics(Router $router): array
    {
        $ssh = new SshExecutor($router);
        $ssh->connect();
        
        $resource = $ssh->exec('/system resource print');
        
        return [
            'cpu_load' => $this->parseCpuLoad($resource),
            'memory_usage' => $this->parseMemoryUsage($resource),
            'temperature' => $this->parseTemperature($resource),
            'uptime' => $this->parseUptime($resource),
        ];
    }
}
```

### 11.2 Health Checks

**RouterStatusCheckService**:
```php
class RouterStatusCheckService
{
    public function checkHealth(Router $router): HealthStatus
    {
        $checks = [
            'connectivity' => $this->checkConnectivity($router),
            'services' => $this->checkServices($router),
            'resources' => $this->checkResources($router),
            'interfaces' => $this->checkInterfaces($router),
        ];
        
        return new HealthStatus($checks);
    }
}
```

### 11.3 Real-time Session Monitoring

**Active Session Tracking**:
```php
class RouterMetricsService
{
    public function getActiveSessions(Router $router): Collection
    {
        $ssh = new SshExecutor($router);
        
        // PPPoE sessions
        $pppoe = $ssh->exec('/ppp active print detail');
        
        // Hotspot sessions
        $hotspot = $ssh->exec('/ip hotspot active print detail');
        
        return collect([
            'pppoe' => $this->parsePppoeSessions($pppoe),
            'hotspot' => $this->parseHotspotSessions($hotspot),
        ]);
    }
}
```

### 11.4 Alerting

**Multi-Channel Notifications**:
```php
class MessagingService
{
    public function sendAlert(string $channel, string $message, array $recipients): void
    {
        switch ($channel) {
            case 'sms':
                $this->smsProvider->send($recipients, $message);
                break;
            case 'email':
                Mail::to($recipients)->send(new AlertMail($message));
                break;
            case 'telegram':
                $this->telegramBot->sendMessage($recipients, $message);
                break;
            case 'webhook':
                $this->dispatchWebhook($recipients, $message);
                break;
        }
    }
}
```

### 11.5 Log Aggregation

**Structured Logging**:
```php
Log::info('Router deployment completed', [
    'router_id' => $router->id,
    'tenant_id' => $router->tenant_id,
    'service_type' => $service->service_type,
    'execution_time' => $executionTime,
    'config_size' => strlen($config),
    'timestamp' => now()->toIso8601String(),
]);
```

### 11.6 Improvements

**Recommended Enhancements**:

1. **Distributed Tracing**:
```php
// OpenTelemetry integration
public function traceDeployment(string $traceId): void
{
    $span = $tracer->spanBuilder('router-deployment')->startSpan();
    $span->setAttribute('router.id', $router->id);
    // ... trace operations
    $span->end();
}
```

2. **Predictive Analytics**:
```php
// ML-based failure prediction
class PredictiveMaintenance
{
    public function predictFailure(Router $router): float
    {
        $metrics = $this->getHistoricalMetrics($router);
        return $this->mlModel->predictFailureProbability($metrics);
    }
}
```

3. **Custom Dashboards**:
```php
// Per-tenant Grafana dashboards
public function provisionDashboard(Tenant $tenant): void
{
    $dashboard = $this->grafanaClient->createDashboard([
        'title' => "{$tenant->name} - Network Overview",
        'panels' => $this->getStandardPanels(),
    ]);
}
```

---

## 12. Billing & Payments

### 12.1 Billing Models

**SubscriptionManager** (`/backend/app/Services/SubscriptionManager.php`):

```php
class SubscriptionManager
{
    const BILLING_PREPAID = 'prepaid';
    const BILLING_POSTPAID = 'postpaid';
    const BILLING_HYBRID = 'hybrid';
    
    public function createSubscription(User $user, Plan $plan): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'billing_type' => $plan->billing_type,
            'start_date' => now(),
            'next_billing_date' => $this->calculateNextBilling($plan),
            'status' => 'active',
        ]);
    }
}
```

### 12.2 Payment Gateways

**M-Pesa C2B Integration** (`MpesaC2BService.php`):
```php
class MpesaC2BService
{
    public function registerUrl(Tenant $tenant): array
    {
        // Register validation and confirmation URLs
        $response = $this->mpesaClient->registerUrl([
            'ValidationURL' => route('mpesa.c2b.validate', $tenant),
            'ConfirmationURL' => route('mpesa.c2b.confirm', $tenant),
            'ResponseType' => 'Completed',
        ]);
        
        return $response;
    }
    
    public function processPayment(array $payload): Payment
    {
        // Validate transaction
        // Create payment record
        // Update subscriber balance
        // Reconnect if suspended
    }
}
```

### 12.3 Usage Metering

**Data Usage Tracking**:
```php
class DataUsageTracker
{
    public function recordUsage(string $username, int $bytesIn, int $bytesOut): void
    {
        DataUsage::create([
            'username' => $username,
            'bytes_in' => $bytesIn,
            'bytes_out' => $bytesOut,
            'recorded_at' => now(),
        ]);
    }
    
    public function getMonthlyUsage(User $user): int
    {
        return DataUsage::where('username', $user->username)
            ->whereMonth('recorded_at', now()->month)
            ->sum('bytes_in') + 
            DataUsage::where('username', $user->username)
            ->whereMonth('recorded_at', now()->month)
            ->sum('bytes_out');
    }
}
```

### 12.4 Invoice Generation

**Billing Lifecycle**:
```php
class SaasBillingService
{
    public function generateInvoice(Subscription $subscription): Invoice
    {
        $usage = $this->calculateUsage($subscription);
        $charges = $this->calculateCharges($subscription, $usage);
        
        $invoice = Invoice::create([
            'subscription_id' => $subscription->id,
            'amount' => $charges['total'],
            'usage_details' => $charges['breakdown'],
            'due_date' => now()->addDays(7),
            'status' => 'pending',
        ]);
        
        // Send invoice notification
        event(new InvoiceGenerated($invoice));
        
        return $invoice;
    }
}
```

### 12.5 Improvements

**Recommended Enhancements**:

1. **Usage Alerts**:
```php
// Notify users at 80% of quota
public function checkQuotaThresholds(User $user): void
{
    $usage = $this->getCurrentUsage($user);
    $plan = $user->subscription->plan;
    
    if ($usage > ($plan->data_quota * 0.8)) {
        event(new QuotaThresholdReached($user, 80));
    }
}
```

2. **Prorated Billing**:
```php
// Handle mid-cycle plan changes
public function calculateProration(Subscription $subscription, Plan $newPlan): float
{
    $daysUsed = $subscription->start_date->diffInDays(now());
    $daysInCycle = $subscription->start_date->diffInDays($subscription->next_billing_date);
    
    $credit = ($subscription->plan->price / $daysInCycle) * ($daysInCycle - $daysUsed);
    $debit = ($newPlan->price / $daysInCycle) * ($daysInCycle - $daysUsed);
    
    return $debit - $credit;
}
```

3. **Auto-Topup**:
```php
// Automatic balance replenishment
public function processAutoTopup(User $user): void
{
    if ($user->balance < $user->auto_topup_threshold) {
        $this->paymentGateway->charge($user->payment_method, $user->auto_topup_amount);
        $user->increment('balance', $user->auto_topup_amount);
    }
}
```

---

## 13. Queue & Background Jobs

### 13.1 Queue Configuration

**Redis Queue Setup**:
```php
// config/queue.php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => 'default',
    'retry_after' => 90,
    'block_for' => null,
    'after_commit' => false,
],

// Tenant-aware queue names
'queues' => [
    'router-provisioning' => [
        'connection' => 'redis',
        'queue' => 'router-provisioning',
        'retry_after' => 300,
    ],
    'radius-sync' => [
        'connection' => 'redis',
        'queue' => 'radius-sync',
        'retry_after' => 60,
    ],
]
```

### 13.2 Job Classes

**TenantAwareJob Trait**:
```php
trait TenantAwareJob
{
    protected string $tenantId;
    
    public function executeInTenantContext(callable $callback): mixed
    {
        $tenant = Tenant::find($this->tenantId);
        
        if (!$tenant) {
            throw new \Exception("Tenant not found: {$this->tenantId}");
        }
        
        // Set tenant context
        app(TenantContext::class)->setTenant($tenant);
        
        // Set database search path
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        
        try {
            return $callback();
        } finally {
            // Reset context
            app(TenantContext::class)->clear();
            DB::statement('SET search_path TO public');
        }
    }
}
```

### 13.3 Retry Logic

**Exponential Backoff**:
```php
class DeployRouterServiceJob implements ShouldQueue
{
    use TenantAwareJob;
    
    public $tries = 0; // Disabled - using retryUntil()
    public $timeout = 300;
    public $backoff = [15, 30, 60, 120]; // Progressive delays
    public $maxExceptions = 3;
    
    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }
    
    public function handle(): void
    {
        $this->executeInTenantContext(function () {
            // Job logic here
        });
    }
}
```

### 13.4 Job Types

**Critical Job Categories**:

| Job Type | Queue | Priority | Retry Policy |
|----------|-------|----------|--------------|
| Router Deployment | router-provisioning | High | 15min timeout |
| RADIUS Sync | radius-sync | Medium | 3 retries |
| Billing Generation | billing | Medium | 5 retries |
| Usage Aggregation | metrics | Low | 1 retry |
| Notification Send | notifications | Low | 2 retries |

### 13.5 Monitoring

**Queue Health Checks**:
```php
class QueueMonitor
{
    public function getQueueStats(): array
    {
        $queues = ['router-provisioning', 'radius-sync', 'billing', 'default'];
        $stats = [];
        
        foreach ($queues as $queue) {
            $stats[$queue] = [
                'size' => Queue::size($queue),
                'processing' => $this->getProcessingCount($queue),
                'failed' => $this->getFailedCount($queue),
            ];
        }
        
        return $stats;
    }
}
```

### 13.6 Improvements

**Recommended Enhancements**:

1. **Priority Queues**:
```php
// Laravel Horizon priority configuration
'queues' => [
    'high' => ['router-provisioning', 'radius-sync'],
    'low' => ['metrics', 'notifications'],
],
```

2. **Batch Jobs**:
```php
// Process multiple routers in batch
$batch = Bus::batch([
    new DeployRouterServiceJob($service1, $tenant),
    new DeployRouterServiceJob($service2, $tenant),
    new DeployRouterServiceJob($service3, $tenant),
])->then(function (Batch $batch) {
    // All jobs completed successfully
})->catch(function (Batch $batch, Throwable $e) {
    // One job failed
})->dispatch();
```

3. **Job Scheduling**:
```php
// Scheduled jobs with tenant context
$schedule->job(new RadiusAccountingSyncJob())
    ->everyFiveMinutes()
    ->onQueue('radius-sync');

$schedule->job(new BillingCycleJob())
    ->dailyAt('00:00')
    ->onQueue('billing');
```

---

## 14. API Architecture

### 14.1 API Design

**RESTful Endpoints**:
```php
// Routes structure
Route::prefix('api/v1')->group(function () {
    
    // Tenant-scoped routes
    Route::middleware(['auth:api', 'tenant.context'])->group(function () {
        
        // Routers
        Route::apiResource('routers', RouterController::class);
        Route::post('routers/{router}/services', [RouterServiceController::class, 'store']);
        Route::post('routers/{router}/deploy', [RouterController::class, 'deploy']);
        
        // Subscribers
        Route::apiResource('subscribers', SubscriberController::class);
        Route::post('subscribers/{subscriber}/suspend', [SubscriberController::class, 'suspend']);
        Route::post('subscribers/{subscriber}/activate', [SubscriberController::class, 'activate']);
        
        // Services
        Route::apiResource('services', ServiceController::class);
        Route::get('services/{service}/status', [ServiceController::class, 'status']);
        
        // Billing
        Route::apiResource('invoices', InvoiceController::class);
        Route::apiResource('payments', PaymentController::class);
        
        // Monitoring
        Route::get('metrics/routers', [MetricsController::class, 'routers']);
        Route::get('metrics/sessions', [MetricsController::class, 'sessions']);
        Route::get('metrics/usage', [MetricsController::class, 'usage']);
    });
});
```

### 14.2 Authentication

**JWT Token Flow**:
```php
class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        
        if (!$token = auth()->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'tenant' => auth()->user()->tenant,
        ]);
    }
}
```

### 14.3 Response Format

**Standard API Response**:
```php
class ApiResponse
{
    public static function success($data, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    
    public static function error(string $message, int $code = 400, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => now()->toIso8601String(),
        ], $code);
    }
}
```

### 14.4 Rate Limiting

**Tenant-Aware Throttling**:
```php
// RateLimiter configuration
RateLimiter::for('api', function (Request $request) {
    $tenant = $request->user()->tenant;
    
    return Limit::perMinute(1000)
        ->by($tenant->id . ':' . $request->ip());
});

RateLimiter::for('router-deploy', function (Request $request) {
    return Limit::perMinute(10)
        ->by($request->user()->tenant->id);
});
```

### 14.5 Webhooks

**Event Subscription**:
```php
class WebhookController
{
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $subscription = WebhookSubscription::create([
            'tenant_id' => auth()->user()->tenant_id,
            'url' => $request->url,
            'events' => $request->events,
            'secret' => Str::random(32),
        ]);
        
        return ApiResponse::success($subscription, 'Webhook subscribed');
    }
}

// Webhook dispatch
class WebhookDispatcher
{
    public function dispatch(string $event, array $payload): void
    {
        $subscriptions = WebhookSubscription::whereJsonContains('events', $event)->get();
        
        foreach ($subscriptions as $subscription) {
            DispatchWebhookJob::dispatch($subscription, $event, $payload);
        }
    }
}
```

### 14.6 Improvements

**Recommended Enhancements**:

1. **GraphQL API**:
```php
// Add GraphQL for flexible queries
class GraphQLSchema
{
    public function build(): Schema
    {
        return new Schema([
            'query' => [
                'routers' => RouterQuery::class,
                'subscribers' => SubscriberQuery::class,
            ],
            'mutation' => [
                'createRouter' => CreateRouterMutation::class,
            ],
        ]);
    }
}
```

2. **API Versioning Strategy**:
```php
// URL-based versioning with deprecation headers
class VersionMiddleware
{
    public function handle($request, Closure $next)
    {
        $version = $request->route('version');
        
        if ($version === 'v1' && $this->isDeprecated($request)) {
            $response->header('Deprecation', 'true');
            $response->header('Sunset', '2025-01-01');
        }
        
        return $next($request);
    }
}
```

3. **API Documentation**:
```php
// OpenAPI/Swagger integration
/**
 * @OA\Get(
 *     path="/api/v1/routers",
 *     summary="List all routers",
 *     @OA\Response(
 *         response=200,
 *         description="Successful operation",
 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Router"))
 *     )
 * )
 */
public function index(): JsonResponse
{
    return ApiResponse::success(Router::all());
}
```

---

## 15. Operational Features

### 15.1 Customer Self-Service Portal

**Vue 3 SPA Features**:
- Real-time session monitoring
- Data usage charts
- Payment history
- Plan upgrades/downgrades
- Support ticket creation

### 15.2 Voucher System

**Digital Voucher Generation**:
```php
class VoucherService
{
    public function generateVouchers(int $count, Plan $plan): Collection
    {
        $vouchers = collect();
        
        for ($i = 0; $i < $count; $i++) {
            $vouchers->push(Voucher::create([
                'code' => $this->generateCode(),
                'plan_id' => $plan->id,
                'status' => 'unused',
                'expires_at' => now()->addDays(30),
            ]));
        }
        
        return $vouchers;
    }
    
    public function redeemVoucher(string $code, User $user): bool
    {
        $voucher = Voucher::where('code', $code)->unused()->first();
        
        if (!$voucher || $voucher->isExpired()) {
            throw new VoucherInvalidException();
        }
        
        // Apply plan to user
        $this->subscriptionManager->createSubscription($user, $voucher->plan);
        
        $voucher->update([
            'status' => 'redeemed',
            'redeemed_by' => $user->id,
            'redeemed_at' => now(),
        ]);
        
        return true;
    }
}
```

### 15.3 Multi-Language Support

**Laravel Localization**:
```php
// Language files per tenant
/resources/lang/en/messages.php
/resources/lang/sw/messages.php  // Swahili
/resources/lang/fr/messages.php  // French

// Usage in Vue components
$t('messages.welcome')
$t('messages.usage_exceeded', ['quota' => '10GB'])
```

### 15.4 WhatsApp Integration

**Two-Way Bot**:
```php
class WhatsAppBotService
{
    public function handleIncomingMessage(string $from, string $message): void
    {
        $user = User::where('phone', $from)->first();
        
        if (!$user) {
            $this->sendMessage($from, "Please register first at: {$this->registrationUrl}");
            return;
        }
        
        switch (strtolower($message)) {
            case 'balance':
                $this->sendBalance($user);
                break;
            case 'usage':
                $this->sendUsage($user);
                break;
            case 'pay':
                $this->sendPaymentLink($user);
                break;
            default:
                $this->sendHelp($from);
        }
    }
}
```

### 15.5 Support Ticket System

**Ticketing Integration**:
```php
class TicketService
{
    public function createTicket(User $user, string $subject, string $body): Ticket
    {
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'subject' => $subject,
            'body' => $body,
            'status' => 'open',
            'priority' => $this->calculatePriority($body),
        ]);
        
        // Notify support team
        event(new TicketCreated($ticket));
        
        return $ticket;
    }
}
```

### 15.6 Improvements

**Recommended Enhancements**:

1. **AI-Powered Support**:
```php
// Chatbot for common queries
class AISupportService
{
    public function handleQuery(string $query, User $user): string
    {
        $context = $this->getUserContext($user);
        $response = $this->openAI->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $query],
            ],
        ]);
        
        return $response->choices[0]->message->content;
    }
}
```

2. **Network Map Visualization**:
```php
// Interactive topology map
public function generateNetworkMap(Tenant $tenant): array
{
    $routers = $tenant->routers;
    $connections = $this->getRouterConnections($routers);
    
    return [
        'nodes' => $this->formatNodes($routers),
        'edges' => $this->formatEdges($connections),
        'status' => $this->getOverallStatus($routers),
    ];
}
```

3. **Service Health Dashboard**:
```php
// Real-time service status
public function getServiceHealth(Tenant $tenant): array
{
    return [
        'radius' => $this->checkRadiusHealth($tenant),
        'routers' => $this->checkRouterHealth($tenant->routers),
        'payment_gateway' => $this->checkPaymentGateway($tenant),
        'vpn' => $this->checkVpnTunnels($tenant),
    ];
}
```

---

## 16. Improvements Feasibility Analysis

This section provides a detailed feasibility assessment for six key improvements to the platform. **For complete implementation details, see: `IMPROVEMENTS_FEASIBILITY_ANALYSIS.md`**

### 16.1 Executive Summary

| Improvement | Feasibility | Complexity | Risk Level | Estimated Effort |
|-------------|-------------|------------|------------|------------------|
| Dynamic VLAN Assignment via RADIUS | **HIGH** | Low | Low | 2-3 weeks |
| CoA (Change of Authorization) | **HIGH** | Low | Low | 1-2 weeks |
| Canary Deployments & Drift Detection | **MEDIUM-HIGH** | Medium | Medium | 3-4 weeks |
| AI-Powered Predictive Maintenance | **MEDIUM** | High | Medium | 4-6 weeks |
| Multi-Vendor Router Driver Abstraction | **HIGH** | Medium | Low | 3-4 weeks |
| Zero Trust Networking with mTLS | **MEDIUM** | High | High | 6-8 weeks |

### 16.2 Quick Wins (Immediate Implementation)

**1. CoA (Change of Authorization)**
- **Feasibility**: HIGH - `dapphp/radius` library already includes CoA-Disconnect example
- **Current State**: CoA port (3799) already configured in `HotspotRadiusService`
- **Implementation**: Create `CoAService` class with bandwidth change and session disconnect
- **Effort**: 1-2 weeks
- **Value**: Real-time session control, immediate bandwidth changes without disconnect

**2. Dynamic VLAN Assignment via RADIUS**
- **Feasibility**: HIGH - FreeRADIUS 3.x supports Tunnel-Private-Group-ID attribute
- **Current State**: `HotspotRadiusService::setReplyAttributes()` already manages radreply
- **Implementation**: Extend with VLAN attributes (Tunnel-Type, Tunnel-Medium-Type, Tunnel-Private-Group-Id)
- **Effort**: 2-3 weeks
- **Value**: Automated network segmentation, flexible user placement

**3. Multi-Vendor Router Driver Abstraction**
- **Feasibility**: HIGH - `BaseMikroTikService` pattern already established
- **Current State**: ZeroConfig generators use consistent patterns
- **Implementation**: Create `RouterDriverInterface`, refactor existing drivers
- **Effort**: 3-4 weeks
- **Value**: Support Cisco, Ubiquiti beyond MikroTik

### 16.3 Medium-Term Initiatives (1-2 Months)

**4. Canary Deployments & Configuration Drift Detection**
- **Feasibility**: MEDIUM-HIGH - `Bus::batch()` exists, Redis locking in place
- **Current State**: `DeployRouterServiceJob` has retry logic and progress tracking
- **Implementation**: Create `CanaryDeploymentService`, add configuration snapshot storage
- **Effort**: 3-4 weeks
- **Value**: Safer deployments, automated compliance checking

**5. AI-Powered Predictive Maintenance**
- **Feasibility**: MEDIUM - Prometheus/Grafana in place, metrics collection exists
- **Current State**: `MetricsService` tracks TPS, database metrics, router health
- **Implementation Options**:
  - **Option A**: OpenAI API integration for quick implementation ($50-200/month)
  - **Option B**: Self-hosted Python microservice with scikit-learn ($200-500/month)
- **AI Integration Architecture**:
```
Prometheus Metrics ──▶ Feature Engineering ──▶ Model Inference ──▶ Predictions
                              │
                              ▼
                    ┌──────────────────┐
                    │   OpenAI API     │ (Natural language explanations)
                    │   GPT-4          │
                    └──────────────────┘
```
- **Effort**: 4-6 weeks
- **Value**: Predict router failures 24-48h in advance, anomaly detection

### 16.4 Long-Term Initiatives (2-3 Months)

**6. Zero Trust Networking with mTLS**
- **Feasibility**: MEDIUM - Complex but achievable
- **Current State**: WireGuard VPN exists for management, HTTP internal communication
- **Implementation Requirements**:
  - Certificate Authority infrastructure
  - Service mesh (Linkerd recommended for simplicity)
  - Router certificate management
  - Client certificate verification middleware
- **Simplified Approach**:
  1. Start with service mesh for internal mTLS
  2. Keep WireGuard for router management (already encrypted)
  3. Add API-SSL for router-to-backend with client certs
- **Effort**: 6-8 weeks
- **Value**: Eliminate implicit trust, defense in depth

### 16.5 AI Implementation Details

**External AI Provider Integration**:

```php
class AIServiceProvider
{
    /**
     * Generate natural language explanation of anomalies
     */
    public function explainAnomaly(array $metrics, array $historicalContext): string
    {
        $cacheKey = "ai_explanation:" . md5(serialize($metrics));
        
        return Cache::remember($cacheKey, 300, function () use ($metrics, $historicalContext) {
            $prompt = $this->buildAnomalyPrompt($metrics, $historicalContext);
            
            $response = $this->openai->chat()->create([
                'model' => 'gpt-4-turbo-preview',
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);
            
            return $response->choices[0]->message->content;
        });
    }
    
    /**
     * Predict maintenance needs with structured JSON output
     */
    public function predictMaintenance(Router $router): MaintenancePrediction
    {
        $metrics = $this->gatherMetrics($router);
        $prompt = $this->buildMaintenancePrompt($router, $metrics);
        
        $response = $this->openai->chat()->create([
            'model' => 'gpt-4-turbo-preview',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a predictive maintenance AI...'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);
        
        return MaintenancePrediction::fromJson($response->choices[0]->message->content);
    }
}
```

**AI Cost Optimization**:
- Response caching (5-10 minute TTL) for similar patterns
- Usage monitoring and quotas per tenant
- Confidence thresholds to reduce API calls
- Local model fallback for common predictions

**Predictive Maintenance Use Cases**:
1. **Router Failure Prediction**: Predict hardware failures 24-48h in advance
2. **Network Congestion Forecasting**: Predict peak usage periods
3. **Anomaly Detection**: Detect unusual traffic patterns
4. **Optimal Maintenance Windows**: Suggest low-impact maintenance times

### 16.6 Implementation Roadmap

**Month 1**:
- Week 1-2: CoA Implementation
- Week 2-3: Dynamic VLAN Assignment
- Week 3-4: Multi-Vendor Driver Foundation

**Month 2**:
- Week 1-2: Multi-Vendor Implementation (Cisco)
- Week 3: Canary Deployment System
- Week 4: Configuration Drift Detection

**Month 3**:
- Week 1-2: AI/ML Data Pipeline
- Week 3: AI Anomaly Detection
- Week 4: AI Predictive Maintenance

**Month 4**:
- Week 1-2: Zero Trust Planning
- Week 3-4: Service-to-Service mTLS

### 16.7 Resource Requirements

**Infrastructure Costs**:
| Improvement | Additional Infrastructure | Estimated Monthly Cost |
|-------------|---------------------------|------------------------|
| CoA | None | $0 |
| Dynamic VLAN | None | $0 |
| Canary Deployments | None | $0 |
| AI (OpenAI API) | None | $50-200 (usage-based) |
| Multi-Vendor | Test lab devices | $1,000-2,000 (one-time) |
| Zero Trust mTLS | Certificate management | $0-50 |

**Personnel Requirements**:
| Phase | Duration | Skills Required | FTE |
|-------|----------|-----------------|-----|
| CoA + VLAN | 3 weeks | PHP/Laravel, RADIUS | 1 |
| Multi-Vendor | 4 weeks | PHP, Network protocols | 1 |
| Canary + AI | 6 weeks | PHP, Python, DevOps | 1-2 |
| Zero Trust | 8 weeks | Security, DevOps, Networking | 2 |

### 16.8 Risk Assessment Summary

| Improvement | Primary Risks | Mitigation |
|-------------|---------------|------------|
| CoA | UDP packet loss, session ID mismatch | Retry logic, pre-validation |
| Dynamic VLAN | VLAN mismatch, firmware incompatibility | Pre-deployment validation |
| Canary | Partial deployment state, false positives | Atomic per-router, whitelisting |
| AI | False positives, API latency, cost | Confidence thresholds, caching |
| Multi-Vendor | Feature parity gaps, testing complexity | Document limitations, device lab |
| Zero Trust | Certificate expiry, complexity | Auto-renewal, gradual rollout |

### 16.9 Recommendations

1. **Start with CoA**: Highest ROI (1-2 weeks), enables real-time session control
2. **Parallel VLAN + Multi-Vendor**: Can be developed simultaneously
3. **AI as Experimental**: Start with OpenAI for quick wins, evaluate self-hosted for scale
4. **Zero Trust as Strategic**: Plan for long-term security transformation
5. **Maintain Focus**: Avoid implementing all features simultaneously

---

## Summary

This documentation provides a comprehensive technical overview of the Traidnet/WifiCore SaaS platform. Key architectural strengths include:

1. **True Multi-Tenancy**: Schema-per-tenant isolation with VPN segmentation
2. **Flexible Service Types**: PPPoE, Hotspot, and Hybrid modes with VLAN support
3. **Low-End Device Optimization**: Tier-based configurations for resource-constrained routers
4. **RADIUS-Centric Authentication**: Centralized AAA with CoA support
5. **Modern Deployment Pipeline**: Queue-based provisioning with rollback capability
6. **Comprehensive Observability**: Real-time metrics, logging, and alerting
7. **Production-Ready Security**: Firewall hardening, SSH key rotation, and audit logging

For production deployments, ensure proper resource allocation, monitoring coverage, and regular security audits.

---

**Document Version**: 1.0  
**Last Updated**: April 4, 2026  
**Maintained By**: WifiCore Engineering Team
