<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Models\RouterService;
use App\Models\TenantIpPool;

/**
 * Zero-Config Hotspot Configuration Generator
 * RADIUS-Only AAA | Tenant-Scoped IPAM | Production-Grade Security
 */
class ZeroConfigHotspotGenerator
{
    /**
     * Generate Hotspot configuration from RouterService
     */
    public function generate(RouterService $service): string
    {
        $router = $service->router;
        $pool = $service->ipPool;
        
        if (!$pool) {
            throw new \Exception('IP pool not assigned to service');
        }

        $interface = $service->interface_name;
        $vlanId = $service->vlan_id;
        
        // If VLAN is required, use VLAN interface
        if ($service->vlan_required && $vlanId) {
            $actualInterface = "vlan-hotspot-{$vlanId}";
        } else {
            $actualInterface = $interface;
        }

        $config = $this->buildConfiguration([
            'router_id' => $router->id,
            'interface' => $actualInterface,
            'parent_interface' => $interface,
            'vlan_id' => $vlanId,
            'vlan_required' => $service->vlan_required,
            'network_cidr' => $pool->network_cidr,
            'gateway_ip' => $pool->gateway_ip,
            'range_start' => $pool->range_start,
            'range_end' => $pool->range_end,
            'dns_primary' => $pool->dns_primary ?? '8.8.8.8',
            'dns_secondary' => $pool->dns_secondary ?? '8.8.4.4',
            'radius_profile' => $service->radius_profile,
            'radius_server' => $router->vpn_ip, // Use VPN gateway for RADIUS
            'radius_secret' => env('RADIUS_SECRET', 'testing123'),
            'tenant_id' => $router->tenant_id,
        ]);

        return $config;
    }

    /**
     * Build complete Hotspot configuration
     */
    private function buildConfiguration(array $params): string
    {
        $script = [];
        
        $script[] = "/log info \"=== Zero-Config Hotspot Deployment ===\"";
        $script[] = "/log info \"Router: {$params['router_id']}\"";
        $script[] = "/log info \"Interface: {$params['interface']}\"";
        $script[] = "";

        // VLAN Setup (if required)
        if ($params['vlan_required'] && $params['vlan_id']) {
            $script = array_merge($script, $this->generateVlanSetup($params));
        }

        // IP Addressing
        $script = array_merge($script, $this->generateIpSetup($params));
        
        // IP Pool
        $script = array_merge($script, $this->generatePoolSetup($params));
        
        // DHCP Server
        $script = array_merge($script, $this->generateDhcpSetup($params));
        
        // Hotspot Profile & Server
        $script = array_merge($script, $this->generateHotspotSetup($params));
        
        // RADIUS Configuration (RADIUS-ONLY AAA)
        $script = array_merge($script, $this->generateRadiusSetup($params));
        
        // Firewall Rules
        $script = array_merge($script, $this->generateFirewallRules($params));
        
        // NAT Rules
        $script = array_merge($script, $this->generateNatRules($params));

        $script[] = "";
        $script[] = "/log info \"=== Hotspot Deployment Complete ===\"";

        return implode("\n", $script);
    }

    /**
     * Generate VLAN setup (for hybrid services)
     */
    private function generateVlanSetup(array $params): array
    {
        $vlanInterface = $params['interface'];
        $parentInterface = $params['parent_interface'];
        $vlanId = $params['vlan_id'];

        return [
            "# VLAN Setup for Hotspot",
            ":do {",
            "  /interface vlan remove [find name=\"{$vlanInterface}\"]",
            "} on-error={}",
            "/interface vlan add name={$vlanInterface} vlan-id={$vlanId} interface={$parentInterface} comment=\"Hotspot VLAN\"",
            "",
        ];
    }

    /**
     * Generate IP addressing
     */
    private function generateIpSetup(array $params): array
    {
        $interface = $params['interface'];
        $gateway = $params['gateway_ip'];
        $cidr = explode('/', $params['network_cidr'])[1] ?? '24';

        return [
            "# IP Addressing",
            "/ip address remove [find interface=\"{$interface}\"]",
            "/ip address add address={$gateway}/{$cidr} interface={$interface} comment=\"Hotspot Gateway\"",
            "",
        ];
    }

    /**
     * Generate IP pool
     */
    private function generatePoolSetup(array $params): array
    {
        $poolName = "pool-hotspot-{$params['router_id']}";
        $rangeStart = $params['range_start'];
        $rangeEnd = $params['range_end'];

        return [
            "# IP Pool - Tenant Scoped",
            "/ip pool remove [find name=\"{$poolName}\"]",
            "/ip pool add name={$poolName} ranges={$rangeStart}-{$rangeEnd} comment=\"Hotspot Pool (Tenant {$params['tenant_id']})\"",
            "",
        ];
    }

