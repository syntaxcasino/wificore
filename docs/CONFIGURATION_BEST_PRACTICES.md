# MikroTik Configuration Best Practices Review

## Current Configuration Analysis

### ✅ What's Good (Already Implemented)

#### 1. **Bridge Architecture** ✓
- **Current**: All service templates use bridges instead of direct physical interfaces
- **Why Good**: Industry standard, scalable, supports multiple interfaces per service
- **Rating**: Excellent

#### 2. **SSH-Only Management** ✓
- **Current**: Removed RouterOS API, using SSH exclusively
- **Why Good**: Single attack surface, better security, faster
- **Rating**: Excellent

#### 3. **Service Isolation** ✓
- **Current**: Separate bridges for Hotspot and PPPoE in hybrid mode
- **Why Good**: Prevents cross-service attacks, better resource management
- **Rating**: Excellent

#### 4. **RADIUS Integration** ✓
- **Current**: All templates integrate with FreeRADIUS via VPN
- **Why Good**: Centralized authentication, per-user policies
- **Rating**: Excellent

### ⚠️ Areas for Improvement

#### 1. **Firewall Rules - Missing Critical Protection**

**Current Issues:**
```routeros
# Current: Basic firewall rules
add chain=input connection-state=established,related action=accept
add chain=input protocol=icmp src-address-list=vpn_subnet action=accept
add chain=input protocol=tcp dst-port=22 src-address-list=vpn_subnet action=accept
add chain=input action=drop comment="Drop all other input"
```

**Missing:**
- ❌ No protection against brute force attacks (beyond rate limiting)
- ❌ No blacklist/dynamic blocking
- ❌ No protection against port scanning
- ❌ No DDoS mitigation (SYN flood, UDP flood)
- ❌ No geo-blocking capabilities
- ❌ Missing INPUT chain protection for router services

**Recommended Additions:**
```routeros
# 1. Brute Force Protection with Blacklist
/ip firewall filter
add chain=input protocol=tcp dst-port=22 src-address-list=ssh_blacklist \
    action=drop comment="Drop SSH blacklisted IPs"

add chain=input protocol=tcp dst-port=22 connection-state=new \
    src-address-list=ssh_stage3 action=add-src-to-address-list \
    address-list=ssh_blacklist address-list-timeout=1d \
    comment="SSH blacklist after 3 attempts"

add chain=input protocol=tcp dst-port=22 connection-state=new \
    src-address-list=ssh_stage2 action=add-src-to-address-list \
    address-list=ssh_stage3 address-list-timeout=1m

add chain=input protocol=tcp dst-port=22 connection-state=new \
    src-address-list=ssh_stage1 action=add-src-to-address-list \
    address-list=ssh_stage2 address-list-timeout=1m

add chain=input protocol=tcp dst-port=22 connection-state=new \
    action=add-src-to-address-list address-list=ssh_stage1 \
    address-list-timeout=1m

# 2. SYN Flood Protection
add chain=input protocol=tcp tcp-flags=syn connection-limit=30,32 \
    action=drop comment="SYN flood protection"

# 3. Port Scan Detection (already partially implemented)
add chain=input protocol=tcp psd=21,3s,3,1 action=add-src-to-address-list \
    address-list=port_scanners address-list-timeout=1d \
    comment="Port scan detection"

add chain=input src-address-list=port_scanners action=drop \
    comment="Drop port scanners"

# 4. ICMP Flood Protection
add chain=input protocol=icmp icmp-options=8:0 limit=5,5:packet \
    action=accept comment="ICMP echo request (ping) - rate limited"

add chain=input protocol=icmp action=drop comment="Drop excessive ICMP"

# 5. Invalid Packet Protection
add chain=input connection-state=invalid action=drop \
    comment="Drop invalid packets"

# 6. Bogon/Martian Address Protection (WAN interface)
add chain=input in-interface=ether1 src-address-list=bogons action=drop \
    comment="Drop bogon addresses"

/ip firewall address-list
add list=bogons address=0.0.0.0/8
add list=bogons address=10.0.0.0/8
add list=bogons address=127.0.0.0/8
add list=bogons address=169.254.0.0/16
add list=bogons address=172.16.0.0/12
add list=bogons address=192.0.0.0/24
add list=bogons address=192.0.2.0/24
add list=bogons address=192.168.0.0/16
add list=bogons address=198.18.0.0/15
add list=bogons address=198.51.100.0/24
add list=bogons address=203.0.113.0/24
add list=bogons address=224.0.0.0/4
add list=bogons address=240.0.0.0/4
```

