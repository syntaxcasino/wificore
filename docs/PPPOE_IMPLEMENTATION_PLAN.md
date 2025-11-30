# PPPoE & Multi-Vendor AP - Detailed Implementation Plan

**Date:** 2025-10-11 08:20  
**Status:** 📋 **IMPLEMENTATION ROADMAP**

---

## 🎯 Implementation Strategy

### **Approach:** Incremental, Test-Driven Development
### **Timeline:** 5 Phases
### **Priority:** High (Production Feature)

---

## 📅 Phase Breakdown

### **Phase 1: Foundation - Database & Models** (Day 1-2)
**Priority:** 🔴 **CRITICAL**  
**Estimated Time:** 4-6 hours

#### **Tasks:**

**1.1 Create Database Migrations** ✅
```bash
php artisan make:migration create_router_services_table
php artisan make:migration create_access_points_table
php artisan make:migration create_ap_active_sessions_table
php artisan make:migration add_service_fields_to_routers_table
```

**Files to Create:**
- `database/migrations/YYYY_MM_DD_create_router_services_table.php`
- `database/migrations/YYYY_MM_DD_create_access_points_table.php`
- `database/migrations/YYYY_MM_DD_create_ap_active_sessions_table.php`
- `database/migrations/YYYY_MM_DD_add_service_fields_to_routers_table.php`

**1.2 Create Models** ✅
```bash
php artisan make:model RouterService
php artisan make:model AccessPoint
php artisan make:model ApActiveSession
```

**Files to Create:**
- `app/Models/RouterService.php`
- `app/Models/AccessPoint.php`
- `app/Models/ApActiveSession.php`

**1.3 Update Router Model** ✅
- Add relationships
- Add service methods
- Add interface management methods

**1.4 Run Migrations** ✅
```bash
php artisan migrate
```

**Deliverables:**
- ✅ 3 new tables created
- ✅ 3 new models created
- ✅ Router model updated
- ✅ Database schema documented

---

### **Phase 2: Service Layer - Core Logic** (Day 2-3)
**Priority:** 🔴 **CRITICAL**  
**Estimated Time:** 6-8 hours

#### **Tasks:**

**2.1 Create InterfaceManagementService** ✅
```bash
php artisan make:service InterfaceManagementService
```

**Methods to Implement:**
```php
- getAvailableInterfaces(Router $router): array
- reserveInterfaces(Router $router, string $serviceType, array $interfaces): bool
- releaseInterfaces(Router $router, string $serviceType): bool
- areInterfacesAvailable(Router $router, array $interfaces): bool
- getServiceInterfaces(Router $router, string $serviceType): array
- validateInterfaceAssignment(Router $router, string $serviceType, array $interfaces): array
- scanRouterInterfaces(Router $router): array
```

**2.2 Create RouterServiceManager** ✅
```bash
php artisan make:service RouterServiceManager
```

**Methods to Implement:**
```php
- deployService(Router $router, string $serviceType, array $config): RouterService
- updateService(RouterService $service, array $config): RouterService
- stopService(RouterService $service): bool
- startService(RouterService $service): bool
- restartService(RouterService $service): bool
- getRouterServices(Router $router): Collection
- getServiceStatus(RouterService $service): array
- syncServiceStatus(Router $router): array
- removeService(RouterService $service): bool
```

**2.3 Update PPPoEService** ✅
- Add interface validation
- Add conflict detection
- Add service registration
- Update generateConfig() method

**2.4 Update HotspotService** ✅
- Add interface validation
- Add conflict detection
- Add service registration
- Update generateConfig() method

**2.5 Create ServiceDeploymentJob** ✅
```bash
php artisan make:job DeployRouterService
```

**Deliverables:**
- ✅ InterfaceManagementService implemented
- ✅ RouterServiceManager implemented
- ✅ PPPoEService updated
- ✅ HotspotService updated
- ✅ Service deployment job created

---

### **Phase 3: Multi-Vendor AP Support** (Day 3-4)
**Priority:** 🟡 **HIGH**  
**Estimated Time:** 8-10 hours

#### **Tasks:**

**3.1 Create AccessPointManager** ✅
```bash
php artisan make:service AccessPoint/AccessPointManager
```

**Methods:**
```php
- discoverAccessPoints(Router $router): array
- addAccessPoint(Router $router, array $data): AccessPoint
- updateAccessPoint(AccessPoint $ap, array $data): AccessPoint
- removeAccessPoint(AccessPoint $ap): bool
- getActiveUsers(AccessPoint $ap): int
- getActiveSessions(AccessPoint $ap): Collection
- syncAccessPointStatus(AccessPoint $ap): array
- getStatistics(AccessPoint $ap): array
```

