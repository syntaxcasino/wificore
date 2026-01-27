<?php

namespace App\Services\MikroTik;

use App\Models\RouterService;
use App\Services\RouterResourceManager;
use Illuminate\Support\Facades\Log;
use App\Models\Router;
use App\Models\TenantIpPool;

/**
 * ISP-Grade Zero-Config Hotspot Generator
 * Multi-interface, VLAN-aware, RADIUS-only, CPU optimized for hAP Lite
 */
class ZeroConfigHotspotGenerator
{
    /**
     * Generate Hotspot configuration from RouterService
     */
    public function generate(RouterService $service): string
    {
        $router = $service->router;
        $scriptSettings = RouterResourceManager::getScriptSettings($router);
        RouterResourceManager::logResourceInfo($router);

        $interfaces = $this->normalizeInterfaces($service);

        // Hotspot multi-interface mode A: single Hotspot instance on a shared bridge
        $bridgeName = "br-hotspot-{$router->id}";

        $radiusServer = env('VPN_SERVER_IP', env('RADIUS_SERVER_IP', env('RADIUS_SERVER_HOST', 'wificore-freeradius')));
        $resolvedRadiusServer = $radiusServer;
        if (filter_var($resolvedRadiusServer, FILTER_VALIDATE_IP) === false) {
            $resolvedRadiusServer = gethostbyname((string) $resolvedRadiusServer);
        }
        $radiusSecret = env('RADIUS_SECRET', 'testing123');

        $tenant = $router->tenant;
        $baseUrl = parse_url(config('app.url'), PHP_URL_HOST);
        $captivePortalUrl = $tenant ? "https://{$tenant->subdomain}.{$baseUrl}/portal" : null;

        $script = [
            "/log info \"=== ISP-Grade Multi-Interface Hotspot Deployment ===\"",
            "/log info \"Router: {$router->id}\"",
            ""
        ];

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
                    ":do { /interface vlan remove [find name=\"{$vlanIface}\"] } on-error={}",
                    "/interface vlan add name=\"{$vlanIface}\" vlan-id={$vlanId} interface=\"{$ifaceName}\" comment=\"Hotspot VLAN ({$router->id})\"",
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

        // Create shared bridge and attach all access interfaces
        $script = array_merge($script, [
            "# Hotspot Access Bridge (Mode A)",
            ":do { :if ([:len [/interface bridge find name=\"{$bridgeName}\"]] = 0) do={ /interface bridge add name=\"{$bridgeName}\" comment=\"Hotspot Bridge ({$router->id})\" } } on-error={}",
            ":do { /interface bridge port remove [find bridge=\"{$bridgeName}\" comment=\"Hotspot Access Port ({$router->id})\"] } on-error={}",
        ]);

        foreach ($accessIfaces as $iface) {
            $script[] = ":if ([:len [/interface find name=\"{$iface}\"]] = 0) do={ :error \"Hotspot: interface not found ({$iface})\" }";
            $script[] = ":do { /interface bridge port remove [find bridge=\"{$bridgeName}\" interface=\"{$iface}\"] } on-error={}";
            $script[] = ":do { /interface bridge port add bridge=\"{$bridgeName}\" interface=\"{$iface}\" comment=\"Hotspot Access Port ({$router->id})\" } on-error={ :error \"Hotspot: bridge port add failed ({$iface})\" }";
        }

        $script[] = "";

        $params = [
            'router_id' => $router->id,
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
            'tenant_id' => $router->tenant_id,
            'captive_portal_url' => $captivePortalUrl,
            'interface_index' => 0,
            'bridge_name' => null,
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

        $script = array_merge(
            $script,
            $this->generateIpSetup($params),
            $this->generatePoolSetup($params),
            $this->generateDhcpSetup($params),
            $this->generateHotspotSetup($params),
            $this->generateRadiusSetup($params),
            $this->generateFirewallRules($params),
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
            ":do { /interface bridge port remove [find bridge=\"{$bridge}\" interface=\"{$iface}\"] } on-error={}",
            ":do { /interface bridge port add bridge=\"{$bridge}\" interface=\"{$iface}\" comment=\"Hotspot Access Port\" } on-error={}",
            ""
        ];
    }

    private function generateVlanSetup(array $params): array
    {
        return [
            "# VLAN Setup",
            ":if ([:len [/interface vlan find name=\"{$params['interface']}\"]] = 0) do={ /interface vlan add name={$params['interface']} vlan-id={$params['vlan_id']} interface={$params['parent_interface']} comment=\"Hotspot VLAN\" }",
            ""
        ];
    }

    private function generateIpSetup(array $params): array
    {
        $cidr = explode('/', $params['network_cidr'])[1] ?? '24';
        $iface = $params['bridge_name'] ?? $params['interface'];

        return [
            "# IP Addressing",
            ":if ([:len [/ip address find interface=\"{$iface}\" address~\"^{$params['gateway_ip']}/\"]] = 0) do={ /ip address add address={$params['gateway_ip']}/{$cidr} interface=\"{$iface}\" comment=\"Hotspot Gateway\" }",
            ""
        ];
    }

    private function generatePoolSetup(array $params): array
    {
        $poolName = "pool-{$params['router_id']}-{$params['interface_index']}";
        return [
            "# IP Pool",
            ":if ([:len [/ip pool find name=\"{$poolName}\"]] = 0) do={ /ip pool add name={$poolName} ranges={$params['range_start']}-{$params['range_end']} comment=\"Tenant {$params['tenant_id']}\" }",
            ""
        ];
    }

    private function generateDhcpSetup(array $params): array
    {
        $dhcpName = "dhcp-{$params['router_id']}-{$params['interface_index']}";
        $poolName = "pool-{$params['router_id']}-{$params['interface_index']}";
        $network = explode('/', $params['network_cidr'])[0];
        $cidr = explode('/', $params['network_cidr'])[1] ?? '24';
        $dns = "{$params['dns_primary']},{$params['dns_secondary']}";
        $iface = $params['bridge_name'] ?? $params['interface'];

        return [
            "# DHCP Server - CPU-Friendly",
            ":if ([:len [/ip dhcp-server find name=\"{$dhcpName}\"]] = 0) do={ /ip dhcp-server add name={$dhcpName} interface=\"{$iface}\" address-pool={$poolName} lease-time=1h disabled=no authoritative=yes }",
            ":if ([:len [/ip dhcp-server network find comment~\"Hotspot.*\"]] = 0) do={ /ip dhcp-server network add address={$network}/{$cidr} gateway={$params['gateway_ip']} dns-server=\"{$dns}\" comment=\"Hotspot Network\" }",
            ""
        ];
    }

    private function generateHotspotSetup(array $params): array
    {
        $profile = "hs-profile-{$params['router_id']}-{$params['interface_index']}";
        $server = "hs-server-{$params['router_id']}-{$params['interface_index']}";
        $poolName = "pool-{$params['router_id']}-{$params['interface_index']}";
        $iface = $params['bridge_name'] ?? $params['interface'];

        $portalUrl = $params['captive_portal_url']
            ? str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $params['captive_portal_url'])
            : null;

        return array_values(array_filter([
            "# Hotspot Profile - ISP & hAP Lite Optimized",
            ":do { /ip hotspot profile remove [find name=\"{$profile}\"] } on-error={}",
            ":do { /ip hotspot profile add name=\"{$profile}\" hotspot-address={$params['gateway_ip']} login-by=http-chap,http-pap use-radius=yes html-directory=hotspot dns-name=hotspot.local } on-error={}",
            ($portalUrl ? ":do { /file set hotspot/login.html contents=\"<html><head><meta http-equiv=refresh content=0;url={$portalUrl}></head><body></body></html>\" } on-error={}" : null),
            "# Hotspot Server - CPU Optimized for hAP Lite",
            ":do { /ip hotspot remove [find name=\"{$server}\"] } on-error={}",
            ":do { /ip hotspot add name=\"{$server}\" interface=\"{$iface}\" profile=\"{$profile}\" address-pool={$poolName} disabled=no } on-error={}",
            "# User Profile - Default CPU-Friendly",
            ":do { /ip hotspot user profile remove [find name=\"default-hotspot-{$params['router_id']}-{$params['interface_index']}\"] } on-error={}",
            ":do { /ip hotspot user profile add name=\"default-hotspot-{$params['router_id']}-{$params['interface_index']}\" add-mac-cookie=yes shared-users=1 session-timeout=6h } on-error={}",
            ""
        ], fn ($line) => $line !== null && $line !== ''));
    }

