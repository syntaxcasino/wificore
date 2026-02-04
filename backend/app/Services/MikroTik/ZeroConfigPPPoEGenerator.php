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

        $bridge = "pppoe-br-$id";
        $s = [];

        // ============ ISP-GRADE PPPoE CONFIGURATION ============
        // Optimized for hAP lite while maintaining best practices

        $s[] = "/log info \"PPPoE-$id-START\"";

        // ============ RADIUS CONFIGURATION ============
        // Full RADIUS with authentication and accounting
        $s[] = ":do { /radius remove [find comment~\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/radius add service=ppp address={$p['radius_server']} secret=\"{$p['radius_secret']}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"PPPoE-$id\"";
        // Enable RADIUS for PPP with accounting and interim updates
        $s[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=5m";

        // ============ IP POOL ============
        // Idempotent: only recreate if missing or different
        $s[] = ":do { :if ([:len [/ip pool find name=\"{$p['pool']}\"]] = 0) do={ /ip pool add name=\"{$p['pool']}\" ranges={$p['range_start']}-{$p['range_end']} comment=\"PPPoE-$id\" } } on-error={}";

        // ============ PPP PROFILE (ISP-GRADE) ============
        // No static rate-limit - RADIUS sets Mikrotik-Rate-Limit dynamically
        // Idempotent: only create if missing
        $s[] = ":do { :if ([:len [/ppp profile find name=\"{$p['profile']}\"]] = 0) do={ /ppp profile add name=\"{$p['profile']}\" local-address={$p['gateway_ip']} remote-address={$p['pool']} dns-server={$p['dns_primary']},{$p['dns_secondary']} only-one=yes change-tcp-mss=yes use-compression=no use-encryption=required comment=\"PPPoE-$id\" } } on-error={}";

        // ============ INTERFACE LISTS ============
        $s[] = ":do { :if ([:len [/interface list find name={$p['wan_list']}]] = 0) do={ /interface list add name={$p['wan_list']} } } on-error={}";
        $s[] = ":do { :if ([:len [/interface list find name={$p['pppoe_list']}]] = 0) do={ /interface list add name={$p['pppoe_list']} } } on-error={}";

        // ============ PPPoE BRIDGE SETUP ============
        // Bridge isolates PPPoE discovery/session traffic from other networks
        // Idempotent: only create bridge if missing (avoid disconnecting existing PPPoE sessions)
        // Use RSTP to prevent bridge loops; frame-types=admit-only-untagged-and-priority-tagged for PPPoE
        $s[] = ":do { :if ([:len [/interface bridge find name=\"{$bridge}\"]] = 0) do={ /interface bridge add name=\"{$bridge}\" protocol-mode=rstp comment=\"PPPoE-$id\" } } on-error={}";

        // Add client-facing ports to bridge
        foreach ($p['interfaces'] as $iface) {
            $access = $iface;
            if ($p['vlan_required'] && $p['vlan_id']) {
                $access = "vlan{$p['vlan_id']}-$iface";
                $s[] = ":do { /interface vlan remove [find name=\"{$access}\"]; } on-error={}";
                $s[] = "/interface vlan add name=\"{$access}\" vlan-id={$p['vlan_id']} interface=\"{$iface}\" comment=\"PPPoE-$id\"";
            }
            // Only add port if not already in bridge
            $s[] = ":do { :if ([:len [/interface bridge port find bridge=\"{$bridge}\" interface=\"{$access}\"]] = 0) do={ /interface bridge port add bridge=\"{$bridge}\" interface=\"{$access}\" comment=\"PPPoE-$id\" } } on-error={}";
        }

        // ============ PPPoE SERVER ============
        // ISP-grade settings: MSS clamping, keepalive, proper MTU
        // Idempotent: only create if missing
        $s[] = ":do { :if ([:len [/interface pppoe-server server find comment~\"PPPoE-$id\"]] = 0) do={ /interface pppoe-server server add interface=\"{$bridge}\" service-name=\"{$p['service']}\" default-profile=\"{$p['profile']}\" authentication=pap,chap,mschap2 one-session-per-host=yes keepalive-timeout=30 max-mtu=1480 max-mru=1480 mrru=disabled disabled=no comment=\"PPPoE-$id\" } } on-error={}";
        $s[] = ":do { :if ([:len [/interface list member find list={$p['pppoe_list']} interface=\"{$bridge}\"]] = 0) do={ /interface list member add list={$p['pppoe_list']} interface=\"{$bridge}\" } } on-error={}";

        // ============ FIREWALL CONFIGURATION ============
        // Idempotent: only add rules if not present (check by comment)

        // INPUT chain - protect the router itself
        // Allow PPPoE discovery on bridge (PADI/PADO/PADR/PADS)
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-DISC\"]] = 0) do={ /ip firewall filter add chain=input in-interface={$bridge} protocol=udp dst-port=8863-8864 action=accept comment=\"PPPoE-$id-DISC\" } } on-error={}";
        // Allow established connections to router
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-EST-IN\"]] = 0) do={ /ip firewall filter add chain=input connection-state=established,related action=accept comment=\"PPPoE-$id-EST-IN\" } } on-error={}";
        // Drop invalid
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-INV-IN\"]] = 0) do={ /ip firewall filter add chain=input connection-state=invalid action=drop comment=\"PPPoE-$id-INV-IN\" } } on-error={}";
        // CRITICAL: Block all other direct access from PPPoE bridge
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-DROP-IN\"]] = 0) do={ /ip firewall filter add chain=input in-interface={$bridge} action=drop comment=\"PPPoE-$id-DROP-IN\" } } on-error={}";

        // FORWARD chain - control client traffic
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-BLOCK-UNAUTH\"]] = 0) do={ /ip firewall filter add chain=forward in-interface={$bridge} action=drop comment=\"PPPoE-$id-BLOCK-UNAUTH\" } } on-error={}";
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-EST\"]] = 0) do={ /ip firewall filter add chain=forward connection-state=established,related action=accept comment=\"PPPoE-$id-EST\" } } on-error={}";
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-INV\"]] = 0) do={ /ip firewall filter add chain=forward connection-state=invalid action=drop comment=\"PPPoE-$id-INV\" } } on-error={}";
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-INET\"]] = 0) do={ /ip firewall filter add chain=forward src-address={$p['network_cidr']} out-interface-list={$p['wan_list']} action=accept comment=\"PPPoE-$id-INET\" } } on-error={}";
        $s[] = ":do { :if ([:len [/ip firewall filter find comment=\"PPPoE-$id-LOCAL\"]] = 0) do={ /ip firewall filter add chain=forward src-address={$p['network_cidr']} dst-address={$p['network_cidr']} action=accept comment=\"PPPoE-$id-LOCAL\" } } on-error={}";

        // ============ NAT CONFIGURATION ============
        // Idempotent: only add NAT rule if not present
        $s[] = ":do { :if ([:len [/ip firewall nat find comment=\"PPPoE-$id\"]] = 0) do={ /ip firewall nat add chain=srcnat src-address={$p['network_cidr']} out-interface-list={$p['wan_list']} action=masquerade comment=\"PPPoE-$id\" } } on-error={}";

        // ============ CONNECTION TRACKING OPTIMIZATION ============
        // Reduce memory usage on low-end devices
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