**3.2 Create AP Adapter Interface** ✅
```php
// app/Services/AccessPoint/Adapters/AccessPointAdapterInterface.php
interface AccessPointAdapterInterface
{
    public function connect(AccessPoint $ap): bool;
    public function disconnect(): void;
    public function getActiveUsers(): int;
    public function getActiveSessions(): array;
    public function getStatistics(): array;
    public function getStatus(): string;
    public function getSystemInfo(): array;
}
```

**3.3 Implement Vendor Adapters** ✅

**A. RuijieAdapter**
```php
// app/Services/AccessPoint/Adapters/RuijieAdapter.php
class RuijieAdapter implements AccessPointAdapterInterface
{
    // SNMP OIDs for Ruijie
    private const OID_ACTIVE_USERS = '1.3.6.1.4.1.4881.1.1.10.2.68.1.1.1.1.2';
    private const OID_SYSTEM_INFO = '1.3.6.1.2.1.1.1.0';
    
    // Implementation using SNMP
}
```

**B. TendaAdapter**
```php
// app/Services/AccessPoint/Adapters/TendaAdapter.php
class TendaAdapter implements AccessPointAdapterInterface
{
    // HTTP API implementation for Tenda
    private const API_ENDPOINT = '/goform/getStatus';
    
    // Implementation using HTTP API
}
```

**C. TPLinkAdapter**
```php
// app/Services/AccessPoint/Adapters/TPLinkAdapter.php
class TPLinkAdapter implements AccessPointAdapterInterface
{
    // SNMP/HTTP implementation for TP-Link
    
    // Implementation
}
```

**D. MikroTikAdapter**
```php
// app/Services/AccessPoint/Adapters/MikroTikAdapter.php
class MikroTikAdapter implements AccessPointAdapterInterface
{
    // RouterOS API implementation
    
    // Implementation using RouterOS API
}
```

**3.4 Create Adapter Factory** ✅
```php
// app/Services/AccessPoint/AdapterFactory.php
class AdapterFactory
{
    public static function create(AccessPoint $ap): AccessPointAdapterInterface
    {
        return match($ap->vendor) {
            'ruijie' => new RuijieAdapter($ap),
            'tenda' => new TendaAdapter($ap),
            'tplink' => new TPLinkAdapter($ap),
            'mikrotik' => new MikroTikAdapter($ap),
            default => throw new \Exception("Unsupported vendor: {$ap->vendor}")
        };
    }
}
```

**3.5 Create AP Sync Job** ✅
```bash
php artisan make:job SyncAccessPointStatus
```

**Deliverables:**
- ✅ AccessPointManager implemented
- ✅ 4 vendor adapters implemented
- ✅ Adapter factory created
- ✅ AP sync job created
- ✅ Multi-vendor support working

---

### **Phase 4: API Endpoints & Controllers** (Day 4-5)
**Priority:** 🔴 **CRITICAL**  
**Estimated Time:** 6-8 hours

#### **Tasks:**

**4.1 Create RouterServiceController** ✅
```bash
php artisan make:controller Api/RouterServiceController
```

**Endpoints:**
```php
- index(Router $router)           // GET /api/routers/{router}/services
- store(Router $router, Request)  // POST /api/routers/{router}/services
- show(Router $router, Service)   // GET /api/routers/{router}/services/{service}
- update(Service, Request)        // PUT /api/routers/{router}/services/{service}
- destroy(Service)                // DELETE /api/routers/{router}/services/{service}
- start(Service)                  // POST /api/routers/{router}/services/{service}/start
- stop(Service)                   // POST /api/routers/{router}/services/{service}/stop
- restart(Service)                // POST /api/routers/{router}/services/{service}/restart
- status(Service)                 // GET /api/routers/{router}/services/{service}/status
- sync(Router $router)            // POST /api/routers/{router}/services/sync
```

**4.2 Create AccessPointController** ✅
```bash
php artisan make:controller Api/AccessPointController
```

