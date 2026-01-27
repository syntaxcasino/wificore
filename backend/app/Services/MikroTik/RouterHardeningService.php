<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\TenantAwareService;
use Illuminate\Support\Facades\Log;

/**
 * Router Hardening Service
 * 
 * Generates and applies ISP-grade security configurations for MikroTik routers
 * including firewall rules, service restrictions, and security best practices.
 */
class RouterHardeningService extends TenantAwareService
{
    /**
     * Generate complete hardening script for a router
     * 
     * @param Router $router
     * @param array $options
     * @return string
     */
    public function generateHardeningScript(Router $router, array $options = []): string
    {
        $vpnSubnet = $options['vpn_subnet'] ?? '10.0.0.0/8';
        $allowedSshIps = $options['allowed_ssh_ips'] ?? [$vpnSubnet];
        $maxSshSessions = $options['max_ssh_sessions'] ?? 5;
        $sshRateLimit = $options['ssh_rate_limit'] ?? 3;
        $enableApiSsl = $options['enable_api_ssl'] ?? false;
        
        $script = $this->generateScriptHeader($router);
        $script .= $this->generateServiceHardening($enableApiSsl);
        $script .= $this->generateSshRestrictions($allowedSshIps, $maxSshSessions, $sshRateLimit);
        $script .= $this->generateFirewallRules($vpnSubnet);
        $script .= $this->generateUserSecurity($router);
        $script .= $this->generateSystemSecurity();
        $script .= $this->generateLoggingConfig();
        
        return $script;
    }
    
    /**
     * Generate script header with comments
     */
    private function generateScriptHeader(Router $router): string
    {
        return <<<SCRIPT
# ============================================================
# WiFiCore ISP-Grade Security Hardening Script
# Router: {$router->name} (ID: {$router->id})
# Generated: {now()->toDateTimeString()}
# ============================================================

:log info "Starting WiFiCore security hardening"

SCRIPT;
    }
    
    /**
     * Generate service hardening configuration
     */
    private function generateServiceHardening(bool $enableApiSsl = false): string
    {
        $script = <<<'SCRIPT'
# ============================================================
# 1. SERVICE HARDENING
# Disable unnecessary services, keep only SSH and required services
# ============================================================

:log info "Hardening services"

# Disable dangerous services
/ip service set telnet disabled=yes
/ip service set ftp disabled=yes
/ip service set www disabled=yes
/ip service set api disabled=yes

SCRIPT;

        if ($enableApiSsl) {
            $script .= "/ip service set api-ssl disabled=no\n";
        } else {
            $script .= "/ip service set api-ssl disabled=yes\n";
        }
        
        $script .= <<<'SCRIPT'

# Keep SSH enabled (required for management)
/ip service set ssh disabled=no

# Keep HTTPS enabled (for Hotspot login pages)
/ip service set www-ssl disabled=no

:log info "Services hardened successfully"


SCRIPT;
        
        return $script;
    }
    
    /**
     * Generate SSH restrictions
     */
    private function generateSshRestrictions(array $allowedIps, int $maxSessions, int $rateLimit): string
    {
        $ipList = implode(',', $allowedIps);
        
        return <<<SCRIPT
# ============================================================
# 2. SSH ACCESS RESTRICTIONS
# Restrict SSH to VPN subnet only
# ============================================================

:log info "Configuring SSH restrictions"

# Restrict SSH to specific source IPs (VPN subnet)
/ip service set ssh address={$ipList}

# Change SSH port to non-standard (optional security through obscurity)
# /ip service set ssh port=2222

:log info "SSH restrictions applied"


SCRIPT;
    }
    
