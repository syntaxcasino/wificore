# Access Point Architecture - Clarification

**Date:** 2025-10-11 08:45  
**Status:** 📋 **ARCHITECTURE DESIGN**

---

## 🎯 Key Distinction

### **Routers vs Access Points**

**Routers:**
- Main gateway devices (MikroTik, etc.)
- Run services (Hotspot, PPPoE, VPN, Firewall)
- Handle authentication (RADIUS)
- Manage network routing
- Control internet access

**Access Points:**
- WiFi broadcasting devices only
- Connected to routers (downstream)
- Provide wireless coverage
- Do NOT run services
- Do NOT handle authentication directly

---

## 🏗️ Correct Architecture

### **Network Topology:**

```
Internet
   ↓
[MikroTik Router] ← Main device (runs Hotspot/PPPoE)
   ↓
   ├─ [Ruijie AP 1] ← Just broadcasts WiFi
   ├─ [Tenda AP 2]  ← Just broadcasts WiFi
   ├─ [TP-Link AP 3] ← Just broadcasts WiFi
   └─ [Clients]     ← Connect via WiFi or Ethernet
```

---

## 📊 Database Relationship

### **Correct Model:**

```
routers (Main devices)
   ↓ (one-to-many)
access_points (WiFi APs connected to router)
   ↓ (one-to-many)
ap_active_sessions (Users connected to specific AP)
```

### **Schema:**

```sql
-- Router (Main device)
CREATE TABLE routers (
    id UUID PRIMARY KEY,
    name VARCHAR(100),
    ip_address VARCHAR(45),
    vendor VARCHAR(50) DEFAULT 'mikrotik',
    device_type VARCHAR(50) DEFAULT 'router', -- Always 'router'
    -- Router manages services
);

-- Access Point (Connected to router)
CREATE TABLE access_points (
    id UUID PRIMARY KEY,
    router_id UUID REFERENCES routers(id), -- Which router manages this AP
    name VARCHAR(100),
    vendor VARCHAR(50), -- 'ruijie', 'tenda', 'tplink'
    ip_address VARCHAR(45),
    mac_address VARCHAR(17),
    -- AP just broadcasts WiFi
);

-- Sessions per AP
CREATE TABLE ap_active_sessions (
    id UUID PRIMARY KEY,
    access_point_id UUID REFERENCES access_points(id),
    router_id UUID REFERENCES routers(id), -- For quick queries
    username VARCHAR(100),
    mac_address VARCHAR(17),
    -- User connected to this specific AP
);
```

---

## 🔍 How It Works

### **1. User Connection Flow:**

```
1. User connects to WiFi (Ruijie AP)
   ↓
2. AP forwards traffic to Router (MikroTik)
   ↓
3. Router intercepts HTTP traffic (Hotspot)
   ↓
4. Router redirects to captive portal
   ↓
5. User authenticates via RADIUS
   ↓
6. Router allows internet access
   ↓
7. System tracks which AP user connected through
```

### **2. Access Point Role:**

**Access Points are "dumb" WiFi broadcasters:**
- They broadcast SSID
- They bridge WiFi to Ethernet
- They forward all traffic to the router
- They do NOT authenticate users
- They do NOT run services

**Router does all the work:**
- Runs Hotspot service
- Handles RADIUS authentication
- Controls internet access
- Manages bandwidth
- Tracks sessions

---

## 📋 Access Point Management

### **How to Add Access Points:**

#### **Option 1: Manual Registration**
```php
// Admin adds AP manually
POST /api/routers/{router}/access-points
{
    "name": "AP-Floor1-Ruijie",
    "vendor": "ruijie",
    "ip_address": "192.168.88.10",
    "mac_address": "AA:BB:CC:DD:EE:01",
    "management_protocol": "snmp",
    "location": "Floor 1 - Main Hall"
}
```

#### **Option 2: Auto-Discovery**
```php
// System scans network and discovers APs
POST /api/routers/{router}/access-points/discover

// Returns:
{
    "discovered": [
        {
            "ip": "192.168.88.10",
            "mac": "AA:BB:CC:DD:EE:01",
            "vendor": "ruijie",
            "model": "RG-AP720-L"
        },
        {
            "ip": "192.168.88.11",
            "mac": "AA:BB:CC:DD:EE:02",
            "vendor": "tenda",
            "model": "W15E"
        }
    ]
}
```

