# WireGuard VPN Setup for RADIUS Connectivity

## 🎯 Overview

Remote MikroTik routers need VPN connectivity to communicate with the RADIUS server for hotspot authentication and accounting.

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    NETWORK TOPOLOGY                              │
└─────────────────────────────────────────────────────────────────┘

Central Server (VPN Server)
├─ Public IP: <YOUR_PUBLIC_IP>
├─ WireGuard Interface: wg0
├─ WireGuard IP: 10.10.10.1/24
├─ RADIUS Server: 10.10.10.1:1812
└─ Services: Laravel Backend, PostgreSQL, FreeRADIUS

                    ↓ WireGuard VPN
                    
Remote MikroTik Routers (VPN Clients)
├─ Router 1
│  ├─ Public IP: Dynamic
│  ├─ WireGuard IP: 10.10.10.2/32
│  └─ RADIUS Client → 10.10.10.1:1812
├─ Router 2
│  ├─ Public IP: Dynamic
│  ├─ WireGuard IP: 10.10.10.3/32
│  └─ RADIUS Client → 10.10.10.1:1812
└─ Router N
   ├─ Public IP: Dynamic
   ├─ WireGuard IP: 10.10.10.N/32
   └─ RADIUS Client → 10.10.10.1:1812
```

## 🔧 Server Setup (Central)

### 1. Install WireGuard

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install wireguard wireguard-tools

# Enable IP forwarding
sudo sysctl -w net.ipv4.ip_forward=1
sudo sysctl -w net.ipv6.conf.all.forwarding=1

# Make permanent
echo "net.ipv4.ip_forward=1" | sudo tee -a /etc/sysctl.conf
echo "net.ipv6.conf.all.forwarding=1" | sudo tee -a /etc/sysctl.conf
```

### 2. Generate Server Keys

```bash
# Create directory
sudo mkdir -p /etc/wireguard
cd /etc/wireguard

# Generate server keys
wg genkey | sudo tee server_private.key | wg pubkey | sudo tee server_public.key

# Set permissions
sudo chmod 600 server_private.key
```

### 3. Create WireGuard Server Configuration

**File:** `/etc/wireguard/wg0.conf`

```ini
[Interface]
# Server private key
PrivateKey = <SERVER_PRIVATE_KEY>

# Server WireGuard IP
Address = 10.10.10.1/24

# WireGuard port
ListenPort = 51820

# Post-up: Enable NAT and routing
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT
PostUp = iptables -A FORWARD -o wg0 -j ACCEPT
PostUp = iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE

# Post-down: Cleanup
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT
PostDown = iptables -D FORWARD -o wg0 -j ACCEPT
PostDown = iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE

# ============================================================================
# MIKROTIK ROUTER PEERS
# ============================================================================

# Router 1 - Nairobi CBD
[Peer]
# Router 1 public key
PublicKey = <ROUTER1_PUBLIC_KEY>

# Allowed IPs for this router
AllowedIPs = 10.10.10.2/32

# Keep alive (important for NAT traversal)
PersistentKeepalive = 25

# Router 2 - Westlands
[Peer]
PublicKey = <ROUTER2_PUBLIC_KEY>
AllowedIPs = 10.10.10.3/32
PersistentKeepalive = 25

# Router 3 - Kilimani
[Peer]
PublicKey = <ROUTER3_PUBLIC_KEY>
AllowedIPs = 10.10.10.4/32
PersistentKeepalive = 25

# Add more routers as needed...
```

### 4. Start WireGuard Server

```bash
# Enable and start WireGuard
sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0

# Check status
sudo systemctl status wg-quick@wg0

# View interface
sudo wg show wg0
```

### 5. Configure Firewall

```bash
# Allow WireGuard port
sudo ufw allow 51820/udp

# Allow RADIUS from WireGuard network
sudo ufw allow from 10.10.10.0/24 to any port 1812 proto udp
sudo ufw allow from 10.10.10.0/24 to any port 1813 proto udp

# Reload firewall
sudo ufw reload
```

## 🔧 MikroTik Router Setup (Client)

### 1. Generate Router Keys

On each MikroTik router:

```routeros
# Generate WireGuard keys
/interface/wireguard
add name=wg-radius listen-port=13231

# Get public key (save this for server config)
/interface/wireguard/print
```

### 2. Configure WireGuard Interface

```routeros
# Configure WireGuard interface
/interface/wireguard
set wg-radius private-key="<ROUTER_PRIVATE_KEY>"

# Add IP address
/ip/address
add address=10.10.10.2/32 interface=wg-radius

# Add peer (server)
/interface/wireguard/peers
add interface=wg-radius \
    public-key="<SERVER_PUBLIC_KEY>" \
    endpoint-address=<SERVER_PUBLIC_IP> \
    endpoint-port=51820 \
    allowed-address=10.10.10.0/24 \
    persistent-keepalive=25s
```

