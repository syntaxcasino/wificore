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
            'id'         => $id,
            'pool'       => "pppoe-pool-$id",
            'profile'    => "pppoe-prof-$id",
            'service'    => "pppoe-svc-$id",
            'pppoe_list' => "PPPOE-$id",
            'pppoe_active_list' => "PPPOE-ACTIVE-$id",
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

        // ============ INTERFACE LISTS ============
        $s[] = ":do { :if ([:len [/interface list find name={$p['wan_list']}]] = 0) do={ /interface list add name={$p['wan_list']} } } on-error={}";
        $s[] = ":do { :if ([:len [/interface list find name={$p['pppoe_list']}]] = 0) do={ /interface list add name={$p['pppoe_list']} } } on-error={}";
        // PPPOE-ACTIVE list: PPP profile assigns authenticated dynamic interfaces here
        $s[] = ":do { :if ([:len [/interface list find name={$p['pppoe_active_list']}]] = 0) do={ /interface list add name={$p['pppoe_active_list']} } } on-error={}";
        $s[] = ":do { :if ([:len [/interface find name=\"ether1\"]] > 0) do={ :if ([:len [/interface list member find list={$p['wan_list']} interface=\"ether1\"]] = 0) do={ /interface list member add list={$p['wan_list']} interface=\"ether1\" } } } on-error={}";

        // ============ PPP PROFILE (ISP-GRADE) ============
        // No static rate-limit - RADIUS sets Mikrotik-Rate-Limit dynamically
        // Idempotent: only create if missing
        // interface-list=PPPOE-ACTIVE: dynamic <pppoe-*> interfaces auto-join this list on auth
        // This is CRITICAL for security: firewall rules match on this list, not src-address
        $s[] = ":do { :if ([:len [/ppp profile find name=\"{$p['profile']}\"]] = 0) do={ /ppp profile add name=\"{$p['profile']}\" local-address={$p['gateway_ip']} remote-address={$p['pool']} dns-server={$p['dns_primary']},{$p['dns_secondary']} interface-list={$p['pppoe_active_list']} only-one=yes change-tcp-mss=yes use-compression=no use-encryption=no comment=\"PPPoE-$id\" } } on-error={}";
        $s[] = ":do { /ppp profile set [find name=\"{$p['profile']}\"] interface-list={$p['pppoe_active_list']} } on-error={}";

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

        // Ensure DHCP is disabled on the PPPoE bridge (PPPoE handles auth/IP assignment)
        $s[] = ":do { /ip dhcp-server remove [find interface=\"{$bridge}\"]; } on-error={}";

        // ============ PPPoE SERVER ============
        // ISP-grade settings: MSS clamping, keepalive, proper MTU
        // Idempotent: only create if missing
        $s[] = ":do { :if ([:len [/interface pppoe-server server find service-name=\"{$p['service']}\"]] = 0) do={ /interface pppoe-server server add service-name=\"{$p['service']}\" interface=\"{$bridge}\" default-profile=\"{$p['profile']}\" authentication=chap,mschap2 one-session-per-host=yes keepalive-timeout=30 max-mtu=1480 max-mru=1480 disabled=no comment=\"PPPoE-$id\" } } on-error={ :error \"PPPoE-$id: PPPoE server create failed ({$p['service']})\" }";
        $s[] = ":do { /interface pppoe-server server set [find service-name=\"{$p['service']}\"] interface=\"{$bridge}\" default-profile=\"{$p['profile']}\" authentication=chap,mschap2 one-session-per-host=yes keepalive-timeout=30 max-mtu=1480 max-mru=1480 disabled=no comment=\"PPPoE-$id\" } on-error={ :error \"PPPoE-$id: PPPoE server set failed ({$p['service']})\" }";
        $s[] = ":if ([:len [/interface pppoe-server server find service-name=\"{$p['service']}\"]] = 0) do={ :error \"PPPoE-$id: PPPoE server missing ({$p['service']})\" }";
        $s[] = ":do { :if ([:len [/interface list member find list={$p['pppoe_list']} interface=\"{$bridge}\"]] = 0) do={ /interface list member add list={$p['pppoe_list']} interface=\"{$bridge}\" } } on-error={}";

        // ============ FIREWALL CONFIGURATION ============
        // Idempotent: only add rules if not present (check by comment)

        // Management access - allow VPN subnet only to management ports
        $managementPorts = '22,8291,8728,8729';
        $s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-MGMT\"]; } on-error={}";
        $s[] = ":do { /ip firewall filter remove [find comment=\"PPPoE-$id-SNMP-ALLOW\"]; } on-error={}";

        // INPUT chain - protect the router itself
        // Rebuild the entire PPPoE-managed input block in reverse order so the final chain order is:
        //   1. MGMT-EST
        //   2. MGMT-ALLOW
        //   3. SNMP-ALLOW
        //   4. DISC
        //   5. EST-IN
        //   6. INV-IN
        //   7. DROP-IN
        //   8. MGMT-DROP
        $s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-(DISC|EST-IN|INV-IN|DROP-IN)\"]; } on-error={}";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port={$managementPorts} src-address=!{$p['management_subnet']} action=drop place-before=0 comment=\"PPPoE-$id-MGMT-DROP\"";
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} action=drop place-before=0 comment=\"PPPoE-$id-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST-IN\"";
        $s[] = "/ip firewall filter add chain=input in-interface={$bridge} protocol=udp dst-port=8863-8864 action=accept place-before=0 comment=\"PPPoE-$id-DISC\"";
        $s[] = ":do { /ip firewall filter add chain=input protocol=udp dst-port=161 src-address={$p['radius_server']} action=accept place-before=0 comment=\"PPPoE-$id-SNMP-ALLOW\" } on-error={ /log warning \"PPPoE-$id-SNMP-ALLOW skipped\" }";
        $s[] = "/ip firewall filter add chain=input protocol=tcp dst-port={$managementPorts} src-address={$p['management_subnet']} action=accept place-before=0 comment=\"PPPoE-$id-MGMT-ALLOW\"";
        $s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-MGMT-EST\"";

        // FORWARD chain - control PPPoE client traffic
        // SECURITY PRINCIPLE: Never allow traffic from the bridge directly.
        // Allow ONLY traffic from PPPoE dynamic interfaces (authenticated sessions).
        // The PPP profile sets interface-list=PPPOE-ACTIVE-{id}, so when a user authenticates,
        // their dynamic <pppoe-*> interface auto-joins that list.
        // An unauthorized client on the bridge can spoof src-address but CANNOT create a PPPoE session.
        //
        // Rule order:
        // 1. Accept established/related FROM WAN back to authenticated PPPoE interfaces
        // 2. Accept established/related FROM authenticated PPPoE interfaces
        // 3. Drop invalid FROM authenticated PPPoE interfaces
        // 4. Allow authenticated PPPoE interfaces to WAN
        // 5. DROP everything from the bridge (unauthenticated devices)

        // Remove existing forward rules for idempotent re-ordering (only our PPPoE service rules)
        $s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-(WAN-EST|EST|INV|DNS|INET|LOCAL|BLOCK-UNAUTH)\"]; } on-error={}";

        // CRITICAL: Insert rules in REVERSE order with place-before=0 so they end up
        // at the TOP of the filter list in the CORRECT order.
        // Since place-before=0 inserts at position 0, we add LAST rule first.
        //
        // 5. DROP all traffic from bridge (unauthenticated devices cannot pass)
        $s[] = "/ip firewall filter add chain=forward in-interface={$bridge} action=drop place-before=0 comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
        // 4. Allow ONLY authenticated PPPoE sessions to WAN (interface-list, NOT src-address)
        $s[] = "/ip firewall filter add chain=forward in-interface-list={$p['pppoe_active_list']} out-interface-list={$p['wan_list']} action=accept place-before=0 comment=\"PPPoE-$id-INET\"";
        // 3. Drop invalid from authenticated PPPoE interfaces
        $s[] = "/ip firewall filter add chain=forward in-interface-list={$p['pppoe_active_list']} connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV\"";
        // 2. Accept established/related from authenticated PPPoE interfaces
        $s[] = "/ip firewall filter add chain=forward in-interface-list={$p['pppoe_active_list']} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST\"";
        // 1. Accept established/related return traffic from WAN to authenticated PPPoE interfaces
        $s[] = "/ip firewall filter add chain=forward in-interface-list={$p['wan_list']} out-interface-list={$p['pppoe_active_list']} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-WAN-EST\"";

        // GLOBAL DEFAULT DROP (input + forward)
        $s[] = ":do { /ip firewall filter remove [find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}";
        $s[] = "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"";
        $s[] = "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"";

        // ============ NAT CONFIGURATION ============
        // Masquerade ONLY traffic from authenticated PPPoE interfaces (not subnet-based)
        // Subnet-based NAT is a security bypass: unauthorized clients can spoof src-address
        $s[] = ":do { /ip firewall nat remove [find comment=\"PPPoE-$id\"]; } on-error={}";
        $s[] = "/ip firewall nat add chain=srcnat in-interface-list={$p['pppoe_active_list']} out-interface-list={$p['wan_list']} action=masquerade comment=\"PPPoE-$id\"";

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
