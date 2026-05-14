<?php

namespace App\Services\MikroTik;

use App\Models\RouterService;
use App\Services\RouterResourceManager;
use Illuminate\Support\Facades\Log;
use App\Models\Router;
use App\Models\TenantIpPool;
use App\Support\SubnetHelper;

/**
 * ISP-Grade Zero-Config Hotspot Generator
 * Multi-interface, VLAN-aware, RADIUS-only, CPU optimized for hAP Lite
 */
class ZeroConfigHotspotGenerator
{
    use ZeroConfigBootstrapTrait;
    /**
     * Generate Hotspot configuration from RouterService
     */
    public function generate(RouterService $service): string
    {
        $router = $service->router;
        $scriptSettings = RouterResourceManager::getScriptSettings($router);
        RouterResourceManager::logResourceInfo($router);

        $interfaces = $this->normalizeInterfaces($service);

        // Short ID (8 hex chars) — RouterOS names max 32 chars; full UUIDs exceed that
        $shortId = substr(str_replace('-', '', (string) $router->id), 0, 8);

        // Hotspot multi-interface mode A: single Hotspot instance on a shared bridge
        $bridgeName = "br-hs-{$shortId}";

        $radiusServer = config('radius.vpn_server_ip', config('services.radius.host', '10.8.0.1'));
        $resolvedRadiusServer = $radiusServer;
        if (filter_var($resolvedRadiusServer, FILTER_VALIDATE_IP) === false) {
            $resolvedRadiusServer = gethostbyname((string) $resolvedRadiusServer);
        }
        $radiusSecret = config('radius.secret', 'testing123');

        // CAPTIVE PORTAL: Generate tenant-specific portal URL
        // Format: https://<tenant_slug>.wificore.traidsolutions.com/api/portal/config
        $tenant = $router->tenant;
        $baseHost = config('app.base_domain', parse_url(config('app.url'), PHP_URL_HOST));
        $captivePortalUrl = null;
        $portalHost = null;
        
        if ($tenant && $tenant->subdomain) {
            // Portal URL for login redirect
            $captivePortalUrl = "https://{$tenant->subdomain}.{$baseHost}/api/portal/config?router_id={$router->id}";
            $portalHost = "{$tenant->subdomain}.{$baseHost}";
        }

        $syslogHost = config('services.syslog.host', $resolvedRadiusServer);

        $script = [
            "/log info \"=== ISP-Grade Multi-Interface Hotspot Deployment ===\"",
            "/log info \"Router: {$router->id}\"",
            ""
        ];

        $script = array_merge($script, $this->bootstrapRadiusAaaAttributes());

        $script = array_merge($script, [
            "# Interface Lists",
            ":if ([:len [/interface list find name=\"LAN\"]] = 0) do={ /interface list add name=\"LAN\" comment=\"Local Area Network\" }",
            ":if ([:len [/interface list find name=\"WAN\"]] = 0) do={ /interface list add name=\"WAN\" comment=\"Wide Area Network\" }",
            ":if ([:len [/interface list member find list=\"WAN\" interface=\"ether1\"]] = 0) do={ /interface list member add list=\"WAN\" interface=\"ether1\" }",
            ""
        ]);

        // Shared pool/gateway settings come from the service IP pool
        $pool = $service->ipPool;
        if (!$pool) {
            throw new \RuntimeException('IP pool not assigned to hotspot service');
        }

        $gateway = $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip);

