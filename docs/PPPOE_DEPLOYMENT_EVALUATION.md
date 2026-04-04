# RouterOS PPPoE Deployment Evaluation
**Device:** CHR (Cloud Hosted Router)  
**RouterOS Version:** 7.22.1  
**System ID:** /riYOMU6u0N  
**Profile ID:** b079c148  
**Evaluation Date:** 2026-04-04  

---

## Executive Summary

| Category | Score | Status |
|----------|-------|--------|
| **Security** | 7.5/10 | Good |
| **Performance** | 8/10 | Very Good |
| **Reliability** | 7/10 | Good |
| **Manageability** | 8.5/10 | Very Good |
| **Standards Compliance** | 9/10 | Excellent |
| **Overall** | **8/10** | **Best-in-Class Ready** |

---

## Detailed Analysis by Component

### 1. Interface Architecture ⭐⭐⭐⭐⭐ (5/5)

**Strengths:**
- **Clean Bridge Design:** Dedicated `pppoe-br-b079c148` isolates PPPoE traffic from other services
- **Proper Ethernet Handling:** `disable-running-check=no` enables proper link monitoring
- **Interface List Segmentation:** Clear separation of WAN, PL (PPPoE LAN), and PA (PPPoE Access) lists
- **WireGuard Integration:** Management VPN on separate interface prevents exposure

**Configuration:**
```rsc
/interface bridge
add comment=PPPoE-b079c148 name=pppoe-br-b079c148
/interface list
add name=WAN
add name=PL-b079c148
add name=PA-b079c148
```

**Verdict:** Industry-standard interface segmentation.

---

### 2. PPPoE Server Configuration ⭐⭐⭐⭐ (4/5)

**Strengths:**
- ✅ **CGNAT Addressing:** Using `100.64.0.0/10` (RFC 6598) for subscriber addresses - ISP best practice
- ✅ **Session Control:** `one-session-per-host=yes` prevents duplicate logins
- ✅ **Keepalive Timeout:** 30 seconds is balanced (not too aggressive, not too loose)
- ✅ **MTU/MRU:** 1480 accounts for PPPoE overhead (1500 - 8 = 1492, but 1480 adds headroom)
- ✅ **Authentication:** CHAP + MS-CHAPv2 only (no PAP - excellent security)
- ✅ **TCP MSS Clamping:** `change-tcp-mss=yes` prevents fragmentation issues
- ✅ **DNS Configuration:** Google DNS (8.8.8.8, 8.8.4.4) - reliable but consider local caching

**Areas for Improvement:**
- ⚠️ **MTU/MRU Mismatch:** 1480 is conservative; consider testing 1492 for better throughput
- ⚠️ **Compression Disabled:** `use-compression=no` - may want to enable for dial-up scenarios (rare today)
- ⚠️ **No Rate Limiting:** No default bandwidth limits in profile - relies on RADIUS for shaping

**Verdict:** Solid production-ready PPPoE configuration with minor optimization opportunities.

---

### 3. Firewall Security ⭐⭐⭐⭐ (4/5)

**Strengths:**
- ✅ **Stateful Inspection:** Proper established/related handling on all chains
- ✅ **Zone-Based Security:** Interface lists create clear security zones
- ✅ **Management Restriction:** SSH/API/Winbox limited to `10.0.0.0/8` (RFC 1918 management network)
- ✅ **PPPoE Discovery:** Rules for UDP 8863-8864 (PADT/PADI/PADR/PADO/PADS) properly placed
- ✅ **Default Deny:** Final drop rules on both chains
- ✅ **Invalid State Drop:** Prevents various state-based attacks

**Security Posture Analysis:**

| Rule | Purpose | Assessment |
|------|---------|------------|
| WireGuard allow | VPN access | ✅ Essential for management |
| Established/related | Stateful tracking | ✅ Core security |
| ICMP from PA | Diagnostic access | ⚠️ Consider rate-limiting |
| MGMT from 10/8 | Admin access | ✅ Properly restricted |
| SNMP from 10/8 | Monitoring | ✅ Restricted but plaintext |
| PPPoE discovery | Session setup | ✅ Required for function |
| Invalid drop | Attack prevention | ✅ Good practice |
| MGMT drop !10/8 | Admin protection | ✅ Explicit deny |

**Critical Findings:**