### 3. Configure Routing

```routeros
# Add route to RADIUS server via WireGuard
/ip/route
add dst-address=10.10.10.1/32 gateway=wg-radius

# Or route entire VPN network
add dst-address=10.10.10.0/24 gateway=wg-radius
```

### 4. Configure RADIUS Client

```routeros
# Add RADIUS server
/radius
add address=10.10.10.1 \
    secret="your_radius_secret_here" \
    service=hotspot \
    timeout=3s

# Configure hotspot to use RADIUS
/ip/hotspot/profile
set default use-radius=yes

# Configure RADIUS accounting
/radius
set [find] accounting-port=1813 \
    authentication-port=1812
```

### 5. Test Connectivity

```routeros
# Ping RADIUS server
/ping 10.10.10.1 count=5

# Check WireGuard status
/interface/wireguard/peers/print

# Test RADIUS
/radius/monitor [find]
```

## 🔐 FreeRADIUS Configuration

### 1. Configure RADIUS Clients

**File:** `/etc/freeradius/3.0/clients.conf`

```conf
# WireGuard network
client wireguard_network {
    ipaddr = 10.10.10.0/24
    secret = your_radius_secret_here
    shortname = wireguard-routers
    nas_type = mikrotik
}

# Individual routers (optional, for better tracking)
client router1 {
    ipaddr = 10.10.10.2
    secret = your_radius_secret_here
    shortname = router1-nairobi-cbd
    nas_type = mikrotik
}

client router2 {
    ipaddr = 10.10.10.3
    secret = your_radius_secret_here
    shortname = router2-westlands
    nas_type = mikrotik
}

client router3 {
    ipaddr = 10.10.10.4
    secret = your_radius_secret_here
    shortname = router3-kilimani
    nas_type = mikrotik
}
```

### 2. Update Database NAS Table

```sql
-- Add routers to nas table
INSERT INTO nas (nasname, shortname, type, secret, description) VALUES
('10.10.10.2', 'router1', 'mikrotik', 'your_radius_secret_here', 'Router 1 - Nairobi CBD'),
('10.10.10.3', 'router2', 'mikrotik', 'your_radius_secret_here', 'Router 2 - Westlands'),
('10.10.10.4', 'router3', 'mikrotik', 'your_radius_secret_here', 'Router 3 - Kilimani')
ON CONFLICT (nasname) DO NOTHING;
```

### 3. Restart FreeRADIUS

```bash
sudo systemctl restart freeradius
sudo systemctl status freeradius

# Test RADIUS
sudo freeradius -X
```

## 📝 Laravel Integration

### 1. Store Router VPN Configuration

**Migration:** Create `router_vpn_configs` table

```php
Schema::create('router_vpn_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
    
    // WireGuard configuration
    $table->string('wireguard_public_key');
    $table->string('wireguard_private_key')->nullable(); // Encrypted
    $table->ipAddress('vpn_ip_address'); // 10.10.10.X
    $table->integer('listen_port')->default(13231);
    
    // Connection status
    $table->boolean('vpn_connected')->default(false);
    $table->timestamp('last_handshake')->nullable();
    $table->bigInteger('bytes_received')->default(0);
    $table->bigInteger('bytes_sent')->default(0);
    
    // RADIUS configuration
    $table->ipAddress('radius_server_ip')->default('10.10.10.1');
    $table->integer('radius_auth_port')->default(1812);
    $table->integer('radius_acct_port')->default(1813);
    $table->string('radius_secret');
    
    $table->timestamps();
});
```

### 2. Router Model Update

```php
class Router extends Model
{
    public function vpnConfig()
    {
        return $this->hasOne(RouterVpnConfig::class);
    }
    
    public function isVpnConnected(): bool
    {
        return $this->vpnConfig?->vpn_connected ?? false;
    }
    
    public function getVpnIpAttribute(): ?string
    {
        return $this->vpnConfig?->vpn_ip_address;
    }
}
```

### 3. WireGuard Management Service

```php
class WireGuardService
{
    public function addPeer(Router $router, string $publicKey): void
    {
        // Add peer to wg0.conf
        $config = "[Peer]\n";
        $config .= "PublicKey = {$publicKey}\n";
        $config .= "AllowedIPs = {$router->vpn_ip}/32\n";
        $config .= "PersistentKeepalive = 25\n\n";
        
        File::append('/etc/wireguard/wg0.conf', $config);
        
        // Reload WireGuard
        exec('sudo wg syncconf wg0 <(wg-quick strip wg0)');
    }
    
    public function removePeer(Router $router): void
    {
        // Remove peer from config
        // Reload WireGuard
    }
    
    public function getPeerStatus(string $publicKey): array
    {
        // Get peer status from wg show
        $output = shell_exec("sudo wg show wg0 dump");
        // Parse output
        return [
            'connected' => true,
            'last_handshake' => now(),
            'bytes_received' => 1234567,
            'bytes_sent' => 7654321,
        ];
    }
}
```