        // Build access interfaces (optionally create per-interface VLAN sub-interfaces)
        $accessIfaces = [];
        foreach ($interfaces as $index => $ifaceConfig) {
            if ($ifaceConfig === null) {
                Log::warning('Hotspot generator: interface config is null; falling back to service interface_name', [
                    'router_id' => $router->id,
                    'service_id' => $service->id,
                    'index' => $index,
                ]);
                $ifaceConfig = ['name' => $service->interface_name];
            } elseif (is_scalar($ifaceConfig)) {
                $ifaceConfig = ['name' => (string) $ifaceConfig];
            }

            if (is_object($ifaceConfig)) {
                $ifaceConfig = (array) $ifaceConfig;
            }

            if (!is_array($ifaceConfig)) {
                throw new \Exception('Invalid interface configuration format at index ' . $index);
            }

            $ifaceName = $ifaceConfig['name'] ?? $ifaceConfig['interface'] ?? $service->interface_name;
            if (!$ifaceName) {
                throw new \Exception('Interface name is missing for interface config at index ' . $index);
            }

            // In Mode A, pool is shared; warn if UI provided a different pool per interface
            $configuredPool = $ifaceConfig['ipPool'] ?? $ifaceConfig['ip_pool'] ?? $ifaceConfig['ip_pool_id'] ?? null;
            if ($configuredPool !== null) {
                $configuredPoolId = is_array($configuredPool) ? ($configuredPool['id'] ?? null) : (is_object($configuredPool) ? ($configuredPool->id ?? null) : (string) $configuredPool);
                if ($configuredPoolId && (string) $configuredPoolId !== (string) $pool->id) {
                    Log::warning('Hotspot generator: Mode A uses a single shared IP pool; ignoring interface-specific pool', [
                        'router_id' => $router->id,
                        'service_id' => $service->id,
                        'interface' => $ifaceName,
                        'interface_pool_id' => (string) $configuredPoolId,
                        'service_pool_id' => (string) $pool->id,
                    ]);
                }
            }

            $vlanRequired = (bool) ($ifaceConfig['vlan_required'] ?? false);
            $vlanId = $ifaceConfig['vlan_id'] ?? null;

            if ($vlanRequired && $vlanId) {
                $vlanIface = "vlan-hotspot-{$vlanId}-{$ifaceName}";
                $script = array_merge($script, [
                    "# VLAN Setup",
                    ":do { /interface vlan remove [/interface vlan find name=\"{$vlanIface}\"] } on-error={}",
                    "/interface vlan add name=\"{$vlanIface}\" vlan-id=\"{$vlanId}\" interface=\"{$ifaceName}\" comment=\"Hotspot VLAN ({$router->id})\"",
                    ""
                ]);
                $accessIfaces[] = $vlanIface;
                continue;
            }

            $accessIfaces[] = $ifaceName;
        }

        $accessIfaces = array_values(array_unique(array_filter($accessIfaces, fn ($v) => is_string($v) && trim($v) !== '')));
        if (empty($accessIfaces)) {
            throw new \RuntimeException('No hotspot access interfaces provided');
        }

        // CRITICAL: Filter out WireGuard/VPN interfaces to prevent VPN disconnection
        // Adding a WireGuard interface to a bridge kills the VPN tunnel
        $vpnExcludePatterns = ['wireguard', 'wg', 'vpn', 'wg0', 'wg1'];
        $originalCount = count($accessIfaces);
        $accessIfaces = array_values(array_filter($accessIfaces, function ($iface) use ($vpnExcludePatterns, $router) {
            $lower = strtolower($iface);
            foreach ($vpnExcludePatterns as $pattern) {
                if (str_contains($lower, $pattern)) {
                    Log::warning('Hotspot generator: Excluded VPN/WireGuard interface from bridge', [
                        'router_id' => $router->id,
                        'excluded_interface' => $iface,
                        'reason' => 'Adding VPN interface to bridge would kill VPN connectivity',
                    ]);
                    return false;
                }
            }
            return true;
        }));

        if (empty($accessIfaces)) {
            throw new \RuntimeException('No valid hotspot access interfaces remaining after excluding VPN interfaces. Original interfaces were all VPN-related.');
        }

        if (count($accessIfaces) < $originalCount) {
            Log::info('Hotspot generator: VPN interfaces excluded from bridge', [
                'router_id' => $router->id,
                'original_count' => $originalCount,
                'remaining_count' => count($accessIfaces),
            ]);
        }

        // Create shared bridge and attach all access interfaces
        $script = array_merge($script, [
            "# Hotspot Access Bridge (Mode A)",
            ":do { /interface bridge add name=\"{$bridgeName}\" comment=\"hs-br-{$shortId}\" } on-error={}",
            ":do { /interface bridge port remove [/interface bridge port find bridge=\"{$bridgeName}\" comment=\"hs-port-{$shortId}\"] } on-error={}",
        ]);

