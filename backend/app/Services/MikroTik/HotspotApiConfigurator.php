<?php

declare(strict_types=1);

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;
use App\Support\SubnetHelper;

class HotspotApiConfigurator
{
    private MikroTikApiInterface $api;
    private string $serviceId;
    private array $config;
    private array $results = [];

    public function __construct(MikroTikApiInterface $api, string $serviceId, array $config)
    {
        $this->api = $api;
        $this->serviceId = $serviceId;
        $this->config = $config;
    }

    public function configure(): array
    {
        try {
            Log::info('Starting Hotspot API configuration', [
                'service_id' => $this->serviceId,
                'config_keys' => array_keys($this->config),
            ]);

            $this->cleanup();
            sleep(1);

            $this->createBridgeAndVlans();
            sleep(1);

            $this->ensureInterfaceLists();
            sleep(1);

            $this->setupNetwork();
            sleep(1);

            $this->setupHotspot();
            sleep(1);

            $this->setupRadius();
            sleep(1);

            $this->setupFirewall();
            sleep(1);

            $this->setupNat();
            sleep(1);

            $this->setConnectionTracking();

            Log::info('Hotspot API configuration completed', [
                'service_id' => $this->serviceId,
                'results' => $this->results,
            ]);

            return [
                'success' => true,
                'message' => 'Configuration applied via API',
                'results' => $this->results,
            ];
        } catch (\Exception $e) {
            Log::error('Hotspot API configuration failed', [
                'service_id' => $this->serviceId,
                'error' => $e->getMessage(),
                'phase' => $this->results['last_phase'] ?? 'unknown',
            ]);

            return [
                'success' => false,
                'message' => 'Configuration failed: ' . $e->getMessage(),
                'phase' => $this->results['last_phase'] ?? 'unknown',
            ];
        }
    }

    private function cleanup(): void
    {
        $this->results['last_phase'] = 'cleanup';

        $bridge = $this->config['bridge'] ?? null;
        $bridgePorts = $this->config['bridge_ports'] ?? $this->config['interfaces'] ?? [];
        $vlans = $this->config['vlans'] ?? [];

        $this->api->removeFirewallFilterByComment('hs-fw-' . $this->serviceId);
        $this->api->removeFirewallFilterByComment('hs-mgmt-' . $this->serviceId);
        $this->api->removeFirewallFilterByComment('GLOBAL-DEFAULT-DROP-');

        $this->api->removeNatByComment('hs-nat-' . $this->serviceId);
        $this->api->removeNatByComment('hs-redir80-' . $this->serviceId);
        $this->api->removeNatByComment('hs-redir443-' . $this->serviceId);

        $this->api->removeRadiusByComment('hs-radius-' . $this->serviceId);

        $this->removeByName('/ip/hotspot', 'name', $this->config['hotspot_server'] ?? null);
        $this->removeByName('/ip/hotspot/profile', 'name', $this->config['hotspot_profile'] ?? null);
        $this->removeByName('/ip/hotspot/user/profile', 'name', $this->config['hotspot_user_profile'] ?? null);
        $this->removeByName('/ip/dhcp-server', 'name', $this->config['dhcp_name'] ?? null);
        $this->removeByField('/ip/dhcp-server/network', 'comment', $this->config['dhcp_network_comment'] ?? null);
        $this->removeByName('/ip/pool', 'name', $this->config['pool_name'] ?? null);

        $accessInterface = $this->accessInterface();
        if ($accessInterface) {
            $this->removeByField('/ip/address', 'interface', $accessInterface);
        }

        foreach ($bridgePorts as $port) {
            $this->api->removeBridgePort($port);
        }

        foreach ($vlans as $vlan) {
            if (!empty($vlan['name'])) {
                $this->api->removeVlan($vlan['name']);
            }
        }

        if ($bridge) {
            $this->api->removeBridge($bridge);
        }

        $this->results['cleanup'] = 'success';
    }

    private function createBridgeAndVlans(): void
    {
        $this->results['last_phase'] = 'bridge';

        $bridge = $this->config['bridge'] ?? null;
        $bridgePorts = $this->config['bridge_ports'] ?? $this->config['interfaces'] ?? [];
        $vlans = $this->config['vlans'] ?? [];

        if ($bridge) {
            $this->api->createBridge($bridge, 'hs-br-' . $this->serviceId);
        }

        foreach ($vlans as $vlan) {
            if (!empty($vlan['name']) && !empty($vlan['vlan_id']) && !empty($vlan['interface'])) {
                $this->api->addVlan($vlan['name'], (int) $vlan['vlan_id'], $vlan['interface'], 'hs-vlan-' . $this->serviceId);
            }
        }

        if ($bridge) {
            foreach ($bridgePorts as $port) {
                $this->api->addBridgePort($bridge, $port, 'hs-port-' . $this->serviceId);
            }
        }

        $this->results['bridge'] = 'success';
    }

