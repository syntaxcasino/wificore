<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Models\RouterService;
use App\Models\TenantIpPool;

/**
 * Zero-Config Hybrid (Hotspot + PPPoE) Configuration Generator
 * VLAN-Enforced Traffic Separation | RADIUS-Only AAA | Tenant-Scoped IPAM
 */
class ZeroConfigHybridGenerator
{
    protected ZeroConfigHotspotGenerator $hotspotGenerator;
    protected ZeroConfigPPPoEGenerator $pppoeGenerator;

    public function __construct()
    {
        $this->hotspotGenerator = new ZeroConfigHotspotGenerator();
        $this->pppoeGenerator = new ZeroConfigPPPoEGenerator();
    }

    /**
     * Generate Hybrid configuration from RouterService
     */
    public function generate(RouterService $service): string
    {
        $router = $service->router;
        $advancedConfig = $service->advanced_config ?? [];
        
        // Get VLANs from service_vlans table
        $vlans = $service->vlans;
        $hotspotVlan = $vlans->where('service_type', 'hotspot')->first();
        $pppoeVlan = $vlans->where('service_type', 'pppoe')->first();
        
        if (!$hotspotVlan || !$pppoeVlan) {
            throw new \Exception('Hybrid service requires both hotspot and pppoe VLANs');
        }

        // Get IP pools
        $hotspotPool = TenantIpPool::find($advancedConfig['hotspot_pool_id']);
        $pppoePool = TenantIpPool::find($advancedConfig['pppoe_pool_id']);
        
        if (!$hotspotPool || !$pppoePool) {
            throw new \Exception('Hybrid service requires both hotspot and pppoe IP pools');
        }

        $config = $this->buildConfiguration([
            'router_id' => $router->id,
            'parent_interface' => $service->interface_name,
            'hotspot_vlan_id' => $hotspotVlan->vlan_id,
            'pppoe_vlan_id' => $pppoeVlan->vlan_id,
            'hotspot_pool' => $hotspotPool,
            'pppoe_pool' => $pppoePool,
            'radius_server' => $router->vpn_ip,
            'radius_secret' => env('RADIUS_SECRET', 'testing123'),
            'tenant_id' => $router->tenant_id,
        ]);

        return $config;
    }

    /**
     * Build complete Hybrid configuration
     */
    private function buildConfiguration(array $params): string
    {
        $script = [];
        
        $script[] = "/log info \"=== Zero-Config Hybrid Deployment (VLAN-Enforced) ===\"";
        $script[] = "/log info \"Router: {$params['router_id']}\"";
        $script[] = "/log info \"Parent Interface: {$params['parent_interface']}\"";
        $script[] = "/log info \"Hotspot VLAN: {$params['hotspot_vlan_id']}\"";
        $script[] = "/log info \"PPPoE VLAN: {$params['pppoe_vlan_id']}\"";
        $script[] = "";

        // VLAN Setup - Traffic Separation
        $script = array_merge($script, $this->generateVlanSetup($params));
        
        // Hotspot Configuration on VLAN
        $script = array_merge($script, $this->generateHotspotConfig($params));
        
        // PPPoE Configuration on VLAN
        $script = array_merge($script, $this->generatePppoeConfig($params));
        
        // RADIUS Configuration
        $script = array_merge($script, $this->generateRadiusSetup($params));
        
        // Firewall Rules - VLAN Separation Enforcement
        $script = array_merge($script, $this->generateFirewallRules($params));
        
        // NAT Rules
        $script = array_merge($script, $this->generateNatRules($params));

        $script[] = "";
        $script[] = "/log info \"=== Hybrid Deployment Complete - Traffic Separated by VLAN ===\"";

        return implode("\n", $script);
    }

    /**
     * Generate VLAN setup for traffic separation
     */
    private function generateVlanSetup(array $params): array
    {
        $parentInterface = $params['parent_interface'];
        $hotspotVlan = $params['hotspot_vlan_id'];
        $pppoeVlan = $params['pppoe_vlan_id'];

        return [
            "# VLAN Setup - Mandatory Traffic Separation",
            "# Hotspot and PPPoE CANNOT share the same physical interface without VLANs",
            "",
            "# Hotspot VLAN",
            ":do {",
            "  /interface vlan remove [find name=\"vlan-hotspot-{$hotspotVlan}\"]",
            "} on-error={}",
            "/interface vlan add name=vlan-hotspot-{$hotspotVlan} vlan-id={$hotspotVlan} interface={$parentInterface} comment=\"Hotspot VLAN (Hybrid)\"",
            "",
            "# PPPoE VLAN",
            ":do {",
            "  /interface vlan remove [find name=\"vlan-pppoe-{$pppoeVlan}\"]",
            "} on-error={}",
            "/interface vlan add name=vlan-pppoe-{$pppoeVlan} vlan-id={$pppoeVlan} interface={$parentInterface} comment=\"PPPoE VLAN (Hybrid)\"",
            "",
        ];
    }