#### 2. **Bridge Security - Missing STP/BPDU Protection**

**Current Issues:**
```routeros
# Current: Basic bridge creation
/interface bridge add name=bridge-hotspot
```

**Missing:**
- ❌ No STP/RSTP configuration
- ❌ No BPDU guard
- ❌ No unknown unicast flood control
- ❌ No IGMP snooping for multicast optimization

**Recommended:**
```routeros
/interface bridge
add name=bridge-hotspot \
    protocol-mode=rstp \
    igmp-snooping=yes \
    unknown-unicast-flood=no \
    comment="Hotspot Bridge with STP"

# Enable BPDU Guard on access ports
/interface bridge port
add bridge=bridge-hotspot interface=ether2 \
    bpdu-guard=yes \
    edge=yes \
    point-to-point=yes \
    comment="Hotspot Port with BPDU Guard"
```

#### 3. **Bandwidth Management - Missing QoS**

**Current Issues:**
```routeros
# Current: Simple queue (disabled)
/queue simple
add name=pppoe-default target=pppoe-pool \
    max-limit=100M/100M disabled=yes
```

**Missing:**
- ❌ No priority queuing (VoIP, gaming, bulk)
- ❌ No PCQ (Per Connection Queue) for fairness
- ❌ No burst configuration for better user experience
- ❌ No queue tree for hierarchical QoS

**Recommended:**
```routeros
# PCQ for fair bandwidth distribution
/queue type
add name=pcq-download kind=pcq pcq-rate=0 \
    pcq-classifier=dst-address
add name=pcq-upload kind=pcq pcq-rate=0 \
    pcq-classifier=src-address

# Queue tree with priority
/queue tree
add name=download parent=global queue=default priority=8 \
    max-limit=1G comment="Total Download"

add name=download-high parent=download queue=default priority=1 \
    max-limit=500M packet-mark=high-priority comment="High Priority (VoIP, Gaming)"

add name=download-normal parent=download queue=pcq-download priority=4 \
    max-limit=800M packet-mark=normal-priority comment="Normal Priority (Web, Streaming)"

add name=download-bulk parent=download queue=pcq-download priority=8 \
    max-limit=300M packet-mark=bulk-priority comment="Bulk (Downloads, P2P)"

# Mangle rules for packet marking
/ip firewall mangle
add chain=prerouting protocol=udp dst-port=5060,5061,10000-20000 \
    action=mark-packet new-packet-mark=high-priority \
    comment="VoIP traffic"

add chain=prerouting protocol=tcp dst-port=80,443 \
    action=mark-packet new-packet-mark=normal-priority \
    comment="Web traffic"

add chain=prerouting protocol=tcp dst-port=20,21,22,25,110,143 \
    action=mark-packet new-packet-mark=bulk-priority \
    comment="Bulk traffic"
```

#### 4. **DNS Security - Missing DNS Protection**

**Current Issues:**
- ❌ No DNS cache poisoning protection
- ❌ No DNS query logging
- ❌ No DNS-based filtering (malware, ads)
- ❌ Using public DNS without DoH/DoT

**Recommended:**
```routeros
# Enable DNS cache with security
/ip dns
set allow-remote-requests=yes \
    cache-size=4096KiB \
    cache-max-ttl=1d \
    max-concurrent-queries=100 \
    max-concurrent-tcp-sessions=20 \
    servers=1.1.1.1,1.0.0.1 \
    use-doh-server=https://cloudflare-dns.com/dns-query \
    verify-doh-cert=yes

# DNS firewall rules
/ip firewall filter
add chain=input protocol=udp dst-port=53 src-address-list=!local_networks \
    action=drop comment="Block external DNS queries"

add chain=forward protocol=udp dst-port=53 dst-address-list=!dns_servers \
    action=drop comment="Force clients to use router DNS"
```

#### 5. **Logging & Monitoring - Insufficient**