    /**
     * Generate comprehensive firewall rules with advanced protection
     */
    private function generateFirewallRules(string $vpnSubnet): string
    {
        return <<<SCRIPT
# ============================================================
# 3. ADVANCED FIREWALL RULES
# Comprehensive protection against attacks and intrusions
# ============================================================

:log info "Configuring advanced firewall rules"

# ============================================================
# 3.1. ADDRESS LISTS - Define network groups
# ============================================================

/ip firewall address-list
add list=vpn_subnet address={$vpnSubnet} comment="WiFiCore VPN Subnet"

# Bogon addresses (should never appear on WAN)
add list=bogons address=0.0.0.0/8 comment="RFC 1122 'this' network"
add list=bogons address=10.0.0.0/8 comment="RFC 1918 private space"
add list=bogons address=127.0.0.0/8 comment="RFC 1122 loopback"
add list=bogons address=169.254.0.0/16 comment="RFC 3927 link local"
add list=bogons address=172.16.0.0/12 comment="RFC 1918 private space"
add list=bogons address=192.0.0.0/24 comment="RFC 6890 IETF protocol"
add list=bogons address=192.0.2.0/24 comment="RFC 5737 TEST-NET-1"
add list=bogons address=192.168.0.0/16 comment="RFC 1918 private space"
add list=bogons address=198.18.0.0/15 comment="RFC 2544 benchmarking"
add list=bogons address=198.51.100.0/24 comment="RFC 5737 TEST-NET-2"
add list=bogons address=203.0.113.0/24 comment="RFC 5737 TEST-NET-3"
add list=bogons address=224.0.0.0/4 comment="RFC 5771 multicast"
add list=bogons address=240.0.0.0/4 comment="RFC 1112 reserved"

# ============================================================
# 3.2. INPUT CHAIN - Protect router itself
# ============================================================

/ip firewall filter

# Drop invalid packets immediately
add chain=input connection-state=invalid action=drop \
    comment="Drop invalid packets"

# Accept established and related connections
add chain=input connection-state=established,related action=accept \
    comment="Accept established/related"

# ============================================================
# 3.3. BRUTE FORCE PROTECTION - SSH Blacklisting
# ============================================================

# Drop already blacklisted IPs
add chain=input protocol=tcp dst-port=22 \
    src-address-list=ssh_blacklist action=drop \
    comment="Drop SSH blacklisted IPs"

# Stage 3: Blacklist after 3 failed attempts
add chain=input protocol=tcp dst-port=22 connection-state=new \
    src-address-list=ssh_stage3 \
    action=add-src-to-address-list \
    address-list=ssh_blacklist address-list-timeout=1d \
    comment="SSH: Blacklist after 3 attempts (24h)"

# Stage 2: Track second attempt
add chain=input protocol=tcp dst-port=22 connection-state=new \
    src-address-list=ssh_stage2 \
    action=add-src-to-address-list \
    address-list=ssh_stage3 address-list-timeout=1m \
    comment="SSH: Stage 3 (1 min)"

# Stage 1: Track first attempt
add chain=input protocol=tcp dst-port=22 connection-state=new \
    src-address-list=ssh_stage1 \
    action=add-src-to-address-list \
    address-list=ssh_stage2 address-list-timeout=1m \
    comment="SSH: Stage 2 (1 min)"

# Initial tracking
add chain=input protocol=tcp dst-port=22 connection-state=new \
    action=add-src-to-address-list \
    address-list=ssh_stage1 address-list-timeout=1m \
    comment="SSH: Stage 1 (1 min)"

# ============================================================
# 3.4. DDoS PROTECTION
# ============================================================

# SYN flood protection
add chain=input protocol=tcp tcp-flags=syn \
    connection-limit=30,32 action=drop \
    comment="SYN flood protection (max 30 per /32)"

# UDP flood protection
add chain=input protocol=udp \
    connection-limit=50,32 action=drop \
    comment="UDP flood protection (max 50 per /32)"

# ICMP flood protection (rate limit ping)
add chain=input protocol=icmp icmp-options=8:0 \
    limit=5,5:packet action=accept \
    comment="ICMP echo request - rate limited"

add chain=input protocol=icmp action=drop \
    comment="Drop excessive ICMP"

# ============================================================
# 3.5. PORT SCAN DETECTION
# ============================================================

# Detect port scanners
add chain=input protocol=tcp psd=21,3s,3,1 \
    action=add-src-to-address-list \
    address-list=port_scanners address-list-timeout=1d \
    comment="Port scan detection"

# Drop port scanners
add chain=input src-address-list=port_scanners action=drop \
    comment="Drop detected port scanners"

# ============================================================
# 3.6. BOGON FILTERING (WAN interface)
# ============================================================

# Drop bogon addresses from WAN
add chain=input in-interface=ether1 \
    src-address-list=bogons action=drop \
    comment="Drop bogon addresses from WAN"

# ============================================================
# 3.7. ALLOWED SERVICES
# ============================================================

# Accept SSH only from VPN with connection limit
add chain=input protocol=tcp dst-port=22 \
    src-address-list=vpn_subnet \
    connection-limit=5,32 action=accept \
    comment="Accept SSH from VPN (max 5 sessions)"

# Accept RADIUS from VPN
add chain=input protocol=udp dst-port=1812,1813 \
    src-address-list=vpn_subnet action=accept \
    comment="Accept RADIUS from VPN"

# Accept DNS queries
add chain=input protocol=udp dst-port=53 action=accept \
    comment="Accept DNS queries"

# Accept DHCP
add chain=input protocol=udp dst-port=67-68 action=accept \
    comment="Accept DHCP"

# Accept Hotspot HTTP/HTTPS
add chain=input protocol=tcp dst-port=80,443,8080,8443 action=accept \
    comment="Accept Hotspot web traffic"

# Accept WireGuard VPN
add chain=input protocol=udp dst-port=51820 action=accept \
    comment="Accept WireGuard VPN"

# ============================================================
# 3.8. DROP EVERYTHING ELSE
# ============================================================

# Log dropped packets (optional - can be verbose)
# add chain=input action=log log-prefix="FW-INPUT-DROP: " \
#     comment="Log dropped input packets"

add chain=input action=drop comment="Drop all other input"

# ============================================================
# 3.9. FORWARD CHAIN - Client traffic protection
# ============================================================

# Accept established and related
add chain=forward connection-state=established,related action=accept \
    comment="Accept established/related forwards"

# Drop invalid connections
add chain=forward connection-state=invalid action=drop \
    comment="Drop invalid connections"

# Port scan detection (FIN, XMAS, NULL scans)
add chain=forward protocol=tcp tcp-flags=fin,!ack action=drop \
    comment="Drop FIN scans"
add chain=forward protocol=tcp tcp-flags=fin,psh,urg action=drop \
    comment="Drop XMAS scans"
add chain=forward protocol=tcp tcp-flags=!fin,!syn,!rst,!ack action=drop \
    comment="Drop NULL scans"

# Drop bogon addresses in forwarded traffic
add chain=forward src-address-list=bogons action=drop \
    comment="Drop bogon source addresses"
add chain=forward dst-address-list=bogons action=drop \
    comment="Drop bogon destination addresses"

:log info "Advanced firewall rules configured"


SCRIPT;
    }
    