**Endpoints:**
```php
- index(Router $router)           // GET /api/routers/{router}/access-points
- store(Router $router, Request)  // POST /api/routers/{router}/access-points
- show(AccessPoint $ap)           // GET /api/access-points/{ap}
- update(AccessPoint $ap, Request)// PUT /api/access-points/{ap}
- destroy(AccessPoint $ap)        // DELETE /api/access-points/{ap}
- users(AccessPoint $ap)          // GET /api/access-points/{ap}/users
- sessions(AccessPoint $ap)       // GET /api/access-points/{ap}/sessions
- statistics(AccessPoint $ap)     // GET /api/access-points/{ap}/statistics
- sync(AccessPoint $ap)           // POST /api/access-points/{ap}/sync
- discover(Router $router)        // POST /api/routers/{router}/access-points/discover
```

**4.3 Create InterfaceController** ✅
```bash
php artisan make:controller Api/InterfaceController
```

**Endpoints:**
```php
- index(Router $router)           // GET /api/routers/{router}/interfaces
- available(Router $router)       // GET /api/routers/{router}/interfaces/available
- scan(Router $router)            // POST /api/routers/{router}/interfaces/scan
```

**4.4 Create API Resources** ✅
```bash
php artisan make:resource RouterServiceResource
php artisan make:resource AccessPointResource
php artisan make:resource ApSessionResource
```

**4.5 Add Routes** ✅
Update `routes/api.php`:
```php
// Router Services
Route::prefix('routers/{router}')->group(function () {
    Route::apiResource('services', RouterServiceController::class);
    Route::post('services/{service}/start', [RouterServiceController::class, 'start']);
    Route::post('services/{service}/stop', [RouterServiceController::class, 'stop']);
    Route::post('services/{service}/restart', [RouterServiceController::class, 'restart']);
    Route::get('services/{service}/status', [RouterServiceController::class, 'status']);
    Route::post('services/sync', [RouterServiceController::class, 'sync']);
    
    // Interfaces
    Route::get('interfaces', [InterfaceController::class, 'index']);
    Route::get('interfaces/available', [InterfaceController::class, 'available']);
    Route::post('interfaces/scan', [InterfaceController::class, 'scan']);
    
    // Access Points
    Route::apiResource('access-points', AccessPointController::class)->only(['index', 'store']);
    Route::post('access-points/discover', [AccessPointController::class, 'discover']);
});

// Access Points (not nested)
Route::prefix('access-points/{accessPoint}')->group(function () {
    Route::get('/', [AccessPointController::class, 'show']);
    Route::put('/', [AccessPointController::class, 'update']);
    Route::delete('/', [AccessPointController::class, 'destroy']);
    Route::get('users', [AccessPointController::class, 'users']);
    Route::get('sessions', [AccessPointController::class, 'sessions']);
    Route::get('statistics', [AccessPointController::class, 'statistics']);
    Route::post('sync', [AccessPointController::class, 'sync']);
});
```

**Deliverables:**
- ✅ 3 controllers created
- ✅ 3 API resources created
- ✅ All routes added
- ✅ API documentation updated

---

### **Phase 5: Frontend Components** (Day 5-6)
**Priority:** 🟡 **HIGH**  
**Estimated Time:** 8-10 hours

#### **Tasks:**

**5.1 Create Service Components** ✅

**A. ServiceCard.vue**
```vue
<!-- frontend/src/components/router/ServiceCard.vue -->
<template>
  <div class="service-card" :class="`status-${service.status}`">
    <div class="service-header">
      <h4>{{ service.service_name }}</h4>
      <span class="service-type">{{ service.service_type }}</span>
    </div>
    
    <div class="service-stats">
      <div class="stat">
        <span class="label">Active Users</span>
        <span class="value">{{ service.active_users }}</span>
      </div>
      <div class="stat">
        <span class="label">Interfaces</span>
        <span class="value">{{ service.interfaces.join(', ') }}</span>
      </div>
    </div>
    
    <div class="service-actions">
      <button @click="$emit('start')" v-if="service.status !== 'active'">Start</button>
      <button @click="$emit('stop')" v-if="service.status === 'active'">Stop</button>
      <button @click="$emit('restart')">Restart</button>
      <button @click="$emit('configure')">Configure</button>
    </div>
  </div>
</template>
```

**B. ServiceConfigModal.vue**
```vue
<!-- frontend/src/components/router/ServiceConfigModal.vue -->
<template>
  <Modal v-model="show" title="Configure Service">
    <form @submit.prevent="handleSubmit">
      <!-- Service Type Selection -->
      <div class="form-group">
        <label>Service Type</label>
        <select v-model="form.service_type">
          <option value="hotspot">Hotspot</option>
          <option value="pppoe">PPPoE</option>
        </select>
      </div>
      
      <!-- Interface Selection -->
      <InterfaceSelector
        v-model="form.interfaces"
        :available-interfaces="availableInterfaces"
        :service-type="form.service_type"
      />
      
      <!-- Service-Specific Config -->
      <component 
        :is="configComponent"
        v-model="form.configuration"
      />
      
      <button type="submit">Deploy Service</button>
    </form>
  </Modal>
</template>
```

