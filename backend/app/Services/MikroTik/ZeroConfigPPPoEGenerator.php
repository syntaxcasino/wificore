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
            'pppoe_list'        => "PPPOE-$id",
            'pppoe_active_list' => "PPPOE-ACTIVE-$id",
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
        $s[] = ":do { /radius remove [find comment~\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/radius add service=ppp address=$rs secret=\"$rsec\" timeout=3s comment=\"PPPoE-$id\"";
        $s[] = "/radius set [find comment=\"PPPoE-$id\"] authentication-port=1812 accounting-port=1813";
        $s[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=5m";

        // IP POOL
        $s[] = ":do { :if ([:len [/ip pool find name=\"$pool\"]] = 0) do={ /ip pool add name=\"$pool\" comment=\"PPPoE-$id\" } } on-error={}";
        $s[] = ":do { /ip pool set [find name=\"$pool\"] ranges={$p['range_start']}-{$p['range_end']} } on-error={}";

        // INTERFACE LISTS
        $s[] = ":do { :if ([:len [/interface list find name=$wan]] = 0) do={ /interface list add name=$wan } } on-error={}";
        $s[] = ":do { :if ([:len [/interface list find name=$pl]] = 0) do={ /interface list add name=$pl } } on-error={}";
        $s[] = ":do { :if ([:len [/interface list find name=$pal]] = 0) do={ /interface list add name=$pal } } on-error={}";
        $s[] = ":do { :if ([:len [/interface list member find list=$wan interface=ether1]] = 0) do={ /interface list member add list=$wan interface=ether1 } } on-error={}";

        // PPP PROFILE — add minimal then set in short chunks
        $s[] = ":do { :if ([:len [/ppp profile find name=\"$prof\"]] = 0) do={ /ppp profile add name=\"$prof\" comment=\"PPPoE-$id\" } } on-error={}";
        $s[] = ":do { /ppp profile set [find name=\"$prof\"] local-address=$gw remote-address=\"$pool\" } on-error={}";
        $s[] = ":do { /ppp profile set [find name=\"$prof\"] dns-server=$dns only-one=yes } on-error={}";
        $s[] = ":do { /ppp profile set [find name=\"$prof\"] interface-list=$pal } on-error={}";
        $s[] = ":do { /ppp profile set [find name=\"$prof\"] change-tcp-mss=yes use-compression=no } on-error={}";
        $s[] = ":do { /ppp profile set [find name=\"$prof\"] add-default-route=no } on-error={}";

        // BRIDGE
        $s[] = ":do { :if ([:len [/interface bridge find name=\"$bridge\"]] = 0) do={ /interface bridge add name=\"$bridge\" comment=\"PPPoE-$id\" } } on-error={}";
        $s[] = ":do { /interface bridge set [find name=\"$bridge\"] protocol-mode=rstp } on-error={}";

        foreach ($p['interfaces'] as $iface) {
            $access = $iface;
            if ($p['vlan_required'] && $p['vlan_id']) {
                $access = "vlan{$p['vlan_id']}-$iface";
                $s[] = ":do { /interface vlan remove [find name=\"$access\"]; } on-error={}";
                $s[] = "/interface vlan add name=\"$access\" vlan-id={$p['vlan_id']} interface=\"$iface\" comment=\"PPPoE-$id\"";
            }
            $s[] = ":do { :if ([:len [/interface bridge port find bridge=\"$bridge\" interface=\"$access\"]] = 0) do={ /interface bridge port add bridge=\"$bridge\" interface=\"$access\" } } on-error={}";
        }

        $s[] = ":do { /ip dhcp-server remove [find interface=\"$bridge\"]; } on-error={}";

        // PPPoE SERVER — add minimal then set in short chunks
        $s[] = ":do { :if ([:len [/interface pppoe-server server find service-name=\"$svc\"]] = 0) do={ /interface pppoe-server server add service-name=\"$svc\" disabled=no } } on-error={}";
        $s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] interface=\"$bridge\" } on-error={}";
        $s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] default-profile=\"$prof\" disabled=no } on-error={}";
        $s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] authentication=chap,mschap2 } on-error={}";
        $s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] one-session-per-host=yes keepalive-timeout=30 } on-error={}";
        $s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] max-mtu=1480 max-mru=1480 } on-error={}";
        $s[] = ":do { :if ([:len [/interface list member find list=$pl interface=\"$bridge\"]] = 0) do={ /interface list member add list=$pl interface=\"$bridge\" } } on-error={}";

        // FIREWALL — clean up old rules then re-add
        $s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id\"]; } on-error={}";
        // INPUT rules (added in reverse order; place-before=0 puts each at top)
        $s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface-list=$pal protocol=icmp action=accept place-before=0 comment=\"PPPoE-$id-ICMP\"";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=$mgmt action=accept place-before=0 comment=\"PPPoE-$id-MGMT-ALLOW\"";
        $s[] = "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address=$mgmt action=accept place-before=0 comment=\"PPPoE-$id-SNMP-ALLOW\"";
        $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" protocol=udp dst-port=8863-8864 action=accept place-before=0 comment=\"PPPoE-$id-DISC\"";
        $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" action=drop place-before=0 comment=\"PPPoE-$id-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=!$mgmt action=drop place-before=0 comment=\"PPPoE-$id-MGMT-DROP\"";
        // FORWARD rules (added in reverse order)
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$wan out-interface-list=$pal connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-WAN-EST\"";
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST\"";
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV\"";
        $s[] = "/ip firewall filter add chain=forward in-interface-list=$pal out-interface-list=$wan action=accept place-before=0 comment=\"PPPoE-$id-INET\"";
        $s[] = "/ip firewall filter add chain=forward in-interface=\"$bridge\" action=drop place-before=0 comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        // GLOBAL DEFAULT DROP
        $s[] = ":do { /ip firewall filter remove [find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}";
        $s[] = "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"";

        // NAT
        $s[] = ":do { /ip firewall nat remove [find comment=\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/ip firewall nat add chain=srcnat in-interface-list=$pal out-interface-list=$wan action=masquerade comment=\"PPPoE-$id\"";

        // CONNECTION TRACKING
        $s[] = "/ip firewall connection tracking set tcp-established-timeout=1h udp-timeout=30s";

        $s[] = "/log info \"PPPoE-$id-DONE\"";

        return implode("\n", $s);
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
}
