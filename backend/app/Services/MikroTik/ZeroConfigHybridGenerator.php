<?php

namespace App\Services\MikroTik;

use App\Models\RouterService;
use App\Services\RouterResourceManager;
use Illuminate\Support\Facades\Log;
use App\Models\TenantIpPool;
use App\Support\SubnetHelper;

/**
 * Zero-Config Hybrid (Hotspot + PPPoE) Configuration Generator
 * VLAN-Enforced Traffic Separation | RADIUS-Only AAA | Tenant-Scoped IPAM
 */
class ZeroConfigHybridGenerator
{
    use ZeroConfigBootstrapTrait;
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
            $resolvedRs = config('radius.server_ip', config('services.radius.host', 'wificore-freeradius'));
            if (filter_var($resolvedRs, FILTER_VALIDATE_IP) === false) {
                $resolvedRs = gethostbyname((string) $resolvedRs) ?: $resolvedRs;
            }
            return $this->buildBridgeConfiguration([
                'router_id'         => $router->id,
                'id'                => $id,
                'interfaces'        => $interfaces,
                'hotspot_pool'      => $hotspotPool,
                'pppoe_pool'        => $pppoePool,
                'portal_url'        => $portalUrl,
                'portal_host'       => $portalHost,
                'bridge_name'       => $advancedConfig['bridge_name'] ?? null,
                'radius_server'     => $resolvedRs,
                'radius_secret'     => config('radius.secret', 'testing123'),
                'management_subnet' => SubnetHelper::normalize(config('vpn.subnet.base', '10.0.0.0/8')),
                'tenant_id'         => $router->tenant_id,
                'is_low_end'        => $scriptSettings['tier'] === 'low_end',
                'syslog_host'       => config('services.syslog.host', $resolvedRs),
                'vpn_ip'            => $router->vpn_ip ? explode('/', (string) $router->vpn_ip)[0] : null,
            ]);
        }

        // VLAN mode
        $vlans       = $service->vlans;
        $hotspotVlan = $vlans->where('service_type', 'hotspot')->first();
        $pppoeVlan   = $vlans->where('service_type', 'pppoe')->first();

        if (!$hotspotVlan || !$pppoeVlan) {
            throw new \Exception('Hybrid service requires both hotspot and PPPoE VLANs');
        }

        $resolvedRs = config('radius.server_ip', config('services.radius.host', 'wificore-freeradius'));
        if (filter_var($resolvedRs, FILTER_VALIDATE_IP) === false) {
            $resolvedRs = gethostbyname((string) $resolvedRs) ?: $resolvedRs;
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
            'radius_server'     => $resolvedRs,
            'radius_secret'     => config('radius.secret', 'testing123'),
            'management_subnet' => SubnetHelper::normalize(config('vpn.subnet.base', '10.0.0.0/8')),
            'tenant_id'         => $router->tenant_id,
            'is_low_end'        => $scriptSettings['tier'] === 'low_end',
            'syslog_host'       => config('services.syslog.host', $resolvedRs),
            'vpn_ip'            => $router->vpn_ip ? explode('/', (string) $router->vpn_ip)[0] : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // VLAN MODE
    // -------------------------------------------------------------------------

    private function buildConfiguration(array $params): string
    {
        $id       = $params['id'];
        $mgmt     = SubnetHelper::normalize($params['management_subnet'] ?? '10.0.0.0/8');
        $isLowEnd = $params['is_low_end'] ?? false;
        $syslogHost = $params['syslog_host'] ?? config('services.syslog.host', $params['radius_server'] ?? '10.8.0.1');

        $script   = [];
        $script[] = "/log info \"=== Zero-Config Hybrid Deployment (VLAN-Enforced) ===\"";
        $script[] = "/log info \"Router: {$params['router_id']}\"";
        $script[] = "";
        $script = array_merge($script, $this->bootstrapRadiusAaaAttributes());

        $script = array_merge($script, $this->generateVlanSetup($params));
        $script = array_merge($script, $this->generateHotspotConfig($params, "vlan-hs-{$params['hotspot_vlan_id']}", "VLAN {$params['hotspot_vlan_id']}"));
        $script = array_merge($script, $this->generatePppoeConfig($params, "vlan-pp-{$params['pppoe_vlan_id']}", "VLAN {$params['pppoe_vlan_id']}"));
        $script = array_merge($script, $this->generateRadiusSetup($params));
        $script = array_merge($script, $this->generateManagementInputRules($params));
        $script = array_merge($script, $this->bootstrapSnmpSyslog($mgmt, $syslogHost, !$isLowEnd));
        $script = array_merge($script, $this->bootstrapSubscriberQueues("hyb-{$id}", 'ether1', $isLowEnd));
        if (!$isLowEnd) {
            $script = array_merge($script, $this->bootstrapTrafficFlow("hyb-{$id}", $syslogHost));
        }
        $vpnIpVlan = $params['vpn_ip'] ?? null;
        $allowAddrVlan = ($vpnIpVlan && filter_var($vpnIpVlan, FILTER_VALIDATE_IP)) ? $vpnIpVlan . '/32' : $mgmt;
        $script = array_merge($script, $this->bootstrapMgmtRateLimit("hyb-{$id}", $allowAddrVlan));
        $script = array_merge($script, $this->bootstrapSecurityHardening([
            'id'               => $id,
            'is_low_end'       => $isLowEnd,
            'wan_list'         => 'WAN',
            'subscriber_ifaces' => [
                ['in' => "vlan-hs-{$params['hotspot_vlan_id']}", 'is_list' => false, 'pool_cidr' => $params['hotspot_pool']->network_cidr, 'tag' => 'HS'],
                ['in' => "vlan-pp-{$params['pppoe_vlan_id']}",   'is_list' => false, 'pool_cidr' => $params['pppoe_pool']->network_cidr,   'tag' => 'PP'],
            ],
        ]));
        $script = array_merge($script, $this->generateFirewallRules($params));
        $script = array_merge($script, $this->bootstrapGlobalDefaultDrop("hyb-{$id}"));
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
            ":do { /interface vlan add name=vlan-hs-{$hsVlan} vlan-id=\"{$hsVlan}\" interface=\"{$parent}\" comment=\"hyb-hs-vlan\" ; } on-error={ :error \"hyb-hs-vlan-fail\" }",
            "",
            ":do { /interface vlan remove [/interface vlan find name=\"vlan-pp-{$ppVlan}\"]; } on-error={}",
            ":do { /interface vlan add name=vlan-pp-{$ppVlan} vlan-id=\"{$ppVlan}\" interface=\"{$parent}\" comment=\"hyb-pp-vlan\" ; } on-error={ :error \"hyb-pp-vlan-fail\" }",
            "",
        ];
    }

    private function generateHotspotConfig(array $params, string $iface, string $label): array
    {
        $pool       = $params['hotspot_pool'];
        $id         = $params['id'];
        $poolName   = "hyb-hs-pool-{$id}";
        $dhcpName   = "hyb-hs-dhcp-{$id}";
        $profile    = "hyb-hs-prof-{$id}";
        $server     = "hyb-hs-srv-{$id}";
        $gateway    = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        [$network, $cidr] = array_pad(explode('/', $pool->network_cidr, 2), 2, '24');
        $dnsPrimary   = $pool->dns_primary ?? '8.8.8.8';
        $dnsSecondary = $pool->dns_secondary ?? '8.8.4.4';
        $dns          = "{$dnsPrimary},{$dnsSecondary}";
        $portalHost = $params['portal_host'] ?? null;
        $s          = [];

        $s[] = "# Hotspot Config ({$label})";
        $s[] = ":do { /ip address remove [/ip address find interface=\"{$iface}\"]; } on-error={}";
        $s[] = ":do { /ip address add address=\"{$gateway}/{$cidr}\" interface=\"{$iface}\" comment=\"hyb-hs-gw-{$id}\"; } on-error={ :error \"hyb-hs-ip-fail\" }";
        $s[] = ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"]; } on-error={}";
        $s[] = ":do { /ip pool add name=\"{$poolName}\" ranges=\"{$pool->range_start}-{$pool->range_end}\" comment=\"hyb-hs-{$id}\"; } on-error={ :error \"hyb-hs-pool-fail\" }";
        $s[] = ":do { /ip dhcp-server remove [/ip dhcp-server find name=\"{$dhcpName}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server add name=\"{$dhcpName}\" interface=\"{$iface}\" address-pool=\"{$poolName}\" lease-time=\"1h\" disabled=no; } on-error={ :error \"hyb-hs-dhcp-fail\" }";
        $s[] = ":do { /ip dhcp-server network remove [/ip dhcp-server network find comment~\"hyb-hs-net-{$id}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server network add address=\"{$network}/{$cidr}\" gateway=\"{$gateway}\" dns-server=\"{$dns}\" comment=\"hyb-hs-net-{$id}\"; } on-error={ :error \"hyb-hs-net-fail\" }";
        $s[] = ":do { /ip hotspot profile remove [/ip hotspot profile find name=\"{$profile}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address=\"{$gateway}\" use-radius=yes html-directory=\"hotspot\" http-cookie-lifetime=\"1d\" dns-name=hotspot.local; } on-error={ :error \"hyb-hs-prof-fail\" }";
        $s[] = ":do { /ip hotspot profile set [/ip hotspot profile find name=\"{$profile}\"] login-by=\"http-chap,http-pap\"; } on-error={ /log warning \"hyb: Failed to set hotspot login-by — default auth methods may be broader than expected\" }";
        $s[] = ":do { /ip hotspot remove [/ip hotspot find name=\"{$server}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot add name=\"{$server}\" interface=\"{$iface}\" profile=\"{$profile}\" address-pool=\"{$poolName}\" addresses-per-mac=2 idle-timeout=\"5m\" keepalive-timeout=\"2m\" disabled=no; } on-error={ :error \"hyb-hs-srv-fail\" }";
        if ($portalHost) {
            $s[] = ":do { /ip hotspot walled-garden remove [/ip hotspot walled-garden find comment=\"hyb-wg-{$id}\"]; } on-error={}";
            $s[] = ":do { /ip hotspot walled-garden add dst-host=\"{$portalHost}\" action=\"allow\" comment=\"hyb-wg-{$id}\"; } on-error={ /log warning \"hyb-{$id}: Failed to add walled-garden entry for {$portalHost} — captive portal may be unreachable\" }";
        }
        $s[] = "";
        return $s;
    }

    private function generatePppoeConfig(array $params, string $iface, string $label): array
    {
        return $this->buildPppoeConfig($params, $iface, $label);
    }

    /**
     * Generate tier-based firewall rules for VLAN mode - minimal for hAP lite, full for high-end
     * SECURITY: Unauthenticated users are BLOCKED from internet in BOTH tiers
     * SECURITY: Only authenticated users (hotspot=\"auth\" or PAL) can access WAN in BOTH tiers
     */
    private function generateFirewallRules(array $params): array
    {
        $id      = $params['id'];
        $hsVlan  = $params['hotspot_vlan_id'];
        $ppVlan  = $params['pppoe_vlan_id'];
        $hsIface = "vlan-hs-{$hsVlan}";
        $ppIface = "vlan-pp-{$ppVlan}";
        $pal     = "PPPOE-ACTIVE-HYB-{$id}";
        $isLowEnd = $params['is_low_end'] ?? false;

        // Rules appended in correct order — NO place-before=0 (which reverses insertion order)
        // Correct order: established/related -> invalid drop -> auth accept -> cross-VLAN drop -> unauth drop
        if ($isLowEnd) {
            return [
                "# Firewall [MINIMAL] - Essential security for low-end device",
                ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-fw-{$id}\"]; } on-error={}",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-EST\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-EST\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface=\"{$hsIface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-WAN\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface-list=\"{$pal}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-WAN\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" connection-state=\"invalid\" action=\"drop\" comment=\"hyb-fw-{$id}-hs-INV\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" hotspot=\"auth\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-AUTH-INET\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-AUTH-INET\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$ppIface}\" action=\"drop\" log=\"yes\" log-prefix=\"hyb-DROP-PP\" comment=\"hyb-fw-{$id}-pp-DROP-UNAUTH\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" action=\"drop\" log=\"yes\" log-prefix=\"hyb-DROP-HS\" comment=\"hyb-fw-{$id}-hs-DROP-UNAUTH\"",
                ""
            ];
        }

        return [
            "# Firewall [FULL] - Complete security for high-end device",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-fw-{$id}\"]; } on-error={}",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-est\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-est\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface=\"{$hsIface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-wan\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface-list=\"{$pal}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-wan\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" connection-state=\"invalid\" action=\"drop\" comment=\"hyb-fw-{$id}-pp-inv\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" connection-state=\"invalid\" action=\"drop\" comment=\"hyb-fw-{$id}-hs-inv\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" hotspot=\"auth\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-inet\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-inet\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$ppIface}\" out-interface=\"{$hsIface}\" action=\"drop\" comment=\"hyb-fw-{$id}-xvlan-pp\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" out-interface=\"{$ppIface}\" action=\"drop\" comment=\"hyb-fw-{$id}-xvlan-hs\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$ppIface}\" action=\"drop\" log=\"yes\" log-prefix=\"hyb-DROP-PP\" comment=\"hyb-fw-{$id}-pp-drop\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$hsIface}\" action=\"drop\" log=\"yes\" log-prefix=\"hyb-DROP-HS\" comment=\"hyb-fw-{$id}-hs-drop\"",
            ""
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
            // Hotspot: srcnat masquerade for hotspot pool subnet going to WAN
            ":do { /ip firewall nat add chain=\"srcnat\" action=\"masquerade\" src-address=\"{$hsNet}/{$hsCidr}\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-hs\"; } on-error={ :error \"hyb-nat-hs-fail\" }",
            // PPPoE: srcnat masquerade for PPPoE active sessions going to WAN (out-interface-list only)
            ":do { /ip firewall nat add chain=\"srcnat\" action=\"masquerade\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-pp\"; } on-error={ :error \"hyb-nat-pp-fail\" }",
            ":do { /ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64872\" protocol=\"tcp\" dst-port=\"80\" in-interface=\"{$hsIface}\" comment=\"hyb-redir80-{$id}\"; } on-error={ :error \"hyb-redir80-fail\" }",
            ":do { /ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64875\" protocol=\"tcp\" dst-port=\"443\" in-interface=\"{$hsIface}\" comment=\"hyb-redir443-{$id}\"; } on-error={ :error \"hyb-redir443-fail\" }",
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

        $mgmt       = SubnetHelper::normalize($params['management_subnet'] ?? '10.0.0.0/8');
        $isLowEnd   = $params['is_low_end'] ?? false;
        $syslogHost = $params['syslog_host'] ?? config('services.syslog.host', $params['radius_server'] ?? '10.8.0.1');

        $script   = [];
        $script[] = "/log info \"=== Zero-Config Hybrid Deployment (Bridge Mode) ===\"";
        $script[] = "/log info \"Router: {$params['router_id']}\"";
        $script[] = "/log info \"Bridge: {$bridge}\"";
        $script[] = "";
        $script = array_merge($script, $this->bootstrapRadiusAaaAttributes());

        $script = array_merge($script, $this->generateBridgeSetup($params));
        $script = array_merge($script, $this->generateHotspotConfig($params, $params['bridge'], 'Bridge'));
        $script = array_merge($script, $this->generatePppoeConfig($params, $params['bridge'], 'Bridge'));
        $script = array_merge($script, $this->generateRadiusSetup($params));
        $script = array_merge($script, $this->generateManagementInputRules($params));
        $script = array_merge($script, $this->bootstrapSnmpSyslog($mgmt, $syslogHost, !$isLowEnd));
        $script = array_merge($script, $this->bootstrapSubscriberQueues("hyb-{$id}", 'ether1', $isLowEnd));
        if (!$isLowEnd) {
            $script = array_merge($script, $this->bootstrapTrafficFlow("hyb-{$id}", $syslogHost));
        }
        $vpnIpBridge = $params['vpn_ip'] ?? null;
        $allowAddrBridge = ($vpnIpBridge && filter_var($vpnIpBridge, FILTER_VALIDATE_IP)) ? $vpnIpBridge . '/32' : $mgmt;
        $script = array_merge($script, $this->bootstrapMgmtRateLimit("hyb-{$id}", $allowAddrBridge));
        $script = array_merge($script, $this->bootstrapSecurityHardening([
            'id'               => $id,
            'is_low_end'       => $isLowEnd,
            'wan_list'         => 'WAN',
            'subscriber_ifaces' => [
                ['in' => $params['bridge'], 'is_list' => false, 'pool_cidr' => $params['hotspot_pool']->network_cidr, 'tag' => 'HS'],
            ],
        ]));
        $script = array_merge($script, $this->generateBridgeFirewallRules($params));
        $script = array_merge($script, $this->bootstrapGlobalDefaultDrop("hyb-{$id}"));
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
            ":do { /interface list add name=\"LAN\" comment=\"Local Area Network\" } on-error={ /log info \"hyb-$id: INFO - LAN list exists or failed\" }",
            ":do { /interface list add name=\"WAN\" comment=\"Wide Area Network\" } on-error={ /log info \"hyb-$id: INFO - WAN list exists or failed\" }",
            ":do { /interface list member add list=\"WAN\" interface=\"ether1\" } on-error={ /log info \"hyb-$id: WARN - Failed to add ether1 to WAN\" }",
            "",
            "# Bridge Setup",
            ":do { /interface bridge port remove [/interface bridge port find bridge=\"{$bridge}\"]; } on-error={ /log info \"hyb-$id: INFO - No bridge ports to remove\" }",
            ":do { /interface bridge remove [/interface bridge find name=\"{$bridge}\"]; } on-error={ /log info \"hyb-$id: INFO - No bridge to remove\" }",
            ":do { /interface bridge add name=\"{$bridge}\" protocol-mode=\"rstp\" comment=\"hyb-br-{$id}\" } on-error={ /log error \"hyb-$id: FATAL - bridge add failed\" }",
            ":delay 500ms;",
        ];

        foreach ($interfaces as $iface) {
            $script[] = ":do { /interface bridge port add bridge=\"{$bridge}\" interface=\"{$iface}\" comment=\"hyb-port-{$id}\" } on-error={ /log error \"hyb-$id: FATAL - port add failed for $iface\" }";
            $script[] = ":do { /interface list member add list=\"LAN\" interface=\"{$iface}\" } on-error={ /log warning \"hyb-{$id}: Failed to add {$iface} to LAN list — inter-VLAN routing or firewall rules may not apply correctly\" }";
        }

        $script[] = "";
        return $script;
    }

    private function generateBridgeHotspotConfig(array $params): array
    {
        return $this->buildHotspotConfig($params, $params['bridge'], 'Bridge');
    }

    private function generateBridgePppoeConfig(array $params): array
    {
        return $this->buildPppoeConfig($params, $params['bridge'], 'Bridge');
    }

    private function buildHotspotConfig(array $params, string $iface, string $label): array
    {
        $pool       = $params['hotspot_pool'];
        $id         = $params['id'];
        $poolName   = "hyb-hs-pool-{$id}";
        $dhcpName   = "hyb-hs-dhcp-{$id}";
        $profile    = "hyb-hs-prof-{$id}";
        $server     = "hyb-hs-srv-{$id}";
        $gateway    = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        [$network, $cidr] = array_pad(explode('/', $pool->network_cidr, 2), 2, '24');
        $dnsPrimary   = $pool->dns_primary ?? '8.8.8.8';
        $dnsSecondary = $pool->dns_secondary ?? '8.8.4.4';
        $dns          = "{$dnsPrimary},{$dnsSecondary}";
        $portalHost = $params['portal_host'] ?? null;
        $s          = [];

        $s[] = "# Hotspot Config ({$label})";
        $s[] = ":do { /ip address remove [/ip address find interface=\"{$iface}\"]; } on-error={}";
        $s[] = ":do { /ip address add address=\"{$gateway}/{$cidr}\" interface=\"{$iface}\" comment=\"hyb-hs-gw-{$id}\"; } on-error={ :error \"hyb-hs-ip-fail\" }";
        $s[] = ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"]; } on-error={}";
        $s[] = ":do { /ip pool add name=\"{$poolName}\" ranges=\"{$pool->range_start}-{$pool->range_end}\" comment=\"hyb-hs-{$id}\"; } on-error={ :error \"hyb-hs-pool-fail\" }";
        $s[] = ":do { /ip dhcp-server remove [/ip dhcp-server find name=\"{$dhcpName}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server add name=\"{$dhcpName}\" interface=\"{$iface}\" address-pool=\"{$poolName}\" lease-time=\"1h\" disabled=no; } on-error={ :error \"hyb-hs-dhcp-fail\" }";
        $s[] = ":do { /ip dhcp-server network remove [/ip dhcp-server network find comment~\"hyb-hs-net-{$id}\"]; } on-error={}";
        $s[] = ":do { /ip dhcp-server network add address=\"{$network}/{$cidr}\" gateway=\"{$gateway}\" dns-server=\"{$dns}\" comment=\"hyb-hs-net-{$id}\"; } on-error={ :error \"hyb-hs-net-fail\" }";
        $s[] = ":do { /ip hotspot profile remove [/ip hotspot profile find name=\"{$profile}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address=\"{$gateway}\" use-radius=yes html-directory=\"hotspot\" http-cookie-lifetime=\"1d\" dns-name=hotspot.local; } on-error={ :error \"hyb-hs-prof-fail\" }";
        $s[] = ":do { /ip hotspot profile set [/ip hotspot profile find name=\"{$profile}\"] login-by=\"http-chap,http-pap\"; } on-error={ /log warning \"hyb: Failed to set hotspot login-by — default auth methods may be broader than expected\" }";
        $s[] = ":do { /ip hotspot remove [/ip hotspot find name=\"{$server}\"]; } on-error={}";
        $s[] = ":do { /ip hotspot add name=\"{$server}\" interface=\"{$iface}\" profile=\"{$profile}\" address-pool=\"{$poolName}\" addresses-per-mac=2 idle-timeout=\"5m\" keepalive-timeout=\"2m\" disabled=no; } on-error={ :error \"hyb-hs-srv-fail\" }";
        if ($portalHost) {
            $s[] = ":do { /ip hotspot walled-garden remove [/ip hotspot walled-garden find comment=\"hyb-wg-{$id}\"]; } on-error={}";
            $s[] = ":do { /ip hotspot walled-garden add dst-host=\"{$portalHost}\" action=\"allow\" comment=\"hyb-wg-{$id}\"; } on-error={ /log warning \"hyb-{$id}: Failed to add walled-garden entry for {$portalHost} — captive portal may be unreachable\" }";
        }
        $s[] = "";
        return $s;
    }

    private function buildPppoeConfig(array $params, string $iface, string $label): array
    {
        $pool        = $params['pppoe_pool'];
        $id          = $params['id'];
        $pal         = $params['pppoe_active_list'] ?? "PPPOE-ACTIVE-HYB-{$id}";
        $poolName    = "hyb-pp-pool-{$id}";
        $profile     = "hyb-pp-prof-{$id}";
        $serviceName = "hyb-pp-svc-{$id}";
        $gateway     = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);
        $s           = [];

        $s[] = "# PPPoE Config ({$label})";
        $s[] = ":do { /interface list add name=\"{$pal}\" } on-error={}";
        $s[] = ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"]; } on-error={}";
        $s[] = ":do { /ip pool add name=\"{$poolName}\" ranges=\"{$pool->range_start}-{$pool->range_end}\" comment=\"hyb-pp-{$id}\"; } on-error={ /log error \"hyb-$id: FATAL - PPPoE pool add failed\" }";
        $s[] = ":do { /ppp profile remove [/ppp profile find name=\"{$profile}\"]; } on-error={}";
        $s[] = ":do { /ppp profile add name=\"{$profile}\" local-address=\"\" remote-address=none comment=\"hyb-pp-{$id}\"; } on-error={ /log error \"hyb-$id: FATAL - PPP profile add failed\" }";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"{$profile}\"] use-radius=yes rate-limit=\"\" only-one=yes change-tcp-mss=yes; } on-error={ /log error \"hyb-$id: FATAL - profile RADIUS flags failed\" }";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"{$profile}\"] interface-list=\"{$pal}\"; } on-error={ /log warning \"hyb-$id: Failed to set profile interface-list (non-fatal)\" }";
        $s = array_merge($s, $this->bootstrapPppAaaHardening("hyb-{$id}", $profile));
        $s = array_merge($s, $this->bootstrapPppSessionLogging("hyb-{$id}", $profile));
        $s[] = ":do { /interface pppoe-server server remove [/interface pppoe-server server find service-name=\"{$serviceName}\"]; } on-error={}";
        $s[] = ":do { /interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$iface}\" default-profile=\"{$profile}\" authentication=\"pap,chap,mschap2\" keepalive-timeout=\"10\" max-mtu=\"1480\" max-mru=\"1480\" disabled=no; } on-error={ /log error \"hyb-$id: FATAL - PPPoE server add failed\" }";
        $s[] = ":do { /interface pppoe-server server set [/interface pppoe-server server find service-name=\"{$serviceName}\"] disabled=no; } on-error={ /log warning \"hyb-$id: Failed to enable PPPoE server (non-fatal)\" }";
        $s[] = "/log info \"hyb-{$id}: PPPoE server '{$serviceName}' started successfully.\"";
        $rs = $params['radius_server'] ?? '10.8.0.1';
        $s = array_merge($s, $this->bootstrapOperationalLogging("hyb-{$id}", $serviceName, $rs));
        $s[] = "";
        return $s;
    }

    /**
     * Generate tier-based firewall rules for Bridge mode - minimal for hAP lite, full for high-end
     */
    private function generateBridgeFirewallRules(array $params): array
    {
        $bridge = $params['bridge'];
        $id     = $params['id'];
        $pal    = $params['pppoe_active_list'];
        $isLowEnd = $params['is_low_end'] ?? false;

        // Rules appended in correct order — NO place-before=0 (which reverses insertion order)
        if ($isLowEnd) {
            return [
                "# Firewall [MINIMAL] - Bridge mode essential security",
                ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-fw-{$id}\"]; } on-error={}",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-EST\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface=\"{$bridge}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-WAN\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface-list=\"{$pal}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-WAN\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" connection-state=\"invalid\" action=\"drop\" comment=\"hyb-fw-{$id}-INV\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" hotspot=\"auth\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-AUTH\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-AUTH\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" action=\"drop\" log=\"yes\" log-prefix=\"hyb-DROP\" comment=\"hyb-fw-{$id}-DROP-UNAUTH\"",
                ""
            ];
        }

        return [
            "# Firewall [FULL] - Bridge mode complete security",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-fw-{$id}\"]; } on-error={}",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-est\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-est\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface=\"{$bridge}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-wan\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface-list=\"{$pal}\" connection-state=\"established,related\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-wan\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" connection-state=\"invalid\" action=\"drop\" comment=\"hyb-fw-{$id}-hs-inv\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" connection-state=\"invalid\" action=\"drop\" comment=\"hyb-fw-{$id}-pp-inv\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" hotspot=\"auth\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-hs-inet\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"{$pal}\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hyb-fw-{$id}-pp-inet\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$bridge}\" action=\"drop\" log=\"yes\" log-prefix=\"hyb-DROP\" comment=\"hyb-fw-{$id}-drop\"",
            ""
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
            ":do { /ip firewall nat add chain=\"srcnat\" action=\"masquerade\" src-address=\"{$hsNet}/{$hsCidr}\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-hs\"; } on-error={ :error \"hyb-nat-hs-fail\" }",
            ":do { /ip firewall nat add chain=\"srcnat\" action=\"masquerade\" in-interface-list=\"{$pal}\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-pp\"; } on-error={ :error \"hyb-nat-pp-fail\" }",
            ":do { /ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64872\" protocol=\"tcp\" dst-port=\"80\" in-interface=\"{$bridge}\" comment=\"hyb-redir80-{$id}\"; } on-error={ :error \"hyb-redir80-fail\" }",
            ":do { /ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64875\" protocol=\"tcp\" dst-port=\"443\" in-interface=\"{$bridge}\" comment=\"hyb-redir443-{$id}\"; } on-error={ :error \"hyb-redir443-fail\" }",
            "",
        ];
    }

    // -------------------------------------------------------------------------
    // SHARED HELPERS
    // -------------------------------------------------------------------------

    private function generateRadiusSetup(array $params): array
    {
        $rs  = $params['radius_server'];
        // Escape secret for safe RouterOS string embedding (uses shared trait method)
        $sec = $this->escapeRouterOsString((string) $params['radius_secret']);
        $id  = $params['id'];
        // Service name must match buildPppoeConfig — pass to netwatch for fail-closed disable on DOWN
        $pppoeServiceName = "hyb-pp-svc-{$id}";

        return array_merge(
            [
                "# RADIUS - RADIUS-ONLY AAA (hotspot + PPPoE): Mikrotik-Rate-Limit + Framed-Pool enforced per-user",
                ":do { /radius remove [/radius find service=hotspot comment~\"hyb-hs-rad-{$id}\"]; } on-error={}",
                ":do { /radius add service=hotspot address=\"{$rs}\" secret=\"{$sec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"hyb-hs-rad-{$id}\"; } on-error={ :error \"hyb-hs-rad-fail\" }",
                ":do { /radius remove [/radius find service=ppp comment~\"hyb-pp-rad-{$id}\"]; } on-error={}",
                ":do { /radius add service=ppp address=\"{$rs}\" secret=\"{$sec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"hyb-pp-rad-{$id}\"; } on-error={ :error \"hyb-pp-rad-fail\" }",
            ],
            $this->bootstrapRadiusNetwatch("hyb-{$id}", $rs, $pppoeServiceName),
            [
                ":local pingResult [/ping address=\"{$rs}\" count=2 interval=500ms]; :if (\$pingResult = 0) do={ /log warning \"hyb-{$id}: CRITICAL - RADIUS {$rs} unreachable at deploy time.\" } else={ /log info \"hyb-{$id}: RADIUS {$rs} reachable (\$pingResult replies). Netwatch monitoring active.\" }",
                "/ppp aaa set use-radius=yes accounting=yes interim-update=5m use-circuit-id-in-nas-port-id=yes",
                ":do { /ip hotspot user remove [/ip hotspot user find] } on-error={}",
                ":do { /ppp secret remove [/ppp secret find] } on-error={}",
                "",
            ]
        );
    }

    private function generateManagementInputRules(array $params): array
    {
        $id    = $params['id'];
        $mgmt  = SubnetHelper::normalize($params['management_subnet'] ?? '10.0.0.0/8');
        $mport = '22,8291,8728,8729';
        $rs    = $params['radius_server'] ?? '10.8.0.1';
        $isLowEnd = $params['is_low_end'] ?? false;

        $rules = [
            "# Management & Service Hardening",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hyb-mgmt-{$id}\"] } on-error={}",
        ];
        // Shared bootstrap: service hardening, firewall logging, brute-force
        $vpnIp = $params['vpn_ip'] ?? null;
        $allowAddr = ($vpnIp && filter_var($vpnIp, FILTER_VALIDATE_IP)) ? $vpnIp . '/32' : $mgmt;
        $rules = array_merge($rules, $this->bootstrapServiceHardening("hyb-{$id}", $mgmt, $vpnIp));
        $rules = array_merge($rules, $this->bootstrapFirewallLogging("hyb-{$id}"));
        // Established connections
        $rules[] = "/ip firewall filter add chain=input connection-state=\"established,related\" action=\"accept\" comment=\"hyb-mgmt-{$id}-est\"";
        $rules = array_merge($rules, $this->bootstrapBruteForceProtection("hyb-mgmt-{$id}", $allowAddr));
        // Management allow
        $rules[] = "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"{$mport}\" src-address=\"{$allowAddr}\" action=\"accept\" comment=\"hyb-mgmt-{$id}-allow\"";
        $rules[] = "/ip firewall filter add chain=input protocol=\"udp\" dst-port=\"161\" src-address=\"{$rs}\" action=\"accept\" comment=\"hyb-mgmt-{$id}-snmp\"";
        $rules[] = "/ip firewall filter add chain=input protocol=\"udp\" dst-port=\"3799\" src-address=\"{$rs}\" action=\"accept\" comment=\"hyb-mgmt-{$id}-coa\"";
        $rules[] = "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"{$mport}\" action=\"drop\" log=\"yes\" log-prefix=\"hyb-mgmt-drop\" comment=\"hyb-mgmt-{$id}-drop\"";

        // TCP flag anomaly detection (high-end only)
        if (!$isLowEnd) {
            $rules = array_merge($rules, $this->bootstrapTcpFlagAnomalyDetection("hyb-mgmt-{$id}"));
        }

        // Connection tracking
        $rules[] = $this->bootstrapConnectionTracking();
        $rules[] = "";
        return $rules;
    }

    // normalizeInterfaces() delegated to trait as normalizeInterfaceList()
    private function normalizeInterfaces($rawInterfaces, ?string $fallback): array
    {
        return $this->normalizeInterfaceList($rawInterfaces, $fallback);
    }

    // getSafeGatewayIp() and escapeRouterOsString() provided by ZeroConfigBootstrapTrait
}