    /**
     * Generate user security configuration
     */
    private function generateUserSecurity(Router $router): string
    {
        $username = $router->username;
        
        return <<<SCRIPT
# ============================================================
# 4. USER SECURITY
# Configure dedicated management user with SSH key
# ============================================================

:log info "Configuring user security"

# Ensure management user exists with proper permissions
/user
:if ([:len [find name="{$username}"]] = 0) do={
    add name={$username} group=full disabled=no
    :log info "Created management user: {$username}"
} else={
    set [find name="{$username}"] group=full disabled=no
    :log info "Updated management user: {$username}"
}

# Disable default admin user (CRITICAL SECURITY)
# CAUTION: Only do this after SSH key is working!
# /user set admin disabled=yes

:log info "User security configured"


SCRIPT;
    }
    
    /**
     * Generate system security settings including DNS security
     */
    private function generateSystemSecurity(): string
    {
        return <<<'SCRIPT'
# ============================================================
# 5. SYSTEM SECURITY & DNS HARDENING
# Additional system-level security hardening
# ============================================================

:log info "Configuring system security"

# ============================================================
# 5.1. SYSTEM HARDENING
# ============================================================

# Disable bandwidth test server
/tool bandwidth-server set enabled=no

# Disable MAC server on WAN
/tool mac-server set allowed-interface-list=none
/tool mac-server mac-winbox set allowed-interface-list=none

# Disable neighbor discovery on WAN
/ip neighbor discovery-settings set discover-interface-list=!ether1

# Enable SYN flood protection
/ip settings set tcp-syncookies=yes

# Disable IP cloud (DDNS service)
/ip cloud set ddns-enabled=no update-time=no

# Secure NTP configuration
/system ntp client
set enabled=yes \
    primary-ntp=10.100.1.1 \
    secondary-ntp=time.cloudflare.com

/system ntp server
set enabled=no broadcast=no multicast=no

:log info "System security configured"

# ============================================================
# 5.2. DNS SECURITY & HARDENING
# ============================================================

:log info "Configuring DNS security"

# Configure DNS with DoH (DNS over HTTPS)
/ip dns
set allow-remote-requests=yes \
    cache-size=4096KiB \
    cache-max-ttl=1d \
    max-concurrent-queries=100 \
    max-concurrent-tcp-sessions=20 \
    servers=1.1.1.1,1.0.0.1 \
    use-doh-server=https://cloudflare-dns.com/dns-query \
    verify-doh-cert=yes

# DNS firewall rules for security
/ip firewall filter

# Block external DNS queries to router
add chain=input protocol=udp dst-port=53 \
    src-address-list=!vpn_subnet \
    in-interface=ether1 action=drop \
    comment="Block external DNS queries"

# Force clients to use router DNS (prevent DNS hijacking)
add chain=forward protocol=udp dst-port=53 \
    dst-address-list=!vpn_subnet action=drop \
    comment="Force clients to use router DNS"

add chain=forward protocol=tcp dst-port=53 \
    dst-address-list=!vpn_subnet action=drop \
    comment="Force clients to use router DNS (TCP)"

# DNS cache poisoning protection (rate limit queries)
add chain=input protocol=udp dst-port=53 \
    connection-limit=50,32 action=drop \
    comment="DNS query rate limit"

:log info "DNS security configured"

# ============================================================
# 5.3. AUTOMATED BACKUPS
# ============================================================

# Create backup script
/system script
:if ([:len [find name="daily-backup"]] = 0) do={
    add name=daily-backup source={
        :log info "Starting daily backup"
        /system backup save name=daily-backup
        :delay 5s
        :log info "Daily backup completed"
    }
}

# Schedule daily backups at 3:00 AM
/system scheduler
:if ([:len [find name="daily-backup-schedule"]] = 0) do={
    add name=daily-backup-schedule \
        interval=1d \
        on-event=daily-backup \
        start-time=03:00:00 \
        comment="Daily automated backup"
}

:log info "Automated backups configured"


SCRIPT;
    }
    
