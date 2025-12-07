# Single Tenant VPN Tunnel Architecture
## One VPN Tunnel Per Tenant - All Routers Share
**Date**: December 6, 2025 - 11:00 PM

---

## 📋 Architecture Overview

### **Key Concept**: One WireGuard Tunnel Per Tenant

Instead of creating individual VPN configs per router, we create **ONE VPN tunnel per tenant** that serves **ALL routers** in that tenant, regardless of location.

### **Benefits**:
- ✅ **Simplified management** - One tunnel to manage per tenant
- ✅ **Scalability** - Add unlimited routers to same tunnel
- ✅ **Cost-effective** - Single VPN server per tenant
- ✅ **Easy monitoring** - One tunnel status to track
- ✅ **Automatic routing** - All routers in same subnet

---

## 🏗️ Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    MANAGEMENT SYSTEM (Backend)                   │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │              WireGuard Server Container                   │  │
│  │                                                            │  │
│  │  Tenant A: wg0 (10.100.0.0/16)                           │  │
│  │  ├─ Server IP: 10.100.0.1                                │  │
│  │  ├─ Router 1: 10.100.1.1 (Branch A)                      │  │
│  │  ├─ Router 2: 10.100.1.2 (Branch B)                      │  │
│  │  └─ Router 3: 10.100.1.3 (Branch C)                      │  │
│  │                                                            │  │
│  │  Tenant B: wg1 (10.101.0.0/16)                           │  │
│  │  ├─ Server IP: 10.101.0.1                                │  │
│  │  ├─ Router 1: 10.101.1.1 (HQ)                            │  │
│  │  └─ Router 2: 10.101.1.2 (Remote)                        │  │
│  │                                                            │  │
│  │  Tenant C: wg2 (10.102.0.0/16)                           │  │
│  │  └─ Router 1: 10.102.1.1 (Main)                          │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                  Laravel Backend                          │  │
│  │  - Manages tenant VPN tunnels                             │  │
│  │  - Generates router configs                               │  │
│  │  - Monitors tunnel status                                 │  │
│  │  - Auto-configures routers                                │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
                    Internet (Encrypted)
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                         TENANT ROUTERS                           │
│                                                                   │
│  Branch A (Router 1)          Branch B (Router 2)               │
│  ├─ Public IP: Dynamic        ├─ Public IP: Dynamic             │
│  ├─ VPN IP: 10.100.1.1       ├─ VPN IP: 10.100.1.2            │
│  └─ Connects to wg0           └─ Connects to wg0               │
│                                                                   │
│  Branch C (Router 3)                                             │
│  ├─ Public IP: Behind NAT                                       │
│  ├─ VPN IP: 10.100.1.3                                         │
│  └─ Connects to wg0                                             │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Updated Flow

### **Step 1: Tenant VPN Tunnel Creation** (One-Time Per Tenant)

**Triggered**: When first router is registered for tenant

```
1. Check if tenant has VPN tunnel
   ├─ YES: Use existing tunnel
   └─ NO: Create new tunnel
       ├─ Generate server keys
       ├─ Allocate subnet (10.X.0.0/16)
       ├─ Create WireGuard interface (wgX)
       ├─ Start WireGuard server
       └─ Save tenant tunnel config
```

**Database**:
```sql
-- New table: tenant_vpn_tunnels
CREATE TABLE tenant_vpn_tunnels (
    id BIGSERIAL PRIMARY KEY,
    tenant_id UUID NOT NULL,
    interface_name VARCHAR(10) NOT NULL, -- wg0, wg1, etc.
    server_private_key TEXT NOT NULL,
    server_public_key TEXT NOT NULL,
    server_ip INET NOT NULL, -- 10.X.0.1
    subnet_cidr VARCHAR(20) NOT NULL, -- 10.X.0.0/16
    listen_port INT NOT NULL, -- 51820, 51821, etc.
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(tenant_id),
    UNIQUE(interface_name)
);
```

---

### **Step 2: Router Registration** (Per Router)

**Triggered**: When user creates a router

```
1. Create router in database
2. Get/Create tenant VPN tunnel
3. Allocate IP from tenant subnet (10.X.1.Y)
4. Generate router-specific keys
5. Generate initial connectivity script
6. Return script to user
```

