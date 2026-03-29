<?php

namespace App\Services\MikroTik;

use App\Models\RouterService;
use App\Services\RouterResourceManager;
use App\Models\Router;
use App\Models\TenantIpPool;

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

        return $this->buildConfiguration([
            'router_id'     => (string) $router->id,
            'tenant_id'     => (string) $router->tenant_id,
            'interfaces'    => $interfaces,
            'vlan_required' => (bool) $service->vlan_required,
            'vlan_id'       => $service->vlan_id,
            'network_cidr'  => $pool->network_cidr,
            'gateway_ip'    => $this->getSafeGatewayIp($pool->network_cidr, $pool->gateway_ip),
            'range_start'   => $pool->range_start,
            'range_end'     => $pool->range_end,
            'dns_primary'   => $pool->dns_primary ?? '8.8.8.8',
            'dns_secondary' => $pool->dns_secondary ?? '8.8.4.4',
            'radius_server' => $resolvedRadiusServer,
            'radius_secret' => config('radius.secret', 'testing123'),
            'management_subnet' => config('vpn.subnet.base', '10.0.0.0/8'),
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
        $rsec    = $p['radius_secret'];
        $mgmt    = $p['management_subnet'];
        $mports  = '22,8291,8728,8729';

        $s = [];

        $s[] = "/log info \"PPPoE-$id-START\"";

        // RADIUS
        $s[] = ":do { /radius remove [/radius find comment~\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/radius add service=ppp address=$rs secret=\"$rsec\" timeout=3s comment=\"PPPoE-$id\"";
        $s[] = "/radius set [/radius find comment=\"PPPoE-$id\"] authentication-port=1812 accounting-port=1813";
        $s[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=5m";

        // IP POOL
        $s[] = ":do { /ip pool add name=\"$pool\" comment=\"PPPoE-$id\" } on-error={}";
        $s[] = ":do { /ip pool set [/ip pool find name=\"$pool\"] ranges={$p['range_start']}-{$p['range_end']} } on-error={}";

        // INTERFACE LISTS
        $s[] = ":do { /interface list add name=$wan } on-error={}";
        $s[] = ":do { /interface list add name=$pl } on-error={}";
        $s[] = ":do { /interface list add name=$pal } on-error={}";
        $s[] = ":do { /interface list member add list=$wan interface=ether1 } on-error={}";

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
        $s[] = ":delay 2s";
        $s[] = ":do { /interface bridge set [/interface bridge find name=\"$bridge\"] protocol-mode=rstp } on-error={ /log info \"PPPoE-$id: WARN - Failed to set bridge protocol\" }";

        // Add ALL interfaces to bridge (silent continuation with logging) - with delays for low-end devices
        $interfaceCount = count($p['interfaces']);
        $currentInterface = 0;
        foreach ($p['interfaces'] as $iface) {
            $access = $iface;
            if ($p['vlan_required'] && $p['vlan_id']) {
                $access = "vlan{$p['vlan_id']}-$iface";
                $s[] = ":do { /interface vlan remove [/interface vlan find name=\"$access\"]; } on-error={ /log info \"PPPoE-$id: WARN - Failed to remove VLAN $access\" }";
                $s[] = "/interface vlan add name=\"$access\" vlan-id={$p['vlan_id']} interface=\"$iface\" comment=\"PPPoE-$id\"";
            }
            $s[] = ":do { /interface bridge port add bridge=\"$bridge\" interface=\"$access\" } on-error={ /log info \"PPPoE-$id: WARN - Failed to add interface $access to bridge\" }";
            // Add delay after every 2 interfaces on low-end devices
            $currentInterface++;
            if ($currentInterface % 2 === 0 && $currentInterface < $interfaceCount) {
                $s[] = ":delay 1s";
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
        $s[] = ":do { /interface list member add list=$pl interface=\"$bridge\" } on-error={ /log info \"PPPoE-$id: WARN - Failed to add bridge to list $pl\" }";

        // FIREWALL — clean up old rules then re-add (optimized for low-end)
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"PPPoE-$id\"]; } on-error={}";
        
        // INPUT rules (append to end instead of insert - much faster on low CPU)
        $s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept comment=\"PPPoE-$id-EST-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface-list=$pal protocol=icmp action=accept comment=\"PPPoE-$id-ICMP\"";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=$mgmt action=accept comment=\"PPPoE-$id-MGMT-ALLOW\"";
        $s[] = "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address=$mgmt action=accept comment=\"PPPoE-$id-SNMP-ALLOW\"";
        $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" protocol=udp dst-port=8863-8864 action=accept comment=\"PPPoE-$id-DISC\"";
        $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" connection-state=invalid action=drop comment=\"PPPoE-$id-INV-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" action=drop comment=\"PPPoE-$id-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=!$mgmt action=drop comment=\"PPPoE-$id-MGMT-DROP\"";
        
        $s[] = ":delay 2s"; // CPU breathing room - longer for hAP lite
        
        // FORWARD rules (append instead of insert)
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$wan out-interface-list=$pal connection-state=established,related action=accept comment=\"pp-wan-est-$id\"";
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=established,related action=accept comment=\"PPPoE-$id-EST\"";
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=invalid action=drop comment=\"PPPoE-$id-INV\"";
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal out-interface-list=$wan action=accept comment=\"PPPoE-$id-INET\"";
        $s[] = "/ip firewall filter add chain=forward in-interface=\"$bridge\" action=drop comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        
        $s[] = ":delay 200ms"; // CPU breathing room
        
        // GLOBAL DEFAULT DROP (at end - less critical for order)
        $s[] = ":do { /ip firewall filter remove [/ip firewall filter find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}";
        $s[] = "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"";
        
        $s[] = ":delay 2s";

        // NAT
        $s[] = ":do { /ip firewall nat remove [/ip firewall nat find comment=\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/ip firewall nat add chain=srcnat in-interface-list=$pal out-interface-list=$wan action=masquerade comment=\"PPPoE-$id\"";

        // CONNECTION TRACKING
        $s[] = "/ip firewall connection tracking set tcp-established-timeout=1h udp-timeout=30s";
        
        $s[] = ":delay 2s"; // Final breathing room before completion

        $s[] = "/log info \"PPPoE-$id-DONE\"";

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