    /**
     * Generate Hotspot configuration on VLAN
     */
    private function generateHotspotConfig(array $params): array
    {
        $pool = $params['hotspot_pool'];
        $vlanId = $params['hotspot_vlan_id'];
        $interface = "vlan-hotspot-{$vlanId}";
        $routerId = $params['router_id'];
        
        $poolName = "pool-hotspot-{$routerId}";
        $dhcpName = "dhcp-hotspot-{$routerId}";
        $profile = "hs-profile-{$routerId}";
        $server = "hs-server-{$routerId}";
        
        $gateway = $pool->gateway_ip;
        $cidr = explode('/', $pool->network_cidr)[1] ?? '24';
        $network = explode('/', $pool->network_cidr)[0];
        $dns = "{$pool->dns_primary},{$pool->dns_secondary}";

        return [
            "# Hotspot Configuration on VLAN {$vlanId}",
            "",
            "# IP Addressing",
            "/ip address remove [find interface=\"{$interface}\"]",
            "/ip address add address={$gateway}/{$cidr} interface={$interface} comment=\"Hotspot Gateway (VLAN {$vlanId})\"",
            "",
            "# IP Pool",
            "/ip pool remove [find name=\"{$poolName}\"]",
            "/ip pool add name={$poolName} ranges={$pool->range_start}-{$pool->range_end} comment=\"Hotspot Pool (Hybrid)\"",
            "",
            "# DHCP Server",
            "/ip dhcp-server remove [find name=\"{$dhcpName}\"]",
            "/ip dhcp-server add name={$dhcpName} interface={$interface} address-pool={$poolName} lease-time=1h disabled=no",
            "/ip dhcp-server network remove [find comment~\"Hotspot.*Hybrid.*{$routerId}\"]",
            "/ip dhcp-server network add address={$network}/{$cidr} gateway={$gateway} dns-server=\"{$dns}\" comment=\"Hotspot Network (Hybrid Router {$routerId})\"",
            "",
            "# Hotspot Profile",
            "/ip hotspot profile remove [find name=\"{$profile}\"]",
            "/ip hotspot profile add name={$profile} \\",
            "  hotspot-address={$gateway} \\",
            "  login-by=http-chap,http-pap \\",
            "  use-radius=yes \\",
            "  html-directory=hotspot \\",
            "  http-cookie-lifetime=1d \\",
            "  dns-name=hotspot.local",
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
        ];
    }

    /**
     * Generate PPPoE configuration on VLAN
     */
    private function generatePppoeConfig(array $params): array
    {
        $pool = $params['pppoe_pool'];
        $vlanId = $params['pppoe_vlan_id'];
        $interface = "vlan-pppoe-{$vlanId}";
        $routerId = $params['router_id'];
        $tenantId = $params['tenant_id'];
        
        $poolName = "pool-pppoe-{$routerId}";
        $profile = "pppoe-profile-{$routerId}";
        $serviceName = "pppoe-{$tenantId}";
        
        $gateway = $pool->gateway_ip;
        $dns = "{$pool->dns_primary},{$pool->dns_secondary}";

        return [
            "# PPPoE Configuration on VLAN {$vlanId}",
            "",
            "# IP Pool",
            "/ip pool remove [find name=\"{$poolName}\"]",
            "/ip pool add name={$poolName} ranges={$pool->range_start}-{$pool->range_end} comment=\"PPPoE Pool (Hybrid)\"",
            "",
            "# PPP Profile",
            "/ppp profile remove [find name=\"{$profile}\"]",
            "/ppp profile add name={$profile} \\",
            "  use-radius=yes \\",
            "  local-address={$gateway} \\",
            "  remote-address={$poolName} \\",
            "  dns-server=\"{$dns}\" \\",
            "  only-one=yes \\",
            "  change-tcp-mss=yes",
            "",
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
            "  disabled=no",
            "",
        ];
    }

