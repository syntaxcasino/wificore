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
        $hotspotPool = $this->resolveServicePool($service, 'hotspotPool', $advancedConfig['hotspot_pool_id'] ?? null);
        $pppoePool   = $this->resolveServicePool($service, 'pppoePool', $advancedConfig['pppoe_pool_id'] ?? null);

        if (!$hotspotPool || !$pppoePool) {
            throw new \Exception('Hybrid service requires both hotspot and pppoe IP pools');
        }

        $id = substr(str_replace('-', '', (string) $router->id), 0, 8);

        if ($bridgeMode) {
            $interfaces = $this->normalizeInterfaces($service->interfaces, $service->interface_name);
            $resolvedRs = config('radius.vpn_server_ip', config('services.radius.host', '10.8.0.1'));
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

        $resolvedRs = config('radius.vpn_server_ip', config('services.radius.host', '10.8.0.1'));
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

        $script   = [
            "# Zero-Config Hybrid Deployment (VLAN-Enforced)"
        ];
        $script = array_merge($script, $this->bootstrapRadiusAaaAttributes());

        $script = array_merge($script, $this->generateVlanSetup($params));
        $script = array_merge($script, $this->generateHotspotConfig($params, "vlan-hs-{$params['hotspot_vlan_id']}", "VLAN {$params['hotspot_vlan_id']}"));
        $script = array_merge($script, $this->generatePppoeConfig($params, "vlan-pp-{$params['pppoe_vlan_id']}", "VLAN {$params['pppoe_vlan_id']}"));
        $script = array_merge($script, $this->generateRadiusSetup($params));
        $script = array_merge($script, $this->generateManagementInputRules($params));
        $script = array_merge($script, $this->bootstrapSnmpSyslog($mgmt, $syslogHost, !$isLowEnd));
        $script = array_merge($script, $this->bootstrapSubscriberQueues("hyb-{$id}", '', $isLowEnd));
        if (!$isLowEnd) {
            $script = array_merge($script, $this->bootstrapTrafficFlow("hyb-{$id}", $syslogHost));
        }
        $allowAddrVlan = $mgmt; // Use only mgmt subnet for ACLs
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

        return implode("\n", $script);
    }

    private function generateVlanSetup(array $params): array
    {
        $parent = $params['parent_interface'];
        $hsVlan = $params['hotspot_vlan_id'];
        $ppVlan = $params['pppoe_vlan_id'];

        return [
            "# VLAN Setup - Traffic Separation",
            "/interface vlan remove [find name=\"vlan-hs-{$hsVlan}\"]]",
            "/interface vlan add name=vlan-hs-{$hsVlan} vlan-id=\"{$hsVlan}\" interface=\"{$parent}\" comment=\"hyb-hs-vlan\"",
            "",
            "/interface vlan remove [find name=\"vlan-pp-{$ppVlan}\"]]",
            "/interface vlan add name=vlan-pp-{$ppVlan} vlan-id=\"{$ppVlan}\" interface=\"{$parent}\" comment=\"hyb-pp-vlan\"",
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
        $portalUrl = $params['portal_url'] ?? null;
        $dnsName = $portalHost ?: 'hotspot.local';
        $portalHtml = null;
        if ($portalUrl) {
            $escapedUrl = htmlspecialchars((string) $portalUrl, ENT_QUOTES);
            $portalHtml = $this->escapeRouterOsString("<html><head><meta http-equiv='refresh' content='0;url={$escapedUrl}'></head><body><a href='{$escapedUrl}'>Continue</a></body></html>");
        }
        $s          = [];

        $s[] = "# Hotspot Config ({$label})";
        $s[] = "/ip address remove [find interface=\"{$iface}\"]";
        $s[] = "/ip address add address=\"{$gateway}/{$cidr}\" interface=\"{$iface}\" comment=\"hyb-hs-gw-{$id}\"";
        $s[] = "/ip pool remove [find name=\"{$poolName}\"]";
        $s[] = "/ip pool add name=\"{$poolName}\" ranges=\"{$pool->range_start}-{$pool->range_end}\" comment=\"hyb-hs-{$id}\"";
        $s[] = "/ip dhcp-server remove [find name=\"{$dhcpName}\"]";
        $s[] = "/ip dhcp-server add name=\"{$dhcpName}\" interface=\"{$iface}\" address-pool=\"{$poolName}\" lease-time=\"1h\" disabled=no";
        $s[] = "/ip dhcp-server network remove [find comment~=\"hyb-hs-net-{$id}\"]]";
        $s[] = "/ip dhcp-server network add address=\"{$network}/{$cidr}\" gateway=\"{$gateway}\" dns-server=\"{$dns}\" comment=\"hyb-hs-net-{$id}\"";
        $s[] = "/ip hotspot profile remove [find name=\"{$profile}\"]";
        $s[] = "/ip hotspot profile add name=\"{$profile}\" hotspot-address=\"{$gateway}\" use-radius=yes html-directory=\"hotspot\" http-cookie-lifetime=\"1d\" dns-name=\"{$dnsName}\"";
        $s[] = "/ip hotspot profile set \"{$profile}\" login-by=\"http-chap,http-pap\"";
        if ($portalHtml) {
            $s[] = "/file set \"hotspot/login.html\" contents=\"{$portalHtml}\"";
        }
        $s[] = "/ip hotspot remove [find name=\"{$server}\"]";
        $s[] = "/ip hotspot add name=\"{$server}\" interface=\"{$iface}\" profile=\"{$profile}\" address-pool=\"{$poolName}\" addresses-per-mac=2 idle-timeout=\"5m\" keepalive-timeout=\"2m\" disabled=no";
        if ($portalHost) {
            $s[] = "/ip hotspot walled-garden remove [find comment=\"hyb-wg-{$id}\"]]";
            $s[] = "/ip hotspot walled-garden add dst-host=\"{$portalHost}\" action=\"allow\" comment=\"hyb-wg-{$id}\"";
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
                "/ip firewall filter remove [find comment~=\"hyb-fw-{$id}\"]]",
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
            "/ip firewall filter remove [find comment~=\"hyb-fw-{$id}\"]]",
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
            "/ip firewall nat remove [find comment~=\"hyb-nat-{$id}\"]]",
            // Hotspot: srcnat masquerade for hotspot pool subnet going to WAN
            "/ip firewall nat add chain=\"srcnat\" action=\"masquerade\" src-address=\"{$hsNet}/{$hsCidr}\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-hs\"",
            // PPPoE: srcnat masquerade for PPPoE active sessions going to WAN (out-interface-list only)
            "/ip firewall nat add chain=\"srcnat\" action=\"masquerade\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-pp\"",
            "/ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64872\" protocol=\"tcp\" dst-port=\"80\" in-interface=\"{$hsIface}\" comment=\"hyb-redir80-{$id}\"",
            "/ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64875\" protocol=\"tcp\" dst-port=\"443\" in-interface=\"{$hsIface}\" comment=\"hyb-redir443-{$id}\"",
            "",
        ];
    }

    // -------------------------------------------------------------------------
    // BRIDGE MODE
    // -------------------------------------------------------------------------

    private function resolveServicePool(RouterService $service, string $relation, ?string $poolId): ?TenantIpPool
    {
        if ($service->relationLoaded($relation)) {
            $pool = $service->getRelation($relation);
            if ($pool instanceof TenantIpPool) {
                if ($poolId === null || (string) $pool->id === (string) $poolId) {
                    return $pool;
                }
            }
        }

        if (! is_string($poolId) || trim($poolId) === '') {
            return null;
        }

        return TenantIpPool::withoutGlobalScopes()->find($poolId);
    }

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

        $script   = [
            "# Zero-Config Hybrid Deployment (Bridge Mode)"
        ];
        $script = array_merge($script, $this->bootstrapRadiusAaaAttributes());

        $script = array_merge($script, $this->generateBridgeSetup($params));
        $script = array_merge($script, $this->generateHotspotConfig($params, $params['bridge'], 'Bridge'));
        $script = array_merge($script, $this->generatePppoeConfig($params, $params['bridge'], 'Bridge'));
        $script = array_merge($script, $this->generateRadiusSetup($params));
        $script = array_merge($script, $this->generateManagementInputRules($params));
        $script = array_merge($script, $this->bootstrapSnmpSyslog($mgmt, $syslogHost, !$isLowEnd));
        $script = array_merge($script, $this->bootstrapSubscriberQueues("hyb-{$id}", '', $isLowEnd));
        if (!$isLowEnd) {
            $script = array_merge($script, $this->bootstrapTrafficFlow("hyb-{$id}", $syslogHost));
        }
        $allowAddrBridge = $mgmt; // Use only mgmt subnet for ACLs
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

        return implode("\n", $script);
    }

    private function generateBridgeSetup(array $params): array
    {
        $bridge     = $params['bridge'];
        $id         = $params['id'];
        $interfaces = $params['interfaces'];

        $script = [
            "# Interface Lists",
            "/interface list add name=\"LAN\" comment=\"Local Area Network\"",
            "/interface list add name=\"WAN\" comment=\"Wide Area Network\"",
            "/interface list member add list=\"WAN\" interface=\"ether1\"",
            "",
            "# Bridge Setup",
            "/interface bridge port remove [find bridge=\"{$bridge}\"]",
            "/interface bridge remove [find name=\"{$bridge}\"]",
            "/interface bridge add name=\"{$bridge}\" protocol-mode=\"rstp\" comment=\"hyb-br-{$id}\"",
            ":delay 500ms",
        ];

        foreach ($interfaces as $iface) {
            $script[] = "/interface bridge port add bridge=\"{$bridge}\" interface=\"{$iface}\" comment=\"hyb-port-{$id}\"";
            $script[] = "/interface list member add list=\"LAN\" interface=\"{$iface}\"";
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
        return $this->buildPppoeConfig(array_merge($params, ['assign_interface_ip' => false]), $params['bridge'], 'Bridge');
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
        $portalUrl = $params['portal_url'] ?? null;
        $dnsName = $portalHost ?: 'hotspot.local';
        $portalHtml = null;
        if ($portalUrl) {
            $escapedUrl = htmlspecialchars((string) $portalUrl, ENT_QUOTES);
            $portalHtml = $this->escapeRouterOsString("<html><head><meta http-equiv='refresh' content='0;url={$escapedUrl}'></head><body><a href='{$escapedUrl}'>Continue</a></body></html>");
        }
        $s          = [];

        $s[] = "# Hotspot Config ({$label})";
        $s[] = "/ip address remove [find interface=\"{$iface}\"]";
        $s[] = "/ip address add address=\"{$gateway}/{$cidr}\" interface=\"{$iface}\" comment=\"hyb-hs-gw-{$id}\"";
        $s[] = "/ip pool remove [find name=\"{$poolName}\"]";
        $s[] = "/ip pool add name=\"{$poolName}\" ranges=\"{$pool->range_start}-{$pool->range_end}\" comment=\"hyb-hs-{$id}\"";
        $s[] = "/ip dhcp-server remove [find name=\"{$dhcpName}\"]";
        $s[] = "/ip dhcp-server add name=\"{$dhcpName}\" interface=\"{$iface}\" address-pool=\"{$poolName}\" lease-time=\"1h\" disabled=no";
        $s[] = "/ip dhcp-server network remove [find comment~=\"hyb-hs-net-{$id}\"]]";
        $s[] = "/ip dhcp-server network add address=\"{$network}/{$cidr}\" gateway=\"{$gateway}\" dns-server=\"{$dns}\" comment=\"hyb-hs-net-{$id}\"";
        $s[] = "/ip hotspot profile remove [find name=\"{$profile}\"]";
        $s[] = "/ip hotspot profile add name=\"{$profile}\" hotspot-address=\"{$gateway}\" use-radius=yes html-directory=\"hotspot\" http-cookie-lifetime=\"1d\" dns-name=\"{$dnsName}\"";
        $s[] = "/ip hotspot profile set \"{$profile}\" login-by=\"http-chap,http-pap\"";
        if ($portalHtml) {
            $s[] = "/file set \"hotspot/login.html\" contents=\"{$portalHtml}\"";
        }
        $s[] = "/ip hotspot remove [find name=\"{$server}\"]";
        $s[] = "/ip hotspot add name=\"{$server}\" interface=\"{$iface}\" profile=\"{$profile}\" address-pool=\"{$poolName}\" addresses-per-mac=2 idle-timeout=\"5m\" keepalive-timeout=\"2m\" disabled=no";
        if ($portalHost) {
            $s[] = "/ip hotspot walled-garden remove [find comment=\"hyb-wg-{$id}\"]]";
            $s[] = "/ip hotspot walled-garden add dst-host=\"{$portalHost}\" action=\"allow\" comment=\"hyb-wg-{$id}\"";
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
        [, $pppoeCidr] = array_pad(explode('/', $pool->network_cidr, 2), 2, '24');
        $assignInterfaceIp = $params['assign_interface_ip'] ?? true;
        $s           = [];

        $s[] = "# PPPoE Config ({$label})";
        $s[] = "/interface list add name=\"{$pal}\"";
        if ($assignInterfaceIp) {
            $s[] = "/ip address remove [find interface=\"{$iface}\"]]";
            $s[] = "/ip address add address=\"{$gateway}/{$pppoeCidr}\" interface=\"{$iface}\" comment=\"hyb-pp-gw-{$id}\"";
        }
        $s[] = "/ip pool remove [find name=\"{$poolName}\"]]";
        $s[] = "/ip pool add name=\"{$poolName}\" ranges=\"{$pool->range_start}-{$pool->range_end}\" comment=\"hyb-pp-{$id}\"";
        $s[] = "/ppp profile remove [find name=\"{$profile}\"]]";
        $s[] = "/ppp profile add name=\"{$profile}\" local-address=\"{$gateway}\" remote-address=none comment=\"hyb-pp-{$id}\"";
        $s[] = "/ppp profile set \"{$profile}\" use-radius=yes rate-limit=\"\" only-one=yes change-tcp-mss=yes";
        $s[] = "/ppp profile set \"{$profile}\" interface-list=\"{$pal}\"";
        $s = array_merge($s, $this->bootstrapPppAaaHardening("hyb-{$id}", $profile));
        $s = array_merge($s, $this->bootstrapPppSessionLogging("hyb-{$id}", $profile));
        $s[] = "/interface pppoe-server server remove [find service-name=\"{$serviceName}\"]]";
        $s[] = "/interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$iface}\" default-profile=\"{$profile}\" authentication=\"pap,chap,mschap2\" keepalive-timeout=\"10\" max-mtu=\"1480\" max-mru=\"1480\" disabled=no";
        $s[] = "# PPPoE server configured";
        $rs = $params['radius_server'] ?? '10.8.0.1';
        $s = array_merge($s, $this->bootstrapOperationalLogging("hyb-{$id}", $serviceName, $rs));
        $s[] = "";
        return $s;
    }

    /**
     * Generate tier-based firewall rules for Bridge mode - minimal for hAP lite, full for high-end
     */

    /**
     * Generate tier-based firewall rules for Bridge mode - minimal for hAP lite, full for high-end
     */

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
                "/ip firewall filter remove [find comment~=\"hyb-fw-{$id}\"]]",
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
            "/ip firewall filter remove [find comment~=\"hyb-fw-{$id}\"]]",
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
            "/ip firewall nat remove [find comment~=\"hyb-nat-{$id}\"]]",
            "/ip firewall nat add chain=\"srcnat\" action=\"masquerade\" src-address=\"{$hsNet}/{$hsCidr}\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-hs\"",
            "/ip firewall nat add chain=\"srcnat\" action=\"masquerade\" in-interface-list=\"{$pal}\" out-interface-list=\"WAN\" comment=\"hyb-nat-{$id}-pp\"",
            "/ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64872\" protocol=\"tcp\" dst-port=\"80\" in-interface=\"{$bridge}\" comment=\"hyb-redir80-{$id}\"",
            "/ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64875\" protocol=\"tcp\" dst-port=\"443\" in-interface=\"{$bridge}\" comment=\"hyb-redir443-{$id}\"",
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
                "/radius remove [find service=hotspot comment~=\"hyb-hs-rad-{$id}\"]]",
                "/radius add service=hotspot address=\"{$rs}\" secret=\"{$sec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"hyb-hs-rad-{$id}\"",
                "/radius remove [find service=ppp comment~=\"hyb-pp-rad-{$id}\"]]",
                "/radius add service=ppp address=\"{$rs}\" secret=\"{$sec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"hyb-pp-rad-{$id}\"",
            ],
            $this->bootstrapRadiusNetwatch("hyb-{$id}", $rs, $pppoeServiceName),
            [
                ":local pingResult [/ping address=\"{$rs}\" count=2 interval=500ms]; :if (\$pingResult = 0) do={}",
                "/ppp aaa set use-radius=yes accounting=yes interim-update=5m use-circuit-id-in-nas-port-id=yes",
                "/ip hotspot user remove [find]",
                "/ppp secret remove [find]",
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
            "/ip firewall filter remove [find comment~=\"hyb-mgmt-{$id}\"]]",
        ];
        // Shared bootstrap: service hardening, system clock, NTP, firewall logging, brute-force
        $allowAddr = $mgmt; // Use only mgmt subnet for ACLs
        $vpnIp = $params['vpn_ip'] ?? null;
        $rules = array_merge($rules, $this->bootstrapServiceHardening("hyb-{$id}", $mgmt, $vpnIp));
        $rules = array_merge($rules, $this->bootstrapSystemClock());
        $rules = array_merge($rules, $this->bootstrapNtpClient());
        $rules = array_merge($rules, $this->bootstrapFirewallLogging("hyb-{$id}", $isLowEnd));
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
