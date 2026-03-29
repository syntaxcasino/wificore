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
        $hotspotPool = TenantIpPool::withoutGlobalScopes()->find($advancedConfig['hotspot_pool_id'] ?? null);
        $pppoePool   = TenantIpPool::withoutGlobalScopes()->find($advancedConfig['pppoe_pool_id'] ?? null);

        if (!$hotspotPool || !$pppoePool) {
            throw new \Exception('Hybrid service requires both hotspot and pppoe IP pools');
        }

        $id = substr(str_replace('-', '', (string) $router->id), 0, 8);

        if ($bridgeMode) {
            $interfaces = $this->normalizeInterfaces($service->interfaces, $service->interface_name);
            return $this->buildBridgeConfiguration([
                'router_id'         => $router->id,
                'id'                => $id,
                'interfaces'        => $interfaces,
                'hotspot_pool'      => $hotspotPool,
                'pppoe_pool'        => $pppoePool,
                'portal_url'        => $portalUrl,
                'portal_host'       => $portalHost,
                'bridge_name'       => $advancedConfig['bridge_name'] ?? null,
                'radius_server'     => config('radius.server_ip', config('services.radius.host', 'wificore-freeradius')),
                'radius_secret'     => config('radius.secret', 'testing123'),
                'management_subnet' => config('vpn.subnet.base', '10.0.0.0/8'),
                'tenant_id'         => $router->tenant_id,
            ]);
        }

        // VLAN mode
        $vlans       = $service->vlans;
        $hotspotVlan = $vlans->where('service_type', 'hotspot')->first();
        $pppoeVlan   = $vlans->where('service_type', 'pppoe')->first();

        if (!$hotspotVlan || !$pppoeVlan) {
            throw new \Exception('Hybrid service requires both hotspot and pppoe VLANs');
        }

        return $this->buildConfiguration([
            'router_id'         => $router->id,
            'id'                => $id,
            'parent_interface'  => $service->interface_name,
            'hotspot_vlan_id'   => $hotspotVlan->vlan_id,
            'pppoe_vlan_id'     => $pppoeVlan->vlan_id,
            'hotspot_pool'      => $hotspotPool,
            'pppoe_pool'        => $pppoePool,
            'portal_url'        => $portalUrl,
            'portal_host'       => $portalHost,
            'radius_server'     => config('radius.server_ip', config('services.radius.host', 'wificore-freeradius')),
            'radius_secret'     => config('radius.secret', 'testing123'),
            'management_subnet' => config('vpn.subnet.base', '10.0.0.0/8'),
            'tenant_id'         => $router->tenant_id,
        ]);
    }

    // -------------------------------------------------------------------------
    // VLAN MODE
    // -------------------------------------------------------------------------

    private function buildConfiguration(array $params): string
    {
        $script   = [];
        $script[] = "/log info \"=== Zero-Config Hybrid Deployment (VLAN-Enforced) ===\"";
        $script[] = "/log info \"Router: {$params['router_id']}\"";
        $script[] = "";

        $script = array_merge($script, $this->generateVlanSetup($params));
        $script = array_merge($script, $this->generateHotspotConfig($params));
        $script = array_merge($script, $this->generatePppoeConfig($params));
        $script = array_merge($script, $this->generateRadiusSetup($params));
        $script = array_merge($script, $this->generateManagementInputRules($params));
        $script = array_merge($script, $this->generateFirewallRules($params));
        $script = array_merge($script, $this->generateGlobalDefaultDropRules());
        $script = array_merge($script, $this->generateNatRules($params));

        $script[] = "";
        $script[] = "/log info \"=== Hybrid Deployment Complete ===\"";

        return implode("\n", $script);
    }

    private function generateVlanSetup(array $params): array
    {
        $parent = $params['parent_interface'];
        $hsVlan = $params['hotspot_vlan_id'];
        $ppVlan = $params['pppoe_vlan_id'];

        return [
            "# VLAN Setup - Traffic Separation",
            ":do { /interface vlan remove [/interface vlan find name=\"vlan-hs-{$hsVlan}\"]; } on-error={}",
            ":do { /interface vlan add name=vlan-hs-{$hsVlan} vlan-id={$hsVlan} interface={$parent} comment=\"hyb-hs-vlan\"; } on-error={ :error \"hyb-hs-vlan-fail\" }",
            "",
            ":do { /interface vlan remove [/interface vlan find name=\"vlan-pp-{$ppVlan}\"]; } on-error={}",
            ":do { /interface vlan add name=vlan-pp-{$ppVlan} vlan-id={$ppVlan} interface={$parent} comment=\"hyb-pp-vlan\"; } on-error={ :error \"hyb-pp-vlan-fail\" }",
            "",
        ];
    }

    private function generateHotspotConfig(array $params): array
    {
        $pool     = $params['hotspot_pool'];
        $id       = $params['id'];
        $vlanId   = $params['hotspot_vlan_id'];
        $iface    = "vlan-hs-{$vlanId}";
        $poolName = "hyb-hs-pool-{$id}";
        $dhcpName = "hyb-hs-dhcp-{$id}";
        $profile  = "hyb-hs-prof-{$id}";
        $server   = "hyb-hs-srv-{$id}";
        $gateway  = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        [$network, $cidr] = array_pad(explode('/', $pool->network_cidr, 2), 2, '24');
        $dns        = "{$pool->dns_primary},{$pool->dns_secondary}";
        $portalHost = $params['portal_host'] ?? null;
        $s          = [];

        $s[] = "# Hotspot Config (VLAN {$vlanId})";
        $s[] = ":do { /ip address remove [/ip address find interface=\"{$iface}\"]; } on-error={}";
        $s[] = ":do { /ip address add address={$gateway}/{$cidr} interface=\"{$iface}\" comment=\"hyb-hs-gw-{$id}\"; } on-error={ :error \"hyb-hs-ip-fail\" }";
        $s[] = ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"]; } on-error={}";
        $s[] = ":do { /ip pool add name={$poolName} ranges={$pool->range_start}-{$pool->range_end} comment=\"hyb-hs-{$id}\"; } on-error={ :error \"hyb-hs-pool-fail\" }";
        $s[] = ":do { /ip dhcp-server remove [/ip dhcp-server find name=\"{$dhcpName}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server add name={$dhcpName} interface=\"{$iface}\" address-pool={$poolName} lease-time=1h disabled=no; } on-error={ :error \"hyb-hs-dhcp-fail\" }";
        $s[] = ":do { /ip dhcp-server network remove [/ip dhcp-server network find comment~\"hyb-hs-net-{$id}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server network add address={$network}/{$cidr} gateway={$gateway} dns-server=\"{$dns}\" comment=\"hyb-hs-net-{$id}\"; } on-error={ :error \"hyb-hs-net-fail\" }";
        $s[] = ":do { /ip hotspot profile remove [/ip hotspot profile find name=\"{$profile}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address={$gateway} use-radius=yes html-directory=hotspot http-cookie-lifetime=1d dns-name=hotspot.local; } on-error={ :error \"hyb-hs-prof-fail\" }";
        $s[] = ":do { /ip hotspot profile set [/ip hotspot profile find name=\"{$profile}\"] login-by=http-chap,http-pap; } on-error={}";
        $s[] = ":do { /ip hotspot remove [/ip hotspot find name=\"{$server}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot add name=\"{$server}\" interface=\"{$iface}\" profile=\"{$profile}\" address-pool={$poolName} addresses-per-mac=2 idle-timeout=5m keepalive-timeout=2m disabled=no; } on-error={ :error \"hyb-hs-srv-fail\" }";
        if ($portalHost) {
            $s[] = ":do { /ip hotspot walled-garden remove [/ip hotspot walled-garden find comment=\"hyb-wg-{$id}\"]; } on-error={}";
            $s[] = ":do { /ip hotspot walled-garden add dst-host={$portalHost} action=allow comment=\"hyb-wg-{$id}\"; } on-error={}";
        }
        $s[] = "";
        return $s;
    }

    private function generatePppoeConfig(array $params): array
    {
        $pool        = $params['pppoe_pool'];
        $id          = $params['id'];
        $vlanId      = $params['pppoe_vlan_id'];
        $iface       = "vlan-pp-{$vlanId}";
        $pal         = "PPPOE-ACTIVE-HYB-{$id}";
        $poolName    = "hyb-pp-pool-{$id}";
        $profile     = "hyb-pp-prof-{$id}";
        $serviceName = "hyb-pp-svc-{$id}";
        $gateway     = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        $dns         = "{$pool->dns_primary},{$pool->dns_secondary}";
        $s           = [];

        $s[] = "# PPPoE Config (VLAN {$vlanId})";
        $s[] = ":do { /interface list add name={$pal} } on-error={}";
        $s[] = ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"]; } on-error={}";
        $s[] = ":do { /ip pool add name={$poolName} ranges={$pool->range_start}-{$pool->range_end} comment=\"hyb-pp-{$id}\"; } on-error={ :error \"hyb-pp-pool-fail\" }";
        $s[] = ":do { /ppp profile remove [/ppp profile find name=\"{$profile}\"]; } on-error={}";
        $s[] = ":do { /ppp profile add name=\"{$profile}\" local-address={$gateway} remote-address=\"{$poolName}\" dns-server=\"{$dns}\"; } on-error={ :error \"hyb-pp-prof-fail\" }";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"{$profile}\"] interface-list={$pal}; } on-error={}";
        $s[] = ":do { /interface pppoe-server server remove [/interface pppoe-server server find service-name=\"{$serviceName}\"]; } on-error={}";
        $s[] = ":do { /interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$iface}\" default-profile=\"{$profile}\" authentication=pap,chap,mschap2 keepalive-timeout=10 max-mtu=1480 max-mru=1480 disabled=no; } on-error={ :error \"hyb-pp-srv-fail\" }";
        $s[] = "";
        return $s;
    }

    private function generateFirewallRules(array $params): array
    {
        $id      = $params['id'];
        $hsVlan  = $params['hotspot_vlan_id'];
        $ppVlan  = $params['pppoe_vlan_id'];
        $hsIface = "vlan-hs-{$hsVlan}";
        $ppIface = "vlan-pp-{$ppVlan}";
        $pal     = "PPPOE-ACTIVE-HYB-{$id}";

        return [
            "# Firewall - VLAN Separation (in-interface scoped)",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-fw-{$id}\"]; } on-error={}",
            "/ip firewall filter add chain=forward in-interface={$ppIface} action=drop place-before=0 comment=\"hyb-fw-{$id}-pp-drop\"",
            "/ip firewall filter add chain=forward in-interface={$hsIface} action=drop place-before=0 comment=\"hyb-fw-{$id}-hs-drop\"",
            "/ip firewall filter add chain=forward in-interface={$hsIface} hotspot=auth out-interface-list=WAN action=accept place-before=0 comment=\"hyb-fw-{$id}-hs-inet\"",
            "/ip firewall filter add chain=forward in-interface-list={$pal} out-interface-list=WAN action=accept place-before=0 comment=\"hyb-fw-{$id}-pp-inet\"",
            "/ip firewall filter add chain=forward in-interface-list={$pal} connection-state=invalid action=drop place-before=0 comment=\"hyb-fw-{$id}-pp-inv\"",
            "/ip firewall filter add chain=forward in-interface={$hsIface} connection-state=invalid action=drop place-before=0 comment=\"hyb-fw-{$id}-hs-inv\"",
            "/ip firewall filter add chain=forward in-interface-list={$pal} connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-pp-est\"",
            "/ip firewall filter add chain=forward in-interface={$hsIface} connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-hs-est\"",
            "/ip firewall filter add chain=forward in-interface-list=WAN out-interface={$hsIface} connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-hs-wan\"",
            "/ip firewall filter add chain=forward in-interface-list=WAN out-interface-list={$pal} connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-pp-wan\"",
            "/ip firewall filter add chain=forward in-interface={$ppIface} out-interface={$hsIface} action=drop place-before=0 comment=\"hyb-fw-{$id}-xvlan-pp\"",
            "/ip firewall filter add chain=forward in-interface={$hsIface} out-interface={$ppIface} action=drop place-before=0 comment=\"hyb-fw-{$id}-xvlan-hs\"",
            "",
        ];
    }

    private function generateNatRules(array $params): array
    {
        $id      = $params['id'];
        $hsVlan  = $params['hotspot_vlan_id'];
        $hsIface = "vlan-hs-{$hsVlan}";
        $pal     = "PPPOE-ACTIVE-HYB-{$id}";
        $hsParts = explode('/', $params['hotspot_pool']->network_cidr);
        $hsNet   = $hsParts[0];
        $hsCidr  = $hsParts[1] ?? '24';

        return [
            "# NAT Rules",
            ":do { /ip firewall nat remove [/ip firewall nat find comment~\"hyb-nat-{$id}\"]; } on-error={}",
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address={$hsNet}/{$hsCidr} out-interface=!{$hsIface} comment=\"hyb-nat-{$id}-hs\"; } on-error={ :error \"hyb-nat-hs-fail\" }",
            ":do { /ip firewall nat add chain=srcnat action=masquerade in-interface-list={$pal} out-interface-list=WAN comment=\"hyb-nat-{$id}-pp\"; } on-error={ :error \"hyb-nat-pp-fail\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface={$hsIface} comment=\"hyb-redir80-{$id}\"; } on-error={ :error \"hyb-redir80-fail\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface={$hsIface} comment=\"hyb-redir443-{$id}\"; } on-error={ :error \"hyb-redir443-fail\" }",
            "",
        ];
    }

    // -------------------------------------------------------------------------
    // BRIDGE MODE
    // -------------------------------------------------------------------------

    private function buildBridgeConfiguration(array $params): string
    {
        $id     = $params['id'];
        $bridge = $params['bridge_name'] ?: "hyb-br-{$id}";
        $params = array_merge($params, [
            'bridge'            => $bridge,
            'pppoe_active_list' => "PPPOE-ACTIVE-HYB-{$id}",
            'wan_list'          => 'WAN',
        ]);

        $script   = [];
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

    private function generateBridgeSetup(array $params): array
    {
        $bridge     = $params['bridge'];
        $id         = $params['id'];
        $interfaces = $params['interfaces'];

        $script = [
            "# Interface Lists",
            ":do { /interface list add name=LAN comment=\"Local Area Network\" } on-error={}",
            ":do { /interface list add name=WAN comment=\"Wide Area Network\" } on-error={}",
            ":do { /interface list member add list=WAN interface=ether1 } on-error={}",
            "",
            "# Bridge Setup",
            ":do { /interface bridge port remove [/interface bridge port find bridge=\"{$bridge}\"]; } on-error={}",
            ":do { /interface bridge remove [/interface bridge find name=\"{$bridge}\"]; } on-error={}",
            ":do { /interface bridge add name=\"{$bridge}\" protocol-mode=rstp comment=\"hyb-br-{$id}\" } on-error={ :error \"hyb-bridge-fail\" }",
            ":delay 500ms",
        ];

        foreach ($interfaces as $iface) {
            $script[] = ":do { /interface bridge port add bridge=\"{$bridge}\" interface=\"{$iface}\" comment=\"hyb-port-{$id}\" } on-error={}";
            $script[] = ":do { /interface list member add list=LAN interface=\"{$iface}\" } on-error={}";
        }

        $script[] = "";
        return $script;
    }

    private function generateBridgeHotspotConfig(array $params): array
    {
        $pool       = $params['hotspot_pool'];
        $bridge     = $params['bridge'];
        $id         = $params['id'];
        $poolName   = "hyb-hs-pool-{$id}";
        $dhcpName   = "hyb-hs-dhcp-{$id}";
        $profile    = "hyb-hs-prof-{$id}";
        $server     = "hyb-hs-srv-{$id}";
        $gateway    = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        [$network, $cidr] = array_pad(explode('/', $pool->network_cidr, 2), 2, '24');
        $dns        = "{$pool->dns_primary},{$pool->dns_secondary}";
        $portalHost = $params['portal_host'] ?? null;
        $s          = [];

        $s[] = "# Hotspot Config (Bridge)";
        $s[] = ":do { /ip address add address={$gateway}/{$cidr} interface=\"{$bridge}\" comment=\"hyb-hs-gw-{$id}\" } on-error={}";
        $s[] = ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"]; } on-error={}";
        $s[] = ":do { /ip pool add name=\"{$poolName}\" ranges={$pool->range_start}-{$pool->range_end} comment=\"hyb-hs-{$id}\"; } on-error={ :error \"hyb-hs-pool-fail\" }";
        $s[] = ":do { /ip dhcp-server remove [/ip dhcp-server find name=\"{$dhcpName}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server add name=\"{$dhcpName}\" interface=\"{$bridge}\" address-pool=\"{$poolName}\" lease-time=1h disabled=no; } on-error={ :error \"hyb-hs-dhcp-fail\" }";
        $s[] = ":do { /ip dhcp-server network remove [/ip dhcp-server network find comment~\"hyb-hs-net-{$id}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server network add address={$network}/{$cidr} gateway={$gateway} dns-server=\"{$dns}\" comment=\"hyb-hs-net-{$id}\"; } on-error={ :error \"hyb-hs-net-fail\" }";
        $s[] = ":do { /ip hotspot profile remove [/ip hotspot profile find name=\"{$profile}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address={$gateway} use-radius=yes html-directory=hotspot http-cookie-lifetime=1d dns-name=hotspot.local; } on-error={ :error \"hyb-hs-prof-fail\" }";
        $s[] = ":do { /ip hotspot profile set [/ip hotspot profile find name=\"{$profile}\"] login-by=http-chap,http-pap; } on-error={}";
        $s[] = ":do { /ip hotspot remove [/ip hotspot find name=\"{$server}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot add name=\"{$server}\" interface=\"{$bridge}\" profile=\"{$profile}\" address-pool=\"{$poolName}\" addresses-per-mac=2 idle-timeout=5m keepalive-timeout=2m disabled=no; } on-error={ :error \"hyb-hs-srv-fail\" }";
        if ($portalHost) {
            $s[] = ":do { /ip hotspot walled-garden remove [/ip hotspot walled-garden find comment=\"hyb-wg-{$id}\"]; } on-error={}";
            $s[] = ":do { /ip hotspot walled-garden add dst-host={$portalHost} action=allow comment=\"hyb-wg-{$id}\"; } on-error={}";
        }
        $s[] = "";
        return $s;
    }

    private function generateBridgePppoeConfig(array $params): array
    {
        $pool        = $params['pppoe_pool'];
        $bridge      = $params['bridge'];
        $id          = $params['id'];
        $pal         = $params['pppoe_active_list'];
        $poolName    = "hyb-pp-pool-{$id}";
        $profile     = "hyb-pp-prof-{$id}";
        $serviceName = "hyb-pp-svc-{$id}";
        $gateway     = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        $dns         = "{$pool->dns_primary},{$pool->dns_secondary}";
        $s           = [];

        $s[] = "# PPPoE Config (Bridge)";
        $s[] = ":do { /interface list add name={$pal} } on-error={}";
        $s[] = ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"]; } on-error={}";
        $s[] = ":do { /ip pool add name=\"{$poolName}\" ranges={$pool->range_start}-{$pool->range_end} comment=\"hyb-pp-{$id}\"; } on-error={ :error \"hyb-pp-pool-fail\" }";
        $s[] = ":do { /ppp profile remove [/ppp profile find name=\"{$profile}\"]; } on-error={}";
        $s[] = ":do { /ppp profile add name=\"{$profile}\" local-address={$gateway} remote-address=\"{$poolName}\" dns-server=\"{$dns}\"; } on-error={ :error \"hyb-pp-prof-fail\" }";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"{$profile}\"] interface-list={$pal}; } on-error={}";
        $s[] = ":do { /interface pppoe-server server remove [/interface pppoe-server server find service-name=\"{$serviceName}\"]; } on-error={}";
        $s[] = ":do { /interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$bridge}\" default-profile=\"{$profile}\" authentication=pap,chap,mschap2 keepalive-timeout=10 max-mtu=1480 max-mru=1480; } on-error={ :error \"hyb-pp-srv-fail\" }";
        $s[] = ":do { /interface pppoe-server server set [/interface pppoe-server server find service-name=\"{$serviceName}\"] disabled=no; } on-error={}";
        $s[] = "";
        return $s;
    }

    private function generateBridgeFirewallRules(array $params): array
    {
        $bridge = $params['bridge'];
        $id     = $params['id'];
        $pal    = $params['pppoe_active_list'];

        return [
            "# Firewall - Bridge Mode (in-interface scoped)",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-fw-{$id}\"]; } on-error={}",
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" action=drop place-before=0 comment=\"hyb-fw-{$id}-drop\"",
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" hotspot=auth out-interface-list=WAN action=accept place-before=0 comment=\"hyb-fw-{$id}-hs-inet\"",
            "/ip firewall filter add chain=forward in-interface-list={$pal} out-interface-list=WAN action=accept place-before=0 comment=\"hyb-fw-{$id}-pp-inet\"",
            "/ip firewall filter add chain=forward in-interface-list={$pal} connection-state=invalid action=drop place-before=0 comment=\"hyb-fw-{$id}-pp-inv\"",
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" connection-state=invalid action=drop place-before=0 comment=\"hyb-fw-{$id}-hs-inv\"",
            "/ip firewall filter add chain=forward in-interface-list={$pal} connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-pp-est\"",
            "/ip firewall filter add chain=forward in-interface=\"{$bridge}\" connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-hs-est\"",
            "/ip firewall filter add chain=forward in-interface-list=WAN out-interface=\"{$bridge}\" connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-hs-wan\"",
            "/ip firewall filter add chain=forward in-interface-list=WAN out-interface-list={$pal} connection-state=established,related action=accept place-before=0 comment=\"hyb-fw-{$id}-pp-wan\"",
            "",
        ];
    }

    private function generateBridgeNatRules(array $params): array
    {
        $id      = $params['id'];
        $bridge  = $params['bridge'];
        $pal     = $params['pppoe_active_list'];
        $hsParts = explode('/', $params['hotspot_pool']->network_cidr);
        $hsNet   = $hsParts[0];
        $hsCidr  = $hsParts[1] ?? '24';

        return [
            "# NAT Rules (Bridge)",
            ":do { /ip firewall nat remove [/ip firewall nat find comment~\"hyb-nat-{$id}\"]; } on-error={}",
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address={$hsNet}/{$hsCidr} out-interface-list=WAN comment=\"hyb-nat-{$id}-hs\"; } on-error={ :error \"hyb-nat-hs-fail\" }",
            ":do { /ip firewall nat add chain=srcnat action=masquerade in-interface-list={$pal} out-interface-list=WAN comment=\"hyb-nat-{$id}-pp\"; } on-error={ :error \"hyb-nat-pp-fail\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface=\"{$bridge}\" comment=\"hyb-redir80-{$id}\"; } on-error={ :error \"hyb-redir80-fail\" }",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface=\"{$bridge}\" comment=\"hyb-redir443-{$id}\"; } on-error={ :error \"hyb-redir443-fail\" }",
            "",
        ];
    }

    // -------------------------------------------------------------------------
    // SHARED HELPERS
    // -------------------------------------------------------------------------

    private function generateRadiusSetup(array $params): array
    {
        $rs  = $params['radius_server'];
        $sec = $params['radius_secret'];
        $id  = $params['id'];

        return [
            "# RADIUS - RADIUS-ONLY AAA",
            ":do { /radius remove [/radius find service=hotspot comment~\"hyb-hs-rad-{$id}\"]; } on-error={}",
            ":do { /radius add service=hotspot address={$rs} secret=\"{$sec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"hyb-hs-rad-{$id}\"; } on-error={ :error \"hyb-hs-rad-fail\" }",
            ":do { /radius remove [/radius find service=ppp comment~\"hyb-pp-rad-{$id}\"]; } on-error={}",
            ":do { /radius add service=ppp address={$rs} secret=\"{$sec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"hyb-pp-rad-{$id}\"; } on-error={ :error \"hyb-pp-rad-fail\" }",
            "/ppp aaa set use-radius=yes accounting=yes interim-update=5m",
            ":do { /ip hotspot user remove [/ip hotspot user find] } on-error={}",
            ":do { /ppp secret remove [/ppp secret find] } on-error={}",
            "",
        ];
    }

    private function generateManagementInputRules(array $params): array
    {
        $id    = $params['id'];
        $mgmt  = $params['management_subnet'] ?? '10.0.0.0/8';
        $mport = '22,8291,8728,8729';
        $rs    = $params['radius_server'] ?? '10.8.0.1';

        return [
            "# Management Input Rules",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-mgmt-{$id}\"]; } on-error={}",
            "/ip firewall filter add chain=input protocol=tcp dst-port={$mport} action=drop place-before=0 comment=\"hyb-mgmt-{$id}-drop\"",
            "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address={$rs} action=accept place-before=0 comment=\"hyb-mgmt-{$id}-snmp\"",
            "/ip firewall filter add chain=input protocol=tcp dst-port={$mport} src-address={$mgmt} action=accept place-before=0 comment=\"hyb-mgmt-{$id}-allow\"",
            "/ip firewall filter add chain=input connection-state=established,related action=accept place-before=0 comment=\"hyb-mgmt-{$id}-est\"",
            "",
        ];
    }

    private function generateGlobalDefaultDropRules(): array
    {
        return [
            "# Global Default Drop",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}",
            "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"",
            "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"",
            "",
        ];
    }

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

    private function getSafeGatewayIp(string $networkCidr, ?string $gatewayIp): string
    {
        $parts      = explode('/', $networkCidr, 2);
        $networkIp  = $parts[0] ?? '';
        $cidr       = (int) ($parts[1] ?? 24);
        $networkLong = ip2long($networkIp);

        if ($networkLong === false) {
            return (string) $gatewayIp;
        }

        if ($cidr < 0 || $cidr > 32) {
            $cidr = 24;
        }

        $mask            = $cidr === 0 ? 0 : ((-1 << (32 - $cidr)) & 0xFFFFFFFF);
        $networkAddrLong = $networkLong & $mask;
        $broadcastLong   = $networkAddrLong | (~$mask & 0xFFFFFFFF);
        $candidateLong   = $gatewayIp ? ip2long($gatewayIp) : false;

        if ($candidateLong === false) {
            return long2ip($networkAddrLong + 1);
        }

        if (($candidateLong & $mask) !== $networkAddrLong || $candidateLong === $networkAddrLong || $candidateLong === $broadcastLong) {
            return long2ip($networkAddrLong + 1);
        }

        return $gatewayIp;
    }
}