| Severity | Issue | Recommendation |
|----------|-------|----------------|
| 🔴 **HIGH** | Missing SYN flood protection | Add `connection-state=new connection-nat-state=srcnat` rate limiting |
| 🟡 **MEDIUM** | SNMP v2c (plaintext) | Upgrade to SNMP v3 with encryption |
| 🟡 **MEDIUM** | No DDoS protection | Add connection limit rules per source |
| 🟢 **LOW** | DNS not filtered | Consider firewalling DNS queries to prevent amplification |

**Verdict:** Good security foundation with enterprise-grade zoning. Add DDoS protection for best-in-class.

---

### 4. RADIUS & AAA Configuration ⭐⭐⭐⭐⭐ (5/5)

**Strengths:**
- ✅ **RADIUS Authentication:** `use-radius=yes` with local fallback (implied)
- ✅ **Interim Updates:** 5-minute accounting updates - excellent for real-time monitoring
- ✅ **Secure Transport:** RADIUS server at `10.8.0.1` reachable only via WireGuard VPN
- ✅ **Timeout Configured:** 3-second timeout prevents hanging connections
- ✅ **Service Isolation:** `service=ppp` restricts to PPP only

**Best Practice Alignment:**
- Interim updates at 5m aligns with ISP standards (RFC 2866)
- RADIUS over VPN tunnel prevents credential sniffing
- Proper timeout prevents resource exhaustion

**Verdict:** Excellent AAA configuration meeting carrier-grade standards.

---

### 5. Network Address Translation ⭐⭐⭐⭐ (4/5)

**Configuration:**
```rsc
/ip firewall nat
add action=masquerade chain=srcnat comment=PPPoE-b079c148 in-interface-list=PA-b079c148 out-interface-list=WAN
```

**Strengths:**
- ✅ **CGNAT Compliant:** Using 100.64.0.0/10 as per RFC 6598
- ✅ **Interface-List Based:** Flexible and maintainable
- ✅ **One-to-Many NAT:** Efficient for subscriber aggregation

**Considerations:**
- ⚠️ **No NAT Logging:** For abuse investigations, consider `log=yes` on NAT rules
- ⚠️ **No Port Restrictions:** Full cone NAT - consider symmetric NAT for security
- ⚠️ **Hairpin NAT Missing:** Internal services may not be reachable via external IP

**Verdict:** Functional NAT for typical ISP deployment. Add logging for best-in-class.

---

### 6. VPN & Management Security ⭐⭐⭐⭐⭐ (5/5)

**WireGuard Configuration:**
- **Port:** 51830 (non-standard, good for obscurity)
- **MTU:** 1420 (appropriate for tunnel overhead)
- **Peer:** Persistent keepalive 25s maintains NAT/firewall state
- **Route:** 10.8.0.0/24 via WireGuard (management network)

**Management Services:**
```rsc
/ip service
set ssh address=10.0.0.0/8
set api address=10.0.0.0/8
set api-ssl address=10.0.0.0/8
```

**Strengths:**
- ✅ **VPN-Only Management:** All admin access via WireGuard tunnel
- ✅ **Service Restriction:** All management APIs bound to 10.0.0.0/8
- ✅ **Modern VPN:** WireGuard over legacy IPsec/L2TP
- ✅ **Persistent Keepalive:** Maintains connection through NAT

**Verdict:** Excellent security posture for remote management.

---

### 7. Monitoring & Observability ⭐⭐⭐⭐ (4/5)

**SNMP Configuration:**
```rsc
/snmp community
add addresses=10.8.0.1/32 name=traidnet-monitor
/snmp
set contact="Network Admin" enabled=yes location="Managed by WifiCore" trap-community=traidnet-monitor
```

**Strengths:**
- ✅ **Source-Restricted:** SNMP community limited to 10.8.0.1/32 (RADIUS/management server)
- ✅ **Descriptive Metadata:** Contact and location fields populated
- ✅ **Trap Community:** Separate trap community for alerts

**Areas for Improvement:**
- ⚠️ **SNMPv2c:** Should upgrade to SNMPv3 with auth+priv
- ⚠️ **No Streaming Telemetry:** Consider adding NetFlow/IPFIX for modern observability
- ⚠️ **Missing Syslog:** No remote syslog configuration visible

**Verdict:** Functional monitoring with room for security hardening.

---

### 8. Routing & Connectivity ⭐⭐⭐⭐ (4/5)

**Strengths:**
- ✅ **Default Route:** Via DHCP client on ether1
- ✅ **VPN Route:** Static route to 10.8.0.0/24 via WireGuard
- ✅ **Connection Tracking:** TCP established timeout 1h (balanced)

