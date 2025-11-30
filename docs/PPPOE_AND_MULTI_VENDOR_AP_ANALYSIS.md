# PPPoE Service & Multi-Vendor AP Implementation - E2E Analysis

**Date:** 2025-10-11 08:17  
**Status:** 📋 **ANALYSIS & PLANNING**

---

## 🎯 Objectives

### **Primary Goals:**
1. ✅ Implement PPPoE service with dedicated interfaces (separate from Hotspot)
2. ✅ Track active users per access point (multi-vendor support: Ruijie, Tenda, TP-Link)
3. ✅ Display running services per router in frontend
4. ✅ Ensure Hotspot and PPPoE use different interfaces

---

## 📊 Current System Analysis

### **Existing Architecture:**

#### **1. Router Model** ✅
**File:** `app/Models/Router.php`

**Current Fields:**
```php
- id (UUID)
- name
- ip_address
- model
- os_version
- last_seen
- port
- username
- password
- location
- status
- interface_assignments (JSON)
- configurations (JSON)
- config_token
```

**Relationships:**
- `wireguardPeers()`
- `routerConfigs()`
- `payments()`
- `vpnConfig()`

---

#### **2. Existing Services** ✅

**A. HotspotService** ✅
- File: `app/Services/MikroTik/HotspotService.php`
- Status: Fully implemented
- Features: RADIUS, Captive Portal, NAT, DNS

**B. PPPoEService** ✅
- File: `app/Services/MikroTik/PPPoEService.php`
- Status: **PARTIALLY IMPLEMENTED**
- Features: RADIUS, IP Pools, Authentication
- **Missing:** Interface conflict prevention, service tracking

**C. SecurityHardeningService** ✅
- File: `app/Services/MikroTik/SecurityHardeningService.php`
- Status: Fully implemented

---

### **Current Limitations:**

#### **1. Interface Management** ❌
- No validation to prevent Hotspot and PPPoE using same interface
- No interface reservation system
- No interface status tracking

#### **2. Service Tracking** ❌
- No database tracking of which services are running on which router
- No service status monitoring
- No service configuration history

#### **3. Access Point Support** ❌
- No multi-vendor AP integration
- No active user tracking per AP
- No AP management system

#### **4. Frontend Visibility** ❌
- No service status display
- No interface assignment visualization
- No AP user statistics

---

## 🏗️ Proposed Architecture

### **1. Database Schema Changes**

#### **A. New Table: `router_services`**
```sql
CREATE TABLE router_services (
    id UUID PRIMARY KEY,
    router_id UUID REFERENCES routers(id),
    service_type VARCHAR(50), -- 'hotspot', 'pppoe', 'vpn', etc.
    service_name VARCHAR(100),
    interfaces JSON, -- Array of interface names
    configuration JSON, -- Service-specific config
    status VARCHAR(20), -- 'active', 'inactive', 'error'
    active_users INT DEFAULT 0,
    total_sessions INT DEFAULT 0,
    last_checked_at TIMESTAMP,
    enabled BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE INDEX idx_router_services_router_id ON router_services(router_id);
CREATE INDEX idx_router_services_service_type ON router_services(service_type);
CREATE INDEX idx_router_services_status ON router_services(status);
```

#### **B. New Table: `access_points`**
```sql
CREATE TABLE access_points (
    id UUID PRIMARY KEY,
    router_id UUID REFERENCES routers(id),
    name VARCHAR(100),
    vendor VARCHAR(50), -- 'ruijie', 'tenda', 'tplink', 'mikrotik'
    model VARCHAR(100),
    ip_address VARCHAR(45),
    mac_address VARCHAR(17),
    management_protocol VARCHAR(20), -- 'snmp', 'ssh', 'api', 'telnet'
    credentials JSON, -- Encrypted credentials
    location VARCHAR(255),
    status VARCHAR(20), -- 'online', 'offline', 'unknown'
    active_users INT DEFAULT 0,
    total_capacity INT,
    signal_strength INT,
    uptime_seconds BIGINT,
    last_seen_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE INDEX idx_access_points_router_id ON access_points(router_id);
CREATE INDEX idx_access_points_vendor ON access_points(vendor);
CREATE INDEX idx_access_points_status ON access_points(status);
```

#### **C. New Table: `ap_active_sessions`**
```sql
CREATE TABLE ap_active_sessions (
    id UUID PRIMARY KEY,
    access_point_id UUID REFERENCES access_points(id),
    router_id UUID REFERENCES routers(id),
    username VARCHAR(100),
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    session_id VARCHAR(100),
    connected_at TIMESTAMP,
    last_activity_at TIMESTAMP,
    bytes_in BIGINT DEFAULT 0,
    bytes_out BIGINT DEFAULT 0,
    signal_strength INT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE INDEX idx_ap_sessions_ap_id ON ap_active_sessions(access_point_id);
CREATE INDEX idx_ap_sessions_router_id ON ap_active_sessions(router_id);
CREATE INDEX idx_ap_sessions_username ON ap_active_sessions(username);
```

