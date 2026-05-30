<?php

namespace App\Services\MikroTik;

use App\Models\RouterService;
use App\Services\RouterResourceManager;
use App\Models\Router;
use App\Models\TenantIpPool;
use App\Support\SubnetHelper;

/**
 * ISP-Grade Zero-Config PPPoE Generator
 * PPPoE ONLY (no hotspot / no hybrid)
 * Safe for hAP lite and low-end RouterOS devices
 */
class ZeroConfigPPPoEGenerator
{
    use ZeroConfigBootstrapTrait;
    public function generate(RouterService $service): string
    {
        $router = $service->router;

        RouterResourceManager::getScriptSettings($router);
        RouterResourceManager::logResourceInfo($router);

        $pool = $service->ipPool;
        if (!$pool) {
            throw new \RuntimeException('IP pool not assigned to PPPoE service');
        }

        $rawInterfaces = $service->interface_name;
        
        // Handle various formats: array, JSON string, comma-separated string
        if (is_array($rawInterfaces)) {
            $interfaces = $rawInterfaces;
        } elseif (is_string($rawInterfaces)) {
            // Try JSON decode first
            $decoded = json_decode($rawInterfaces, true);
            if (is_array($decoded)) {
                // Flatten any nested arrays/JSON strings
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
                // Plain comma-separated string
                $interfaces = array_map('trim', explode(',', $rawInterfaces));
            }
        } else {
            $interfaces = [];
        }
        
        // Clean: only valid interface names (alphanumeric, dash, underscore, no brackets)
        $interfaces = array_values(array_unique(array_filter($interfaces, function ($i) {
            return is_string($i) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $i);
        })));

        if (empty($interfaces)) {
            throw new \RuntimeException('No valid PPPoE interfaces provided. Raw value: ' . json_encode($rawInterfaces));
        }

        $radiusServer = config('radius.vpn_server_ip', config('services.radius.host', '10.8.0.1'));
        $resolvedRadiusServer = $radiusServer;
        if (filter_var((string) $resolvedRadiusServer, FILTER_VALIDATE_IP) === false) {
            $resolvedRadiusServer = gethostbyname((string) $resolvedRadiusServer);
        }
        if (filter_var((string) $resolvedRadiusServer, FILTER_VALIDATE_IP) === false) {
            $resolvedRadiusServer = config('vpn.server_ip', '10.8.0.1');
        }

        $radiusSrcAddress = $router->vpn_ip ?: null;
        if (is_string($radiusSrcAddress) && str_contains($radiusSrcAddress, '/')) {
            $radiusSrcAddress = explode('/', $radiusSrcAddress)[0];
        }

        // WAN interface: use service wan_interface if set, otherwise router wan_interface, else ether1
        $wanInterface = $service->wan_interface
            ?? $router->wan_interface
            ?? 'ether1';

