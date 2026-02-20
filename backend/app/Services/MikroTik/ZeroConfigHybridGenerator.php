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
        $bridgeMode = (bool) ($advancedConfig['bridge_mode'] ?? $advancedConfig['no_vlan'] ?? false);

        $portalUrl = null;
        $portalHost = null;
        try {
            $tenant = $router->tenant ?? null;
            if ($tenant && $tenant->subdomain) {
                $baseHost = config('app.base_domain', parse_url(config('app.url'), PHP_URL_HOST));
                $portalUrl = "https://{$tenant->subdomain}.{$baseHost}/api/portal/config?router_id={$router->id}";
                $portalHost = "{$tenant->subdomain}.{$baseHost}";
            }
        } catch (\Exception $e) {
            $portalUrl = null;
            $portalHost = null;
        }
        
        // Get IP pools
        $hotspotPool = TenantIpPool::withoutGlobalScopes()->find($advancedConfig['hotspot_pool_id']);
        $pppoePool = TenantIpPool::withoutGlobalScopes()->find($advancedConfig['pppoe_pool_id']);
        
        if (!$hotspotPool || !$pppoePool) {
            throw new \Exception('Hybrid service requires both hotspot and pppoe IP pools');
        }

        if ($bridgeMode) {
            $interfaces = $this->normalizeInterfaces($service->interfaces, $service->interface_name);

            return $this->buildBridgeConfiguration([
                'router_id' => $router->id,
                'interfaces' => $interfaces,
                'hotspot_pool' => $hotspotPool,
                'pppoe_pool' => $pppoePool,
                'portal_url' => $portalUrl,
                'portal_host' => $portalHost,
                'bridge_name' => $advancedConfig['bridge_name'] ?? null,
                // Prefer static RADIUS IP from env/docker-compose, fallback to host name
                'radius_server' => config('radius.server_ip', config('services.radius.host', 'wificore-freeradius')),
                'radius_secret' => config('radius.secret', 'testing123'),
                'management_subnet' => config('vpn.subnet.base', '10.0.0.0/8'),
                'tenant_id' => $router->tenant_id,
            ]);
        }

        // Get VLANs from service_vlans table
        $vlans = $service->vlans;
        $hotspotVlan = $vlans->where('service_type', 'hotspot')->first();
        $pppoeVlan = $vlans->where('service_type', 'pppoe')->first();
        
        if (!$hotspotVlan || !$pppoeVlan) {
            throw new \Exception('Hybrid service requires both hotspot and pppoe VLANs');
        }

        $config = $this->buildConfiguration([
            'router_id' => $router->id,
            'parent_interface' => $service->interface_name,
            'hotspot_vlan_id' => $hotspotVlan->vlan_id,
            'pppoe_vlan_id' => $pppoeVlan->vlan_id,
            'hotspot_pool' => $hotspotPool,
            'pppoe_pool' => $pppoePool,
            // Prefer static RADIUS IP from env/docker-compose, fallback to host name
            'radius_server' => config('radius.server_ip', config('services.radius.host', 'wificore-freeradius')),
            'radius_secret' => config('radius.secret', 'testing123'),
            'management_subnet' => config('vpn.subnet.base', '10.0.0.0/8'),
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

        // Management Input Rules
        $script = array_merge($script, $this->generateManagementInputRules($params));
        
        // Firewall Rules - VLAN Separation Enforcement
        $script = array_merge($script, $this->generateFirewallRules($params));
        $script = array_merge($script, $this->generateGlobalDefaultDropRules());
        
        // NAT Rules
        $script = array_merge($script, $this->generateNatRules($params));

        $script[] = "";
        $script[] = "/log info \"=== Hybrid Deployment Complete - Traffic Separated by VLAN ===\"";

        return implode("\n", $script);
    }

    /**
     * Build Hybrid configuration in bridge mode (no VLAN)
     */
    private function buildBridgeConfiguration(array $params): string
    {
        $id = substr(str_replace('-', '', $params['router_id']), 0, 8);
        $bridge = $params['bridge_name'] ?: "hybrid-br-{$id}";
        $params = array_merge($params, [
            'id' => $id,
            'bridge' => $bridge,
            'pppoe_active_list' => "PPPOE-ACTIVE-HYB-{$id}",
            'wan_list' => 'WAN',
        ]);

        $script = [];
        $script[] = "/log info \"=== Zero-Config Hybrid Deployment (Bridge Mode) ===\"";
        $script[] = "/log info \"Router: {$params['router_id']}\"";
        $script[] = "/log info \"Bridge: {$bridge}\"";
        $script[] = "";

        $script = array_merge($script, $this->generateBridgeSetup($params));
        $script = array_merge($script, $this->generateBridgeHotspotConfig($params));
        $script = array_merge($script, $this->generateBridgePppoeConfig($params));
        $script = array_merge($script, $this->generateRadiusSetup($params));
        $script = array_merge($script, $this->generateManagementInputRules($params));
        $script = array_merge($script, $this->generateBridgeFirewallRules($params));
        $script = array_merge($script, $this->generateGlobalDefaultDropRules());
        $script = array_merge($script, $this->generateBridgeNatRules($params));

        $script[] = "";
        $script[] = "/log info \"=== Hybrid Deployment Complete - Bridge Mode ===\"";

        return implode("\n", $script);
    }

    /**
     * Normalize interfaces from array, JSON, or comma-separated formats.
     */
    private function normalizeInterfaces($rawInterfaces, ?string $fallback): array
    {
        if (is_array($rawInterfaces)) {
            $interfaces = $rawInterfaces;
        } elseif (is_string($rawInterfaces)) {
            $decoded = json_decode($rawInterfaces, true);
            if (is_array($decoded)) {
                $interfaces = [];
                foreach ($decoded as $item) {
                    if (is_string($item)) {
                        $nestedDecoded = json_decode($item, true);
                        if (is_array($nestedDecoded)) {
                            $interfaces = array_merge($interfaces, $nestedDecoded);
                        } elseif (!empty(trim($item)) && $item[0] !== '[') {
                            $interfaces[] = $item;
                        }
                    }
                }
            } else {
                $interfaces = array_map('trim', explode(',', $rawInterfaces));
            }
        } else {
            $interfaces = [];
        }

        $interfaces = array_values(array_unique(array_filter($interfaces, function ($iface) {
            return is_string($iface) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $iface);
        })));

        if (empty($interfaces) && $fallback) {
            $interfaces = [$fallback];
        }

        return $interfaces;
    }

    private function generateBridgeSetup(array $params): array
    {
        $bridge = $params['bridge'];
        $routerId = $params['router_id'];
        $interfaces = $params['interfaces'];

        $script = [
            "# Interface Lists",
            ":if ([:len [/interface list find name=\"LAN\"]] = 0) do={ /interface list add name=LAN comment=\"Local Area Network\" }",
            ":if ([:len [/interface list find name=\"WAN\"]] = 0) do={ /interface list add name=WAN comment=\"Wide Area Network\" }",
            ":if ([:len [/interface list member find list=WAN interface=ether1]] = 0) do={ /interface list member add list=WAN interface=ether1 }",
            "",
            "# Bridge Setup",
            ":do { :if ([:len [/interface bridge find name=\"{$bridge}\"]] = 0) do={ /interface bridge add name=\"{$bridge}\" protocol-mode=rstp comment=\"Hybrid-{$routerId}\" } } on-error={ /log error \"Hybrid: bridge add failed\"; :error \"Hybrid: bridge add failed\" }",
        ];

        foreach ($interfaces as $iface) {
            $script[] = ":do { :if ([:len [/interface bridge port find bridge=\"{$bridge}\" interface=\"{$iface}\"]] = 0) do={ /interface bridge port add bridge=\"{$bridge}\" interface=\"{$iface}\" comment=\"Hybrid-{$routerId}\" } } on-error={}";
            $script[] = ":do { :if ([:len [/interface list member find list=LAN interface=\"{$iface}\"]] = 0) do={ /interface list member add list=LAN interface=\"{$iface}\" } } on-error={}";
        }

        $script[] = "";

        return $script;
    }

    private function generateBridgeHotspotConfig(array $params): array
    {
        $pool = $params['hotspot_pool'];
        $bridge = $params['bridge'];
        $routerId = $params['router_id'];
        $id = $params['id'];

        $poolName = "hyb-hs-pool-{$id}";
        $dhcpName = "hyb-hs-dhcp-{$id}";
        $profile = "hyb-hs-prof-{$id}";
        $server = "hyb-hs-server-{$id}";

        $gateway = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        $cidr = explode('/', $pool->network_cidr)[1] ?? '24';
        $network = explode('/', $pool->network_cidr)[0];
        $dns = "{$pool->dns_primary},{$pool->dns_secondary}";

        $portalUrlForHtml = $params['portal_url']
            ? str_replace(['\\', '"'], ['\\\\', '\\"'], $params['portal_url'])
            : null;

        return array_values(array_filter([
            "# Hotspot Configuration (Bridge Mode)",
            "",
            "# IP Addressing",
            ":do { :if ([:len [/ip address find interface=\"{$bridge}\" address~\"^{$gateway}/\"]] = 0) do={ /ip address add address={$gateway}/{$cidr} interface=\"{$bridge}\" comment=\"Hybrid Hotspot Gateway\" } } on-error={ /log error \"Hybrid: Hotspot IP add failed\"; :error \"Hybrid: Hotspot IP add failed\" }",
            "",
            "# IP Pool",
            ":do { /ip pool remove [find name=\"{$poolName}\"]; } on-error={} ",
            ":do { /ip pool add name=\"{$poolName}\" ranges={$pool->range_start}-{$pool->range_end} comment=\"Hybrid Hotspot Pool\"; } on-error={ /log error \"Hybrid: Hotspot pool add failed\"; :error \"Hybrid: Hotspot pool add failed\" }",
            "",
            "# DHCP Server",
            ":do { /ip dhcp-server remove [find name=\"{$dhcpName}\"]; } on-error={} ",
            ":do { /ip dhcp-server add name=\"{$dhcpName}\" interface=\"{$bridge}\" address-pool=\"{$poolName}\" lease-time=1h disabled=no; } on-error={ /log error \"Hybrid: Hotspot DHCP add failed\"; :error \"Hybrid: Hotspot DHCP add failed\" }",
            ":do { /ip dhcp-server network remove [find comment~\"Hybrid Hotspot Network {$routerId}\"]; } on-error={} ",
            ":do { /ip dhcp-server network add address={$network}/{$cidr} gateway={$gateway} dns-server=\"{$dns}\" comment=\"Hybrid Hotspot Network {$routerId}\"; } on-error={ /log error \"Hybrid: Hotspot DHCP network add failed\"; :error \"Hybrid: Hotspot DHCP network add failed\" }",
            "",
            "# Hotspot Profile",
            ":do { /ip hotspot profile remove [find name=\"{$profile}\"]; } on-error={} ",
            ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address={$gateway} login-by=http-chap,http-pap use-radius=yes html-directory=hotspot http-cookie-lifetime=1d dns-name=hotspot.local; } on-error={ /log error \"Hybrid: Hotspot profile add failed\"; :error \"Hybrid: Hotspot profile add failed\" }",
            $portalUrlForHtml ? ":do { /file set hotspot/login.html contents=\"<html><head><meta http-equiv='refresh' content='0;url={$portalUrlForHtml}'></head><body>Redirecting to portal...</body></html>\" } on-error={}" : null,
            "",
            "# Hotspot Server",
            ":do { /ip hotspot remove [find name=\"{$server}\"]; } on-error={} ",
            ":do { /ip hotspot add name=\"{$server}\" interface=\"{$bridge}\" profile=\"{$profile}\" address-pool=\"{$poolName}\" addresses-per-mac=2 idle-timeout=5m keepalive-timeout=2m disabled=no; } on-error={ /log error \"Hybrid: Hotspot server add failed\"; :error \"Hybrid: Hotspot server add failed\" }",
            $params['portal_host'] ? ":do { /ip hotspot walled-garden remove [find comment=\"WiFiCore Portal (Hybrid Bridge)\"]; /ip hotspot walled-garden add dst-host={$params['portal_host']} action=allow comment=\"WiFiCore Portal (Hybrid Bridge)\"; } on-error={}" : null,
            "",
        ], fn ($line) => $line !== null && $line !== ''));
    }

    private function generateBridgePppoeConfig(array $params): array
    {
        $pool = $params['pppoe_pool'];
        $bridge = $params['bridge'];
        $routerId = $params['router_id'];
        $tenantId = $params['tenant_id'];
        $id = $params['id'];

        $poolName = "hyb-pppoe-pool-{$id}";
        $profile = "hyb-pppoe-prof-{$id}";
        $serviceNameSuffix = $tenantId ?: $routerId;
        $serviceName = "pppoe-hyb-{$serviceNameSuffix}";
        $gateway = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        $dns = "{$pool->dns_primary},{$pool->dns_secondary}";

        return [
            "# PPPoE Configuration (Bridge Mode)",
            "",
            "# PPPOE-ACTIVE interface list: authenticated dynamic <pppoe-*> interfaces auto-join",
            ":do { :if ([:len [/interface list find name={$params['pppoe_active_list']}]] = 0) do={ /interface list add name={$params['pppoe_active_list']} } } on-error={}",
            "",
            "# IP Pool",
            ":do { /ip pool remove [find name=\"{$poolName}\"]; } on-error={} ",
            ":do { /ip pool add name=\"{$poolName}\" ranges={$pool->range_start}-{$pool->range_end} comment=\"Hybrid PPPoE Pool\"; } on-error={ /log error \"Hybrid: PPPoE pool add failed\"; :error \"Hybrid: PPPoE pool add failed\" }",
            "",
            "# PPP Profile - interface-list assigns dynamic PPPoE interfaces to PPPOE-ACTIVE on auth",
            ":do { /ppp profile remove [find name=\"{$profile}\"]; /ppp profile add name=\"{$profile}\" use-radius=yes local-address={$gateway} remote-address=\"{$poolName}\" dns-server=\"{$dns}\" interface-list={$params['pppoe_active_list']}; } on-error={ /log error \"Hybrid: PPP profile create failed\"; :error \"Hybrid: PPP profile create failed\" }",
            ":do { /ppp profile set [find name=\"{$profile}\"] interface-list={$params['pppoe_active_list']} } on-error={}",
            "",
            "# PPPoE Server",
            ":do { /interface pppoe-server server remove [find service-name=\"{$serviceName}\"]; /interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$bridge}\" default-profile=\"{$profile}\" authentication=pap,chap,mschap2 keepalive-timeout=10 max-mtu=1480 max-mru=1480 disabled=no; } on-error={ /log error \"Hybrid: PPPoE server create failed\"; :error \"Hybrid: PPPoE server create failed\" }",
            "",
        ];
    }

    private function generateManagementInputRules(array $params): array
    {
        $routerId = $params['router_id'];
        $managementSubnet = $params['management_subnet'] ?? '10.0.0.0/8';
        $managementPorts = '22,8291,8728,8729';

        return [
            "# Management Access - VPN/Management Subnet Only",
            ":do { /ip firewall filter remove [find comment~\"Hybrid-{$routerId}-MGMT\"]; } on-error={}",
            // Insert in reverse order with place-before=0 for correct ordering at the top
            ":do { /ip firewall filter add chain=input protocol=tcp dst-port={$managementPorts} action=drop place-before=0 comment=\"Hybrid-{$routerId}-MGMT-DROP\" } on-error={}",
            ":do { /ip firewall filter add chain=input protocol=tcp dst-port={$managementPorts} src-address={$managementSubnet} action=accept place-before=0 comment=\"Hybrid-{$routerId}-MGMT-ALLOW\" } on-error={}",
            ":do { /ip firewall filter add chain=input connection-state=established,related action=accept place-before=0 comment=\"Hybrid-{$routerId}-MGMT-EST\" } on-error={}",
            "",
        ];
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

        // CAPTIVE PORTAL: Generate tenant-specific portal URL for Hybrid mode
        // Format: https://<tenant_slug>.wificore.traidsolutions.com/api/portal/config
        $portalUrl = null;
        $portalHost = null;
        try {
            $tenant = $params['router_id'] ? (\App\Models\Router::find($params['router_id'])?->tenant) : null;
            if ($tenant && $tenant->subdomain) {
                $baseHost = config('app.base_domain', parse_url(config('app.url'), PHP_URL_HOST));
                $portalUrl = "https://{$tenant->subdomain}.{$baseHost}/api/portal/config?router_id={$params['router_id']}";
                $portalHost = "{$tenant->subdomain}.{$baseHost}";
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
            // CAPTIVE PORTAL: Redirect to tenant-specific portal for package selection and payment
            ($portalUrlForHtml ? ":do { /file set hotspot/login.html contents=\"<html><head><meta http-equiv='refresh' content='0;url={$portalUrlForHtml}'></head><body>Redirecting to portal...</body></html>\" } on-error={}" : ":do { } on-error={} "),
            "",
            "# Hotspot Server",
            ":do { /ip hotspot remove [find name=\"{$server}\"]; } on-error={} ",
            // Single-line hotspot server add for SSH compatibility
            ":do { /ip hotspot add name=\"{$server}\" interface=\"{$interface}\" profile=\"{$profile}\" address-pool={$poolName} addresses-per-mac=2 idle-timeout=5m keepalive-timeout=2m disabled=no; } on-error={ /log error \"Hybrid: Hotspot server add failed\"; :error \"Hybrid: Hotspot server add failed\" }",
            ":if ([:len [/ip hotspot find name=\"{$server}\"]] = 0) do={ /log error \"Hybrid: Hotspot server missing\"; :error \"Hybrid: Hotspot server missing\" }",
            // CAPTIVE PORTAL: Walled garden for portal access without authentication
            ($portalHost ? ":do { /ip hotspot walled-garden remove [find comment=\"WiFiCore Portal (Hybrid)\"]; /ip hotspot walled-garden add dst-host={$portalHost} action=allow comment=\"WiFiCore Portal (Hybrid)\"; } on-error={}" : ":do { } on-error={} "),
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

        $pppoeActiveList = "PPPOE-ACTIVE-HYB-" . substr(str_replace('-', '', $routerId), 0, 8);

        return [
            "# PPPoE Configuration on VLAN {$vlanId}",
            "",
            "# PPPOE-ACTIVE interface list: authenticated dynamic <pppoe-*> interfaces auto-join",
            ":do { :if ([:len [/interface list find name={$pppoeActiveList}]] = 0) do={ /interface list add name={$pppoeActiveList} } } on-error={}",
            "",
            "# IP Pool",
            ":do { /ip pool remove [find name=\"{$poolName}\"]; } on-error={}",
            ":do { /ip pool add name={$poolName} ranges={$pool->range_start}-{$pool->range_end} comment=\"PPPoE Pool (Hybrid)\"; } on-error={ /log error \"Hybrid: PPPoE pool add failed\"; :error \"Hybrid: PPPoE pool add failed\" }",
            ":if ([:len [/ip pool find name=\"{$poolName}\"]] = 0) do={ /log error \"Hybrid: PPPoE pool missing\"; :error \"Hybrid: PPPoE pool missing\" }",
            "",
            "# PPP Profile - interface-list assigns dynamic PPPoE interfaces to PPPOE-ACTIVE on auth",
            ":do { /ppp profile remove [find name=\"{$profile}\"]; /ppp profile add name=\"{$profile}\" use-radius=yes local-address={$gateway} remote-address=\"{$poolName}\" dns-server=\"{$dns}\" interface-list={$pppoeActiveList}; } on-error={ /log error \"Hybrid: PPP profile create failed\"; :error \"Hybrid: PPP profile create failed\" }",
            ":if ([:len [/ppp profile find name=\"{$profile}\"]] = 0) do={ /log error \"Hybrid: PPP profile missing\"; :error \"Hybrid: PPP profile missing\" }",
            "# Ensure existing profile gets interface-list updated",
            ":do { /ppp profile set [find name=\"{$profile}\"] interface-list={$pppoeActiveList} } on-error={}",
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
            "/ppp aaa set use-radius=yes accounting=yes interim-update=5m",
            "",
            "# Clear local credentials (RADIUS-ONLY)",
            // Guard removals to avoid errors on non-removable defaults
            ":do { /ip hotspot user remove [find] } on-error={}",
            ":do { /ppp secret remove [find] } on-error={}",
            "",
        ];
    }

    private function generateBridgeFirewallRules(array $params): array
    {
        $bridge = $params['bridge'];
        $routerId = $params['router_id'];
        $hotspotCidr = $params['hotspot_pool']->network_cidr;
        $pppoeActiveList = $params['pppoe_active_list'];

        return [
            "# Firewall Rules - Bridge Mode Authentication Enforcement",
            "# Hotspot: hotspot=auth | PPPoE: PPPOE-ACTIVE interface list",
            "",
            // Remove existing Hybrid firewall rules for idempotent re-ordering
            ":do { /ip firewall filter remove [find comment~\"Hybrid-{$routerId}-FW\"]; } on-error={}",
            // Insert in REVERSE order (last rule first) with place-before=0
            // 6. DROP all other traffic from bridge (unauthenticated devices)
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-DROP\"",
            // 5. Allow authenticated hotspot users to WAN
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" hotspot=auth out-interface-list=WAN action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-HS-INET\"",
            // 4. Allow authenticated PPPoE sessions to WAN
            "/ip firewall filter add chain=forward in-interface-list={$pppoeActiveList} out-interface-list=WAN action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-PP-INET\"",
            // 3. Drop invalid
            "/ip firewall filter add chain=forward in-interface-list={$pppoeActiveList} connection-state=invalid action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-PP-INV\"",
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" connection-state=invalid action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-HS-INV\"",
            // 2. Accept established/related
            "/ip firewall filter add chain=forward in-interface-list={$pppoeActiveList} connection-state=established,related action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-PP-EST\"",
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" connection-state=established,related action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-HS-EST\"",
            "",
        ];
    }

    private function generateGlobalDefaultDropRules(): array
    {
        return [
            "# Global Default Drop",
            ":do { /ip firewall filter remove [find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}",
            "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"",
            "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"",
            "",
        ];
    }

    private function generateBridgeNatRules(array $params): array
    {
        $hotspotPool = $params['hotspot_pool'];
        $routerId = $params['router_id'];
        $bridge = $params['bridge'];
        $pppoeActiveList = $params['pppoe_active_list'];

        $hotspotParts = explode('/', $hotspotPool->network_cidr);
        $hotspotNetwork = $hotspotParts[0];
        $hotspotCidr = $hotspotParts[1] ?? '24';

        return [
            "# NAT Rules - Bridge Mode",
            ":do { /ip firewall nat remove [find comment=\"Hybrid: Hotspot NAT\"]; } on-error={}",
            ":do { /ip firewall nat remove [find comment=\"Hybrid: PPPoE NAT\"]; } on-error={}",
            // Hotspot NAT: subnet-based is acceptable (hotspot controls auth)
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address={$hotspotNetwork}/{$hotspotCidr} out-interface-list=WAN comment=\"Hybrid: Hotspot NAT\"; } on-error={ /log error \"Hybrid: NAT add failed (Hotspot NAT)\"; :error \"Hybrid: NAT add failed\" }",
            // PPPoE NAT: interface-list based (NOT subnet)
            ":do { /ip firewall nat add chain=srcnat action=masquerade in-interface-list={$pppoeActiveList} out-interface-list=WAN comment=\"Hybrid: PPPoE NAT\"; } on-error={ /log error \"Hybrid: NAT add failed (PPPoE NAT)\"; :error \"Hybrid: NAT add failed\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface=\"{$bridge}\" comment=\"Hybrid: Hotspot HTTP\"; } on-error={ /log error \"Hybrid: NAT redirect failed (HTTP)\"; :error \"Hybrid: NAT redirect failed\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface=\"{$bridge}\" comment=\"Hybrid: Hotspot HTTPS\"; } on-error={ /log error \"Hybrid: NAT redirect failed (HTTPS)\"; :error \"Hybrid: NAT redirect failed\" }",
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
        $routerId = $params['router_id'];
        $hotspotCidr = $params['hotspot_pool']->network_cidr;
        $pppoeActiveList = "PPPOE-ACTIVE-HYB-" . substr(str_replace('-', '', $routerId), 0, 8);

        // SECURITY PRINCIPLE for PPPoE:
        // Never allow traffic based on src-address subnet from the PPPoE VLAN.
        // Allow ONLY traffic from PPPoE dynamic interfaces (PPPOE-ACTIVE list).
        // Unauthorized clients can spoof src-address but CANNOT create a PPPoE session.
        //
        // For Hotspot: src-address is acceptable because the hotspot system itself
        // controls which IPs get internet (walled garden + auth). The hotspot
        // firewall is managed by RouterOS hotspot system.
        //
        // Rules inserted in REVERSE order with place-before=0 for correct top-of-chain ordering.

        return [
            "# Firewall Rules - VLAN Separation + Authentication Enforcement",
            "# PPPoE: interface-list based (PPPOE-ACTIVE) | Hotspot: managed by RouterOS hotspot system",
            "",
            // Remove existing Hybrid firewall rules for idempotent re-ordering
            ":do { /ip firewall filter remove [find comment~\"Hybrid-{$routerId}-FW\"]; } on-error={}",
            //
            // Insert in REVERSE order (last rule first) with place-before=0:
            //
            // 7. DROP all other traffic from PPPoE VLAN (unauthenticated devices)
            "/ip firewall filter add chain=forward in-interface={$pppoeInterface} action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-PP-DROP\"",
            // 6. DROP all other traffic from Hotspot VLAN (unauthenticated devices)
            "/ip firewall filter add chain=forward in-interface={$hotspotInterface} action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-HS-DROP\"",
            // 5. Allow authenticated Hotspot subnet traffic to WAN (hotspot system manages auth)
            "/ip firewall filter add chain=forward src-address={$hotspotCidr} in-interface={$hotspotInterface} action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-HS-INET\"",
            // 4. Allow ONLY authenticated PPPoE sessions to WAN (interface-list, NOT src-address)
            "/ip firewall filter add chain=forward in-interface-list={$pppoeActiveList} out-interface-list=WAN action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-PP-INET\"",
            // 3. Drop invalid — scoped per service
            "/ip firewall filter add chain=forward in-interface-list={$pppoeActiveList} connection-state=invalid action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-PP-INV\"",
            "/ip firewall filter add chain=forward in-interface={$hotspotInterface} connection-state=invalid action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-HS-INV\"",
            // 2. Accept established/related — scoped per service
            "/ip firewall filter add chain=forward in-interface-list={$pppoeActiveList} connection-state=established,related action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-PP-EST\"",
            "/ip firewall filter add chain=forward in-interface={$hotspotInterface} connection-state=established,related action=accept place-before=0 comment=\"Hybrid-{$routerId}-FW-HS-EST\"",
            // 1. Block cross-VLAN traffic (isolation between Hotspot and PPPoE)
            "/ip firewall filter add chain=forward in-interface={$pppoeInterface} out-interface={$hotspotInterface} action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-XVLAN-PP\"",
            "/ip firewall filter add chain=forward in-interface={$hotspotInterface} out-interface={$pppoeInterface} action=drop place-before=0 comment=\"Hybrid-{$routerId}-FW-XVLAN-HS\"",
            "",
        ];
    }

    /**
     * Generate NAT rules
     */
    private function generateNatRules(array $params): array
    {
        $hotspotPool = $params['hotspot_pool'];
        $hotspotVlan = $params['hotspot_vlan_id'];
        $hotspotInterface = "vlan-hotspot-{$hotspotVlan}";
        $routerId = $params['router_id'];
        $pppoeActiveList = "PPPOE-ACTIVE-HYB-" . substr(str_replace('-', '', $routerId), 0, 8);
        
        $hotspotParts = explode('/', $hotspotPool->network_cidr);
        $hotspotNetwork = $hotspotParts[0];
        $hotspotCidr = $hotspotParts[1] ?? '24';

        return [
            "# NAT Rules - Separate NAT per service",
            // Remove existing Hybrid NAT rules for idempotency
            ":do { /ip firewall nat remove [find comment=\"Hybrid: Hotspot NAT\"]; } on-error={}",
            ":do { /ip firewall nat remove [find comment=\"Hybrid: PPPoE NAT\"]; } on-error={}",
            // Hotspot: src-address based NAT is acceptable (hotspot system controls auth)
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address={$hotspotNetwork}/{$hotspotCidr} out-interface=!{$hotspotInterface} comment=\"Hybrid: Hotspot NAT\"; } on-error={ /log error \"Hybrid: NAT add failed (Hotspot NAT)\"; :error \"Hybrid: NAT add failed\" }",
            // PPPoE: interface-list based NAT (NOT subnet) — prevents unauthorized bypass
            ":do { /ip firewall nat add chain=srcnat action=masquerade in-interface-list={$pppoeActiveList} out-interface-list=WAN comment=\"Hybrid: PPPoE NAT\"; } on-error={ /log error \"Hybrid: NAT add failed (PPPoE NAT)\"; :error \"Hybrid: NAT add failed\" }",
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