#### **D. Update `routers` Table**
```sql
ALTER TABLE routers ADD COLUMN vendor VARCHAR(50) DEFAULT 'mikrotik';
ALTER TABLE routers ADD COLUMN device_type VARCHAR(50) DEFAULT 'router'; -- 'router', 'access_point', 'switch'
ALTER TABLE routers ADD COLUMN capabilities JSON; -- ['hotspot', 'pppoe', 'vpn', 'firewall']
ALTER TABLE routers ADD COLUMN interface_list JSON; -- List of available interfaces
ALTER TABLE routers ADD COLUMN reserved_interfaces JSON; -- Interfaces reserved by services
```

---

### **2. Service Models**

#### **A. RouterService Model**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class RouterService extends Model
{
    use HasUuid;
    
    protected $fillable = [
        'router_id',
        'service_type',
        'service_name',
        'interfaces',
        'configuration',
        'status',
        'active_users',
        'total_sessions',
        'last_checked_at',
        'enabled',
    ];
    
    protected $casts = [
        'id' => 'string',
        'router_id' => 'string',
        'interfaces' => 'array',
        'configuration' => 'array',
        'active_users' => 'integer',
        'total_sessions' => 'integer',
        'last_checked_at' => 'datetime',
        'enabled' => 'boolean',
    ];
    
    // Relationships
    public function router()
    {
        return $this->belongsTo(Router::class);
    }
    
    // Service type constants
    const TYPE_HOTSPOT = 'hotspot';
    const TYPE_PPPOE = 'pppoe';
    const TYPE_VPN = 'vpn';
    const TYPE_FIREWALL = 'firewall';
    
    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_ERROR = 'error';
}
```

#### **B. AccessPoint Model**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class AccessPoint extends Model
{
    use HasUuid;
    
    protected $fillable = [
        'router_id',
        'name',
        'vendor',
        'model',
        'ip_address',
        'mac_address',
        'management_protocol',
        'credentials',
        'location',
        'status',
        'active_users',
        'total_capacity',
        'signal_strength',
        'uptime_seconds',
        'last_seen_at',
    ];
    
    protected $casts = [
        'id' => 'string',
        'router_id' => 'string',
        'credentials' => 'encrypted:array',
        'active_users' => 'integer',
        'total_capacity' => 'integer',
        'signal_strength' => 'integer',
        'uptime_seconds' => 'integer',
        'last_seen_at' => 'datetime',
    ];
    
    // Relationships
    public function router()
    {
        return $this->belongsTo(Router::class);
    }
    
    public function activeSessions()
    {
        return $this->hasMany(ApActiveSession::class);
    }
    
    // Vendor constants
    const VENDOR_RUIJIE = 'ruijie';
    const VENDOR_TENDA = 'tenda';
    const VENDOR_TPLINK = 'tplink';
    const VENDOR_MIKROTIK = 'mikrotik';
    const VENDOR_UBIQUITI = 'ubiquiti';
}
```