    private function ensureInterfaceLists(): void
    {
        $this->results['last_phase'] = 'interface_lists';

        $wanList = $this->config['wan_list'] ?? 'WAN';
        $wanInterface = $this->config['wan_interface'] ?? 'ether1';

        try {
            $this->api->executeCommand('/interface/list/add', ['name' => $wanList]);
        } catch (\Exception $e) {
            // list may already exist
        }

        try {
            $this->api->addInterfaceListMember($wanList, $wanInterface);
        } catch (\Exception $e) {
            // member may already exist
        }

        $this->results['interface_lists'] = 'success';
    }

    private function setupNetwork(): void
    {
        $this->results['last_phase'] = 'network';

        $accessInterface = $this->accessInterface();
        if (!$accessInterface) {
            throw new \Exception('Hotspot access interface missing');
        }

        $shortId = $this->shortId();
        $gatewayIp = $this->config['gateway_ip'] ?? null;
        $cidr = (int) ($this->config['cidr'] ?? 24);
        $networkCidr = $this->config['network_cidr'] ?? null;
        $rangeStart = $this->config['range_start'] ?? null;
        $rangeEnd = $this->config['range_end'] ?? null;
        $poolName = $this->config['pool_name'] ?? "hs-pool-{$shortId}";
        $dhcpName = $this->config['dhcp_name'] ?? "hs-dhcp-{$shortId}";
        $dhcpNetworkComment = $this->config['dhcp_network_comment'] ?? "hs-net-{$shortId}";
        $dnsServers = $this->config['dns_servers'] ?? '8.8.8.8,8.8.4.4';

        if (!$gatewayIp || !$rangeStart || !$rangeEnd) {
            throw new \Exception('Missing hotspot network parameters');
        }

        if (!$networkCidr) {
            $networkCidr = $this->calculateNetworkCidr($gatewayIp, $cidr);
        }

        $this->removeByField('/ip/address', 'interface', $accessInterface);
        $this->api->executeCommand('/ip/address/add', [
            'address' => "{$gatewayIp}/{$cidr}",
            'interface' => $accessInterface,
            'comment' => 'Hotspot Gateway',
        ]);

        $this->removeByName('/ip/pool', 'name', $poolName);
        $this->api->executeCommand('/ip/pool/add', [
            'name' => $poolName,
            'ranges' => "{$rangeStart}-{$rangeEnd}",
            'comment' => "hs-{$shortId}",
        ]);

        $this->removeByName('/ip/dhcp-server', 'name', $dhcpName);
        $this->api->executeCommand('/ip/dhcp-server/add', [
            'name' => $dhcpName,
            'interface' => $accessInterface,
            'address-pool' => $poolName,
            'lease-time' => '1h',
            'disabled' => 'no',
            'authoritative' => 'yes',
        ]);

        if ($networkCidr) {
            $this->removeByField('/ip/dhcp-server/network', 'comment', $dhcpNetworkComment);
            $this->api->executeCommand('/ip/dhcp-server/network/add', [
                'address' => $networkCidr,
                'gateway' => $gatewayIp,
                'dns-server' => $dnsServers,
                'comment' => $dhcpNetworkComment,
            ]);
        }

        $this->results['network'] = 'success';
    }

