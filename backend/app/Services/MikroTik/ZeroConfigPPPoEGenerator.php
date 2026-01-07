<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Models\RouterService;
use App\Models\TenantIpPool;

/**
 * Zero-Config PPPoE Configuration Generator
 * RADIUS-Only AAA | Tenant-Scoped IPAM | Production-Grade Security
 */
class ZeroConfigPPPoEGenerator
{
    /**
     * Generate PPPoE configuration from RouterService
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
            $actualInterface = "vlan-pppoe-{$vlanId}";
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
     * Build complete PPPoE configuration
     */
    private function buildConfiguration(array $params): string
    {
        $script = [];
        
        $script[] = "/log info \"=== Zero-Config PPPoE Deployment ===\"";
        $script[] = "/log info \"Router: {$params['router_id']}\"";
        $script[] = "/log info \"Interface: {$params['interface']}\"";
        $script[] = "";

        // VLAN Setup (if required)
        if ($params['vlan_required'] && $params['vlan_id']) {
            $script = array_merge($script, $this->generateVlanSetup($params));
        }

        // IP Pool
        $script = array_merge($script, $this->generatePoolSetup($params));
        
        // PPP Profile
        $script = array_merge($script, $this->generatePppProfile($params));
        
        // PPPoE Server
        $script = array_merge($script, $this->generatePppoeServer($params));
        
        // RADIUS Configuration (RADIUS-ONLY AAA)
        $script = array_merge($script, $this->generateRadiusSetup($params));
        
        // Firewall Rules
        $script = array_merge($script, $this->generateFirewallRules($params));
        
        // NAT Rules
        $script = array_merge($script, $this->generateNatRules($params));

        $script[] = "";
        $script[] = "/log info \"=== PPPoE Deployment Complete ===\"";

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
            "# VLAN Setup for PPPoE",
            ":do {",
            "  /interface vlan remove [find name=\"{$vlanInterface}\"]",
            "} on-error={}",
            "/interface vlan add name={$vlanInterface} vlan-id={$vlanId} interface={$parentInterface} comment=\"PPPoE VLAN\"",
            "",
        ];
    }

    /**
     * Generate IP pool
     */
    private function generatePoolSetup(array $params): array
    {
        $poolName = "pool-pppoe-{$params['router_id']}";
        $rangeStart = $params['range_start'];
        $rangeEnd = $params['range_end'];

        return [
            "# IP Pool - Tenant Scoped",
            "/ip pool remove [find name=\"{$poolName}\"]",
            "/ip pool add name={$poolName} ranges={$rangeStart}-{$rangeEnd} comment=\"PPPoE Pool (Tenant {$params['tenant_id']})\"",
            "",
        ];
    }

    /**
     * Generate PPP profile
     */
    private function generatePppProfile(array $params): array
    {
        $profile = "pppoe-profile-{$params['router_id']}";
        $poolName = "pool-pppoe-{$params['router_id']}";
        $gateway = $params['gateway_ip'];
        $dns = "{$params['dns_primary']},{$params['dns_secondary']}";

        return [
            "# PPP Profile - RADIUS-Only AAA",
            "/ppp profile remove [find name=\"{$profile}\"]",
            "/ppp profile add name={$profile} \\",
            "  use-radius=yes \\",
            "  local-address={$gateway} \\",
            "  remote-address={$poolName} \\",
            "  dns-server=\"{$dns}\" \\",
            "  only-one=yes \\",
            "  change-tcp-mss=yes \\",
            "  use-mpls=no \\",
            "  use-compression=no \\",
            "  use-encryption=no",
            "",
        ];
    }

    /**
     * Generate PPPoE server
     */
    private function generatePppoeServer(array $params): array
    {
        $serviceName = "pppoe-{$params['tenant_id']}";
        $profile = "pppoe-profile-{$params['router_id']}";
        $interface = $params['interface'];

        return [
            "# PPPoE Server",
            "/interface pppoe-server server remove [find service-name=\"{$serviceName}\"]",
            "/interface pppoe-server server add \\",
            "  service-name={$serviceName} \\",
            "  interface={$interface} \\",
            "  default-profile={$profile} \\",
            "  authentication=pap,chap,mschap2 \\",
            "  keepalive-timeout=10 \\",
            "  max-mtu=1480 \\",
            "  max-mru=1480 \\",
            "  mrru=disabled \\",
            "  disabled=no",
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

        return [
            "# RADIUS Configuration - RADIUS-ONLY AAA",
            "# ❌ Local secrets DISABLED",
            "# ✅ RADIUS required for ALL authentication",
            "",
            "/radius remove [find service=ppp comment~\"PPPoE.*{$params['router_id']}\"]",
            "/radius add \\",
            "  service=ppp \\",
            "  address={$radiusServer} \\",
            "  secret={$radiusSecret} \\",
            "  authentication-port=1812 \\",
            "  accounting-port=1813 \\",
            "  timeout=3s \\",
            "  comment=\"PPPoE RADIUS (Router {$params['router_id']})\"",
            "",
            "# Clear any local secrets (RADIUS-ONLY)",
            "/ppp secret remove [find]",
            "",
        ];
    }

    /**
     * Generate firewall rules
     */
    private function generateFirewallRules(array $params): array
    {
        return [
            "# Firewall Rules - PPPoE Security",
            "/ip firewall filter add chain=forward action=accept connection-state=established,related comment=\"PPPoE: Allow Established\" place-before=0",
            "/ip firewall filter add chain=forward action=drop connection-state=invalid comment=\"PPPoE: Drop Invalid\"",
            "/ip firewall filter add chain=input action=accept protocol=tcp dst-port=1723 comment=\"PPPoE: Allow PPTP Control\"",
            "/ip firewall filter add chain=input action=accept protocol=gre comment=\"PPPoE: Allow GRE\"",
            "",
        ];
    }

    /**
     * Generate NAT rules
     */
    private function generateNatRules(array $params): array
    {
        $network = explode('/', $params['network_cidr'])[0];

        return [
            "# NAT Rules - PPPoE",
            "/ip firewall nat add chain=srcnat action=masquerade src-address={$network}/24 comment=\"PPPoE: Internet Access (Router {$params['router_id']})\"",
            "",
        ];
    }
}