    /**
     * Generate DHCP server
     */
    private function generateDhcpSetup(array $params): array
    {
        $dhcpName = "dhcp-hotspot-{$params['router_id']}";
        $poolName = "pool-hotspot-{$params['router_id']}";
        $interface = $params['interface'];
        $network = explode('/', $params['network_cidr'])[0];
        $gateway = $params['gateway_ip'];
        $dns = "{$params['dns_primary']},{$params['dns_secondary']}";

        return [
            "# DHCP Server",
            "/ip dhcp-server remove [find name=\"{$dhcpName}\"]",
            "/ip dhcp-server add name={$dhcpName} interface={$interface} address-pool={$poolName} lease-time=1h disabled=no",
            "/ip dhcp-server network remove [find comment~\"Hotspot.*{$params['router_id']}\"]",
            "/ip dhcp-server network add address={$network}/24 gateway={$gateway} dns-server=\"{$dns}\" comment=\"Hotspot Network (Router {$params['router_id']})\"",
            "",
        ];
    }

    /**
     * Generate Hotspot profile and server
     */
    private function generateHotspotSetup(array $params): array
    {
        $profile = "hs-profile-{$params['router_id']}";
        $server = "hs-server-{$params['router_id']}";
        $poolName = "pool-hotspot-{$params['router_id']}";
        $interface = $params['interface'];
        $gateway = $params['gateway_ip'];

        return [
            "# Hotspot Profile - RADIUS-Only AAA",
            "/ip hotspot profile remove [find name=\"{$profile}\"]",
            "/ip hotspot profile add name={$profile} \\",
            "  hotspot-address={$gateway} \\",
            "  login-by=http-chap,http-pap \\",
            "  use-radius=yes \\",
            "  html-directory=hotspot \\",
            "  http-cookie-lifetime=1d \\",
            "  dns-name=hotspot.local \\",
            "  split-user-domain=no",
            "",
            "# Hotspot Server",
            "/ip hotspot remove [find name=\"{$server}\"]",
            "/ip hotspot add name={$server} \\",
            "  interface={$interface} \\",
            "  profile={$profile} \\",
            "  address-pool={$poolName} \\",
            "  addresses-per-mac=2 \\",
            "  idle-timeout=5m \\",
            "  keepalive-timeout=2m \\",
            "  disabled=no",
            "",
            "# User Profile - Default",
            "/ip hotspot user profile remove [find name=\"default-hotspot-{$params['router_id']}\"]",
            "/ip hotspot user profile add name=default-hotspot-{$params['router_id']} \\",
            "  add-mac-cookie=yes \\",
            "  idle-timeout=5m \\",
            "  keepalive-timeout=2m \\",
            "  status-autorefresh=1m \\",
            "  shared-users=1",
            "",
        ];
    }

    /**
     * Generate RADIUS configuration (RADIUS-ONLY AAA)
     */
    private function generateRadiusSetup(array $params): array
    {
        $radiusServer = $params['radius_server'];
        $radiusSecret = $params['radius_secret'];
        $profile = "hs-profile-{$params['router_id']}";

        return [
            "# RADIUS Configuration - RADIUS-ONLY AAA",
            "# ❌ Local users DISABLED",
            "# ❌ Local secrets DISABLED",
            "# ✅ RADIUS required for ALL authentication",
            "",
            "/radius remove [find service=hotspot comment~\"Hotspot.*{$params['router_id']}\"]",
            "/radius add \\",
            "  service=hotspot \\",
            "  address={$radiusServer} \\",
            "  secret={$radiusSecret} \\",
            "  authentication-port=1812 \\",
            "  accounting-port=1813 \\",
            "  timeout=3s \\",
            "  comment=\"Hotspot RADIUS (Router {$params['router_id']})\"",
            "",
            "# Enforce RADIUS-only authentication",
            "/ip hotspot profile set {$profile} use-radius=yes",
            "",
            "# Clear any local users (RADIUS-ONLY)",
            "/ip hotspot user remove [find]",
            "",
        ];
    }

    /**
     * Generate firewall rules
     */
    private function generateFirewallRules(array $params): array
    {
        $interface = $params['interface'];
        $network = $params['network_cidr'];

        return [
            "# Firewall Rules - Security Best Practices",
            "/ip firewall filter add chain=forward action=accept connection-state=established,related comment=\"Hotspot: Allow Established\" place-before=0",
            "/ip firewall filter add chain=forward action=drop connection-state=invalid comment=\"Hotspot: Drop Invalid\"",
            "/ip firewall filter add chain=forward action=accept in-interface={$interface} out-interface=!{$interface} comment=\"Hotspot: Allow to WAN\"",
            "/ip firewall filter add chain=input action=accept protocol=udp dst-port=67-68 in-interface={$interface} comment=\"Hotspot: Allow DHCP\"",
            "/ip firewall filter add chain=input action=accept protocol=tcp dst-port=64872,64875 in-interface={$interface} comment=\"Hotspot: Allow Portal\"",
            "",
        ];
    }

    /**
     * Generate NAT rules
     */
    private function generateNatRules(array $params): array
    {
        $interface = $params['interface'];
        $network = explode('/', $params['network_cidr'])[0];

        return [
            "# NAT Rules",
            "/ip firewall nat add chain=srcnat action=masquerade src-address={$network}/24 out-interface=!{$interface} comment=\"Hotspot: Internet Access (Router {$params['router_id']})\"",
            "/ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface={$interface} comment=\"Hotspot: HTTP Redirect\"",
            "/ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface={$interface} comment=\"Hotspot: HTTPS Redirect\"",
            "",
        ];
    }
}