    private function setupHotspot(): void
    {
        $this->results['last_phase'] = 'hotspot';

        $shortId = $this->shortId();
        $accessInterface = $this->accessInterface();
        $gatewayIp = $this->config['gateway_ip'] ?? null;
        $poolName = $this->config['pool_name'] ?? "hs-pool-{$shortId}";
        $profile = $this->config['hotspot_profile'] ?? "hs-prof-{$shortId}";
        $server = $this->config['hotspot_server'] ?? "hs-srv-{$shortId}";
        $userProfile = $this->config['hotspot_user_profile'] ?? "hs-usr-{$shortId}";

        if (!$gatewayIp || !$accessInterface) {
            throw new \Exception('Hotspot profile parameters missing');
        }

        $this->removeByName('/ip/hotspot/profile', 'name', $profile);
        $this->api->executeCommand('/ip/hotspot/profile/add', [
            'name' => $profile,
            'hotspot-address' => $gatewayIp,
            'login-by' => 'http-chap,http-pap',
            'use-radius' => 'yes',
            'html-directory' => 'hotspot',
            'dns-name' => 'hotspot.local',
            'http-cookie-lifetime' => '1d',
        ]);

        $this->removeByName('/ip/hotspot', 'name', $server);
        $this->api->executeCommand('/ip/hotspot/add', [
            'name' => $server,
            'interface' => $accessInterface,
            'profile' => $profile,
            'address-pool' => $poolName,
            'addresses-per-mac' => 2,
            'idle-timeout' => '5m',
            'keepalive-timeout' => '2m',
            'disabled' => 'no',
        ]);

        $this->removeByName('/ip/hotspot/user/profile', 'name', $userProfile);
        $this->api->executeCommand('/ip/hotspot/user/profile/add', [
            'name' => $userProfile,
            'add-mac-cookie' => 'yes',
            'shared-users' => 1,
            'session-timeout' => '6h',
        ]);

        $portalHost = $this->config['portal_host'] ?? null;
        if ($portalHost) {
            $this->removeByField('/ip/hotspot/walled-garden', 'comment', 'WiFiCore Portal');
            $this->api->executeCommand('/ip/hotspot/walled-garden/add', [
                'dst-host' => $portalHost,
                'action' => 'allow',
                'comment' => 'WiFiCore Portal',
            ]);
        }

        $this->results['hotspot'] = 'success';
    }

    private function setupRadius(): void
    {
        $this->results['last_phase'] = 'radius';

        $radiusServers = $this->config['radius_servers'] ?? [];
        if (empty($radiusServers) && !empty($this->config['radius_server'])) {
            $radiusServers[] = [
                'address' => $this->config['radius_server'],
                'secret' => $this->config['radius_secret'] ?? '',
                'timeout' => 3,
            ];
        }

        foreach ($radiusServers as $server) {
            $this->api->addRadiusServer(
                'hotspot',
                $server['address'],
                $server['secret'],
                $server['timeout'] ?? 3,
                'hs-radius-' . $this->serviceId
            );
        }

        $this->results['radius'] = 'success';
    }