    /**
     * Generate RADIUS configuration
     */
    private function generateRadiusSetup(array $params): array
    {
        $radiusServer = $params['radius_server'];
        $radiusSecret = $params['radius_secret'];
        $routerId = $params['router_id'];

        return [
            "# RADIUS Configuration - RADIUS-ONLY AAA for Both Services",
            "",
            "# Hotspot RADIUS",
            "/radius remove [find service=hotspot comment~\"Hybrid.*{$routerId}\"]",
            "/radius add \\",
            "  service=hotspot \\",
            "  address={$radiusServer} \\",
            "  secret={$radiusSecret} \\",
            "  authentication-port=1812 \\",
            "  accounting-port=1813 \\",
            "  timeout=3s \\",
            "  comment=\"Hybrid Hotspot RADIUS (Router {$routerId})\"",
            "",
            "# PPPoE RADIUS",
            "/radius remove [find service=ppp comment~\"Hybrid.*{$routerId}\"]",
            "/radius add \\",
            "  service=ppp \\",
            "  address={$radiusServer} \\",
            "  secret={$radiusSecret} \\",
            "  authentication-port=1812 \\",
            "  accounting-port=1813 \\",
            "  timeout=3s \\",
            "  comment=\"Hybrid PPPoE RADIUS (Router {$routerId})\"",
            "",
            "# Clear local credentials (RADIUS-ONLY)",
            "/ip hotspot user remove [find]",
            "/ppp secret remove [find]",
            "",
        ];
    }

    /**
     * Generate firewall rules - VLAN separation enforcement
     */
    private function generateFirewallRules(array $params): array
    {
        $hotspotVlan = $params['hotspot_vlan_id'];
        $pppoeVlan = $params['pppoe_vlan_id'];
        $hotspotInterface = "vlan-hotspot-{$hotspotVlan}";
        $pppoeInterface = "vlan-pppoe-{$pppoeVlan}";

        return [
            "# Firewall Rules - VLAN Separation Enforcement",
            "# ❌ NO traffic allowed between Hotspot and PPPoE VLANs",
            "# ✅ Both VLANs can access WAN independently",
            "",
            "/ip firewall filter add chain=forward action=drop in-interface={$hotspotInterface} out-interface={$pppoeInterface} comment=\"Hybrid: Block Hotspot->PPPoE\"",
            "/ip firewall filter add chain=forward action=drop in-interface={$pppoeInterface} out-interface={$hotspotInterface} comment=\"Hybrid: Block PPPoE->Hotspot\"",
            "/ip firewall filter add chain=forward action=accept connection-state=established,related comment=\"Hybrid: Allow Established\"",
            "/ip firewall filter add chain=forward action=accept in-interface={$hotspotInterface} out-interface=!{$hotspotInterface} comment=\"Hybrid: Hotspot to WAN\"",
            "/ip firewall filter add chain=forward action=accept in-interface={$pppoeInterface} out-interface=!{$pppoeInterface} comment=\"Hybrid: PPPoE to WAN\"",
            "",
        ];
    }

    /**
     * Generate NAT rules
     */
    private function generateNatRules(array $params): array
    {
        $hotspotPool = $params['hotspot_pool'];
        $pppoePool = $params['pppoe_pool'];
        $hotspotVlan = $params['hotspot_vlan_id'];
        $pppoeVlan = $params['pppoe_vlan_id'];
        $hotspotInterface = "vlan-hotspot-{$hotspotVlan}";
        $pppoeInterface = "vlan-pppoe-{$pppoeVlan}";
        
        $hotspotNetwork = explode('/', $hotspotPool->network_cidr)[0];
        $pppoeNetwork = explode('/', $pppoePool->network_cidr)[0];

        return [
            "# NAT Rules - Separate NAT for Each VLAN",
            "/ip firewall nat add chain=srcnat action=masquerade src-address={$hotspotNetwork}/24 out-interface=!{$hotspotInterface} comment=\"Hybrid: Hotspot NAT\"",
            "/ip firewall nat add chain=srcnat action=masquerade src-address={$pppoeNetwork}/24 out-interface=!{$pppoeInterface} comment=\"Hybrid: PPPoE NAT\"",
            "/ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface={$hotspotInterface} comment=\"Hybrid: Hotspot HTTP\"",
            "/ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface={$hotspotInterface} comment=\"Hybrid: Hotspot HTTPS\"",
            "",
        ];
    }
}