---

## 🎯 Access Point Features

### **What We Track Per AP:**

1. **Active Users Count**
   - How many users currently connected to this AP
   - Query RADIUS accounting data filtered by AP MAC

2. **Session Details**
   - Which users are on which AP
   - Connection time per AP
   - Data usage per AP

3. **AP Health**
   - Is AP online/offline
   - Signal strength
   - Uptime
   - CPU/Memory (if available via SNMP)

4. **AP Statistics**
   - Total users served
   - Peak concurrent users
   - Average session duration
   - Total data transferred

---

## 🔌 How to Identify Which AP a User Connected Through

### **Method 1: RADIUS Called-Station-ID**

When user connects, RADIUS receives:
```
Called-Station-ID = "AA:BB:CC:DD:EE:01:SSID-Name"
```

This is the AP's MAC address! We can:
1. Parse the MAC from Called-Station-ID
2. Look up which AP has that MAC
3. Link session to that AP

**Implementation:**
```php
// In RADIUS accounting
$calledStationId = $radiusData['Called-Station-ID']; // "AA:BB:CC:DD:EE:01:MyWiFi"
$apMac = explode(':', $calledStationId)[0]; // Extract MAC

// Find AP
$ap = AccessPoint::where('mac_address', $apMac)->first();

// Create session record
ApActiveSession::create([
    'access_point_id' => $ap->id,
    'router_id' => $ap->router_id,
    'username' => $username,
    'mac_address' => $userMac,
    'connected_at' => now(),
]);
```

### **Method 2: NAS-Port-Id (MikroTik)**

MikroTik can send interface name:
```
NAS-Port-Id = "ether2"
```

If AP is connected to specific interface, we can map:
```php
// Map interface to AP
$interface = $radiusData['NAS-Port-Id']; // "ether2"
$ap = AccessPoint::where('router_id', $routerId)
    ->where('connected_interface', $interface)
    ->first();
```

### **Method 3: SNMP Polling**

For managed APs (Ruijie, Ubiquiti):
```php
// Poll AP via SNMP
$activeClients = SNMPClient::get($apIp, 'clientTable');

foreach ($activeClients as $client) {
    ApActiveSession::updateOrCreate([
        'access_point_id' => $ap->id,
        'mac_address' => $client['mac'],
    ], [
        'username' => $this->lookupUsername($client['mac']),
        'ip_address' => $client['ip'],
        'signal_strength' => $client['rssi'],
        'last_activity_at' => now(),
    ]);
}
```

---

## 🎨 Frontend Display

### **Router Details Page:**

```vue
<template>
  <div class="router-details">
    <h2>{{ router.name }}</h2>
    
    <!-- Router Services -->
    <section class="services">
      <h3>Running Services</h3>
      <ServiceCard 
        v-for="service in services"
        :service="service"
      />
    </section>
    
    <!-- Access Points -->
    <section class="access-points">
      <h3>Access Points ({{ accessPoints.length }})</h3>
      <button @click="discoverAPs">Discover APs</button>
      
      <div class="ap-grid">
        <APCard
          v-for="ap in accessPoints"
          :key="ap.id"
          :ap="ap"
        >
          <div class="ap-stats">
            <span>Active Users: {{ ap.active_users }}</span>
            <span>Status: {{ ap.status }}</span>
            <span>Signal: {{ ap.signal_strength }}%</span>
          </div>
          
          <button @click="viewAPSessions(ap)">
            View Sessions
          </button>
        </APCard>
      </div>
    </section>
  </div>
</template>
```

### **AP Sessions Modal:**

```vue
<template>
  <Modal v-model="show" :title="`${ap.name} - Active Sessions`">
    <div class="ap-info">
      <p>Vendor: {{ ap.vendor }}</p>
      <p>IP: {{ ap.ip_address }}</p>
      <p>Location: {{ ap.location }}</p>
    </div>
    
    <table class="sessions-table">
      <thead>
        <tr>
          <th>Username</th>
          <th>MAC Address</th>
          <th>IP Address</th>
          <th>Connected</th>
          <th>Signal</th>
          <th>Data Usage</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="session in sessions" :key="session.id">
          <td>{{ session.username }}</td>
          <td>{{ session.mac_address }}</td>
          <td>{{ session.ip_address }}</td>
          <td>{{ formatDuration(session.connected_at) }}</td>
          <td>{{ session.signal_strength }}%</td>
          <td>{{ formatBytes(session.bytes_in + session.bytes_out) }}</td>
        </tr>
      </tbody>
    </table>
  </Modal>
</template>
```