**C. InterfaceSelector.vue**
```vue
<!-- frontend/src/components/router/InterfaceSelector.vue -->
<template>
  <div class="interface-selector">
    <h4>Select Interfaces</h4>
    <div class="interface-list">
      <div 
        v-for="iface in interfaces"
        :key="iface.name"
        class="interface-item"
        :class="getInterfaceClass(iface)"
        @click="toggleInterface(iface)"
      >
        <span class="interface-name">{{ iface.name }}</span>
        <span v-if="iface.reserved_by" class="reserved-badge">
          Reserved by {{ iface.reserved_by }}
        </span>
        <span v-if="isSelected(iface)" class="selected-badge">✓</span>
      </div>
    </div>
  </div>
</template>
```

**5.2 Create AP Components** ✅

**A. APCard.vue**
```vue
<!-- frontend/src/components/accesspoint/APCard.vue -->
<template>
  <div class="ap-card" :class="`status-${ap.status}`">
    <div class="ap-header">
      <h4>{{ ap.name }}</h4>
      <span class="vendor-badge">{{ ap.vendor }}</span>
    </div>
    
    <div class="ap-stats">
      <div class="stat">
        <span class="icon">👥</span>
        <span class="value">{{ ap.active_users }}</span>
        <span class="label">Active Users</span>
      </div>
      <div class="stat">
        <span class="icon">📶</span>
        <span class="value">{{ ap.signal_strength }}%</span>
        <span class="label">Signal</span>
      </div>
    </div>
    
    <div class="ap-info">
      <p>IP: {{ ap.ip_address }}</p>
      <p>Model: {{ ap.model }}</p>
      <p>Location: {{ ap.location }}</p>
    </div>
    
    <div class="ap-actions">
      <button @click="$emit('view-sessions')">View Sessions</button>
      <button @click="$emit('sync')">Sync</button>
    </div>
  </div>
</template>
```

**B. APSessionsView.vue**
```vue
<!-- frontend/src/components/accesspoint/APSessionsView.vue -->
<template>
  <div class="ap-sessions">
    <h3>Active Sessions - {{ ap.name }}</h3>
    
    <table class="sessions-table">
      <thead>
        <tr>
          <th>Username</th>
          <th>IP Address</th>
          <th>MAC Address</th>
          <th>Connected</th>
          <th>Data Usage</th>
          <th>Signal</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="session in sessions" :key="session.id">
          <td>{{ session.username }}</td>
          <td>{{ session.ip_address }}</td>
          <td>{{ session.mac_address }}</td>
          <td>{{ formatDuration(session.connected_at) }}</td>
          <td>{{ formatBytes(session.bytes_in + session.bytes_out) }}</td>
          <td>{{ session.signal_strength }}%</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

**5.3 Update Router Views** ✅

**A. RouterDetailsView.vue**
```vue
<!-- Add Services Tab -->
<template>
  <div class="router-details">
    <Tabs>
      <Tab name="Overview">...</Tab>
      <Tab name="Services">
        <RouterServicesView :router="router" />
      </Tab>
      <Tab name="Access Points">
        <AccessPointsView :router="router" />
      </Tab>
      <Tab name="Interfaces">
        <InterfacesView :router="router" />
      </Tab>
    </Tabs>
  </div>
</template>
```

**B. RouterServicesView.vue**
```vue
<!-- frontend/src/components/router/RouterServicesView.vue -->
<template>
  <div class="router-services">
    <div class="services-header">
      <h3>Running Services</h3>
      <button @click="showAddService = true">+ Add Service</button>
    </div>
    
    <div class="services-grid">
      <ServiceCard
        v-for="service in services"
        :key="service.id"
        :service="service"
        @start="startService(service)"
        @stop="stopService(service)"
        @restart="restartService(service)"
        @configure="configureService(service)"
      />
    </div>
    
    <ServiceConfigModal
      v-model="showAddService"
      :router="router"
      @deployed="loadServices"
    />
  </div>