**Configuration:**
```rsc
/ip route
add comment="Route to VPN server network" dst-address=10.8.0.0/24 gateway=wg-b079c148
```

**Verdict:** Clean routing with appropriate path selection.

---

## Best-in-Class Compliance Matrix

| Best Practice | Status | Notes |
|---------------|--------|-------|
| RFC 6598 CGNAT | ✅ | 100.64.0.0/10 properly used |
| BCP 38 Ingress Filtering | ⚠️ | Not implemented - add uRPF |
| PPPoE Session Isolation | ✅ | Bridge-based isolation |
| RADIUS Accounting | ✅ | 5-minute interim updates |
| Management via OOB/VPN | ✅ | WireGuard-only access |
| Stateful Firewall | ✅ | Proper connection tracking |
| Default Deny Policy | ✅ | Final drop rules present |
| SNMP v3 | ❌ | Using v2c - upgrade needed |
| DNS Security (DoH/DoT) | ❌ | Plain DNS to 8.8.8.8 |
| Rate Limiting | ⚠️ | Not configured locally |
| DDoS Protection | ❌ | No connection limits |
| Audit Logging | ❌ | No remote syslog |

---

## Recommendations Summary

### Critical Priority (Implement Immediately)

1. **Add BCP 38 Anti-Spoofing**
   ```rsc
   /ip firewall filter
   add action=drop chain=forward comment="BCP 38 - Block spoofed from PA" in-interface-list=PA-b079c148 src-address=!100.64.0.0/24
   ```

2. **Enable Connection Rate Limiting**
   ```rsc
   /ip firewall filter
   add action=drop chain=input comment="DDoS - Connection limit" connection-state=new limit=50,5 protocol=tcp
   add action=drop chain=forward comment="Per-host connection limit" connection-state=new limit=100,10 src-address-list=PA-b079c148
   ```

### High Priority (Implement Soon)

3. **Upgrade to SNMP v3**
   ```rsc
   /snmp set enabled=no
   /snmp community remove traidnet-monitor
   /snmp v3 view add name=all oid=0.0.0.0.0
   /snmp v3 group add name=admin security=usm view-read=all
   /snmp v3 user add name=monitor group=admin auth-sha1-auth-passphrase=<secure> enc-aes-enc-passphrase=<secure>
   ```

4. **Add Remote Syslog**
   ```rsc
   /system logging action add name=remote remote=10.8.0.1 src-address=0.0.0.0 target=remote
   /system logging add action=remote topics=firewall,critical,error,warning
   ```

### Medium Priority (Optimize)

5. **Consider DNS-over-TLS**
   ```rsc
   /ip dns set use-doh-server=https://cloudflare-dns.com/dns-query verify-doh-cert=yes
   ```
   (Note: May require RouterOS 7.15+)

6. **Add NAT Logging**
   ```rsc
   /ip firewall nat
   set [find comment=PPPoE-b079c148] log=yes log-prefix=PPPoE-NAT
   ```

7. **Optimize MTU**
   ```rsc
   /interface pppoe-server server
   set [find service-name=pppoe-svc-b079c148] max-mtu=1492 max-mru=1492
   ```

### Low Priority (Nice to Have)

8. **Add Interface Comments**
   - Add descriptive comments to ether1-4 for documentation

9. **Enable IPv6** (if supported by your RADIUS)
   ```rsc
   /ipv6 pool add name=pppoe-v6 prefix=2001:db8:100::/40 prefix-length=64
   /ppp profile set [find name=pppoe-prof-b079c148] remote-ipv6-prefix-pool=pppoe-v6
   ```

---

## Conclusion

This RouterOS PPPoE deployment demonstrates **solid engineering practices** and is production-ready. The configuration shows:

- ✅ Proper use of CGNAT addressing (RFC 6598)
- ✅ Secure management via WireGuard VPN
- ✅ Stateful firewall with clear zoning
- ✅ RADIUS integration with appropriate accounting
- ✅ Good separation of concerns (interface lists, bridges)

### Overall Rating: **8/10 - Best-in-Class Ready**

With the recommended additions (especially BCP 38 filtering, connection rate limiting, and SNMP v3), this deployment will meet carrier-grade standards.

The configuration follows modern ISP practices and demonstrates the operator (Traidnet Solution LTD / WifiCore) understands multi-tenant network security requirements.

---

*Report generated for RouterOS 7.22.1 PPPoE deployment profile b079c148*