**User sees**:
```bash
# Initial Connectivity Script
# This configures the router to connect to management system

/system identity set name="Branch-Router-A"
/interface wireguard add name=wg-mgmt
/interface wireguard peers add \
    interface=wg-mgmt \
    public-key="SERVER_PUBLIC_KEY" \
    endpoint=vpn.example.com:51820 \
    allowed-address=10.100.0.0/16 \
    persistent-keepalive=25s
/ip address add address=10.100.1.1/16 interface=wg-mgmt
/ip route add dst-address=10.100.0.0/16 gateway=wg-mgmt
```

---

### **Step 3: Router Applies Script**

**User action**: Copy script and paste into MikroTik terminal

**What happens**:
1. Router creates WireGuard interface
2. Router connects to VPN server
3. VPN tunnel established
4. Router gets VPN IP (10.100.1.1)
5. Router can now communicate with backend

---

### **Step 4: Backend Auto-Configuration**

**Triggered**: When router connects to VPN (detected by handshake)

```
1. Backend detects new peer connection
2. Identifies router by public key
3. Updates router status to 'vpn_connected'
4. Dispatches RouterAutoConfigurationJob
5. Job connects to router via VPN IP
6. Fetches router info (model, OS, interfaces)
7. Generates service configurations
8. Applies configurations automatically
9. Router goes online
```

---

### **Step 5: Adding More Routers** (Automatic)

**When tenant adds Router 2**:

```
1. Router 2 created in database
2. REUSE existing tenant VPN tunnel
3. Allocate next IP (10.100.1.2)
4. Generate router-specific keys
5. Generate connectivity script
6. User applies script on Router 2
7. Router 2 connects to SAME tunnel
8. Auto-configuration runs
9. Router 2 online
```

**Result**: Both routers on same VPN tunnel, can communicate with each other and backend.

---

## 🗄️ Database Schema Changes

### **New Table: `tenant_vpn_tunnels`**

```php
Schema::create('tenant_vpn_tunnels', function (Blueprint $table) {
    $table->id();
    $table->uuid('tenant_id')->unique();
    $table->string('interface_name', 10)->unique(); // wg0, wg1
    $table->text('server_private_key'); // Encrypted
    $table->text('server_public_key');
    $table->ipAddress('server_ip'); // 10.X.0.1
    $table->string('subnet_cidr', 20); // 10.X.0.0/16
    $table->integer('listen_port'); // 51820, 51821
    $table->enum('status', ['active', 'inactive', 'error'])->default('active');
    $table->timestamp('last_handshake_at')->nullable();
    $table->timestamps();
    
    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
    $table->index('status');
});
```

### **Update Table: `vpn_configurations`**

```php
Schema::table('vpn_configurations', function (Blueprint $table) {
    // Add reference to tenant tunnel
    $table->foreignId('tenant_vpn_tunnel_id')->after('tenant_id')->constrained('tenant_vpn_tunnels');
    
    // Keep router-specific fields
    // - client_private_key (router's key)
    // - client_public_key (router's key)
    // - client_ip (router's VPN IP)
    // - status
});
```

### **Update Table: `routers`**

```php
// No changes needed, existing fields work:
// - vpn_ip (router's VPN IP)
// - vpn_status (router's connection status)
// - vpn_enabled (always true)
// - vpn_last_handshake
```

---

## 🔧 Backend Implementation

### **New Service: `TenantVpnTunnelService`**