    /**
     * Generate comprehensive monitoring and logging configuration
     */
    private function generateLoggingConfig(): string
    {
        return <<<'SCRIPT'
# ============================================================
# 6. MONITORING & LOGGING CONFIGURATION
# Comprehensive monitoring, logging, and alerting
# ============================================================

:log info "Configuring monitoring and logging"

# ============================================================
# 6.1. REMOTE SYSLOG - Centralized log collection
# ============================================================

# Create remote syslog action
/system logging action
:if ([:len [find name="remote-syslog"]] = 0) do={
    add name=remote-syslog remote=10.100.1.1 remote-port=514 \
        src-address=auto target=remote \
        comment="WiFiCore Centralized Logging"
}

# Configure logging topics to remote syslog
/system logging
add topics=critical action=remote-syslog prefix="CRITICAL:"
add topics=error action=remote-syslog prefix="ERROR:"
add topics=warning action=remote-syslog prefix="WARNING:"
add topics=firewall,info action=remote-syslog prefix="FW:"
add topics=account,info action=remote-syslog prefix="AUTH:"
add topics=system,info action=remote-syslog prefix="SYS:"
add topics=ssh action=remote-syslog prefix="SSH:"

# Keep local logging for quick access
add topics=ssh action=memory
add topics=firewall action=memory
add topics=account action=memory
add topics=critical action=memory

:log info "Remote syslog configured"

# ============================================================
# 6.2. SNMP - Network monitoring
# ============================================================

# Enable SNMP for monitoring
/snmp
set enabled=yes \
    contact="admin@wificore.local" \
    location="Managed by WiFiCore" \
    trap-community=public \
    trap-version=2

# Configure SNMP community (restrict to management network)
/snmp community
set [find name=public] addresses=10.100.0.0/16 \
    comment="WiFiCore Management Network"

:log info "SNMP monitoring enabled"

# ============================================================
# 6.3. EMAIL ALERTS - Proactive notifications
# ============================================================

# Configure email server (update with actual SMTP settings)
/tool e-mail
set server=smtp.wificore.local \
    port=587 \
    from=router-alerts@wificore.local \
    user=alerts@wificore.local \
    password="" \
    tls=yes

# Note: Email alerts can be triggered via scripts
# Example: /tool e-mail send to="admin@wificore.local" subject="Alert" body="Message"

:log info "Email alerts configured"

# ============================================================
# 6.4. TRAFFIC ACCOUNTING - Usage tracking
# ============================================================

# Enable IP accounting for traffic statistics
/ip accounting
set enabled=yes \
    threshold=2560 \
    account-local-traffic=no

# Web proxy accounting (if using web proxy)
/ip accounting web-access
set accessible-via-web=yes

:log info "Traffic accounting enabled"

# ============================================================
# 6.5. NETFLOW/IPFIX - Advanced traffic analysis (optional)
# ============================================================

# Configure NetFlow export (optional - uncomment if needed)
# /ip traffic-flow
# set enabled=yes \
#     interfaces=all \
#     cache-entries=4k \
#     active-flow-timeout=30m \
#     inactive-flow-timeout=15s

# /ip traffic-flow target
# add dst-address=10.100.1.1:2055 version=9

:log info "Monitoring and logging configured"

# ============================================================
# HARDENING COMPLETE
# ============================================================

:log info "WiFiCore security hardening completed successfully"

SCRIPT;
    }
    
