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

        $interfaces = is_array($service->interface_name)
            ? $service->interface_name
            : array_values(array_filter(array_map('trim', explode(',', (string) $service->interface_name))));

        if (empty($interfaces)) {
            throw new \RuntimeException('No PPPoE interfaces provided');
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
            'radius_server' => env('VPN_SERVER_IP', env('RADIUS_SERVER_IP', env('RADIUS_SERVER_HOST', 'wificore-freeradius'))),
            'radius_secret' => env('RADIUS_SECRET', 'testing123'),
        ]);
    }

    private function buildConfiguration(array $p): string
    {
        $id = substr(str_replace('-', '', $p['router_id']), 0, 8);

        $p += [
            'id'         => $id,
            'pool'       => "pppoe-pool-$id",
            'profile'    => "pppoe-prof-$id",
            'service'    => "pppoe-svc-$id",
            'pppoe_list' => "PPPOE-$id",
            'wan_list'   => "WAN",
        ];

        $s = [];

        /* ================= VERSION GUARD (FIXED) ================= */

        $s[] = ":if ([:tonum [:pick [/system resource get version] 0 1]] < 7) do={ /log error \"ROS7REQ\"; :error \"ROS7\" }";
        $s[] = "";

        /* ================= ROLLBACK SAFETY ================= */

        $s[] = "/log info \"PPPoE-$id-START\"";
        $s[] = ":do { /file remove [find name=\"pre-pppoe-$id.backup\"]; } on-error={}";
        $s[] = "/system backup save name=pre-pppoe-$id";
        $s[] = ":do { /system script environment set [find name=rollback_needed] value=\"yes\"; } on-error={ /system script environment add name=rollback_needed value=\"yes\"; }";

        $s[] = ":do { /system scheduler remove [find name=pppoe-rollback-$id]; } on-error={}";
        $s[] = ":do { /system scheduler add name=pppoe-rollback-$id interval=2m on-event=\":if (\\\$rollback_needed=\\\"yes\\\") do={ /system backup load name=pre-pppoe-$id }\"; } on-error={}";
        $s[] = "";

        /* ================= LOW-END DEVICE SAFETY ================= */

        $s[] = "/ip firewall connection tracking set enabled=yes tcp-established-timeout=1h udp-timeout=10s";

        $s[] = "/ip firewall service-port set ftp disabled=yes";
        $s[] = "/ip firewall service-port set tftp disabled=yes";
        $s[] = "/ip firewall service-port set sip disabled=yes";
        $s[] = "/ip firewall service-port set h323 disabled=yes";
        $s[] = "/ip firewall service-port set pptp disabled=yes";
        $s[] = "";

        /* ================= CAPACITY AWARENESS ================= */

        $s[] = "/log info \"PPPoE-$id-CAP80\"";
        $s[] = ":do { /system scheduler remove [find name=pppoe-sessions-$id]; } on-error={}";
        $s[] = ":do { /system scheduler add name=pppoe-sessions-$id interval=1m on-event=\":local c [/ppp active print count-only]; :if (\\\$c > 70) do={ /log warning \\\"PPPoE-$id-HIGH\\\" }\"; } on-error={}";
        $s[] = "";

        /* ================= INTERFACE LISTS ================= */

        $s[] = ":do { /interface list add name={$p['wan_list']}; } on-error={}";
        $s[] = ":do { /interface list add name={$p['pppoe_list']}; } on-error={}";
        $s[] = "";

        /* ================= RADIUS ================= */

        $s[] = ":do { /radius remove [find comment=\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/radius add service=ppp address={$p['radius_server']} ".
                "secret=\"{$p['radius_secret']}\" authentication-port=1812 ".
                "accounting-port=1813 timeout=3s comment=\"PPPoE-$id\"";
        $s[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=5m";
        $s[] = "";

        /* ================= IP POOL ================= */

        $s[] = ":do { /ip pool remove [find name=\"{$p['pool']}\"]; } on-error={}";
        $s[] = "/ip pool add name=\"{$p['pool']}\" ranges={$p['range_start']}-{$p['range_end']} comment=\"PPPoE-$id\"";
        $s[] = "";

        /* ================= PPP PROFILE ================= */

        $s[] = ":do { /ppp profile remove [find name=\"{$p['profile']}\"]; } on-error={}";
        $s[] = "/ppp profile add name=\"{$p['profile']}\" ".
                "local-address={$p['gateway_ip']} remote-address={$p['pool']} ".
                "dns-server=\"{$p['dns_primary']},{$p['dns_secondary']}\" ".
                "use-compression=no use-encryption=required only-one=yes ".
                "change-tcp-mss=yes comment=\"PPPoE-$id\"";
        $s[] = "";

        /* ================= INTERFACES & PPPoE SERVERS ================= */

        $s[] = ":do { /interface pppoe-server server remove [find comment=\"PPPoE-$id\"]; } on-error={}";

        foreach ($p['interfaces'] as $iface) {
            $access = $iface;

            if ($p['vlan_required'] && $p['vlan_id']) {
                $access = "vlan{$p['vlan_id']}-$iface";
                $s[] = ":do { /interface vlan remove [find name=\"{$access}\"]; } on-error={}";
                $s[] = "/interface vlan add name=\"{$access}\" vlan-id={$p['vlan_id']} interface=\"{$iface}\" comment=\"PPPoE-$id\"";
            }

            $s[] = ":do { /interface pppoe-server server remove [find interface=\"{$access}\" service-name=\"{$p['service']}\"]; } on-error={}";
            $s[] = "/interface pppoe-server server add interface=\"{$access}\" ".
                    "service-name=\"{$p['service']}\" default-profile=\"{$p['profile']}\" ".
                    "authentication=pap,chap,mschap2 one-session-per-host=yes ".
                    "keepalive-timeout=10 max-mtu=1480 max-mru=1480 ".
                    "disabled=no comment=\"PPPoE-$id\"";

            $s[] = ":do { /interface list member remove [find list={$p['pppoe_list']} interface=\"{$access}\"]; } on-error={}";
            $s[] = "/interface list member add list={$p['pppoe_list']} interface=\"{$access}\"";
            $s[] = "";
        }

        /* ================= FIREWALL ================= */

        $s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id\"]; } on-error={}";

        $s[] = "/ip firewall filter add chain=forward protocol=udp dst-port=1812,1813 action=accept comment=\"PPPoE-$id-RAD\"";

        $s[] = "/ip firewall filter add chain=forward action=fasttrack-connection connection-state=established,related comment=\"PPPoE-$id-FT\"";
        $s[] = "/ip firewall filter add chain=forward action=accept connection-state=established,related comment=\"PPPoE-$id-EST\"";
        $s[] = "/ip firewall filter add chain=forward action=drop connection-state=invalid comment=\"PPPoE-$id-INV\"";

        $s[] = "/ip firewall filter add chain=forward in-interface-list={$p['pppoe_list']} out-interface-list={$p['wan_list']} action=accept comment=\"PPPoE-$id-INET\"";
        $s[] = "";

        /* ================= SSH BRUTE FORCE ================= */

        $s[] = ":do { /ip firewall filter remove [find comment=\"MGMT-EST\"]; } on-error={}";
        $s[] = ":do { /ip firewall filter remove [find comment=\"MGMT-INV\"]; } on-error={}";
        $s[] = ":do { /ip firewall filter remove [find comment=\"SSH-S1\"]; } on-error={}";
        $s[] = ":do { /ip firewall filter remove [find comment=\"SSH-S2\"]; } on-error={}";
        $s[] = ":do { /ip firewall filter remove [find comment=\"SSH-BL\"]; } on-error={}";
        $s[] = ":do { /ip firewall filter remove [find comment=\"SSH-DROP\"]; } on-error={}";

        $s[] = "/ip firewall filter add chain=input action=accept connection-state=established,related comment=\"MGMT-EST\"";
        $s[] = "/ip firewall filter add chain=input action=drop connection-state=invalid comment=\"MGMT-INV\"";

        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=22 connection-state=new action=add-src-to-address-list address-list=ssh_stage1 address-list-timeout=1m comment=\"SSH-S1\"";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=22 src-address-list=ssh_stage1 action=add-src-to-address-list address-list=ssh_stage2 address-list-timeout=1m comment=\"SSH-S2\"";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=22 src-address-list=ssh_stage2 action=add-src-to-address-list address-list=ssh_blacklist address-list-timeout=1d comment=\"SSH-BL\"";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=22 src-address-list=ssh_blacklist action=drop comment=\"SSH-DROP\"";
        $s[] = "";

        /* ================= NAT ================= */

        $s[] = ":do { /ip firewall nat remove [find comment=\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/ip firewall nat add chain=srcnat out-interface-list={$p['wan_list']} action=masquerade comment=\"PPPoE-$id\"";
        $s[] = "";

        /* ================= COMMIT ================= */

        $s[] = ":do { /system script environment set [find name=rollback_needed] value=\"no\"; } on-error={ /system script environment add name=rollback_needed value=\"no\"; }";
        $s[] = ":do { /system scheduler remove [find name=pppoe-rollback-$id]; } on-error={}";
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