```php
class TenantVpnTunnelService
{
    /**
     * Get or create VPN tunnel for tenant
     */
    public function getOrCreateTenantTunnel(string $tenantId): TenantVpnTunnel
    {
        // Check if tenant already has tunnel
        $tunnel = TenantVpnTunnel::where('tenant_id', $tenantId)->first();
        
        if ($tunnel) {
            return $tunnel;
        }
        
        // Create new tunnel
        return $this->createTenantTunnel($tenantId);
    }
    
    /**
     * Create new VPN tunnel for tenant
     */
    protected function createTenantTunnel(string $tenantId): TenantVpnTunnel
    {
        // 1. Allocate subnet (10.X.0.0/16)
        $subnet = $this->allocateSubnet();
        
        // 2. Generate server keys
        $serverKeys = $this->generateKeys();
        
        // 3. Allocate interface name (wg0, wg1, etc.)
        $interfaceName = $this->allocateInterface();
        
        // 4. Allocate port (51820, 51821, etc.)
        $port = $this->allocatePort();
        
        // 5. Create tunnel record
        $tunnel = TenantVpnTunnel::create([
            'tenant_id' => $tenantId,
            'interface_name' => $interfaceName,
            'server_private_key' => encrypt($serverKeys['private']),
            'server_public_key' => $serverKeys['public'],
            'server_ip' => $subnet . '.0.1',
            'subnet_cidr' => $subnet . '.0.0/16',
            'listen_port' => $port,
            'status' => 'active',
        ]);
        
        // 6. Create WireGuard interface on server
        $this->createWireGuardInterface($tunnel);
        
        return $tunnel;
    }
    
    /**
     * Create WireGuard interface on server
     */
    protected function createWireGuardInterface(TenantVpnTunnel $tunnel): void
    {
        // Generate WireGuard config
        $config = $this->generateServerConfig($tunnel);
        
        // Save to /etc/wireguard/{interface}.conf
        file_put_contents(
            "/etc/wireguard/{$tunnel->interface_name}.conf",
            $config
        );
        
        // Start interface
        exec("wg-quick up {$tunnel->interface_name}");
        
        // Enable on boot
        exec("systemctl enable wg-quick@{$tunnel->interface_name}");
    }
    
    /**
     * Add router peer to tenant tunnel
     */
    public function addRouterPeer(TenantVpnTunnel $tunnel, VpnConfiguration $config): void
    {
        // Add peer to WireGuard interface
        exec("wg set {$tunnel->interface_name} peer {$config->client_public_key} allowed-ips {$config->client_ip}/32");
        
        // Persist config
        exec("wg-quick save {$tunnel->interface_name}");
    }
}
```

### **Updated: `VpnService`**

```php
class VpnService
{
    protected TenantVpnTunnelService $tunnelService;
    
    /**
     * Create VPN configuration for router
     */
    public function createVpnConfiguration(string $tenantId, string $routerId): VpnConfiguration
    {
        // 1. Get or create tenant tunnel
        $tunnel = $this->tunnelService->getOrCreateTenantTunnel($tenantId);
        
        // 2. Allocate IP for router
        $clientIp = $this->allocateIpFromTunnel($tunnel);
        
        // 3. Generate router keys
        $clientKeys = $this->generateKeys();
        
        // 4. Create VPN config
        $config = VpnConfiguration::create([
            'tenant_id' => $tenantId,
            'tenant_vpn_tunnel_id' => $tunnel->id,
            'router_id' => $routerId,
            'client_private_key' => encrypt($clientKeys['private']),
            'client_public_key' => $clientKeys['public'],
            'client_ip' => $clientIp,
            'server_ip' => $tunnel->server_ip,
            'server_public_key' => $tunnel->server_public_key,
            'subnet_cidr' => $tunnel->subnet_cidr,
            'server_endpoint' => config('vpn.server_endpoint'),
            'server_port' => $tunnel->listen_port,
            'status' => 'pending',
        ]);
        
        // 5. Add router as peer to tunnel
        $this->tunnelService->addRouterPeer($tunnel, $config);
        
        // 6. Generate MikroTik script
        $config->mikrotik_script = $this->generateMikrotikScript($config);
        $config->save();
        
        return $config;
    }
}
```

---

## 🐳 Docker Setup for WireGuard Server

### **docker-compose.yml**

```yaml
services:
  wireguard:
    image: linuxserver/wireguard:latest
    container_name: traidnet-wireguard
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Africa/Nairobi
    volumes:
      - ./wireguard/config:/config
      - /lib/modules:/lib/modules:ro
    ports:
      - "51820-51830:51820-51830/udp"  # Support 10 tenants
    sysctls:
      - net.ipv4.conf.all.src_valid_mark=1
      - net.ipv4.ip_forward=1
    restart: unless-stopped
    networks:
      - traidnet-network
```

---

## 📝 MikroTik Script Generation

### **Initial Connectivity Script** (Given to user immediately)