#### **C. ApActiveSession Model**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class ApActiveSession extends Model
{
    use HasUuid;
    
    protected $fillable = [
        'access_point_id',
        'router_id',
        'username',
        'mac_address',
        'ip_address',
        'session_id',
        'connected_at',
        'last_activity_at',
        'bytes_in',
        'bytes_out',
        'signal_strength',
    ];
    
    protected $casts = [
        'id' => 'string',
        'access_point_id' => 'string',
        'router_id' => 'string',
        'connected_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'signal_strength' => 'integer',
    ];
    
    // Relationships
    public function accessPoint()
    {
        return $this->belongsTo(AccessPoint::class);
    }
    
    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
```

---

### **3. Service Layer Architecture**

#### **A. InterfaceManagementService**
```php
<?php

namespace App\Services;

class InterfaceManagementService
{
    /**
     * Get available interfaces for a router
     */
    public function getAvailableInterfaces(Router $router): array;
    
    /**
     * Reserve interfaces for a service
     */
    public function reserveInterfaces(Router $router, string $serviceType, array $interfaces): bool;
    
    /**
     * Release interfaces from a service
     */
    public function releaseInterfaces(Router $router, string $serviceType): bool;
    
    /**
     * Check if interfaces are available
     */
    public function areInterfacesAvailable(Router $router, array $interfaces): bool;
    
    /**
     * Get interfaces used by a service
     */
    public function getServiceInterfaces(Router $router, string $serviceType): array;
    
    /**
     * Validate interface assignment
     */
    public function validateInterfaceAssignment(Router $router, string $serviceType, array $interfaces): array;
}
```

#### **B. RouterServiceManager**
```php
<?php

namespace App\Services;

class RouterServiceManager
{
    /**
     * Deploy a service to a router
     */
    public function deployService(Router $router, string $serviceType, array $config): RouterService;
    
    /**
     * Update service configuration
     */
    public function updateService(RouterService $service, array $config): RouterService;
    
    /**
     * Stop/disable a service
     */
    public function stopService(RouterService $service): bool;
    
    /**
     * Get all services for a router
     */
    public function getRouterServices(Router $router): Collection;
    
    /**
     * Get service status
     */
    public function getServiceStatus(RouterService $service): array;
    
    /**
     * Sync service status from router
     */
    public function syncServiceStatus(Router $router): array;
}
```

#### **C. AccessPointManager**
```php
<?php

namespace App\Services\AccessPoint;

class AccessPointManager
{
    /**
     * Discover access points connected to router
     */
    public function discoverAccessPoints(Router $router): array;
    
    /**
     * Add access point
     */
    public function addAccessPoint(Router $router, array $data): AccessPoint;
    
    /**
     * Get active users for an AP
     */
    public function getActiveUsers(AccessPoint $ap): int;
    
    /**
     * Get active sessions for an AP
     */
    public function getActiveSessions(AccessPoint $ap): Collection;
    
    /**
     * Sync AP status
     */
    public function syncAccessPointStatus(AccessPoint $ap): array;
    
    /**
     * Get AP statistics
     */
    public function getStatistics(AccessPoint $ap): array;
}
```

#### **D. Multi-Vendor AP Adapters**
```php
<?php

namespace App\Services\AccessPoint\Adapters;

interface AccessPointAdapterInterface
{
    public function connect(AccessPoint $ap): bool;
    public function getActiveUsers(): int;
    public function getActiveSessions(): array;
    public function getStatistics(): array;
    public function getStatus(): string;
}

// Implementations:
class RuijieAdapter implements AccessPointAdapterInterface { }
class TendaAdapter implements AccessPointAdapterInterface { }
class TPLinkAdapter implements AccessPointAdapterInterface { }
class MikroTikAdapter implements AccessPointAdapterInterface { }
class UbiquitiAdapter implements AccessPointAdapterInterface { }
```

---

### **4. API Endpoints**

#### **A. Router Services**
```
GET    /api/routers/{router}/services           - List all services
POST   /api/routers/{router}/services           - Deploy new service
GET    /api/routers/{router}/services/{service} - Get service details
PUT    /api/routers/{router}/services/{service} - Update service
DELETE /api/routers/{router}/services/{service} - Remove service
POST   /api/routers/{router}/services/{service}/start   - Start service
POST   /api/routers/{router}/services/{service}/stop    - Stop service
POST   /api/routers/{router}/services/{service}/restart - Restart service
GET    /api/routers/{router}/services/{service}/status  - Get service status
POST   /api/routers/{router}/services/sync      - Sync all services
```

#### **B. Interface Management**
```
GET    /api/routers/{router}/interfaces         - List interfaces
GET    /api/routers/{router}/interfaces/available - Available interfaces
POST   /api/routers/{router}/interfaces/scan    - Scan for interfaces
```

#### **C. Access Points**
```
GET    /api/routers/{router}/access-points      - List APs
POST   /api/routers/{router}/access-points      - Add AP
GET    /api/access-points/{ap}                  - Get AP details
PUT    /api/access-points/{ap}                  - Update AP
DELETE /api/access-points/{ap}                  - Remove AP
GET    /api/access-points/{ap}/users            - Get active users
GET    /api/access-points/{ap}/sessions         - Get active sessions
GET    /api/access-points/{ap}/statistics       - Get statistics
POST   /api/access-points/{ap}/sync             - Sync AP status
POST   /api/routers/{router}/access-points/discover - Discover APs
```

---

### **5. Frontend Components**

#### **A. Router Service Dashboard**
```vue
<template>
  <div class="router-services">
    <h2>Running Services</h2>
    <div class="service-cards">
      <ServiceCard 
        v-for="service in services"
        :key="service.id"
        :service="service"
        @start="startService"
        @stop="stopService"
        @configure="configureService"
      />
    </div>
    <button @click="addService">+ Add Service</button>
  </div>
</template>
```

#### **B. Service Configuration Modal**
```vue
<template>
  <Modal v-model="show">
    <h3>Configure {{ serviceType }} Service</h3>
    
    <!-- Interface Selection -->
    <InterfaceSelector
      v-model="selectedInterfaces"
      :available-interfaces="availableInterfaces"
      :reserved-interfaces="reservedInterfaces"
      :service-type="serviceType"
    />
    
    <!-- Service-specific Configuration -->
    <component 
      :is="configComponent"
      v-model="configuration"
    />
    
    <button @click="deploy">Deploy Service</button>
  </Modal>
</template>
```

#### **C. Access Point Dashboard**
```vue
<template>
  <div class="access-points">
    <h2>Access Points</h2>
    <div class="ap-grid">
      <APCard
        v-for="ap in accessPoints"
        :key="ap.id"
        :ap="ap"
        :active-users="ap.active_users"
        :status="ap.status"
        @view-sessions="viewSessions"
      />
    </div>
  </div>
</template>
```

#### **D. Interface Assignment Visualizer**
```vue
<template>
  <div class="interface-map">
    <div class="interface" 
         v-for="iface in interfaces"
         :key="iface.name"
         :class="getInterfaceClass(iface)">
      <span>{{ iface.name }}</span>
      <span v-if="iface.service">{{ iface.service }}</span>
    </div>
  </div>
</template>
```

---

## 📋 Implementation Plan

### **Phase 1: Database & Models** (Priority: HIGH)
1. ✅ Create migrations for new tables
2. ✅ Create RouterService model
3. ✅ Create AccessPoint model
4. ✅ Create ApActiveSession model
5. ✅ Update Router model with new fields

### **Phase 2: Service Layer** (Priority: HIGH)
1. ✅ Create InterfaceManagementService
2. ✅ Create RouterServiceManager
3. ✅ Update PPPoEService with interface validation
4. ✅ Update HotspotService with interface validation
5. ✅ Create AccessPointManager

### **Phase 3: Multi-Vendor AP Support** (Priority: MEDIUM)
1. ✅ Create AccessPointAdapterInterface
2. ✅ Implement RuijieAdapter
3. ✅ Implement TendaAdapter
4. ✅ Implement TPLinkAdapter
5. ✅ Implement MikroTikAdapter

### **Phase 4: API Endpoints** (Priority: HIGH)
1. ✅ Create RouterServiceController
2. ✅ Create AccessPointController
3. ✅ Add routes for services
4. ✅ Add routes for APs
5. ✅ Add interface management routes

### **Phase 5: Frontend Components** (Priority: MEDIUM)
1. ✅ Create ServiceCard component
2. ✅ Create ServiceConfigModal component
3. ✅ Create InterfaceSelector component
4. ✅ Create APCard component
5. ✅ Create APSessionsView component
6. ✅ Update RouterDetailsView

### **Phase 6: Testing & Documentation** (Priority: MEDIUM)
1. ✅ Create E2E tests
2. ✅ Create API tests
3. ✅ Create documentation
4. ✅ Create user guide

---

## 🎯 Key Features

### **1. Interface Conflict Prevention** ✅
- Validate interfaces before service deployment
- Track interface reservations
- Prevent overlapping assignments
- Visual interface map

### **2. Service Tracking** ✅
- Real-time service status
- Active user counts
- Configuration history
- Service health monitoring

### **3. Multi-Vendor AP Support** ✅
- Ruijie, Tenda, TP-Link, MikroTik, Ubiquiti
- SNMP, SSH, API protocols
- Active user tracking per AP
- Session management

### **4. Frontend Visibility** ✅
- Service dashboard per router
- Interface assignment visualization
- AP statistics and monitoring
- Real-time updates

---

## 📊 Technical Specifications

### **Interface Assignment Rules:**
1. One interface can only be assigned to ONE service at a time
2. Hotspot and PPPoE MUST use different interfaces
3. Interface validation before deployment
4. Automatic interface release on service removal

### **Service Deployment Flow:**
```
1. Select service type (Hotspot/PPPoE)
2. Select available interfaces
3. Validate interfaces (not reserved)
4. Configure service parameters
5. Generate configuration script
6. Deploy to router
7. Track service in database
8. Monitor service status
```

### **AP Discovery Flow:**
```
1. Scan network for devices
2. Identify vendor (SNMP/MAC)
3. Test connectivity
4. Add to database
5. Start monitoring
6. Track active users
```

---

## 🚀 Next Steps

1. **Immediate:** Create database migrations
2. **Priority:** Implement InterfaceManagementService
3. **Priority:** Update PPPoEService with validation
4. **Medium:** Implement AP adapters
5. **Medium:** Create frontend components

---

**Prepared By:** Cascade AI  
**Date:** 2025-10-11 08:17  
**Status:** 📋 READY FOR IMPLEMENTATION