## 🧪 Testing & Verification

### 1. Test VPN Connectivity

```bash
# On server
sudo wg show wg0

# Should show connected peers with recent handshake
```

### 2. Test RADIUS from Router

```routeros
# On MikroTik
/radius/monitor [find]

# Should show:
# status: connected
# requests: X
# accepts: Y
```

### 3. Test Hotspot Authentication

```bash
# Create test user in RADIUS
INSERT INTO radcheck (username, attribute, op, value) VALUES
('testuser', 'Cleartext-Password', ':=', 'testpass');

# Try to login from hotspot
# Should authenticate successfully
```

### 4. Monitor RADIUS Logs

```bash
# Server logs
sudo tail -f /var/log/freeradius/radius.log

# Should see authentication requests from 10.10.10.X
```

## 🔒 Security Best Practices

### 1. Firewall Rules

```bash
# Only allow WireGuard and RADIUS
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 51820/udp
sudo ufw allow from 10.10.10.0/24 to any port 1812 proto udp
sudo ufw allow from 10.10.10.0/24 to any port 1813 proto udp
```

### 2. Key Management

- Store private keys encrypted in database
- Rotate keys periodically
- Use strong RADIUS secrets
- Different secrets per router (optional)

### 3. Monitoring

- Monitor VPN connection status
- Alert on disconnected routers
- Track RADIUS authentication failures
- Monitor bandwidth usage

## 📊 Automated Router Provisioning

### 1. Router Onboarding Flow

```
1. Admin adds new router in dashboard
2. System generates WireGuard keys
3. System assigns VPN IP (10.10.10.X)
4. System generates RADIUS secret
5. System updates wg0.conf
6. System reloads WireGuard
7. System provides router configuration script
8. Admin applies script to MikroTik
9. Router connects via VPN
10. System verifies RADIUS connectivity
```

### 2. Configuration Script Generator

```php
public function generateRouterConfig(Router $router): string
{
    $vpn = $router->vpnConfig;
    
    return <<<ROUTEROS
# WireGuard VPN Configuration
/interface/wireguard
add name=wg-radius listen-port=13231 private-key="{$vpn->wireguard_private_key}"

/ip/address
add address={$vpn->vpn_ip_address}/32 interface=wg-radius

/interface/wireguard/peers
add interface=wg-radius \
    public-key="{$this->serverPublicKey}" \
    endpoint-address={$this->serverPublicIp} \
    endpoint-port=51820 \
    allowed-address=10.10.10.0/24 \
    persistent-keepalive=25s

/ip/route
add dst-address=10.10.10.0/24 gateway=wg-radius

# RADIUS Configuration
/radius
add address={$vpn->radius_server_ip} \
    secret="{$vpn->radius_secret}" \
    service=hotspot \
    timeout=3s

/ip/hotspot/profile
set default use-radius=yes
ROUTEROS;
}
```

## ✅ Checklist

### Server Setup
- [ ] Install WireGuard
- [ ] Generate server keys
- [ ] Create wg0.conf
- [ ] Start WireGuard service
- [ ] Configure firewall
- [ ] Configure FreeRADIUS clients
- [ ] Test RADIUS locally

### Router Setup
- [ ] Generate router keys
- [ ] Configure WireGuard interface
- [ ] Add server peer
- [ ] Configure routing
- [ ] Configure RADIUS client
- [ ] Test VPN connectivity
- [ ] Test RADIUS authentication

### Integration
- [ ] Create router_vpn_configs table
- [ ] Update Router model
- [ ] Create WireGuardService
- [ ] Add router provisioning
- [ ] Add monitoring
- [ ] Test end-to-end

## 📝 Environment Variables

Add to `.env`:

```env
# WireGuard Configuration
WIREGUARD_SERVER_IP=10.10.10.1
WIREGUARD_SERVER_PORT=51820
WIREGUARD_SERVER_PUBLIC_KEY=<server_public_key>
WIREGUARD_NETWORK=10.10.10.0/24

# RADIUS Configuration
RADIUS_SERVER_IP=10.10.10.1
RADIUS_AUTH_PORT=1812
RADIUS_ACCT_PORT=1813
RADIUS_SECRET=your_radius_secret_here
```

## 🎯 Summary

**VPN Type:** WireGuard  
**VPN Network:** 10.10.10.0/24  
**Server IP:** 10.10.10.1  
**Router IPs:** 10.10.10.2+  
**RADIUS Port:** 1812 (auth), 1813 (acct)  
**Status:** Ready for implementation  

---

**Next Steps:** 
1. Set up WireGuard server
2. Configure first router
3. Test RADIUS connectivity
4. Automate provisioning