---

## 🔧 API Endpoints

### **Access Point Management:**

```
# List APs for a router
GET /api/routers/{router}/access-points

# Add AP manually
POST /api/routers/{router}/access-points
{
  "name": "AP-Floor1",
  "vendor": "ruijie",
  "ip_address": "192.168.88.10",
  "mac_address": "AA:BB:CC:DD:EE:01"
}

# Discover APs automatically
POST /api/routers/{router}/access-points/discover

# Get AP details
GET /api/access-points/{ap}

# Update AP
PUT /api/access-points/{ap}

# Delete AP
DELETE /api/access-points/{ap}

# Get active sessions on AP
GET /api/access-points/{ap}/sessions

# Get AP statistics
GET /api/access-points/{ap}/statistics

# Sync AP status
POST /api/access-points/{ap}/sync
```

---

## 📊 Data Flow Example

### **Scenario: User connects to Ruijie AP**

**1. Physical Connection:**
```
User Device (WiFi)
    ↓
Ruijie AP (192.168.88.10)
    ↓ (Ethernet)
MikroTik Router (192.168.88.1)
    ↓
Internet
```

**2. Authentication Flow:**
```
1. User connects to SSID "MyWiFi"
2. AP forwards DHCP request to Router
3. Router assigns IP: 192.168.88.50
4. User opens browser
5. Router intercepts HTTP (Hotspot)
6. Router redirects to captive portal
7. User enters credentials
8. Router sends RADIUS Access-Request
   - Username: user@example.com
   - Called-Station-ID: AA:BB:CC:DD:EE:01:MyWiFi (AP MAC)
   - Calling-Station-ID: 11:22:33:44:55:66 (User MAC)
9. RADIUS accepts
10. Router allows internet access
```

**3. Session Tracking:**
```php
// Parse RADIUS data
$apMac = parseCalledStationId($radiusData['Called-Station-ID']);
$ap = AccessPoint::where('mac_address', $apMac)->first();

// Create session
ApActiveSession::create([
    'access_point_id' => $ap->id,
    'router_id' => $ap->router_id,
    'username' => $radiusData['User-Name'],
    'mac_address' => $radiusData['Calling-Station-ID'],
    'ip_address' => $radiusData['Framed-IP-Address'],
    'connected_at' => now(),
]);

// Update AP active user count
$ap->increment('active_users');
```

**4. Frontend Display:**
```
Router: MikroTik-Main
├─ Services
│  ├─ Hotspot (Active) - 15 users
│  └─ PPPoE (Active) - 8 users
└─ Access Points
   ├─ AP-Floor1-Ruijie (Online) - 8 users ← User is here
   ├─ AP-Floor2-Tenda (Online) - 5 users
   └─ AP-Floor3-TPLink (Online) - 2 users
```

---

## ✅ Summary

### **Key Points:**

1. **Access Points are NOT routers**
   - They are separate entities
   - They are managed BY routers
   - They have their own table

2. **Routers manage APs**
   - One router can have multiple APs
   - Router runs all services
   - APs just broadcast WiFi

3. **Session Tracking**
   - Track which AP user connected through
   - Use RADIUS Called-Station-ID (AP MAC)
   - Store in `ap_active_sessions` table

4. **Frontend Display**
   - Show APs under each router
   - Display active users per AP
   - Allow viewing sessions per AP

5. **Management**
   - Add APs manually or auto-discover
   - Monitor AP health via SNMP/API
   - Track statistics per AP

---

## 🚀 Implementation Priority

**Phase 1: Basic AP Management**
- Add AP manually
- List APs per router
- Display AP status

**Phase 2: Session Tracking**
- Parse RADIUS Called-Station-ID
- Link sessions to APs
- Display active users per AP

**Phase 3: Auto-Discovery**
- Network scanning
- Vendor detection
- Automatic registration

**Phase 4: Advanced Monitoring**
- SNMP polling
- Signal strength tracking
- Performance metrics

---

**This architecture keeps routers and access points properly separated while enabling comprehensive monitoring!**

---

**Prepared By:** Cascade AI  
**Date:** 2025-10-11 08:45  
**Status:** 📋 ARCHITECTURE CLARIFIED