        foreach ($accessIfaces as $iface) {
            $script[] = ":if ([:len [/interface find name=\"{$iface}\"]] = 0) do={ :error \"hs-iface-miss-{$iface}\" }";
            $script[] = ":if ([:len [/interface wireguard find name=\"{$iface}\"]] > 0) do={ :error \"hs-wg-refuse-{$iface}\" }";
            $script[] = ":do { /interface bridge port remove [/interface bridge port find bridge=\"{$bridgeName}\" interface=\"{$iface}\"] } on-error={}";
            $script[] = ":do { /interface bridge port add bridge=\"{$bridgeName}\" interface=\"{$iface}\" comment=\"hs-port-{$shortId}\" } on-error={ :error \"hs-port-fail-{$iface}\" }";
        }

        // Verify ALL expected bridge ports were added
        // Wrapped in { } block so :local variable is visible to :if on the next line
        // (top-level lines in /import .rsc each get their own scope — variables don't persist)
        $expectedPortCount = count($accessIfaces);
        $script[] = "{ :local actualPorts [:len [/interface bridge port find bridge=\"{$bridgeName}\" comment~\"hs-port\"]]; :if (\$actualPorts < {$expectedPortCount}) do={ :error \"hs-port-count-mismatch-{$shortId}\" } }";
        $script[] = "/log info \"hs-{$shortId}-ports-ok\"";
        $script[] = "";

        $params = [
            'router_id' => $router->id,
            'short_id'  => $shortId,
            'interface' => $bridgeName,
            'parent_interface' => null,
            'vlan_required' => false,
            'vlan_id' => null,
            'network_cidr' => $pool->network_cidr,
            'gateway_ip' => $gateway,
            'range_start' => $pool->range_start,
            'range_end' => $pool->range_end,
            'dns_primary' => $pool->dns_primary ?? '8.8.8.8',
            'dns_secondary' => $pool->dns_secondary ?? '8.8.4.4',
            'radius_profile' => $service->radius_profile,
            'radius_server' => $resolvedRadiusServer,
            'radius_secret' => $radiusSecret,
            'management_subnet' => SubnetHelper::normalize(config('vpn.subnet.base', '10.0.0.0/8')),
            'tenant_id' => $router->tenant_id,
            'captive_portal_url' => $captivePortalUrl,
            'portal_host' => $portalHost,
            'interface_index' => 0,
            'bridge_name' => null,
            'syslog_host'  => $syslogHost,
            'vpn_ip'       => $router->vpn_ip ? explode('/', (string) $router->vpn_ip)[0] : null,
        ];

        $script = array_merge($script, $this->buildInterfaceConfig($params));

        $script[] = "/log info \"=== ISP-Grade Hotspot Deployment Complete ===\"";

