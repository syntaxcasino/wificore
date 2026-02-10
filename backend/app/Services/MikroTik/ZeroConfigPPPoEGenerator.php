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
            'radius_server' => config('radius.server_ip', config('services.radius.host', 'wificore-freeradius')),
            'radius_secret' => config('radius.secret', 'testing123'),
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
        $s[] = ":do { :if ([:len [/ppp profile find name=\"{$p['profile']}\"]] = 0) do={ /ppp profile add name=\"{$p['profile']}\" local-address={$p['gateway_ip']} remote-address={$p['pool']} dns-server={$p['dns_primary']},{$p['dns_secondary']} only-one=yes change-tcp-mss=yes use-compression=no use-encryption=no comment=\"PPPoE-$id\" } } on-error={}";

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
        // Remove existing input rules for idempotent re-ordering
        $s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-(DISC|EST-IN|INV-IN|DROP-IN)\"]; } on-error={}";
        // Insert in REVERSE order with place-before=0 for correct ordering at top:
        //   1. DISC (PPPoE discovery)
        //   2. EST-IN (established/related)
        //   3. INV-IN (invalid drop)
        //   4. DROP-IN (block all other)
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} action=drop place-before=0 comment=\"PPPoE-$id-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} protocol=udp dst-port=8863-8864 action=accept place-before=0 comment=\"PPPoE-$id-DISC\"";

        // FORWARD chain - control PPPoE client traffic
        // CRITICAL: ALL rules MUST be scoped to in-interface={bridge} to avoid affecting other router traffic.
        // Without in-interface scoping, established/related accept would match ALL traffic on the router,
        // letting any connected device (not just PPPoE) access the internet.
        // PPPoE auth is L2 (PADI/PADO/PADR/PADS) — clients don't need DNS before authentication.
        // Rule order:
        // 1. Allow established/related FROM PPPoE bridge (keeps authenticated sessions working)
        // 2. Drop invalid FROM PPPoE bridge
        // 3. Allow authenticated PPPoE subnet to WAN
        // 4. Allow local traffic within PPPoE subnet
        // 5. DROP everything else from bridge (unauthenticated devices)

        // Remove existing forward rules for idempotent re-ordering (only our PPPoE service rules)
        $s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-(EST|INV|DNS|INET|LOCAL|BLOCK-UNAUTH)\"]; } on-error={}";

        // CRITICAL: Insert rules in REVERSE order with place-before=0 so they end up
        // at the TOP of the filter list in the CORRECT order:
        //   1. EST (established/related accept)
        //   2. INV (invalid drop)
        //   3. INET (authenticated subnet to WAN)
        //   4. LOCAL (authenticated subnet local traffic)
        //   5. BLOCK-UNAUTH (drop everything else from bridge)
        // Since place-before=0 inserts at position 0, we add LAST rule first.
        $s[] = "/ip firewall filter add chain=forward in-interface={$bridge} action=drop place-before=0 comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        $s[] = "/ip firewall filter add chain=forward src-address={$p['network_cidr']} in-interface={$bridge} dst-address={$p['network_cidr']} action=accept place-before=0 comment=\"PPPoE-$id-LOCAL\"";
        $s[] = "/ip firewall filter add chain=forward src-address={$p['network_cidr']} in-interface={$bridge} out-interface-list={$p['wan_list']} action=accept place-before=0 comment=\"PPPoE-$id-INET\"";
        $s[] = "/ip firewall filter add chain=forward in-interface={$bridge} connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV\"";
        $s[] = "/ip firewall filter add chain=forward in-interface={$bridge} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST\"";

        // ============ NAT CONFIGURATION ============
        // Scope masquerade to ONLY the PPPoE subnet (authenticated users)
        // Do NOT masquerade all traffic — only traffic from the assigned IP pool
        $s[] = ":do { /ip firewall nat remove [find comment=\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/ip firewall nat add chain=srcnat src-address={$p['network_cidr']} out-interface-list={$p['wan_list']} action=masquerade comment=\"PPPoE-$id\"";

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