    /**
     * Apply hardening script to router
     * 
     * @param Router $router
     * @param array $options
     * @return array
     */
    public function applyHardening(Router $router, array $options = []): array
    {
        $startTime = microtime(true);
        
        Log::info('Applying security hardening to router', [
            'router_id' => $router->id,
            'router_name' => $router->name
        ]);
        
        try {
            // Generate hardening script
            $script = $this->generateHardeningScript($router, $options);
            
            // Connect via SSH
            $ssh = new SshExecutor($router, 60);
            $ssh->connect();
            
            // Upload script
            $scriptName = "hardening_{$router->id}_" . time() . ".rsc";
            $tempFile = tempnam(sys_get_temp_dir(), 'hardening_');
            file_put_contents($tempFile, $script);
            
            $ssh->uploadFile($tempFile, $scriptName);
            unlink($tempFile);
            
            // Execute script
            $result = $ssh->importFile($scriptName);
            
            // Cleanup
            $ssh->deleteFile($scriptName);
            $ssh->disconnect();
            
            $response = [
                'success' => true,
                'router_id' => $router->id,
                'script_name' => $scriptName,
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'result_preview' => substr($result, 0, 500)
            ];
            
            Log::info('Security hardening applied successfully', $response);
            
            return $response;
            
        } catch (\Exception $e) {
            Log::error('Failed to apply security hardening', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Security hardening failed: ' . $e->getMessage(), 500, $e);
        }
    }
    
    /**
     * Generate hardening script and save to database
     * 
     * @param Router $router
     * @param array $options
     * @return string
     */
    public function generateAndSave(Router $router, array $options = []): string
    {
        $script = $this->generateHardeningScript($router, $options);
        
        // Save to router_configs table
        \App\Models\RouterConfig::updateOrCreate(
            [
                'router_id' => $router->id,
                'config_type' => 'hardening'
            ],
            [
                'config_content' => $script
            ]
        );
        
        Log::info('Security hardening script generated and saved', [
            'router_id' => $router->id,
            'script_length' => strlen($script)
        ]);
        
        return $script;
    }
}