    private function setupFirewall(): void
    {
        $this->results['last_phase'] = 'firewall';

        $accessInterface = $this->accessInterface();
        $wanList = $this->config['wan_list'] ?? 'WAN';
        $mgmtSubnet = SubnetHelper::normalize($this->config['mgmt_subnet'] ?? '10.0.0.0/8');
        $mgmtPorts = $this->config['mgmt_ports'] ?? '22,8291,8728,8729';
        $radiusServer = $this->config['radius_server'] ?? ($this->config['radius_servers'][0]['address'] ?? null);

        try {
            $this->api->addInterfaceListMember($wanList, $this->config['wan_interface'] ?? 'ether1');
        } catch (\Exception $e) {
            // member may already exist
        }

        // INPUT chain — order matters: ACCEPTs before DROP for same traffic class.
        // 1. Accept established/related (return traffic, fast path)
        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'hs-mgmt-' . $this->serviceId . '-est',
        ]);

        // 2. Accept mgmt ports from allowed subnet
        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'protocol' => 'tcp',
            'dst-port' => $mgmtPorts,
            'src-address' => $mgmtSubnet,
            'action' => 'accept',
            'comment' => 'hs-mgmt-' . $this->serviceId . '-allow',
        ]);

        // 3. Accept SNMP from RADIUS server
        if ($radiusServer) {
            $this->api->addFirewallFilterRule([
                'chain' => 'input',
                'protocol' => 'udp',
                'dst-port' => '161',
                'src-address' => $radiusServer,
                'action' => 'accept',
                'comment' => 'hs-mgmt-' . $this->serviceId . '-snmp',
            ]);
        }

        // 4. Drop mgmt ports from all other sources (after ACCEPTs above)
        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'protocol' => 'tcp',
            'dst-port' => $mgmtPorts,
            'action' => 'drop',
            'comment' => 'hs-mgmt-' . $this->serviceId . '-drop',
        ]);

        // FORWARD chain — correct order: established/related → invalid drop → auth accept → unauth drop
        // 1. Accept established/related (return traffic for active sessions)
        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface' => $accessInterface,
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'hs-fw-' . $this->serviceId . '-EST',
        ]);

        // 2. Drop invalid packets
        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface' => $accessInterface,
            'connection-state' => 'invalid',
            'action' => 'drop',
            'comment' => 'hs-fw-' . $this->serviceId . '-INV',
        ]);

        // 3. Accept authenticated hotspot clients to WAN
        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface' => $accessInterface,
            'hotspot' => 'auth',
            'out-interface-list' => $wanList,
            'action' => 'accept',
            'comment' => 'hs-fw-' . $this->serviceId . '-AUTH-INET',
        ]);

        // 4. Accept WAN return traffic back to hotspot clients
        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $wanList,
            'out-interface' => $accessInterface,
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'hs-fw-' . $this->serviceId . '-WAN-RET',
        ]);

        // 5. Drop unauthenticated clients (after all ACCEPTs)
        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface' => $accessInterface,
            'action' => 'drop',
            'comment' => 'hs-fw-' . $this->serviceId . '-DROP-UNAUTH',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'action' => 'drop',
            'comment' => 'GLOBAL-DEFAULT-DROP-IN',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'action' => 'drop',
            'comment' => 'GLOBAL-DEFAULT-DROP-FWD',
        ]);

        $this->results['firewall'] = 'success';
    }

    private function setupNat(): void
    {
        $this->results['last_phase'] = 'nat';

        $wanList = $this->config['wan_list'] ?? 'WAN';
        $accessInterface = $this->accessInterface();
        $networkCidr = $this->config['network_cidr'] ?? null;

        if (!$networkCidr) {
            $gatewayIp = $this->config['gateway_ip'] ?? null;
            $cidr = (int) ($this->config['cidr'] ?? 24);
            if ($gatewayIp) {
                $networkCidr = $this->calculateNetworkCidr($gatewayIp, $cidr);
            }
        }

        if ($networkCidr) {
            $this->api->addNatRule([
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'src-address' => $networkCidr,
                'out-interface-list' => $wanList,
                'comment' => 'hs-nat-' . $this->serviceId,
            ]);
        }

        $this->api->addNatRule([
            'chain' => 'dstnat',
            'action' => 'redirect',
            'to-ports' => 64872,
            'protocol' => 'tcp',
            'dst-port' => 80,
            'in-interface' => $accessInterface,
            'comment' => 'hs-redir80-' . $this->serviceId,
        ]);

        $this->api->addNatRule([
            'chain' => 'dstnat',
            'action' => 'redirect',
            'to-ports' => 64875,
            'protocol' => 'tcp',
            'dst-port' => 443,
            'in-interface' => $accessInterface,
            'comment' => 'hs-redir443-' . $this->serviceId,
        ]);

        $this->results['nat'] = 'success';
    }

    private function setConnectionTracking(): void
    {
        $this->results['last_phase'] = 'connection_tracking';

        $this->api->setConnectionTracking(
            $this->config['tcp_timeout'] ?? 3600,
            $this->config['udp_timeout'] ?? 30
        );

        $this->results['connection_tracking'] = 'success';
    }

    public function verify(): array
    {
        $shortId = $this->shortId();
        $serverName = $this->config['hotspot_server'] ?? "hs-srv-{$shortId}";

        $hotspots = $this->api->fetch('/ip/hotspot');
        foreach ($hotspots as $hotspot) {
            if (($hotspot['name'] ?? null) === $serverName) {
                return [
                    'valid' => true,
                    'message' => 'Hotspot server configured successfully',
                ];
            }
        }

        return [
            'valid' => false,
            'error' => 'Hotspot server not found after configuration',
        ];
    }

    private function shortId(): string
    {
        return substr(str_replace('-', '', $this->serviceId), 0, 8);
    }

    private function accessInterface(): ?string
    {
        return $this->config['bridge'] ?? $this->config['access_interface'] ?? $this->config['interface'] ?? null;
    }

    private function removeByName(string $resource, string $field, ?string $name): void
    {
        if (!$name) {
            return;
        }

        try {
            $items = $this->api->fetch($resource);
            foreach ($items as $item) {
                if (($item[$field] ?? null) === $name) {
                    $this->api->executeCommand($resource . '/remove', ['.id' => $item['.id']]);
                }
            }
        } catch (\Exception $e) {
            // ignore cleanup failures
        }
    }

    private function removeByField(string $resource, string $field, ?string $value): void
    {
        if (!$value) {
            return;
        }

        try {
            $items = $this->api->fetch($resource);
            foreach ($items as $item) {
                if (($item[$field] ?? null) === $value) {
                    $this->api->executeCommand($resource . '/remove', ['.id' => $item['.id']]);
                }
            }
        } catch (\Exception $e) {
            // ignore cleanup failures
        }
    }

    private function calculateNetworkCidr(string $gatewayIp, int $cidr): string
    {
        $parts = explode('.', $gatewayIp);
        if (count($parts) !== 4) {
            return $gatewayIp . '/' . $cidr;
        }
        return sprintf('%s.%s.%s.0/%d', $parts[0], $parts[1], $parts[2], $cidr);
    }
}