```bash
# Router: Branch-A
# Tenant: Acme Corp
# VPN IP: 10.100.1.1

/system identity set name="Branch-A"

# Create WireGuard interface
/interface wireguard add \
    name=wg-mgmt \
    private-key="ROUTER_PRIVATE_KEY"

# Add server as peer
/interface wireguard peers add \
    interface=wg-mgmt \
    public-key="SERVER_PUBLIC_KEY" \
    endpoint=vpn.example.com:51820 \
    allowed-address=10.100.0.0/16 \
    persistent-keepalive=25s

# Assign VPN IP to interface
/ip address add \
    address=10.100.1.1/16 \
    interface=wg-mgmt

# Add route to VPN subnet
/ip route add \
    dst-address=10.100.0.0/16 \
    gateway=wg-mgmt

# Enable API for management
/ip service set api address=10.100.1.1 port=8728
/ip service set api-ssl address=10.100.1.1 port=8729

# Create management user
/user add \
    name=traidnet_mgmt \
    password="GENERATED_PASSWORD" \
    group=full

# Done! Router will connect to VPN automatically
```

---

## 🔄 Auto-Configuration Flow

### **When Router Connects to VPN**:

```
1. WireGuard server detects handshake
2. Backend monitors handshakes (every 30s)
3. Identifies router by public key
4. Updates router.vpn_status = 'active'
5. Dispatches RouterAutoConfigurationJob
```

### **RouterAutoConfigurationJob**:

```php
class RouterAutoConfigurationJob implements ShouldQueue
{
    public function handle()
    {
        // 1. Connect to router via VPN IP
        $client = new MikroTikClient([
            'host' => $this->router->vpn_ip,
            'user' => 'traidnet_mgmt',
            'pass' => decrypt($this->router->password),
        ]);
        
        // 2. Fetch router info
        $identity = $client->query('/system/identity/print')->read();
        $resources = $client->query('/system/resource/print')->read();
        
        // 3. Update router model
        $this->router->update([
            'model' => $resources[0]['board-name'] ?? 'Unknown',
            'os_version' => $resources[0]['version'] ?? 'Unknown',
            'status' => 'online',
            'last_seen' => now(),
        ]);
        
        // 4. Fetch interfaces
        $interfaces = $client->query('/interface/print')->read();
        $this->router->update(['interface_list' => $interfaces]);
        
        // 5. Fire RouterConnected event
        event(new RouterConnected($this->router));
        
        // 6. User can now configure services via UI
    }
}
```

---

## 🎯 User Experience

### **Creating First Router** (Tenant has no VPN tunnel yet):

```
1. User: Create router "Branch-A"
2. Backend: 
   - Creates tenant VPN tunnel (wg0, 10.100.0.0/16)
   - Allocates IP 10.100.1.1 for router
   - Generates connectivity script
3. User: Sees script, copies it
4. User: Pastes script in MikroTik terminal
5. Router: Connects to VPN
6. Backend: Detects connection, auto-configures router
7. User: Router appears online, can configure services
```

**Time**: ~30 seconds

### **Creating Second Router** (Tenant already has VPN tunnel):

```
1. User: Create router "Branch-B"
2. Backend:
   - REUSES existing tunnel (wg0)
   - Allocates IP 10.100.1.2 for router
   - Generates connectivity script
3. User: Sees script, copies it
4. User: Pastes script in MikroTik terminal
5. Router: Connects to SAME VPN tunnel
6. Backend: Detects connection, auto-configures router
7. User: Router appears online
```

**Time**: ~20 seconds (faster, tunnel already exists)

### **Creating 10th Router**:

Same flow, just allocates 10.100.1.10. **No limit** on routers per tunnel.

---

## 📊 Scalability

### **Per Tenant**:
- **One VPN tunnel** (one WireGuard interface)
- **One subnet** (10.X.0.0/16 = 65,534 IPs)
- **Unlimited routers** (practically limited by subnet size)
- **One port** (51820 + tenant_index)

### **System-Wide**:
- **Support 1000+ tenants** (ports 51820-52820)
- **Each tenant isolated** (separate interface + subnet)
- **No cross-tenant routing**
- **Efficient resource usage**

---

## ✅ Summary

### **Key Changes**:
1. ✅ **One VPN tunnel per tenant** (not per router)
2. ✅ **Shared tunnel** for all tenant routers
3. ✅ **Auto-configuration** after VPN connection
4. ✅ **Simple user experience** (one script, paste, done)
5. ✅ **Unlimited routers** per tenant
6. ✅ **Automatic peer management**

### **Benefits**:
- ✅ **Simplified** - One tunnel to manage
- ✅ **Scalable** - Add routers instantly
- ✅ **Efficient** - Shared resources
- ✅ **Automatic** - No manual config
- ✅ **Secure** - Tenant isolation maintained

---

**Next Steps**: Implement this architecture!
