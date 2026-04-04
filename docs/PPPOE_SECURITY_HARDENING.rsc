# RouterOS Security Hardening Implementation
# Profile: b079c148
# Generated: 2026-04-04

# ============================================================
# 1. BCP 38 ANTI-SPOOFING (Ingress Filtering)
# ============================================================

# Block traffic with source addresses outside the assigned PPPoE pool
# This prevents customers from spoofing IP addresses
/ip firewall filter
add action=drop chain=forward comment="BCP 38 - Drop spoofed src from PA (not in 100.64.0.0/24)" in-interface-list=PA-b079c148 src-address=!100.64.0.0/24 log=yes log-prefix=BCP38-SPOOF

# Additional check: Drop private RFC1918 sources coming from WAN (ingress filtering)
add action=drop chain=input comment="BCP 38 - Drop private src from WAN" in-interface-list=WAN src-address=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,100.64.0.0/10 log=yes log-prefix=BCP38-WAN

# Drop packets with invalid source addresses (martians)
add action=drop chain=forward comment="BCP 38 - Drop martian sources" src-address=0.0.0.0/8,127.0.0.0/8,169.254.0.0/16,192.0.2.0/24,198.51.100.0/24,203.0.113.0/24,240.0.0.0/4

# ============================================================
# 2. DDoS / CONNECTION RATE LIMITING
# ============================================================

# Create address list for tracking connection attempts
/ip firewall address-list
add list=ddos-whitelist address=10.0.0.0/8 comment="Management network whitelist"

# Rate limit new TCP connections per source (SYN flood protection)
/ip firewall filter
add action=accept chain=input comment="DDoS - Whitelist management" src-address-list=ddos-whitelist
add action=drop chain=input comment="DDoS - SYN flood protection (50/sec limit)" connection-state=new limit=50,5 protocol=tcp log=yes log-prefix=DDoS-SYN

# Connection count limit per source IP (prevent connection exhaustion)
add action=drop chain=forward comment="DDoS - Max 100 connections per host" connection-state=new connection-limit=100,32 src-address-list=PA-b079c148 log=yes log-prefix=DDoS-CONN-LIMIT

# UDP flood protection (DNS amplification, etc)
add action=drop chain=input comment="DDoS - UDP flood protection (100/sec limit)" connection-state=new limit=100,5 protocol=udp log=yes log-prefix=DDoS-UDP

# ICMP flood protection (ping flood)
add action=drop chain=input comment="DDoS - ICMP flood protection (20/sec limit)" connection-state=new limit=20,5 protocol=icmp log=yes log-prefix=DDoS-ICMP

# Per-PPPoE user connection tracking (more granular)
add action=drop chain=forward comment="DDoS - Max 200 conns per PPPoE user" connection-state=new connection-limit=200,32 in-interface-list=PA-b079c148 log=yes log-prefix=DDoS-PPPoE-LIMIT

# ============================================================
# 3. PPP ACTIVE SESSION DISPLAY ENHANCEMENTS
# ============================================================

# Enable PPP session accounting and tracking
/ppp aaa
set accounting=yes interim-update=5m use-radius=yes

# Ensure PPP sessions are logged for visibility
/system logging
add action=memory topics=ppp
add action=memory topics=pppoe

# Enable connection tracking for PPPoE interfaces
/ip firewall connection tracking
set enabled=yes tcp-established-timeout=1h udp-stream-timeout=5m icmp-timeout=30s

# Add logging for PPPoE session events
/ip firewall filter
add action=log chain=input comment="PPPoE - Log new sessions" connection-state=new in-interface-list=PA-b079c148 log-prefix=PPPoE-NEW passthrough=yes
add action=log chain=forward comment="PPPoE - Log forwarded traffic" connection-state=new in-interface-list=PA-b079c148 log-prefix=PPPoE-FWD passthrough=yes

# ============================================================
# 4. SNMP EXTENSION FOR PPP SESSION MONITORING
# ============================================================

# Enable SNMP to expose PPP active session data
/snmp
set enabled=yes location="Managed by WifiCore - PPPoE b079c148" contact="Network Admin" \
    trap-community=traidnet-monitor \
    trap-generators=interfaces,start-trap,snmp-trap

# Add SNMP community for PPP monitoring (read-only, restricted)
/snmp community
add addresses=10.8.0.1/32 name=pppoe-monitor read-access=yes write-access=no

# ============================================================
# 5. SCHEDULER FOR ACTIVE SESSION REPORTING (Optional)
# ============================================================

# Create a script to log active PPP sessions periodically
/system script
add name=ppp-session-monitor source="/ppp active print count-only; /ppp active print detail; /log info \"PPPoE Active Sessions monitored\""

# Schedule the script to run every 5 minutes
/system scheduler
add interval=5m name=ppp-monitor on-event=ppp-session-monitor start-date=jan/01/2026 start-time=00:00:00

# ============================================================
# 6. VERIFICATION COMMANDS
# ============================================================

# After applying, verify with:
# /ppp active print - Shows connected users
# /ppp active print detail - Shows detailed session info
# /ip firewall filter print - Verify rules are in place
# /ip firewall connection print - Check connection tracking
# /log print where topics~"ppp" - View PPP logs

# ============================================================
# DEPLOYMENT ORDER (Apply in this sequence)
# ============================================================

# 1. First, add the firewall rules in order (they stack)
# 2. Then configure logging
# 3. Finally enable SNMP enhancements

# NOTE: The PPP active session display works automatically in RouterOS
# when users connect. The /ppp active menu shows:
# - Caller ID (username)
# - Service (pppoe)
# - Interface (dynamic interface name)
# - Uptime
# - Address (assigned IP)
# - Bytes transferred
# 
# To view: Run '/ppp active print' in terminal
# Or use WinBox/WebFig: PPP -> Active Connections