        return implode("\n", $script);
    }

    private function normalizeInterfaces(RouterService $service): array
    {
        $interfaces = $service->interfaces;

        Log::debug('Hotspot generator: raw interfaces payload', [
            'service_id' => $service->id,
            'interfaces_type' => gettype($interfaces),
            'interfaces_preview' => is_scalar($interfaces) ? substr((string) $interfaces, 0, 300) : null,
        ]);

        if ($interfaces instanceof \Illuminate\Support\Collection) {
            $interfaces = $interfaces->toArray();
        }

        if (is_string($interfaces)) {
            $decoded = json_decode($interfaces, true);
            if (is_array($decoded)) {
                $interfaces = $decoded;
            }
        }

        if (is_array($interfaces) && !array_is_list($interfaces) && (isset($interfaces['name']) || isset($interfaces['interface']) || isset($interfaces['ipPool']) || isset($interfaces['ip_pool_id']))) {
            $interfaces = [$interfaces];
        }

        if (!is_array($interfaces) || empty($interfaces)) {
            return [
                [
                    'name' => $service->interface_name,
                    'ipPool' => $service->ipPool,
                    'vlan_required' => $service->vlan_required,
                    'vlan_id' => $service->vlan_id,
                ]
            ];
        }

        return $interfaces;
    }

    /**
     * Build interface-specific Hotspot configuration
     */
    private function buildInterfaceConfig(array $params): array
    {
        $script = [];

        if ($params['vlan_required'] && $params['vlan_id']) {
            $script = array_merge($script, $this->generateVlanSetup($params));
        }

        // Optional bridge only if no VLAN
        if (!empty($params['bridge_name'])) {
            $script = array_merge($script, $this->generateBridgeSetup($params));
        }

        // Determine router tier for firewall rules
        $routerModel = $params['router_model'] ?? '';
        $isLowEnd = RouterResourceManager::getRouterTierByModel($routerModel) === 'low_end';
        $params['is_low_end'] = $isLowEnd;

        $mgmt = SubnetHelper::normalize($params['management_subnet'] ?? '10.0.0.0/8');
        $sid  = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $syslogHost = $params['syslog_host'] ?? config('services.syslog.host', $params['radius_server'] ?? '10.8.0.1');

        $script = array_merge(
            $script,
            $this->generateIpSetup($params),
            $this->generatePoolSetup($params),
            $this->generateDhcpSetup($params),
            $this->generateHotspotSetup($params),
            $this->generateRadiusSetup($params),
            $this->generateWalledGarden($params),
            $this->generateManagementInputRules($params),
            $this->bootstrapSnmpSyslog($mgmt, $syslogHost, !$isLowEnd),
            $this->bootstrapSubscriberQueues("hs-{$sid}", 'ether1', $isLowEnd),
            $isLowEnd ? [] : $this->bootstrapTrafficFlow("hs-{$sid}", $syslogHost),
            $this->bootstrapMgmtRateLimit("hs-{$sid}", ($params['vpn_ip'] ?? null) ? ($params['vpn_ip'] . '/32') : $mgmt),
            $this->bootstrapSecurityHardening([
                'id'               => $sid,
                'is_low_end'       => $isLowEnd,
                'wan_list'         => 'WAN',
                'subscriber_ifaces' => [
                    ['in' => $params['bridge_name'] ?? $params['interface'], 'is_list' => false, 'pool_cidr' => $params['network_cidr'] ?? '192.168.0.0/24'],
                ],
            ]),
            $this->generateFirewallRules($params),
            $this->bootstrapGlobalDefaultDrop("hs-{$sid}"),
            $this->generateNatRules($params)
        );

        return $script;
    }

    private function generateBridgeSetup(array $params): array
    {
        $bridge = $params['bridge_name'];
        $iface = $params['interface'];

        return [
            "# Bridge Setup (Optional)",
            ":do { /interface bridge add name=\"{$bridge}\" comment=\"Hotspot Bridge\" } on-error={}",
            ":do { /interface bridge port remove [/interface bridge port find bridge=\"{$bridge}\" interface=\"{$iface}\"] } on-error={}",
            ":do { /interface bridge port add bridge=\"{$bridge}\" interface=\"{$iface}\" comment=\"Hotspot Access Port\" } on-error={}",
            ""
        ];
    }


    private function generateVlanSetup(array $params): array
    {
        return [
            "# VLAN Setup",
            ":do { /interface vlan add name=\"{$params['interface']}\" vlan-id=\"{$params['vlan_id']}\" interface=\"{$params['parent_interface']}\" comment=\"Hotspot VLAN\" } on-error={}",
            ""
        ];
    }

    private function generateIpSetup(array $params): array
    {
        $cidr = explode('/', $params['network_cidr'])[1] ?? '24';
        $iface = $params['bridge_name'] ?? $params['interface'];

        return [
            "# IP Addressing",
            ":do { /ip address add address=\"{$params['gateway_ip']}/{$cidr}\" interface=\"{$iface}\" comment=\"Hotspot Gateway\" } on-error={}",
            ""
        ];
    }

    private function generatePoolSetup(array $params): array
    {
        $sid = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $poolName = "hs-pool-{$sid}";
        return [
            "# IP Pool",
            ":do { /ip pool remove [/ip pool find name=\"{$poolName}\"] } on-error={}",
            ":do { /ip pool add name=\"{$poolName}\" ranges=\"{$params['range_start']}-{$params['range_end']}\" comment=\"hs-{$sid}\" } on-error={ /log error \"hs-{$sid}: FATAL - pool add failed\" }",
            ""
        ];
    }

    private function generateDhcpSetup(array $params): array
    {
        $sid   = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $dhcpName = "hs-dhcp-{$sid}";
        $poolName = "hs-pool-{$sid}";
        $network = explode('/', $params['network_cidr'])[0];
        $cidr    = explode('/', $params['network_cidr'])[1] ?? '24';
        $dns = "{$params['dns_primary']},{$params['dns_secondary']}";
        $iface = $params['bridge_name'] ?? $params['interface'];

        return [
            "# DHCP Server",
            ":do { /ip dhcp-server remove [/ip dhcp-server find name=\"{$dhcpName}\"] } on-error={}",
            ":do { /ip dhcp-server add name=\"{$dhcpName}\" interface=\"{$iface}\" address-pool=\"{$poolName}\" lease-time=1h disabled=no authoritative=yes } on-error={ /log error \"hs-{$sid}: FATAL - DHCP server add failed\" }",
            ":do { /ip dhcp-server network remove [/ip dhcp-server network find comment=\"hs-net-{$sid}\"]; } on-error={}",
            ":do { /ip dhcp-server network add address=\"{$network}/{$cidr}\" gateway=\"{$params['gateway_ip']}\" dns-server=\"{$dns}\" comment=\"hs-net-{$sid}\" } on-error={ /log error \"hs-{$sid}: FATAL - DHCP network add failed\" }",
            ""
        ];
    }

    private function generateHotspotSetup(array $params): array
    {
        $sid = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $profile = "hs-prof-{$sid}";
        $server  = "hs-srv-{$sid}";
        $poolName = "hs-pool-{$sid}";
        $userProf = "hs-usr-{$sid}";
        $iface = $params['bridge_name'] ?? $params['interface'];

        $portalUrl = $params['captive_portal_url']
            ? str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $params['captive_portal_url'])
            : null;

        return array_values(array_filter([
            "# Hotspot Profile",
            ":do { /ip hotspot profile remove [/ip hotspot profile find name=\"{$profile}\"] } on-error={}",
            ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address=\"{$params['gateway_ip']}\" login-by=http-chap,http-pap use-radius=yes html-directory=hotspot dns-name=hotspot.local http-cookie-lifetime=1d } on-error={ /log error \"hs-{$sid}: FATAL - hotspot profile add failed\" }",
            //($portalUrl ? ":do { /file set hotspot/login.html contents=\"{$portalUrl}\" } on-error={ /log warning \"hs-{$sid}: Failed to set portal URL (non-fatal)\" }" : null),
           ($portalUrl ? ":do { /file set [/file find name=\"hotspot/login.html\"] contents=\"{$portalUrl}\" } on-error={ /log warning \"hs-{$sid}: Failed to set portal URL (non-fatal)\" }" : null),
            "# Hotspot Server",
            ":do { /ip hotspot remove [/ip hotspot find name=\"{$server}\"] } on-error={}",
            ":do { /ip hotspot add name=\"{$server}\" interface=\"{$iface}\" profile=\"{$profile}\" address-pool=\"{$poolName}\" addresses-per-mac=2 idle-timeout=5m keepalive-timeout=2m disabled=no } on-error={ /log error \"hs-{$sid}: FATAL - hotspot server add failed\" }",
            "# User Profile - RADIUS-only: no local rate-limit fallback",
            ":do { /ip hotspot user profile remove [/ip hotspot user profile find name=\"{$userProf}\"] } on-error={}",
            ":do { /ip hotspot user profile add name=\"{$userProf}\" add-mac-cookie=yes shared-users=1 session-timeout=0 rate-limit=\"\" } on-error={ /log warning \"hs-{$sid}: Failed to add user profile (non-fatal, default will be used)\" }",
            ""
        ], fn ($line) => $line !== null && $line !== ''));
    }

    private function generateRadiusSetup(array $params): array
    {
        $sid = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $profile = "hs-prof-{$sid}";
        $rs  = $params['radius_server'];
        // Escape secret for safe embedding in RouterOS script string (uses shared trait method)
        $sec = $this->escapeRouterOsString((string) $params['radius_secret']);
        return array_merge(
            [
                "# RADIUS - RADIUS-only AAA: Mikrotik-Rate-Limit and session attrs enforced per-user",
                ":do { /radius remove [/radius find service=hotspot comment=\"hs-radius-{$sid}\"]; } on-error={}",
                ":do { /radius add service=hotspot address=\"{$rs}\" secret=\"{$sec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"hs-radius-{$sid}\"; } on-error={ :error \"hs-{$sid}: RADIUS add failed\" }",
                ":do { /ip hotspot profile set [/ip hotspot profile find name=\"{$profile}\"] use-radius=yes } on-error={}",
            ],
            $this->bootstrapRadiusNetwatch("hs-{$sid}", $rs),
            [
                ":local pingResult [/ping address=\"{$rs}\" count=2 interval=500ms]; :if (\$pingResult = 0) do={ /log warning \"hs-{$sid}: CRITICAL - RADIUS {$rs} unreachable at deploy time.\" } else={ /log info \"hs-{$sid}: RADIUS {$rs} reachable (\$pingResult replies). Netwatch monitoring active.\" }",
                ":do { /ip hotspot user remove [/ip hotspot user find] } on-error={}",
                ""
            ]
        );
    }

    /**
     * Generate tier-based firewall rules - minimal for hAP lite, full for high-end
     * SECURITY: Unauthenticated users are BLOCKED from internet in BOTH tiers
     * SECURITY: Only hotspot=auth users can access WAN in BOTH tiers
     */
    private function generateFirewallRules(array $params): array
    {
        $sid   = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $iface = $params['bridge_name'] ?? $params['interface'];
        $isLowEnd = $params['is_low_end'] ?? false;

        // Rules appended in correct order — NO place-before=0 (which reverses insertion order)
        // Correct order: established/related -> invalid drop -> auth accept -> unauth drop
        if ($isLowEnd) {
            return [
                "# Firewall [MINIMAL] - Essential security for low-end device",
                ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hs-fw-{$sid}\"] } on-error={}",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hs-fw-{$sid}-EST\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" connection-state=\"invalid\" action=\"drop\" comment=\"hs-fw-{$sid}-INV\"",
                "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface=\"{$iface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hs-fw-{$sid}-WAN-RET\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" hotspot=\"auth\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hs-fw-{$sid}-AUTH-INET\"",
                "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" action=\"drop\" log=\"yes\" log-prefix=\"hs-DROP-UNAUTH\" comment=\"hs-fw-{$sid}-DROP-UNAUTH\"",
                ""
            ];
        }

        return [
            "# Firewall [FULL] - Complete security for high-end device",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hs-fw-{$sid}\"] } on-error={}",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hs-fw-{$sid}-EST\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" connection-state=\"invalid\" action=\"drop\" comment=\"hs-fw-{$sid}-INV\"",
            "/ip firewall filter add chain=\"forward\" in-interface-list=\"WAN\" out-interface=\"{$iface}\" connection-state=\"established,related\" action=\"accept\" comment=\"hs-fw-{$sid}-WAN-RET\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" hotspot=\"auth\" out-interface-list=\"WAN\" action=\"accept\" comment=\"hs-fw-{$sid}-AUTH-INET\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" protocol=\"icmp\" action=\"accept\" comment=\"hs-fw-{$sid}-ICMP\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" dst-port=\"53\" protocol=\"udp\" action=\"accept\" comment=\"hs-fw-{$sid}-DNS-UDP\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" dst-port=\"53\" protocol=\"tcp\" action=\"accept\" comment=\"hs-fw-{$sid}-DNS-TCP\"",
            "/ip firewall filter add chain=\"forward\" in-interface=\"{$iface}\" action=\"drop\" log=\"yes\" log-prefix=\"hs-DROP-UNAUTH\" comment=\"hs-fw-{$sid}-DROP-UNAUTH\"",
            ""
        ];
    }

    private function generateManagementInputRules(array $params): array
    {
        $sid   = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $mgmt  = SubnetHelper::normalize($params['management_subnet'] ?? '10.0.0.0/8');
        $mport = '22,8291,8728,8729';
        $rs    = $params['radius_server'] ?? '10.8.0.1';
        $isLowEnd = $params['is_low_end'] ?? false;

        $rules = [
            "# Management & Service Hardening",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"hs-mgmt-{$sid}\"] } on-error={}",
        ];
        // Shared bootstrap: service hardening, system clock, NTP, firewall logging, brute-force
        $vpnIp = $params['vpn_ip'] ?? null;
        $allowAddr = $mgmt; // Use only mgmt subnet for ACLs
        $rules = array_merge($rules, $this->bootstrapServiceHardening("hs-{$sid}", $mgmt, $vpnIp));
        $rules = array_merge($rules, $this->bootstrapSystemClock());
        $rules = array_merge($rules, $this->bootstrapNtpClient());
        $rules = array_merge($rules, $this->bootstrapFirewallLogging("hs-{$sid}"));
        // Established connections
        $rules[] = "/ip firewall filter add chain=input connection-state=\"established,related\" action=\"accept\" comment=\"hs-mgmt-{$sid}-est\"";
        $rules = array_merge($rules, $this->bootstrapBruteForceProtection("hs-mgmt-{$sid}", $allowAddr));
        // Management allow
        $rules[] = "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"{$mport}\" src-address=\"{$allowAddr}\" action=\"accept\" comment=\"hs-mgmt-{$sid}-allow\"";
        $rules[] = "/ip firewall filter add chain=input protocol=\"udp\" dst-port=\"161\" src-address=\"{$rs}\" action=\"accept\" comment=\"hs-mgmt-{$sid}-snmp\"";
        $rules[] = "/ip firewall filter add chain=input protocol=\"udp\" dst-port=\"3799\" src-address=\"{$rs}\" action=\"accept\" comment=\"hs-mgmt-{$sid}-coa\"";
        $rules[] = "/ip firewall filter add chain=input protocol=\"tcp\" dst-port=\"{$mport}\" action=\"drop\" log=\"yes\" log-prefix=\"hs-mgmt-drop\" comment=\"hs-mgmt-{$sid}-drop\"";

        // TCP flag anomaly detection (high-end only)
        if (!$isLowEnd) {
            $rules = array_merge($rules, $this->bootstrapTcpFlagAnomalyDetection("hs-mgmt-{$sid}"));
        }

        // Connection tracking
        $rules[] = $this->bootstrapConnectionTracking();
        $rules[] = "";
        return $rules;
    }

    private function generateNatRules(array $params): array
    {
        $sid     = $params['short_id'] ?? substr(str_replace('-', '', $params['router_id']), 0, 8);
        $network = explode('/', $params['network_cidr'])[0];
        $cidr    = explode('/', $params['network_cidr'])[1] ?? '24';
        $iface   = $params['bridge_name'] ?? $params['interface'];

        return [
            "# NAT Rules",
            ":do { /ip firewall nat remove [/ip firewall nat find comment=\"hs-nat-{$sid}\"] } on-error={}",
            ":do { /ip firewall nat add chain=\"srcnat\" action=\"masquerade\" src-address=\"{$network}/{$cidr}\" out-interface-list=\"WAN\" comment=\"hs-nat-{$sid}\" } on-error={}",
            ":do { /ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64872\" protocol=\"tcp\" dst-port=\"80\" in-interface=\"{$iface}\" comment=\"hs-redir80-{$sid}\" } on-error={}",
            ":do { /ip firewall nat add chain=\"dstnat\" action=\"redirect\" to-ports=\"64875\" protocol=\"tcp\" dst-port=\"443\" in-interface=\"{$iface}\" comment=\"hs-redir443-{$sid}\" } on-error={}",
            ""
        ];
    }

    /**
     * CAPTIVE PORTAL: Generate walled garden configuration
     * Allows access to portal domain without authentication for package selection and payment
     */
    private function generateWalledGarden(array $params): array
    {
        $portalHost = $params['portal_host'] ?? null;
        
        if (!$portalHost) {
            return [
                "# Walled Garden - No portal configured",
                ""
            ];
        }

        return [
            "# Walled Garden - Allow Portal Access Without Authentication",
            "# CRITICAL: Users must access portal to view packages and make payments",
            ":do { /ip hotspot walled-garden remove [/ip hotspot walled-garden find comment=\"WiFiCore Portal\"] } on-error={}",
            ":do { /ip hotspot walled-garden add dst-host=\"{$portalHost}\" action=\"allow\" comment=\"WiFiCore Portal\" } on-error={}",
            "# Allow API endpoints for package loading and payment",
            ":do { /ip hotspot walled-garden ip remove [/ip hotspot walled-garden ip find comment=\"WiFiCore API\"] } on-error={}",
            ""
        ];
    }

    // getSafeGatewayIp() provided by ZeroConfigBootstrapTrait
}