        return $this->buildConfiguration([
            'router_id'         => (string) $router->id,
            'tenant_id'         => (string) $router->tenant_id,
            'router_model'      => $router->model ?? '',
            'router_name'       => $router->name ?? '',
            'interfaces'        => $interfaces,
            'vlan_required'     => (bool) $service->vlan_required,
            'vlan_id'           => $service->vlan_id,
            'network_cidr'      => $pool->network_cidr,
            'gateway_ip'        => $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip),
            'range_start'       => $pool->range_start,
            'range_end'         => $pool->range_end,
            'dns_primary'       => $pool->dns_primary ?? '8.8.8.8',
            'dns_secondary'     => $pool->dns_secondary ?? '8.8.4.4',
            'radius_server'     => $resolvedRadiusServer,
            'radius_secret'     => config('radius.secret', 'testing123'),
            'management_subnet' => SubnetHelper::normalize(config('vpn.subnet.base', '10.0.0.0/8')),
            'radius_src_address' => $radiusSrcAddress,
            'wan_interface'     => $wanInterface,
            'syslog_host'       => config('services.syslog.host', $resolvedRadiusServer),
        ]);
    }

    private function buildConfiguration(array $p): string
    {
        $id = substr(str_replace('-', '', $p['router_id']), 0, 8);

        $p += [
            'id'                => $id,
            'pool'              => "pppoe-pool-$id",
            'profile'           => "pppoe-prof-$id",
            'service'           => "pppoe-svc-$id",
            'pppoe_list'        => "PL-$id",
            'pppoe_active_list' => "PA-$id",
            'wan_list'          => "WAN",
        ];

        $bridge  = "pppoe-br-$id";
        $prof    = $p['profile'];
        $pool    = $p['pool'];
        $svc     = $p['service'];
        $pal     = $p['pppoe_active_list'];
        $pl      = $p['pppoe_list'];
        $wan     = $p['wan_list'];
        $gw      = $p['gateway_ip'];
        $dns     = "{$p['dns_primary']},{$p['dns_secondary']}";
        $rs      = $p['radius_server'];
        $rsec    = $this->escapeRouterOsString($p['radius_secret']);
        $mgmt    = $p['management_subnet'];
        $mports  = '22,8291,8728,8729';

        $deviceModel = $p['device_model'] ?? $p['router_model'] ?? '';
        $isLowEnd = $this->isLowEndDevice($deviceModel);
        
        // Delay profiles (in milliseconds/seconds for :delay command)
        // Per LOW_END_DEVICE_OPTIMIZATION.md: total deployment should be 12-18s
        $delays = $isLowEnd ? [
            'bridge' => '500ms',           // Was 2s - reduced for faster deployment
            'interface_batch' => '300ms',  // Slightly higher delay to reduce CPU spikes on low-end
            'firewall' => '300ms',         // Was 2s - CPU breathing room
            'between_sections' => '200ms', // Was 2s - reduced section delays
            'final' => '500ms',            // Was 2s - final breathing room
        ] : [
            'bridge' => '200ms',
            'interface_batch' => null, // No delay
            'firewall' => '100ms',
            'between_sections' => '100ms',
            'final' => '100ms',
        ];
        
        $profileName = $isLowEnd ? 'SLOW' : 'FAST';
        $routerName = $p['router_name'] ?? '';
        $sanitizedRouterName = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($routerName));
        $nasIdentifier = $p['nas_identifier']
            ?? ($sanitizedRouterName !== '' ? $sanitizedRouterName : ('wificore-' . $id));
        $nasIdentifier = $this->escapeRouterOsString($nasIdentifier);
        $radiusSrcAddress = $p['radius_src_address'] ?? null;
        $s = [];

        $syslogHost = $p['syslog_host'] ?? config('services.syslog.host', $rs);
        $s[] = "/log info \"PPPoE-$id-START [$profileName profile for: $deviceModel]\"";
        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 1. System Identity & Logging";
        $s[] = "# ============================";
        $s = array_merge($s, $this->bootstrapRadiusAaaAttributes());

        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 2. RADIUS Configuration";
        $s[] = "# ============================";
        $s[] = ':do { /radius remove [/radius find service="ppp" comment~"WiFiCore PPPoE"]; } on-error={}';
        $s[] = ":do { /radius add service=\"ppp\" address=\"{$rs}\" secret=\"{$rsec}\" authentication-port=\"1812\" accounting-port=\"1813\" timeout=\"3s\" comment=\"WiFiCore PPPoE ({$id})\"; } on-error={ /log error \"PPPoE: RADIUS configure failed (non-fatal)\" }";
        if ($radiusSrcAddress) {
            $s[] = ":do { /radius set [/radius find service=\"ppp\"] src-address=\"{$radiusSrcAddress}\"; } on-error={ /log info \"PPPoE-$id: WARN - Failed to set RADIUS src-address\" }";
        }
        $s[] = "/ppp aaa set use-radius=\"yes\" accounting=\"yes\" interim-update=\"5m\" use-circuit-id-in-nas-port-id=\"yes\"";
        $s[] = ":do { /radius incoming set accept=\"yes\" port=\"3799\"; } on-error={ /log info \"PPPoE-$id: WARN - Failed to enable RADIUS incoming\" }";
        // RADIUS health: netwatch monitors continuously; one-shot ping confirms at deploy time.
        // Pass $svc so DOWN disables the PPPoE server (fail-closed) and UP re-enables it.
        $s = array_merge($s, $this->bootstrapRadiusNetwatch("PPPoE-$id", $rs, $svc));
        $s[] = ":local pingResult [/ping address=\"{$rs}\" count=2 interval=500ms]; :if (\$pingResult = 0) do={ /log warning \"PPPoE-$id: CRITICAL - RADIUS {$rs} unreachable at deploy time.\" } else={ /log info \"PPPoE-$id: RADIUS {$rs} reachable (\$pingResult replies). Netwatch monitoring active.\" }";

        // NAS-Identifier (version-safe): RouterOS uses system identity as NAS-ID
        $s[] = ":do { /system identity set name=\"{$nasIdentifier}\"; } on-error={ /log info \"PPPoE-$id: WARN - Failed to set system identity\" }";

        // PPP AAA and Accounting (ensure session visibility)
        
        // Enable PPP session logging for visibility (deduplicated by comment)
        $pppLogComment = "PPPoE-$id-PPP-LOG";
        $pppoeLogComment = "PPPoE-$id-PPPOE-LOG";
        $s[] = ":do { /system logging remove [/system logging find comment=\"$pppLogComment\"]; } on-error={}";
        $s[] = ":do { /system logging add action=\"memory\" topics=\"ppp\" } on-error={}";
        $s[] = ":do { /system logging remove [/system logging find comment=\"$pppoeLogComment\"]; } on-error={}";
        $s[] = ":do { /system logging add action=\"memory\" topics=\"pppoe\" } on-error={}";
        // Log dropped packets for visibility
        $s = array_merge($s, $this->bootstrapFirewallLogging("PPPoE-$id", $isLowEnd));

        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 3. IP Pools & Interfaces";
        $s[] = "# ============================";
        // IP POOL - Atomic creation with ranges
        $s[] = ":do { /ip pool add name=\"$pool\" ranges=\"{$p['range_start']}-{$p['range_end']}\" comment=\"PPPoE-$id\" } on-error={ /log info \"PPPoE-$id: Pool may exist, attempting update\"; /ip pool set [/ip pool find name=\"$pool\"] ranges=\"{$p['range_start']}-{$p['range_end']}\"; }";

        // INTERFACE LISTS
        $s[] = ":do { /interface list add name=\"$wan\" } on-error={} ";
        $s[] = ":do { /interface list add name=\"$pl\" } on-error={} ";
        $s[] = ":do { /interface list add name=\"$pal\" } on-error={} ";
        // Clean up stale list members before re-adding current interfaces
        $wanIface = $p['wan_interface'] ?? 'ether1';
        $s[] = ":do { /interface list member remove [/interface list member find list=\"$wan\" interface=\"{$wanIface}\"]; } on-error={} ";
        $s[] = ":do { /interface list member remove [/interface list member find list=\"$pl\"]; } on-error={} ";
        $s[] = ":do { /interface list member add list=\"$wan\" interface=\"{$wanIface}\" } on-error={ /log warning \"PPPoE-$id: Failed to add $wanIface to WAN list — routing may be broken\" } ";

        // WAN baseline (optional) - DHCP client on WAN interface + disable running-check
        $s[] = ":do { /ip dhcp-client add interface=\"{$wanIface}\" disabled=no } on-error={ /ip dhcp-client set [/ip dhcp-client find interface=\"{$wanIface}\"] disabled=no; }";
        $runningCheckInterfaces = array_values(array_unique(array_merge([$wanIface], $p['interfaces'])));
        foreach ($runningCheckInterfaces as $iface) {
            $s[] = ":do { /interface ethernet set [find name=\"{$iface}\"] disable-running-check=no } on-error={} ";
        }


        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 4. PPP Profile & Bridge";
        $s[] = "# ============================";
        // PPP PROFILE — RADIUS-only: no local pool/DNS/rate-limit fallback.
        // All attributes (Framed-Pool, Mikrotik-Rate-Limit, DNS) MUST come from RADIUS.
        // If RADIUS is unreachable, users cannot authenticate (fail-closed by design).
        $s[] = ":do { /ppp profile add name=\"$prof\" comment=\"PPPoE-$id\" } on-error={ /log info \"PPPoE-$id: profile exists, updating\" }";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"$prof\"] local-address=\"\" remote-address=\"\"  } on-error={ /log error \"PPPoE-$id: FATAL - profile set failed\" }";
        $s[] = ":do { /ppp aaa set use-radius=yes } on-error={ :log error \"PPPoE-$id: FATAL - radius aaa set failed\" }; :do { /ppp profile set [/ppp profile find name=\"$prof\"] rate-limit=\"\" } on-error={ :log error \"PPPoE-$id: FATAL - profile rate-limit set failed\" }";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"$prof\"] interface-list=\"$pal\" } on-error={ /log warning \"PPPoE-$id: Failed to set profile interface-list (non-fatal)\" }";
        $s[] = ":do { /ppp profile set [/ppp profile find where name=\"$prof\"] change-tcp-mss=yes use-compression=no only-one=yes; /ppp aaa set use-radius=yes accounting=yes } on-error={ /log warning \"PPPoE-$id: Failed to apply PPP settings (non-fatal)\" }";
        $s = array_merge($s, $this->bootstrapPppAaaHardening("PPPoE-$id", $prof));
        $s = array_merge($s, $this->bootstrapPppSessionLogging("PPPoE-$id", $prof, $isLowEnd));

        // BRIDGE - Clean slate: remove everything first, then rebuild
        $s[] = ":do { /interface bridge port remove [/interface bridge port find bridge=\"$bridge\"]; } on-error={ /log info \"PPPoE-$id: WARN - Failed to remove bridge ports\" }";
        $s[] = ":do { /interface bridge remove [/interface bridge find name=\"$bridge\"]; } on-error={ /log info \"PPPoE-$id: WARN - Failed to remove bridge\" }";
        $s[] = ":do { /interface bridge add name=\"$bridge\" comment=\"PPPoE-$id\" } on-error={ /log error \"PPPoE-$id: FATAL - bridge add failed\" }";
        if ($delays['bridge']) {
            $s[] = ":delay {$delays['bridge']}";
        }
        $s[] = ":do { /interface bridge set [/interface bridge find name=\"$bridge\"] protocol-mode=\"rstp\" } on-error={ /log warning \"PPPoE-$id: Failed to set bridge protocol-mode (non-fatal)\" }";

        // Add ALL interfaces to bridge — skip WireGuard/VPN interfaces (adding wg to bridge kills VPN)
        $vpnPatterns = ['wireguard', 'wg', 'vpn'];
        $interfaceCount = count($p['interfaces']);
        $currentInterface = 0;
        foreach ($p['interfaces'] as $iface) {
            $ifaceLower = strtolower($iface);
            $isVpn = false;
            foreach ($vpnPatterns as $pat) {
                if (str_contains($ifaceLower, $pat)) { $isVpn = true; break; }
            }
            if ($isVpn) {
                $s[] = "/log info \"PPPoE-$id: SKIP VPN interface $iface (not added to bridge)\"";
                continue;
            }
            $access = $iface;
            if ($p['vlan_required'] && $p['vlan_id']) {
                $access = "vlan{$p['vlan_id']}-$iface";
                $s[] = ":do { /interface vlan remove [/interface vlan find name=\"$access\"]; } on-error={ /log info \"PPPoE-$id: WARN - Failed to remove VLAN $access\" }";
                $s[] = "/interface vlan add name=\"$access\" vlan-id=\"{$p['vlan_id']}\" interface=\"$iface\" comment=\"PPPoE-$id\"";
            }
            $s[] = ":do { /interface bridge port add bridge=\"$bridge\" interface=\"$access\" } on-error={ /log error \"PPPoE-$id: FATAL - port add failed for $access\" }";
            $currentInterface++;
            if ($isLowEnd && $currentInterface % 2 === 0 && $currentInterface < $interfaceCount) {
                $s[] = ":delay {$delays['interface_batch']}";
            }
        }

        $s[] = ":do { /ip dhcp-server remove [/ip dhcp-server find interface=\"$bridge\"]; } on-error={}";

        // Assign dedicated gateway IP to the bridge — PPP local-address uses this,
        // preventing the router from consuming an address from the subscriber pool.
        $s[] = ":do { /ip address remove [/ip address find interface=\"$bridge\"]; } on-error={}";
        $s[] = ":do { /ip address add address=\"{$gw}/24\" interface=\"$bridge\" comment=\"PPPoE-$id-GW\" } on-error={ /log error \"PPPoE-$id: FATAL - gateway IP assign failed\" }";
        // Set profile local-address to dedicated gateway IP (not the pool name)
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"$prof\"] local-address=\"{$gw}\" } on-error={ /log warning \"PPPoE-$id: Failed to set profile local-address (non-fatal)\" }";

        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 5. PPPoE Server";
        $s[] = "# ============================";
        $s[] = ":do { /interface pppoe-server server remove [/interface pppoe-server server find service-name=\"$svc\"] } on-error={ /log info \"PPPoE-$id: No existing PPPoE server to remove\" }";
        $keepaliveTimeout = $isLowEnd ? '120' : '30';
        $s[] = ":do { /interface pppoe-server server add service-name=\"$svc\" interface=\"$bridge\" default-profile=\"$prof\" authentication=\"chap,mschap2\" one-session-per-host=yes keepalive-timeout=\"{$keepaliveTimeout}\" max-mtu=\"1480\" max-mru=\"1480\" disabled=no } on-error={ /log error \"PPPoE-$id: PPPoE server add FAILED\" }";
        $s[] = ":do { /interface list member remove [/interface list member find list=\"$pl\"] } on-error={}";
        $s[] = ":do { /interface list member add list=\"$pl\" interface=\"$bridge\" comment=\"PPPoE-$id-PL\" } on-error={ /log warning \"PPPoE-$id: Failed to add bridge to list $pl\" }";
        $s[] = "/log info \"PPPoE-$id: PPPoE server '$svc' started successfully.\"";
        $s = array_merge($s, $this->bootstrapOperationalLogging("PPPoE-$id", $svc, $rs, $isLowEnd));

        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 6. Management Access & Monitoring";
        $s[] = "# ============================";
        $s = array_merge($s, $this->bootstrapServiceHardening("PPPoE-$id", $mgmt, $radiusSrcAddress));
        $s = array_merge($s, $this->bootstrapSystemClock());
        $s = array_merge($s, $this->bootstrapNtpClient());
        $s = array_merge($s, $this->bootstrapSnmpSyslog($mgmt, $syslogHost, !$isLowEnd));
        $s = array_merge($s, $this->bootstrapSubscriberQueues("PPPoE-$id", $wanIface, $isLowEnd));
        if (!$isLowEnd) {
            $s = array_merge($s, $this->bootstrapTrafficFlow("PPPoE-$id", $syslogHost));
        }

        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 7. Firewall & Security";
        $s[] = "# ============================";
        // FIREWALL — clean up ALL old rules
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"PPPoE-$id\"] } on-error={}";
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"pp-wan-est-$id\"] } on-error={}";
        $s[] = ":delay 100ms";
        
        // Security hardening - BCP 38 and DDoS protection
        $s = array_merge($s, $this->bootstrapSecurityHardening([
            'id'                => $id,
            'is_low_end'        => $isLowEnd,
            'wan_list'          => $wan,
            'subscriber_ifaces' => [
                ['in' => $pal, 'is_list' => true, 'pool_cidr' => $p['network_cidr']],
            ],
        ]));

        // Use mgmt subnet for brute-force protection (consistent with service hardening)
        $allowAddr = $mgmt;
        // Brute-force protection for SSH/Winbox/API/API-SSL
        $s = array_merge($s, $this->bootstrapBruteForceProtection("PPPoE-$id", $allowAddr));
        // Management rate limiting (API-SSL + SNMP)
        $s = array_merge($s, $this->bootstrapMgmtRateLimit("PPPoE-$id", $allowAddr));

        if ($isLowEnd) {
            // MINIMAL FIREWALL for hAP lite (low memory/CPU) - ~7 rules
            // SECURITY: Unauthenticated users CANNOT access internet
            // SECURITY: Only PPPoE authenticated users (PAL list) can access WAN
            // SECURITY: All AAA is via RADIUS (configured above)
            
            $s[] = "# Firewall [MINIMAL] - Essential security only for low-end device";
            
            // INPUT: Allow established + management only (3 rules)
            $s[] = "/ip firewall filter add chain=\"input\" connection-state=\"established,related\" action=\"accept\" comment=\"PPPoE-$id-EST-IN\"";
            $s[] = "/ip firewall filter add chain=\"input\" protocol=\"tcp\" dst-port=\"$mports\" src-address=\"$allowAddr\" action=\"accept\" comment=\"PPPoE-$id-MGMT\"";
            $s[] = "/ip firewall filter add chain=\"input\" protocol=\"udp\" dst-port=\"161\" src-address=\"$allowAddr\" action=\"accept\" comment=\"PPPoE-$id-SNMP\"";
            $s[] = "/ip firewall filter add chain=\"input\" in-interface=\"$bridge\" action=\"drop\" comment=\"PPPoE-$id-DROP-IN\"";
            
            // FORWARD: Auth enforcement - critical security rules (4 rules)
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$pal\" out-interface-list=\"$wan\" action=\"accept\" comment=\"PPPoE-$id-INET-AUTH\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$pal\" connection-state=\"invalid\" action=\"drop\" comment=\"PPPoE-$id-DROP-INV\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$pal\" connection-state=\"established,related\" action=\"accept\" comment=\"PPPoE-$id-EST-FWD\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$wan\" out-interface-list=\"$pal\" connection-state=\"established,related\" action=\"accept\" comment=\"PPPoE-$id-WAN-EST\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface=\"$bridge\" action=\"drop\" comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        } else {
            // FULL FIREWALL for high-end devices - ~15 rules
            // Includes all security features plus extras like ICMP, SNMP, etc.
            
            $s[] = "# Firewall [FULL] - Complete security for high-end device";
            
            // INPUT rules (8 rules)
            $s[] = "/ip firewall filter add chain=\"input\" connection-state=\"established,related\" action=\"accept\" comment=\"PPPoE-$id-EST-IN\"";
            $s[] = "/ip firewall filter add chain=\"input\" in-interface-list=\"$pal\" protocol=\"icmp\" action=\"accept\" comment=\"PPPoE-$id-ICMP\"";
            $s[] = "/ip firewall filter add chain=\"input\" protocol=\"tcp\" dst-port=\"$mports\" src-address=\"$allowAddr\" action=\"accept\" comment=\"PPPoE-$id-MGMT-ALLOW\"";
            $s[] = "/ip firewall filter add chain=\"input\" protocol=\"udp\" dst-port=\"161\" src-address=\"$allowAddr\" action=\"accept\" comment=\"PPPoE-$id-SNMP\"";
            $s[] = "/ip firewall filter add chain=\"input\" in-interface=\"$bridge\" protocol=\"udp\" dst-port=\"8863-8864\" action=\"accept\" comment=\"PPPoE-$id-DISC\"";
            // TCP flag anomaly detection
            $s = array_merge($s, $this->bootstrapTcpFlagAnomalyDetection("PPPoE-$id"));
            $s[] = "/ip firewall filter add chain=\"input\" in-interface=\"$bridge\" connection-state=\"invalid\" action=\"drop\" log=\"yes\" log-prefix=\"PPPoE-INV-IN\" comment=\"PPPoE-$id-INV-IN\"";
            $s[] = "/ip firewall filter add chain=\"input\" in-interface=\"$bridge\" action=\"drop\" log=\"yes\" log-prefix=\"PPPoE-DROP-IN\" comment=\"PPPoE-$id-DROP-IN\"";
            
            $s[] = ":delay {$delays['firewall']}"; // CPU breathing room
            
            // FORWARD rules (5 rules)
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$wan\" out-interface-list=\"$pal\" connection-state=\"established,related\" action=\"accept\" comment=\"PPPoE-$id-WAN-EST\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$pal\" connection-state=\"established,related\" action=\"accept\" comment=\"PPPoE-$id-PAL-EST\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$pal\" connection-state=\"invalid\" action=\"drop\" comment=\"PPPoE-$id-PAL-INV\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface-list=\"$pal\" out-interface-list=\"$wan\" action=\"accept\" comment=\"PPPoE-$id-INET\"";
            $s[] = "/ip firewall filter add chain=\"forward\" in-interface=\"$bridge\" action=\"drop\" log=\"yes\" log-prefix=\"PPPoE-BLOCK\" comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        }
        
        $s[] = ":delay {$delays['between_sections']}";

        // NAT
        $s[] = ":do { /ip firewall nat remove [/ip firewall nat find comment=\"PPPoE-$id\"] } on-error={}";
        $s[] = "/ip firewall nat add chain=\"srcnat\" out-interface-list=\"$wan\" action=\"masquerade\" comment=\"PPPoE-$id\"";

        // RADIUS CoA INPUT accept (port 3799) — must be before GLOBAL-DROP
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"PPPoE-$id-COA\"] } on-error={}";
        $s[] = "/ip firewall filter add chain=\"input\" protocol=\"udp\" dst-port=\"3799\" src-address=\"{$rs}\" action=\"accept\" comment=\"PPPoE-$id-COA\"";

        // Global default drop — last rules, re-added on every deploy
        $s = array_merge($s, $this->bootstrapGlobalDefaultDrop("PPPoE-$id"));

        $s[] = "";
        $s[] = "# ============================";
        $s[] = "# 8. Connection Tracking";
        $s[] = "# ============================";
        $s[] = $this->bootstrapConnectionTracking();

        $s[] = ":delay {$delays['final']}";
        $s[] = "/log info \"PPPoE-$id-DONE [$profileName profile]\"";

        return implode("\n", $s);
    }

    /**
     * Generate optimized script for low-end devices (hAP lite, etc.)
     * Reduces CPU load by removing expensive operations
     */
    public function buildConfigurationOptimized(array $params): string
    {
        // Use standard build but with low-resource flag
        $params['optimized'] = true;
        return $this->buildConfiguration($params);
    }

    private function isLowEndDevice(string $model): bool
    {
        if (empty($model)) {
            return false; // Default to fast profile if unknown
        }
        return \App\Services\RouterResourceManager::getRouterTierByModel($model) === 'low_end';
    }

    // escapeRouterOsString() and getSafeGatewayIp() provided by ZeroConfigBootstrapTrait

    /**
     * Generate script to add additional interfaces to existing PPPoE bridge.
     * This is called separately after initial deployment with primary interface.
     */
    public function generateAdditionalInterfacesScript(array $params): string
    {
        $bridge = $params['bridge'] ?? 'pppoe-br-' . $params['id'];
        $id = $params['id'];
        $vlanRequired = $params['vlan_required'] ?? false;
        $vlanId = $params['vlan_id'] ?? null;
        $additionalInterfaces = $params['additional_interfaces'] ?? [];

        if (empty($additionalInterfaces)) {
            return "# No additional interfaces to add";
        }

        $s = [];
        $s[] = "/log info \"PPPoE-$id-ADD-INTERFACES-START\"";

        foreach ($additionalInterfaces as $iface) {
            $access = $iface;
            if ($vlanRequired && $vlanId) {
                $access = "vlan{$vlanId}-$iface";
                $s[] = ":do { /interface vlan remove [/interface vlan find name=\"$access\"]; } on-error={}";
                $s[] = "/interface vlan add name=\"$access\" vlan-id=\"{$vlanId}\" interface=\"$iface\" comment=\"PPPoE-$id\"";
            }
            $s[] = ":do { /interface bridge port add bridge=\"$bridge\" interface=\"$access\" comment=\"PPPoE-$id-add\" } on-error={ /log error \"PPPoE-$id: FATAL - Failed to add $access to bridge $bridge. PPPoE clients on this port will not connect.\" }";
            $s[] = "/log info \"PPPoE-$id: Added interface $iface to bridge\"";
        }

        $s[] = "/log info \"PPPoE-$id-ADD-INTERFACES-DONE\"";

        return implode("\n", $s);
    }
}