**Current Issues:**
```routeros
# Current: Basic logging
/system logging
add topics=ssh action=memory
add topics=firewall action=memory
add topics=account action=memory
```

**Missing:**
- ❌ No remote syslog
- ❌ No email alerts
- ❌ No SNMP monitoring
- ❌ No traffic accounting
- ❌ No connection tracking statistics

**Recommended:**
```routeros
# Remote syslog for centralized logging
/system logging action
add name=remote-syslog remote=10.100.1.1 remote-port=514 \
    src-address=auto target=remote

/system logging
add topics=critical action=remote-syslog
add topics=error action=remote-syslog
add topics=warning action=remote-syslog
add topics=firewall,info action=remote-syslog prefix="FW:"
add topics=account,info action=remote-syslog prefix="AUTH:"

# Email alerts for critical events
/tool e-mail
set server=smtp.example.com port=587 \
    from=router@wificore.local \
    user=alerts@wificore.local \
    password=secret \
    tls=yes

# SNMP for monitoring
/snmp
set enabled=yes contact="admin@wificore.local" \
    location="Router {$router->name}"

:do { /snmp community remove [find name=traidnet-monitor]; } on-error={}
/snmp community add name=traidnet-monitor addresses=<TENANT_VPN_SUBNET> security=none read-access=yes write-access=no
/snmp set trap-community=traidnet-monitor trap-version=2

# Traffic accounting
/ip accounting
set enabled=yes threshold=2560
```

#### 6. **Hotspot Security - Missing Client Protection**

**Current Issues:**
```routeros
# Current: Basic hotspot
/ip hotspot add name=wificore-hotspot interface=bridge-hotspot
```

**Missing:**
- ❌ No HTTPS redirect enforcement
- ❌ No client-to-client isolation at layer 2
- ❌ No MAC address filtering
- ❌ No session timeout enforcement
- ❌ No bandwidth limits per user

**Recommended:**
```routeros
# Enhanced Hotspot profile
/ip hotspot profile
set [find name=hotspot-profile] \
    login-by=http-chap,http-pap,https \
    use-radius=yes \
    radius-accounting=yes \
    radius-interim-update=5m \
    session-timeout=8h \
    idle-timeout=30m \
    keepalive-timeout=5m \
    status-autorefresh=1m \
    trial-uptime-limit=30m \
    trial-user-profile=trial

# Layer 2 client isolation (bridge filter)
/interface bridge filter
add chain=forward in-bridge=bridge-hotspot \
    mac-protocol=ip out-bridge=bridge-hotspot \
    action=drop comment="Block client-to-client (L2)"

# MAC address binding (optional)
/ip hotspot user profile
set [find name=default] \
    mac-cookie-timeout=3d \
    shared-users=1 \
    status-autorefresh=1m
```

#### 7. **PPPoE Security - Missing Protection**

**Current Issues:**
```routeros
# Current: Basic PPPoE server
/interface pppoe-server server add interface=bridge-pppoe
```

**Missing:**
- ❌ No PADO delay (prevents discovery floods)
- ❌ No service name filtering
- ❌ No MAC-based authentication
- ❌ No session limits per user

**Recommended:**
```routeros
# Enhanced PPPoE server
/interface pppoe-server server
set [find interface=bridge-pppoe] \
    pado-delay=0,100ms \
    max-sessions=1000 \
    max-mtu=1480 \
    max-mru=1480 \
    mrru=disabled \
    authentication=mschap2 \
    keepalive-timeout=60 \
    one-session-per-host=yes \
    service-name=wificore-pppoe

# PPPoE firewall protection
/ip firewall filter
add chain=input protocol=pppoe-discovery \
    src-address-list=pppoe_blacklist action=drop \
    comment="Block blacklisted PPPoE clients"

add chain=input protocol=pppoe-discovery \
    connection-limit=5,32 action=add-src-to-address-list \
    address-list=pppoe_blacklist address-list-timeout=1h \
    comment="PPPoE discovery flood protection"
```

#### 8. **System Hardening - Missing Critical Settings**

**Current Issues:**
- ❌ No NTP security
- ❌ No router identity obfuscation
- ❌ No LCD/console security
- ❌ No backup automation
- ❌ No firmware update policy

