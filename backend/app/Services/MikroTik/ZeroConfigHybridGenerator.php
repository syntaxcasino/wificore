<?php

namespace App\Services\MikroTik;

use App\Models\RouterService;
use App\Services\RouterResourceManager;
use Illuminate\Support\Facades\Log;
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
        $scriptSettings = RouterResourceManager::getScriptSettings($router);
        RouterResourceManager::logResourceInfo($router);
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
            // Prefer static RADIUS IP from env/docker-compose, fallback to host name
            'radius_server' => env('VPN_SERVER_IP', env('RADIUS_SERVER_IP', env('RADIUS_SERVER_HOST', 'wificore-freeradius'))),
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
            ":do { /interface vlan remove [find name=\"vlan-hotspot-{$hotspotVlan}\"]; } on-error={}",
            ":do { /interface vlan add name=vlan-hotspot-{$hotspotVlan} vlan-id={$hotspotVlan} interface={$parentInterface} comment=\"Hotspot VLAN (Hybrid)\"; } on-error={ /log error \"Hybrid: Hotspot VLAN setup failed\"; :error \"Hybrid: Hotspot VLAN setup failed\" }",
            ":if ([:len [/interface vlan find name=\"vlan-hotspot-{$hotspotVlan}\"]] = 0) do={ /log error \"Hybrid: Hotspot VLAN missing\"; :error \"Hybrid: Hotspot VLAN missing\" }",
            "",
            "# PPPoE VLAN",
            ":do { /interface vlan remove [find name=\"vlan-pppoe-{$pppoeVlan}\"]; } on-error={}",
            ":do { /interface vlan add name=vlan-pppoe-{$pppoeVlan} vlan-id={$pppoeVlan} interface={$parentInterface} comment=\"PPPoE VLAN (Hybrid)\"; } on-error={ /log error \"Hybrid: PPPoE VLAN setup failed\"; :error \"Hybrid: PPPoE VLAN setup failed\" }",
            ":if ([:len [/interface vlan find name=\"vlan-pppoe-{$pppoeVlan}\"]] = 0) do={ /log error \"Hybrid: PPPoE VLAN missing\"; :error \"Hybrid: PPPoE VLAN missing\" }",
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
        
        $gateway = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        $cidr = explode('/', $pool->network_cidr)[1] ?? '24';
        $network = explode('/', $pool->network_cidr)[0];
        $dns = "{$pool->dns_primary},{$pool->dns_secondary}";

        $portalUrl = null;
        $portalHost = null;
        try {
            $tenant = $params['router_id'] ? (\App\Models\Router::find($params['router_id'])?->tenant) : null;
            if ($tenant) {
                $baseHost = parse_url(config('app.url'), PHP_URL_HOST);
                $portalUrl = "https://{$tenant->subdomain}.{$baseHost}/portal";
                $portalHost = parse_url($portalUrl, PHP_URL_HOST);
            }
        } catch (\Exception $e) {
            $portalUrl = null;
            $portalHost = null;
        }

        $portalUrlForHtml = $portalUrl ? str_replace(['\\', '"'], ['\\\\', '\\"'], $portalUrl) : null;

        return [
            "# Hotspot Configuration on VLAN {$vlanId}",
            "",
            "# IP Addressing",
            ":do { /ip address remove [find interface=\"{$interface}\"]; } on-error={}",
            ":do { /ip address add address={$gateway}/{$cidr} interface=\"{$interface}\" comment=\"Hotspot Gateway (VLAN {$vlanId})\"; } on-error={ /log error \"Hybrid: Hotspot IP add failed\"; :error \"Hybrid: Hotspot IP add failed\" }",
            "",
            "# IP Pool",
            ":do { /ip pool remove [find name=\"{$poolName}\"]; } on-error={}",
            ":do { /ip pool add name={$poolName} ranges={$pool->range_start}-{$pool->range_end} comment=\"Hotspot Pool (Hybrid)\"; } on-error={ /log error \"Hybrid: Hotspot pool add failed\"; :error \"Hybrid: Hotspot pool add failed\" }",
            ":if ([:len [/ip pool find name=\"{$poolName}\"]] = 0) do={ /log error \"Hybrid: Hotspot pool missing\"; :error \"Hybrid: Hotspot pool missing\" }",
            "",
            "# DHCP Server",
            ":do { /ip dhcp-server remove [find name=\"{$dhcpName}\"]; } on-error={}",
            ":do { /ip dhcp-server add name={$dhcpName} interface=\"{$interface}\" address-pool={$poolName} lease-time=1h disabled=no; } on-error={ /log error \"Hybrid: Hotspot DHCP add failed\"; :error \"Hybrid: Hotspot DHCP add failed\" }",
            ":if ([:len [/ip dhcp-server find name=\"{$dhcpName}\"]] = 0) do={ /log error \"Hybrid: Hotspot DHCP missing\"; :error \"Hybrid: Hotspot DHCP missing\" }",
            ":do { /ip dhcp-server network remove [find comment~\"Hotspot.*Hybrid.*{$routerId}\"]; } on-error={}",
            ":do { /ip dhcp-server network add address={$network}/{$cidr} gateway={$gateway} dns-server=\"{$dns}\" comment=\"Hotspot Network (Hybrid Router {$routerId})\"; } on-error={ /log error \"Hybrid: Hotspot DHCP network add failed\"; :error \"Hybrid: Hotspot DHCP network add failed\" }",
            "",
            "# Hotspot Profile",
            ":do { /ip hotspot profile remove [find name=\"{$profile}\"]; } on-error={} ",
            // Single-line hotspot profile add for SSH compatibility
            ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address={$gateway} login-by=http-chap,http-pap use-radius=yes html-directory=hotspot http-cookie-lifetime=1d dns-name=hotspot.local; } on-error={ /log error \"Hybrid: Hotspot profile add failed\"; :error \"Hybrid: Hotspot profile add failed\" }",
            ":if ([:len [/ip hotspot profile find name=\"{$profile}\"]] = 0) do={ /log error \"Hybrid: Hotspot profile missing\"; :error \"Hybrid: Hotspot profile missing\" }",
            ($portalUrlForHtml ? ":do { /file set hotspot/login.html contents=\"<html><head><meta http-equiv=refresh content=0;url={$portalUrlForHtml}></head><body>Redirecting...</body></html>\" } on-error={}" : ":do { } on-error={} "),
            "",
            "# Hotspot Server",
            ":do { /ip hotspot remove [find name=\"{$server}\"]; } on-error={} ",
            // Single-line hotspot server add for SSH compatibility
            ":do { /ip hotspot add name=\"{$server}\" interface=\"{$interface}\" profile=\"{$profile}\" address-pool={$poolName} addresses-per-mac=2 idle-timeout=5m keepalive-timeout=2m disabled=no; } on-error={ /log error \"Hybrid: Hotspot server add failed\"; :error \"Hybrid: Hotspot server add failed\" }",
            ":if ([:len [/ip hotspot find name=\"{$server}\"]] = 0) do={ /log error \"Hybrid: Hotspot server missing\"; :error \"Hybrid: Hotspot server missing\" }",
            ($portalHost ? ":do { /ip hotspot walled-garden remove [find comment=\"WiFiCore Portal\"]; /ip hotspot walled-garden add dst-host={$portalHost} action=allow comment=\"WiFiCore Portal\"; } on-error={}" : ":do { } on-error={} "),
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
        $serviceNameSuffix = $tenantId ?: $routerId;
        $serviceName = "pppoe-{$serviceNameSuffix}";
        
        $gateway = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        $dns = "{$pool->dns_primary},{$pool->dns_secondary}";

        return [
            "# PPPoE Configuration on VLAN {$vlanId}",
            "",
            "# IP Pool",
            ":do { /ip pool remove [find name=\"{$poolName}\"]; } on-error={}",
            ":do { /ip pool add name={$poolName} ranges={$pool->range_start}-{$pool->range_end} comment=\"PPPoE Pool (Hybrid)\"; } on-error={ /log error \"Hybrid: PPPoE pool add failed\"; :error \"Hybrid: PPPoE pool add failed\" }",
            ":if ([:len [/ip pool find name=\"{$poolName}\"]] = 0) do={ /log error \"Hybrid: PPPoE pool missing\"; :error \"Hybrid: PPPoE pool missing\" }",
            "",
            "# PPP Profile",
            ":do { /ppp profile remove [find name=\"{$profile}\"]; /ppp profile add name=\"{$profile}\" use-radius=yes local-address={$gateway} remote-address=\"{$poolName}\" dns-server=\"{$dns}\"; } on-error={ /log error \"Hybrid: PPP profile create failed\"; :error \"Hybrid: PPP profile create failed\" }",
            ":if ([:len [/ppp profile find name=\"{$profile}\"]] = 0) do={ /log error \"Hybrid: PPP profile missing\"; :error \"Hybrid: PPP profile missing\" }",
            "",
            "# PPPoE Server",
            ":do { /interface pppoe-server server remove [find service-name=\"{$serviceName}\"]; /interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$interface}\" default-profile=\"{$profile}\" authentication=pap,chap,mschap2 keepalive-timeout=10 max-mtu=1480 max-mru=1480 disabled=no; } on-error={ /log error \"Hybrid: PPPoE server create failed\"; :error \"Hybrid: PPPoE server create failed\" }",
            ":if ([:len [/interface pppoe-server server find service-name=\"{$serviceName}\"]] = 0) do={ /log error \"Hybrid: PPPoE server missing\"; :error \"Hybrid: PPPoE server missing\" }",
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
            ":do { /radius remove [find service=hotspot comment~\"Hybrid.*{$routerId}\"]; } on-error={}",
            // Single-line hotspot RADIUS add for SSH compatibility
            ":do { /radius add service=hotspot address={$radiusServer} secret={$radiusSecret} authentication-port=1812 accounting-port=1813 timeout=3s comment=\"Hybrid Hotspot RADIUS (Router {$routerId})\"; } on-error={ /log error \"Hybrid: Hotspot RADIUS add failed\"; :error \"Hybrid: Hotspot RADIUS add failed\" }",
            "",
            "# PPPoE RADIUS",
            ":do { /radius remove [find service=ppp comment~\"Hybrid.*{$routerId}\"]; } on-error={}",
            // Single-line PPPoE RADIUS add for SSH compatibility
            ":do { /radius add service=ppp address={$radiusServer} secret={$radiusSecret} authentication-port=1812 accounting-port=1813 timeout=3s comment=\"Hybrid PPPoE RADIUS (Router {$routerId})\"; } on-error={ /log error \"Hybrid: PPPoE RADIUS add failed\"; :error \"Hybrid: PPPoE RADIUS add failed\" }",
            "",
            "# Clear local credentials (RADIUS-ONLY)",
            // Guard removals to avoid errors on non-removable defaults
            ":do { /ip hotspot user remove [find] } on-error={}",
            ":do { /ppp secret remove [find] } on-error={}",
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
            // Remove existing Hybrid firewall rules for idempotency
            ":do { /ip firewall filter remove [find comment=\"Hybrid: Block Hotspot->PPPoE\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Hybrid: Block PPPoE->Hotspot\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Hybrid: Allow Established\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Hybrid: Hotspot to WAN\"]; } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Hybrid: PPPoE to WAN\"]; } on-error={}",
            // Re-add rules
            ":do { /ip firewall filter add chain=forward action=drop in-interface={$hotspotInterface} out-interface={$pppoeInterface} comment=\"Hybrid: Block Hotspot->PPPoE\"; } on-error={ /log error \"Hybrid: Firewall add failed (Block Hotspot->PPPoE)\"; :error \"Hybrid: Firewall add failed\" }",
            ":do { /ip firewall filter add chain=forward action=drop in-interface={$pppoeInterface} out-interface={$hotspotInterface} comment=\"Hybrid: Block PPPoE->Hotspot\"; } on-error={ /log error \"Hybrid: Firewall add failed (Block PPPoE->Hotspot)\"; :error \"Hybrid: Firewall add failed\" }",
            ":do { /ip firewall filter add chain=forward action=accept connection-state=established,related comment=\"Hybrid: Allow Established\"; } on-error={ /log error \"Hybrid: Firewall add failed (Allow Established)\"; :error \"Hybrid: Firewall add failed\" }",
            ":do { /ip firewall filter add chain=forward action=accept in-interface={$hotspotInterface} out-interface=!{$hotspotInterface} comment=\"Hybrid: Hotspot to WAN\"; } on-error={ /log error \"Hybrid: Firewall add failed (Hotspot to WAN)\"; :error \"Hybrid: Firewall add failed\" }",
            ":do { /ip firewall filter add chain=forward action=accept in-interface={$pppoeInterface} out-interface=!{$pppoeInterface} comment=\"Hybrid: PPPoE to WAN\"; } on-error={ /log error \"Hybrid: Firewall add failed (PPPoE to WAN)\"; :error \"Hybrid: Firewall add failed\" }",
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
        
        $hotspotParts = explode('/', $hotspotPool->network_cidr);
        $pppoeParts = explode('/', $pppoePool->network_cidr);
        $hotspotNetwork = $hotspotParts[0];
        $hotspotCidr = $hotspotParts[1] ?? '24';
        $pppoeNetwork = $pppoeParts[0];
        $pppoeCidr = $pppoeParts[1] ?? '24';

        return [
            "# NAT Rules - Separate NAT for Each VLAN",
            // Remove existing Hybrid NAT rules for idempotency
            ":do { /ip firewall nat remove [find comment=\"Hybrid: Hotspot NAT\"]; } on-error={}",
            ":do { /ip firewall nat remove [find comment=\"Hybrid: PPPoE NAT\"]; } on-error={}",
            // Re-add with correct CIDR
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address={$hotspotNetwork}/{$hotspotCidr} out-interface=!{$hotspotInterface} comment=\"Hybrid: Hotspot NAT\"; } on-error={ /log error \"Hybrid: NAT add failed (Hotspot NAT)\"; :error \"Hybrid: NAT add failed\" }",
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address={$pppoeNetwork}/{$pppoeCidr} out-interface=!{$pppoeInterface} comment=\"Hybrid: PPPoE NAT\"; } on-error={ /log error \"Hybrid: NAT add failed (PPPoE NAT)\"; :error \"Hybrid: NAT add failed\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface={$hotspotInterface} comment=\"Hybrid: Hotspot HTTP\"; } on-error={ /log error \"Hybrid: NAT redirect failed (HTTP)\"; :error \"Hybrid: NAT redirect failed\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface={$hotspotInterface} comment=\"Hybrid: Hotspot HTTPS\"; } on-error={ /log error \"Hybrid: NAT redirect failed (HTTPS)\"; :error \"Hybrid: NAT redirect failed\" }",
            "",
        ];
    }

    private function getSafeGatewayIp(string $networkCidr, ?string $gatewayIp): string
    {
        $parts = explode('/', $networkCidr, 2);
        $networkIp = $parts[0] ?? '';
        $cidr = (int) ($parts[1] ?? 24);

        $networkLong = ip2long($networkIp);
        if ($networkLong === false) {
            return (string) $gatewayIp;
        }

        if ($cidr < 0 || $cidr > 32) {
            $cidr = 24;
        }

        $mask = $cidr === 0 ? 0 : ((-1 << (32 - $cidr)) & 0xFFFFFFFF);
        $networkAddrLong = $networkLong & $mask;
        $broadcastLong = $networkAddrLong | (~$mask & 0xFFFFFFFF);

        $candidateLong = $gatewayIp ? ip2long($gatewayIp) : false;
        if ($candidateLong === false) {
            return long2ip($networkAddrLong + 1);
        }

        if (($candidateLong & $mask) !== $networkAddrLong || $candidateLong === $networkAddrLong || $candidateLong === $broadcastLong) {
            return long2ip($networkAddrLong + 1);
        }

        return $gatewayIp;
    }
}