    private function generateRadiusSetup(array $params): array
    {
        $profile = "hs-profile-{$params['router_id']}-{$params['interface_index']}";
        return [
            "# RADIUS Configuration",
            ":if ([:len [/radius find service=hotspot comment~\"Hotspot.*\"]] = 0) do={ /radius add service=hotspot address={$params['radius_server']} secret=\"{$params['radius_secret']}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"Hotspot RADIUS\" }",
            ":do { /ip hotspot profile set [find name=\"{$profile}\"] use-radius=yes } on-error={}",
            ":do { /ip hotspot user remove [find] } on-error={}",
            ""
        ];
    }

    private function generateFirewallRules(array $params): array
    {
        $iface = $params['bridge_name'] ?? $params['interface'];

        return [
            "# Firewall Rules",
            ":do { /ip firewall filter remove [find comment=\"Hotspot: Allow Established\"] } on-error={}",
            ":do { /ip firewall filter add chain=forward action=accept connection-state=established,related comment=\"Hotspot: Allow Established\" } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Hotspot: Drop Invalid\"] } on-error={}",
            ":do { /ip firewall filter add chain=forward action=drop connection-state=invalid comment=\"Hotspot: Drop Invalid\" } on-error={}",
            ":do { /ip firewall filter remove [find comment=\"Hotspot: Allow to WAN\"] } on-error={}",
            ":do { /ip firewall filter add chain=forward action=accept in-interface={$iface} out-interface=!{$iface} comment=\"Hotspot: Allow to WAN\" } on-error={}",
            ""
        ];
    }