</template>
```

**Deliverables:**
- ✅ 8 Vue components created
- ✅ Router details view updated
- ✅ Service management UI complete
- ✅ AP management UI complete

---

## 🧪 Testing Strategy

### **Unit Tests:**
```bash
# Models
tests/Unit/Models/RouterServiceTest.php
tests/Unit/Models/AccessPointTest.php

# Services
tests/Unit/Services/InterfaceManagementServiceTest.php
tests/Unit/Services/RouterServiceManagerTest.php
tests/Unit/Services/AccessPointManagerTest.php

# Adapters
tests/Unit/Services/AccessPoint/Adapters/RuijieAdapterTest.php
tests/Unit/Services/AccessPoint/Adapters/TendaAdapterTest.php
```

### **Feature Tests:**
```bash
# API
tests/Feature/Api/RouterServiceControllerTest.php
tests/Feature/Api/AccessPointControllerTest.php
tests/Feature/Api/InterfaceControllerTest.php

# Integration
tests/Feature/ServiceDeploymentTest.php
tests/Feature/InterfaceConflictTest.php
tests/Feature/APDiscoveryTest.php
```

### **E2E Tests:**
```bash
# Complete workflows
tests/e2e/deploy_pppoe_service.php
tests/e2e/discover_access_points.php
tests/e2e/track_active_users.php
```

---

## 📊 Success Criteria

### **Phase 1:** ✅
- [ ] All migrations run successfully
- [ ] All models created with relationships
- [ ] Database schema matches design

### **Phase 2:** ✅
- [ ] Interface validation working
- [ ] Service deployment working
- [ ] No interface conflicts possible
- [ ] Service status tracking working

### **Phase 3:** ✅
- [ ] At least 3 vendor adapters working
- [ ] AP discovery working
- [ ] Active user tracking working
- [ ] Session management working

### **Phase 4:** ✅
- [ ] All API endpoints working
- [ ] Proper error handling
- [ ] API documentation complete
- [ ] Authentication/authorization working

### **Phase 5:** ✅
- [ ] All components rendering correctly
- [ ] Service management working
- [ ] AP management working
- [ ] Real-time updates working

---

## 🚀 Deployment Checklist

- [ ] Run migrations on production
- [ ] Update environment variables
- [ ] Deploy backend code
- [ ] Deploy frontend code
- [ ] Test service deployment
- [ ] Test AP discovery
- [ ] Monitor for errors
- [ ] Update documentation

---

## 📝 Documentation Requirements

1. **API Documentation** - OpenAPI/Swagger spec
2. **User Guide** - How to deploy services
3. **Admin Guide** - How to manage APs
4. **Developer Guide** - How to add new vendors
5. **Troubleshooting Guide** - Common issues

---

## 🔄 Phase 6: Automated Service Management (NEW)

**Priority:** 🔴 **CRITICAL**  
**Estimated Time:** 10-12 hours

### **Objectives:**
- Auto-disconnect users on payment failure
- Auto-reconnect users after payment
- RADIUS-based service control
- Queue-based task processing
- Payment reminders and notifications

### **Components:**

**6.1 RADIUS Integration**
- RADIUSServiceController
- CoA (Change of Authorization) support
- Session termination
- User account control

**6.2 Queue Jobs**
- DisconnectUserJob (queue: service-control)
- ReconnectUserJob (queue: service-control)
- CheckExpiredSubscriptionsJob (queue: payment-checks)
- SendPaymentRemindersJob (queue: notifications)

**6.3 Subscription Management**
- SubscriptionManager service
- Grace period logic
- Auto-renewal support
- Payment tracking

**6.4 Notification System**
- Payment reminders (7, 3, 1 days before)
- Disconnection notices
- Reconnection confirmations
- Grace period warnings

**6.5 Scheduled Tasks**
- Check expired subscriptions (every 5 min)
- Send payment reminders (daily 9 AM)
- Process grace periods (every 30 min)

**Deliverables:**
- ✅ RADIUS service controller
- ✅ 4 queue jobs
- ✅ Subscription manager
- ✅ 4 notification classes
- ✅ Scheduled task configuration
- ✅ Queue worker setup

**See:** `AUTOMATED_SERVICE_MANAGEMENT_PLAN.md` for detailed implementation

---

**Ready to Start Implementation!**

**Next Step:** Begin Phase 1 - Database & Models

---

**Prepared By:** Cascade AI  
**Date:** 2025-10-11 08:20  
**Updated:** 2025-10-11 08:30  
**Status:** 📋 READY TO IMPLEMENT
