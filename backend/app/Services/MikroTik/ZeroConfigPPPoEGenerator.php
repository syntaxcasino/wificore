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

        $radiusServer = config('radius.server_ip', config('services.radius.host', 'wificore-freeradius'));
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

        // Device-aware deployment profiles
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

        $s[] = "/log info \"PPPoE-$id-START [$profileName profile for: $deviceModel]\"";

        // RADIUS
        $s[] = ':do { /radius remove [/radius find service=ppp comment~"WiFiCore PPPoE"]; } on-error={}';
        $s[] = ":do { /radius add service=ppp address={$rs} secret=\"{$rsec}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"WiFiCore PPPoE ({$id})\"; } on-error={ :error \"PPPoE: RADIUS configure failed\" }";
        if ($radiusSrcAddress) {
            $s[] = ":do { /radius set [/radius find service=ppp] src-address={$radiusSrcAddress}; } on-error={ /log info \"PPPoE-$id: WARN - Failed to set RADIUS src-address\" }";
        }
        $s[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=5m use-circuit-id-in-nas-port-id=yes";
        $s[] = ":do { /radius incoming set accept=yes port=3799; } on-error={ /log info \"PPPoE-$id: WARN - Failed to enable RADIUS incoming\" }";

        // NAS-Identifier (version-safe): RouterOS uses system identity as NAS-ID
        $s[] = ":do { /system identity set name=\"{$nasIdentifier}\"; } on-error={ /log info \"PPPoE-$id: WARN - Failed to set system identity\" }";

        // PPP AAA and Accounting (ensure session visibility)
        
        // Enable PPP session logging for visibility (deduplicated by comment)
        $pppLogComment = "PPPoE-$id-PPP-LOG";
        $pppoeLogComment = "PPPoE-$id-PPPOE-LOG";
        $s[] = ":do { /system logging remove [/system logging find comment=\"$pppLogComment\"]; } on-error={}";
        $s[] = ":do { /system logging add action=memory topics=ppp comment=\"$pppLogComment\" } on-error={}";
        $s[] = ":do { /system logging remove [/system logging find comment=\"$pppoeLogComment\"]; } on-error={}";
        $s[] = ":do { /system logging add action=memory topics=pppoe comment=\"$pppoeLogComment\" } on-error={}";
        
        // Ensure connection tracking is enabled for proper session handling
        $s[] = "/ip firewall connection tracking set enabled=yes tcp-established-timeout=1h udp-timeout=30s icmp-timeout=30s";

        // IP POOL - Atomic creation with ranges
        $s[] = ":do { /ip pool add name=\"$pool\" ranges={$p['range_start']}-{$p['range_end']} comment=\"PPPoE-$id\" } on-error={ /log info \"PPPoE-$id: Pool may exist, attempting update\"; /ip pool set [/ip pool find name=\"$pool\"] ranges={$p['range_start']}-{$p['range_end']}; }";

        // INTERFACE LISTS
        $s[] = ":do { /interface list add name=$wan } on-error={} ";
        $s[] = ":do { /interface list add name=$pl } on-error={} ";
        $s[] = ":do { /interface list add name=$pal } on-error={} ";
        // Clean up stale list members before re-adding current interfaces
        $wanIface = $p['wan_interface'] ?? 'ether1';
        $s[] = ":do { /interface list member remove [/interface list member find list=$wan interface={$wanIface}]; } on-error={} ";
        $s[] = ":do { /interface list member remove [/interface list member find list=$pl]; } on-error={} ";
        $s[] = ":do { /interface list member add list=$wan interface={$wanIface} } on-error={} ";

        // WAN baseline (optional) - DHCP client on WAN interface + disable running-check
        $s[] = ":do { /ip dhcp-client add interface={$wanIface} disabled=no } on-error={ /ip dhcp-client set [/ip dhcp-client find interface={$wanIface}] disabled=no; }";
        $runningCheckInterfaces = array_values(array_unique(array_merge([$wanIface], $p['interfaces'])));
        foreach ($runningCheckInterfaces as $iface) {
            $s[] = ":do { /interface ethernet set [find name=\"{$iface}\"] disable-running-check=no } on-error={} ";
        }

        // ENABLE REST API (api-ssl) for modern provisioning
        $s[] = ":do { /ip service enable api-ssl } on-error={ /log info \"PPPoE-$id: INFO - api-ssl already enabled or not available\" }";
        $s[] = ":do { /ip service set api-ssl address=$mgmt } on-error={ /log info \"PPPoE-$id: WARN - Failed to set api-ssl address\" }";
        $s[] = "/log info \"PPPoE-$id: REST API (api-ssl) enabled on port 8729\"";

        // SERVICE HARDENING - disable unused services, restrict management access
        $s[] = ":do { /ip service set ssh address=$mgmt } on-error={ /log info \"PPPoE-$id: WARN - Failed to set SSH address\" }";
        $s[] = ":do { /ip service set winbox address=$mgmt } on-error={ /log info \"PPPoE-$id: WARN - Failed to set Winbox address\" }";
        $s[] = ":do { /ip service disable telnet } on-error={ /log info \"PPPoE-$id: INFO - Telnet already disabled\" }";
        $s[] = ":do { /ip service disable ftp } on-error={ /log info \"PPPoE-$id: INFO - FTP already disabled\" }";
        $s[] = ":do { /ip service disable www } on-error={ /log info \"PPPoE-$id: INFO - WWW already disabled\" }";
        $s[] = ":do { /ip service disable www-ssl } on-error={ /log info \"PPPoE-$id: INFO - WWW-SSL already disabled\" }";
        $s[] = ":do { /ip service disable api } on-error={ /log info \"PPPoE-$id: INFO - API already disabled\" }";
        $s[] = ":do { /ip service disable romon } on-error={ /log info \"PPPoE-$id: INFO - ROMON already disabled\" }";

        // PPP PROFILE — add minimal then set in short chunks
        $s[] = ":do { /ppp profile add name=\"$prof\" comment=\"PPPoE-$id\" } on-error={}";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"$prof\"] local-address=$gw remote-address=\"$pool\" } on-error={}";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"$prof\"] dns-server=$dns only-one=yes } on-error={}";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"$prof\"] interface-list=$pal } on-error={}";
        $s[] = ":do { /ppp profile set [/ppp profile find name=\"$prof\"] change-tcp-mss=yes use-compression=no } on-error={}";

        // BRIDGE - Clean slate: remove everything first, then rebuild
        $s[] = ":do { /interface bridge port remove [/interface bridge port find bridge=\"$bridge\"]; } on-error={ /log info \"PPPoE-$id: WARN - Failed to remove bridge ports\" }";
        $s[] = ":do { /interface bridge remove [/interface bridge find name=\"$bridge\"]; } on-error={ /log info \"PPPoE-$id: WARN - Failed to remove bridge\" }";
        $s[] = ":do { /interface bridge add name=\"$bridge\" comment=\"PPPoE-$id\" } on-error={ /log info \"PPPoE-$id: WARN - Failed to add bridge\" }";
        if ($delays['bridge']) {
            $s[] = ":delay {$delays['bridge']}";
        }
        $s[] = ":do { /interface bridge set [/interface bridge find name=\"$bridge\"] protocol-mode=rstp } on-error={ /log info \"PPPoE-$id: WARN - Failed to set bridge protocol\" }";

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
                $s[] = "/interface vlan add name=\"$access\" vlan-id={$p['vlan_id']} interface=\"$iface\" comment=\"PPPoE-$id\"";
            }
            $s[] = ":do { /interface bridge port add bridge=\"$bridge\" interface=\"$access\" } on-error={ /log info \"PPPoE-$id: WARN - Failed to add interface $access to bridge\" }";
            $currentInterface++;
            if ($isLowEnd && $currentInterface % 2 === 0 && $currentInterface < $interfaceCount) {
                $s[] = ":delay {$delays['interface_batch']}";
            }
        }

        $s[] = ":do { /ip dhcp-server remove [/ip dhcp-server find interface=\"$bridge\"]; } on-error={}";

        // PPPoE SERVER — remove then add (idempotent, with logging)
        $s[] = ":do { /interface pppoe-server server remove [/interface pppoe-server server find service-name=\"$svc\"]; } on-error={ /log info \"PPPoE-$id: INFO - No existing PPPoE server to remove\" }";
        $s[] = ":do { /interface pppoe-server server add service-name=\"$svc\" interface=\"$bridge\" default-profile=\"$prof\" } on-error={ /log info \"PPPoE-$id: WARN - Failed to add PPPoE server\" }";
        $s[] = ":do { /interface pppoe-server server set [/interface pppoe-server server find service-name=\"$svc\"] disabled=no } on-error={ /log info \"PPPoE-$id: WARN - Failed to enable PPPoE server\" }";
        $s[] = ":do { /interface pppoe-server server set [/interface pppoe-server server find service-name=\"$svc\"] authentication=chap,mschap2 } on-error={ /log info \"PPPoE-$id: WARN - Failed to set PPPoE auth\" }";
        $s[] = ":do { /interface pppoe-server server set [/interface pppoe-server server find service-name=\"$svc\"] one-session-per-host=yes keepalive-timeout=30 } on-error={ /log info \"PPPoE-$id: WARN - Failed to set PPPoE session params\" }";
        $s[] = ":do { /interface pppoe-server server set [/interface pppoe-server server find service-name=\"$svc\"] max-mtu=1480 max-mru=1480 } on-error={ /log info \"PPPoE-$id: WARN - Failed to set PPPoE MTU/MRU\" }";
        $s[] = ":do { /interface list member remove [/interface list member find list=$pl]; } on-error={}";
        $s[] = ":do { /interface list member add list=$pl interface=\"$bridge\" comment=\"PPPoE-$id-PL\" } on-error={ /log info \"PPPoE-$id: WARN - Failed to add bridge to list $pl\" }";

        // FIREWALL — clean up ALL old rules including pp-wan-est and PPPoE patterns
        // Use multiple patterns to ensure complete cleanup
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"PPPoE-$id\"]; } on-error={}";
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"pp-wan-est-$id\"]; } on-error={}";
        $s[] = ":delay 100ms"; // Brief delay to ensure removal completes before add
        
        // SECURITY HARDENING - BCP 38 and DDoS protection
        $s = array_merge($s, $this->generateSecurityHardeningRules($p + ['is_low_end' => $isLowEnd]));
        
        if ($isLowEnd) {
            // MINIMAL FIREWALL for hAP lite (low memory/CPU) - ~7 rules
            // SECURITY: Unauthenticated users CANNOT access internet
            // SECURITY: Only PPPoE authenticated users (PAL list) can access WAN
            // SECURITY: All AAA is via RADIUS (configured above)
            
            $s[] = "# Firewall [MINIMAL] - Essential security only for low-end device";
            
            // INPUT: Allow established + management only (3 rules)
            $s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept comment=\"PPPoE-$id-EST-IN\"";
            $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=$mgmt action=accept comment=\"PPPoE-$id-MGMT\"";
            $s[] = "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address=$mgmt action=accept comment=\"PPPoE-$id-SNMP\"";
            $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" action=drop comment=\"PPPoE-$id-DROP-IN\"";
            
            // FORWARD: Auth enforcement - critical security rules (4 rules)
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal out-interface-list=$wan action=accept comment=\"PPPoE-$id-INET-AUTH\"";
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=invalid action=drop comment=\"PPPoE-$id-DROP-INV\"";
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=established,related action=accept comment=\"PPPoE-$id-EST-FWD\"";
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$wan out-interface-list=$pal connection-state=established,related action=accept comment=\"PPPoE-$id-WAN-EST\"";
            $s[] = "/ip firewall filter add chain=forward in-interface=\"$bridge\" action=drop comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        } else {
            // FULL FIREWALL for high-end devices - ~15 rules
            // Includes all security features plus extras like ICMP, SNMP, etc.
            
            $s[] = "# Firewall [FULL] - Complete security for high-end device";
            
            // INPUT rules (8 rules)
            $s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept comment=\"PPPoE-$id-EST-IN\"";
            $s[] = "/ip firewall filter add chain=input in-interface-list=$pal protocol=icmp action=accept comment=\"PPPoE-$id-ICMP\"";
            $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=$mgmt action=accept comment=\"PPPoE-$id-MGMT-ALLOW\"";
            $s[] = "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address=$mgmt action=accept comment=\"PPPoE-$id-SNMP\"";
            $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" protocol=udp dst-port=8863-8864 action=accept comment=\"PPPoE-$id-DISC\"";
            $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" connection-state=invalid action=drop comment=\"PPPoE-$id-INV-IN\"";
            $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" action=drop comment=\"PPPoE-$id-DROP-IN\"";
            $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=!$mgmt action=drop comment=\"PPPoE-$id-MGMT-DROP\"";
            
            $s[] = ":delay {$delays['firewall']}"; // CPU breathing room
            
            // FORWARD rules (5 rules)
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$wan out-interface-list=$pal connection-state=established,related action=accept comment=\"PPPoE-$id-WAN-EST\"";
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=established,related action=accept comment=\"PPPoE-$id-PAL-EST\"";
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=invalid action=drop comment=\"PPPoE-$id-PAL-INV\"";
            $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal out-interface-list=$wan action=accept comment=\"PPPoE-$id-INET\"";
            $s[] = "/ip firewall filter add chain=forward in-interface=\"$bridge\" action=drop comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        }
        
        $s[] = ":delay {$delays['between_sections']}";

        // NAT — srcnat masquerade: PPPoE users exit via WAN (out-interface-list only, no in-interface on srcnat)
        $s[] = ":do { /ip firewall nat remove [/ip firewall nat find comment=\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/ip firewall nat add chain=srcnat out-interface-list=$wan action=masquerade comment=\"PPPoE-$id\"";

        // RADIUS CoA INPUT accept (port 3799) — must be before GLOBAL-DROP
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"PPPoE-$id-COA\"]; } on-error={}";
        $s[] = "/ip firewall filter add chain=input protocol=udp dst-port=3799 src-address={$rs} action=accept comment=\"PPPoE-$id-COA\"";

        // GLOBAL DEFAULT DROP — appended last so all service-specific accept rules above it take effect
        // On re-deploy, old GLOBAL-DROP rules are removed first then re-added at the end
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}";
        $s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept comment=\"GLOBAL-EST-IN\"";
        $s[] = "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"";

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

    /**
     * Generate security hardening rules (BCP 38 anti-spoofing + DDoS protection)
     * These rules are added before service-specific firewall rules
     */
    private function generateSecurityHardeningRules(array $p): array
    {
        $id       = $p['id'];
        $pal      = $p['pppoe_active_list'];
        $wan      = $p['wan_list'];
        $isLowEnd = $p['is_low_end'] ?? false;
        // Actual PPPoE client pool CIDR — used for BCP38 anti-spoof (not CGNAT)
        $poolCidr = $p['network_cidr'] ?? '192.168.0.0/24';
        $mgmt     = $p['management_subnet'] ?? '10.0.0.0/8';

        $rules = [
            "# SECURITY HARDENING - BCP 38 Anti-Spoofing & DDoS Protection",
            ":do { /ip firewall filter remove [/ip firewall filter find comment~\"SEC-$id\"]; } on-error={}",
        ];

        if ($isLowEnd) {
            // MINIMAL — 4 rules only; low-memory devices cannot afford more
            // BCP38: clients must originate from pool subnet only
            $rules[] = "/ip firewall filter add chain=forward in-interface-list=$pal src-address=!{$poolCidr} action=drop comment=\"SEC-$id-BCP38-SPOOF\"";
            // DDoS: SYN flood — RouterOS limit syntax: rate/interval,burst
            $rules[] = "/ip firewall filter add chain=input protocol=tcp connection-state=new limit=50/s,5 action=drop comment=\"SEC-$id-DDoS-SYN\"";
            // DDoS: ICMP flood
            $rules[] = "/ip firewall filter add chain=input protocol=icmp connection-state=new limit=20/s,5 action=drop comment=\"SEC-$id-DDoS-ICMP\"";
            // DDoS: per-client connection cap (32-bit bucket)
            $rules[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=new connection-limit=100,32 action=drop comment=\"SEC-$id-DDoS-CONN\"";
        } else {
            // FULL — high-end devices
            // BCP38: drop RFC1918/CGNAT ingress from WAN — exclude management subnet (WireGuard uses 10.x)
            $wanSpoof = ['172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '0.0.0.0/8'];
            foreach ($wanSpoof as $cidr) {
                $rules[] = "/ip firewall filter add chain=input in-interface-list=$wan src-address={$cidr} action=drop comment=\"SEC-$id-BCP38-WAN\"";
            }
            // BCP38: clients must originate from pool subnet
            $rules[] = "/ip firewall filter add chain=forward in-interface-list=$pal src-address=!{$poolCidr} action=drop comment=\"SEC-$id-BCP38-SPOOF\"";
            // BCP38: drop martians in forward chain
            $martians = '0.0.0.0/8,127.0.0.0/8,169.254.0.0/16,192.0.2.0/24,198.51.100.0/24,203.0.113.0/24,240.0.0.0/4';
            $rules[] = "/ip firewall filter add chain=forward src-address={$martians} action=drop comment=\"SEC-$id-BCP38-MARTIAN\"";
            // DDoS: SYN flood
            $rules[] = "/ip firewall filter add chain=input protocol=tcp connection-state=new limit=50/s,5 action=drop comment=\"SEC-$id-DDoS-SYN\"";
            // DDoS: UDP flood (DNS amplification)
            $rules[] = "/ip firewall filter add chain=input protocol=udp connection-state=new limit=100/s,5 action=drop comment=\"SEC-$id-DDoS-UDP\"";
            // DDoS: ICMP flood
            $rules[] = "/ip firewall filter add chain=input protocol=icmp connection-state=new limit=20/s,5 action=drop comment=\"SEC-$id-DDoS-ICMP\"";
            // DDoS: per-client connection cap (each PPPoE session limited to 200 new conns/s)
            $rules[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=new connection-limit=200,32 action=drop comment=\"SEC-$id-DDoS-CONN\"";
        }

        $rules[] = "";
        return $rules;
    }

    private function isLowEndDevice(string $model): bool
    {
        if (empty($model)) {
            return false; // Default to fast profile if unknown
        }
        
        $lowEndPatterns = [
            'hAP ac lite', 'hAP lite', 'hAP mini', 'hAP 2n',
            'cAP lite', 'cAP ac', 'wAP', 'wsAP',
            'OmniTIK 5 PoE ac', 'mAP',
            'RB941', 'RB951', 'RB952', 'RB750',
            'LDF', 'QRT', 'SXT',
            'grooveA', 'Metal',
        ];
        
        $modelLower = strtolower($model);
        foreach ($lowEndPatterns as $pattern) {
            if (stripos($modelLower, strtolower($pattern)) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function escapeRouterOsString(string $string): string
    {
        $string = str_replace(["\r\n", "\r", "\n"], '\\r\\n', $string);
        return str_replace(['\\', '"', ';', '{', '}', '$'], ['\\\\', '\\"', '\\;', '\\{', '\\}', '\\$'], $string);
    }

    private function getSafeGatewayIp(string $cidr, ?string $gw): string
    {
        [$net, $mask] = array_pad(explode('/', $cidr, 2), 2, 24);
        $netL = ip2long($net);
        if ($netL === false) {
            return (string) $gw;
        }

        $mask = (int) $mask;
        $base = $netL & ((-1 << (32 - $mask)) & 0xFFFFFFFF);
        $broadcast = $base | (~((-1 << (32 - $mask)) & 0xFFFFFFFF) & 0xFFFFFFFF);

        $gwL = $gw ? ip2long($gw) : false;
        if ($gwL === false || ($gwL & ((-1 << (32 - $mask)) & 0xFFFFFFFF)) !== $base || $gwL === $base || $gwL === $broadcast) {
            return long2ip($base + 1);
        }

        return $gw;
    }

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
                $s[] = "/interface vlan add name=\"$access\" vlan-id={$vlanId} interface=\"$iface\" comment=\"PPPoE-$id\"";
            }
            $s[] = ":do { /interface bridge port add bridge=\"$bridge\" interface=\"$access\" comment=\"PPPoE-$id-add\" } on-error={}";
            $s[] = "/log info \"PPPoE-$id: Added interface $iface to bridge\"";
        }

        $s[] = "/log info \"PPPoE-$id-ADD-INTERFACES-DONE\"";

        return implode("\n", $s);
    }
}