    private function generateNatRules(array $params): array
    {
        $network = explode('/', $params['network_cidr'])[0];
        $cidr = explode('/', $params['network_cidr'])[1] ?? '24';
        $iface = $params['bridge_name'] ?? $params['interface'];

        return [
            "# NAT Rules",
            ":do { /ip firewall nat remove [find comment=\"Hotspot: Internet Access\"] } on-error={}",
            ":do { /ip firewall nat add chain=srcnat action=masquerade src-address={$network}/{$cidr} out-interface=!{$iface} comment=\"Hotspot: Internet Access\" } on-error={}",
            ":do { /ip firewall nat remove [find comment=\"Hotspot: HTTP Redirect\"] } on-error={}",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface={$iface} comment=\"Hotspot: HTTP Redirect\" } on-error={}",
            ":do { /ip firewall nat remove [find comment=\"Hotspot: HTTPS Redirect\"] } on-error={}",
            ":do { /ip firewall nat add chain=dstnat action=redirect to-ports=64875 protocol=tcp dst-port=443 in-interface={$iface} comment=\"Hotspot: HTTPS Redirect\" } on-error={}",
            ""
        ];
    }

    private function getSafeGatewayIp(string $networkCidr, ?string $gatewayIp): string
    {
        $parts = explode('/', $networkCidr, 2);
        $networkIp = $parts[0] ?? '';
        $cidr = (int) ($parts[1] ?? 24);
        $networkLong = ip2long($networkIp);
        if ($networkLong === false) return (string) $gatewayIp;
        if ($cidr < 0 || $cidr > 32) $cidr = 24;

        $mask = $cidr === 0 ? 0 : ((-1 << (32 - $cidr)) & 0xFFFFFFFF);
        $networkAddrLong = $networkLong & $mask;
        $broadcastLong = $networkAddrLong | (~$mask & 0xFFFFFFFF);
        $candidateLong = $gatewayIp ? ip2long($gatewayIp) : false;

        if ($candidateLong === false || ($candidateLong & $mask) !== $networkAddrLong || $candidateLong === $networkAddrLong || $candidateLong === $broadcastLong) {
            return long2ip($networkAddrLong + 1);
        }

        return $gatewayIp;
    }
}