**Recommended:**
```routeros
# Secure NTP
/system ntp client
set enabled=yes primary-ntp=10.100.1.1 secondary-ntp=time.cloudflare.com

/system ntp server
set enabled=no broadcast=no multicast=no

# Router identity (don't reveal MikroTik)
/system identity
set name="Router-{$router->id}"

# Disable LCD (if present)
/lcd
set enabled=no

# Disable console access
/system console
disable [find]

# Automated backups
/system scheduler
add name=daily-backup interval=1d on-event=backup-script \
    start-time=03:00:00

/system script
add name=backup-script source={
    /system backup save name=daily-backup
    :delay 5s
    /tool fetch url="https://backup.wificore.local/upload" \
        mode=https upload=yes src-path=daily-backup.backup
}
```

## Recommended Priority Implementation Order

### Phase 1: Critical Security (Immediate)
1. ✅ **Firewall brute force protection** - Prevents account compromise
2. ✅ **SYN flood protection** - Prevents DoS attacks
3. ✅ **Port scan detection** - Identifies attackers
4. ✅ **Invalid packet dropping** - Prevents exploits
5. ✅ **Bogon filtering** - Prevents spoofing

### Phase 2: Service Hardening (Week 1)
1. ✅ **Bridge STP/BPDU guard** - Prevents network loops
2. ✅ **Hotspot client isolation** - Prevents lateral attacks
3. ✅ **PPPoE flood protection** - Prevents service disruption
4. ✅ **DNS security** - Prevents cache poisoning

### Phase 3: Performance & Monitoring (Week 2)
1. ✅ **QoS/Queue tree** - Better user experience
2. ✅ **Remote syslog** - Centralized monitoring
3. ✅ **SNMP** - Network monitoring
4. ✅ **Traffic accounting** - Usage tracking

### Phase 4: Operational Excellence (Week 3)
1. ✅ **Automated backups** - Disaster recovery
2. ✅ **Email alerts** - Proactive monitoring
3. ✅ **NTP security** - Accurate time sync
4. ✅ **Firmware update policy** - Security patches

## Configuration Rating

| Category | Current | Best Practice | Gap |
|----------|---------|---------------|-----|
| **Bridge Architecture** | ✅ Excellent | ✅ Excellent | None |
| **SSH Security** | ✅ Excellent | ✅ Excellent | None |
| **Service Isolation** | ✅ Excellent | ✅ Excellent | None |
| **Firewall Rules** | ⚠️ Basic | ✅ Advanced | **High** |
| **Bridge Security** | ⚠️ Basic | ✅ Advanced | **Medium** |
| **QoS/Bandwidth** | ❌ Missing | ✅ Required | **High** |
| **DNS Security** | ❌ Missing | ✅ Required | **Medium** |
| **Logging/Monitoring** | ⚠️ Basic | ✅ Advanced | **High** |
| **Hotspot Security** | ⚠️ Basic | ✅ Advanced | **Medium** |
| **PPPoE Security** | ⚠️ Basic | ✅ Advanced | **Medium** |
| **System Hardening** | ⚠️ Basic | ✅ Advanced | **Medium** |

## Overall Assessment

**Current Score: 6.5/10**
**Best Practice Score: 9.5/10**

### Strengths
- ✅ Excellent bridge-based architecture
- ✅ SSH-only management is secure
- ✅ Good service isolation
- ✅ RADIUS integration is solid

### Critical Gaps
- ❌ **Firewall protection is too basic** - Vulnerable to brute force, DDoS
- ❌ **No QoS** - Poor user experience during congestion
- ❌ **Insufficient monitoring** - Can't detect/respond to attacks
- ❌ **Missing DNS security** - Vulnerable to cache poisoning

### Recommendation
**Implement Phase 1 (Critical Security) immediately**, then proceed with Phases 2-4 over the next 3 weeks. This will bring the configuration from 6.5/10 to 9.5/10.

## Next Steps

1. **Review this document** with your team
2. **Prioritize improvements** based on your risk assessment
3. **Create enhanced hardening service** with Phase 1 improvements
4. **Test in staging** before production deployment
5. **Document changes** in agent-actions.log
6. **Monitor results** and adjust as needed
