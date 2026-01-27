<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\ScriptBuilder;
use App\Services\InterfaceManagementService;
use App\Models\Router;

/**
 * MikroTik Hotspot Service
 *
 * Generates a best-practice production Hotspot configuration
 * with external captive portal, RADIUS integration, and secure defaults.
 */
class HotspotService extends BaseMikroTikService
{
    protected ?InterfaceManagementService $interfaceManager = null;

    /**
     * Set interface manager for validation (optional)
     */
    public function setInterfaceManager(InterfaceManagementService $manager): void
    {
        $this->interfaceManager = $manager;
    }
    /**
     * Simple escape for RouterOS values - only escape quotes and backslashes
     */
    protected function escapeRouterOSString(string $string): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $string);
    }
    
    /**
     * Generate complete MikroTik Hotspot configuration
     */
    public function generateConfig(array $interfaces, string $routerId, array $options = []): string
    {
        if (empty($interfaces)) {
            throw new \Exception('At least one interface is required for hotspot setup.');
        }

        // NEW: Validate interfaces if interface manager is set
        if ($this->interfaceManager) {
            $router = Router::find($routerId);
            if ($router) {
                $validation = $this->interfaceManager->validateInterfaceAssignment(
                    $router,
                    'hotspot',
                    $interfaces
                );
                
                if (!$validation['valid']) {
                    throw new \Exception('Interface validation failed: ' . implode(', ', $validation['errors']));
                }
            }
        }

        // === Default Settings - Clean values for RSC ===
        $bridge      = $options['bridge_name'] ?? "br-hotspot-$routerId";
        $network     = $options['network'] ?? '192.168.88.0/24';
        $gateway     = $options['gateway'] ?? '192.168.88.1';
        $pool        = $options['ip_pool'] ?? '192.168.88.10-192.168.88.254';
        $dns         = $options['dns_servers'] ?? '8.8.8.8,1.1.1.1';
        
        // Resolve RADIUS hostname to IP address for MikroTik compatibility
        $radiusHost  = $options['radius_ip'] ?? env('VPN_SERVER_IP', env('RADIUS_SERVER_HOST', 'traidnet-freeradius'));
        $radiusIP    = gethostbyname($radiusHost);
        // If resolution fails, gethostbyname returns the hostname, so check and use fallback
        if ($radiusIP === $radiusHost && filter_var($radiusHost, FILTER_VALIDATE_IP) === false) {
            // Fallback to common Docker network IP or use hostname as-is
            $radiusIP = $radiusHost; // MikroTik will try to resolve it
            \Log::warning('RADIUS hostname resolution failed, using hostname', [
                'hostname' => $radiusHost,
                'router_id' => $routerId
            ]);
        }
        
        $radiusSecret= $options['radius_secret'] ?? env('RADIUS_SECRET', 'testing123');
        $portalURL   = $options['portal_url'] ?? 'https://wificore.traidsolutions.com/hotspot/login';
        $rateLimit   = $options['rate_limit'] ?? '10M/10M';
        $profile     = "hs-profile-$routerId";
        $server      = "hs-server-$routerId";
        $poolName    = "pool-hotspot-$routerId";
        $dhcpName    = "dhcp-hotspot-$routerId";

        $portalHost = null;
        try {
            $portalHost = parse_url($portalURL, PHP_URL_HOST);
        } catch (\Exception $e) {
            $portalHost = null;
        }

        $portalUrlForHtml = $this->escapeRouterOSString($portalURL);

        // === Clean RSC Script Generation ===
        $script = [
            "/log info \"=== Starting Hotspot Setup on Router $routerId ===\"",
            "",
            "# Bridge Setup - NON-DESTRUCTIVE (update existing or create new)",
            "# Create bridge if it doesn't exist (will fail silently if exists)",
            ":do { /interface bridge add name=$bridge comment=\"Hotspot Bridge\" } on-error={}"
        ];

        // Add bridge ports (skip if already added)
        foreach ($interfaces as $iface) {
            $script[] = ":do { /interface bridge port add bridge=$bridge interface=$iface comment=\"Hotspot Interface\" } on-error={}";
        }

        $script = array_merge($script, [
            "",
            "# IP Addressing & Pool",
            "/ip address remove [find interface=$bridge]",
            "/ip address add address=$gateway/24 interface=$bridge comment=\"Hotspot Gateway\"",
            "/ip pool remove [find name=$poolName]",
            "/ip pool add name=$poolName ranges=$pool comment=\"Hotspot IP Pool\"",
            "",
            "# DHCP Setup - Production Grade",
            "/ip dhcp-server remove [find name=$dhcpName]",
            "/ip dhcp-server add name=$dhcpName interface=$bridge address-pool=$poolName lease-time=1h disabled=no",
            "/ip dhcp-server network remove [find gateway=$gateway]",
            "/ip dhcp-server network add address=$network gateway=$gateway dns-server=\"$dns\"",
            "/ip dhcp-server network set [find address=$network] comment=\"Hotspot Network\"",
            "/ip dhcp-server network set [find address=$network] ntp-server=$gateway",
            "",
            "# Hotspot Profile - Secure Configuration",
            "/ip hotspot profile remove [find name=$profile]",
            "/ip hotspot profile add name=$profile hotspot-address=$gateway",
            "/ip hotspot profile set $profile login-by=http-chap,mac-cookie,http-pap",
            "/ip hotspot profile set $profile use-radius=yes",
            "/ip hotspot profile set $profile html-directory=hotspot",
            "/ip hotspot profile set $profile http-cookie-lifetime=1d",
            "/ip hotspot profile set $profile rate-limit=$rateLimit",
            "/ip hotspot profile set $profile dns-name=hotspot.local",
            "/ip hotspot profile set $profile http-proxy=0.0.0.0:0",
            "/ip hotspot profile set $profile smtp-server=0.0.0.0",
            "/ip hotspot profile set $profile split-user-domain=no",
            "",
            ":do { /file set hotspot/login.html contents=\"<html><head><meta http-equiv=refresh content=0;url=$portalUrlForHtml></head><body>Redirecting...</body></html>\" } on-error={}",
            "",
            "# Hotspot Server",
            "/ip hotspot remove [find name=$server]",
            "/ip hotspot add name=$server interface=$bridge profile=$profile address-pool=$poolName disabled=no",
            "/ip hotspot set $server addresses-per-mac=2",
            "/ip hotspot set $server idle-timeout=5m",
            "/ip hotspot set $server keepalive-timeout=2m",
            "",
            "# User Profile - Default Settings",
            "/ip hotspot user profile remove [find name=\"default-hotspot\"]",
            "/ip hotspot user profile add name=default-hotspot",
            "/ip hotspot user profile set default-hotspot add-mac-cookie=yes",
            "/ip hotspot user profile set default-hotspot rate-limit=$rateLimit",
            "/ip hotspot user profile set default-hotspot idle-timeout=5m",
            "/ip hotspot user profile set default-hotspot keepalive-timeout=2m",
            "/ip hotspot user profile set default-hotspot status-autorefresh=1m",
            "/ip hotspot user profile set default-hotspot shared-users=1",
            "",
            "# RADIUS Configuration - Production Settings",
            "/radius remove [find service=hotspot]",
            "/radius add address=$radiusIP service=hotspot secret=$radiusSecret",
            "/radius set [find service=hotspot] authentication-port=1812",
            "/radius set [find service=hotspot] accounting-port=1813",
            "/radius set [find service=hotspot] timeout=3s",
            "/radius set [find service=hotspot] src-address=0.0.0.0",
            "/ip hotspot profile set $profile use-radius=yes",
            "",
            "# Walled Garden - Configured via API after deployment (script import has issues with walled garden)",
            ":do { /ip hotspot walled-garden remove [find comment=\"WiFiCore Portal\"]; } on-error={}",
            ($portalHost ? "/ip hotspot walled-garden add dst-host=$portalHost action=allow comment=\"WiFiCore Portal\"" : ":do { } on-error={}"),
            "",
            "# Firewall Filter Rules - Security Best Practices",
            ":do { /ip firewall filter remove [find comment=\"Allow Established/Related\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Drop Invalid\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Limit TCP Connections per IP\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Drop Port Scanners\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Limit ICMP\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Allow Hotspot to WAN\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Allow DHCP\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Allow Hotspot HTTP/HTTPS\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Allow RADIUS\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Drop Other Hotspot Input\"]; } on-error={}",
            "/ip firewall filter add chain=forward action=accept connection-state=established,related comment=\"Allow Established/Related\"",
            "/ip firewall filter add chain=forward action=drop connection-state=invalid comment=\"Drop Invalid\"",
            "/ip firewall filter add chain=forward action=drop protocol=tcp tcp-flags=syn connection-limit=20,32 comment=\"Limit TCP Connections per IP\"",
            "/ip firewall filter add chain=input action=drop protocol=tcp psd=21,3s,3,1 comment=\"Drop Port Scanners\"",
            "/ip firewall filter add chain=input action=accept protocol=icmp limit=5,5:packet comment=\"Limit ICMP\"",
            "/ip firewall filter add chain=forward action=accept in-interface=$bridge out-interface=!$bridge comment=\"Allow Hotspot to WAN\"",
            "/ip firewall filter add chain=input action=accept protocol=udp dst-port=67-68 in-interface=$bridge comment=\"Allow DHCP\"",
            "/ip firewall filter add chain=input action=accept protocol=tcp dst-port=64872,64875 in-interface=$bridge comment=\"Allow Hotspot HTTP/HTTPS\"",
            "/ip firewall filter add chain=input action=accept protocol=udp dst-port=1812-1813 comment=\"Allow RADIUS\"",
            "/ip firewall filter add chain=input action=drop in-interface=$bridge comment=\"Drop Other Hotspot Input\"",
            "",
            "# NAT Rules - Production Configuration",
            "/ip firewall nat remove [find comment~\"Hotspot\"]",
            "/ip firewall nat add chain=srcnat action=masquerade src-address=$network out-interface=ether1 comment=\"Hotspot Internet Access\"",
            "# Fallback NAT for any interface except bridge",
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address=$network out-interface=!$bridge comment=\"Hotspot NAT Fallback\" } on-error={}",
            "/ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface=$bridge",
            "/ip firewall nat set [find to-ports=64872] comment=\"HTTP to Hotspot\"",
            "/ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface=$bridge",
            "/ip firewall nat set [find to-ports=64875] comment=\"HTTPS to Hotspot\"",
            "",
            "# DNS Configuration - Secure Setup",
            "/ip dns set servers=\"$dns\"",
            "/ip dns set allow-remote-requests=yes",
            "/ip dns set cache-size=2048KiB",
            "/ip dns set cache-max-ttl=1d",
            "",
            "# IP Services - Maximum Security Configuration",
            "/ip service set telnet disabled=yes",
            "/ip service set www disabled=yes",
            "/ip service set api disabled=no address=192.168.56.0/24",
            "/ip service set api-ssl disabled=yes",
            "/ip service set ssh disabled=no address=192.168.56.0/24",
            "/ip service set winbox disabled=no address=192.168.56.0/24",
            "# Note: FTP is managed dynamically by deployment system (enabled during upload, disabled after)",
            "",
            "# Logging Configuration - Security Audit Trail",
            ":do { /system logging action remove [find name=remote-syslog]; } on-error={}",
            ":do { /system logging action add name=remote-syslog target=remote remote=192.168.56.1:514 } on-error={} ",
            ":do { /system logging add topics=hotspot,info action=remote-syslog } on-error={}",
            ":do { /system logging add topics=hotspot,warning action=remote-syslog } on-error={}",
            ":do { /system logging add topics=hotspot,error action=remote-syslog } on-error={}",
            ":do { /system logging add topics=radius,info action=remote-syslog } on-error={}",
            ":do { /system logging add topics=firewall,info action=remote-syslog } on-error={}",
        ]);

        $script[] = "/log info \"=== Hotspot Setup Completed Successfully ===\"";

        // Join with actual line breaks for RouterOS - no escaping needed
        return implode("\n", $script);
    }
}
