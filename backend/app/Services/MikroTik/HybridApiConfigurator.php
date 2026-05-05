<?php

declare(strict_types=1);

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

class HybridApiConfigurator
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
            Log::info('Starting Hybrid API configuration', [
                'service_id' => $this->serviceId,
                'config_keys' => array_keys($this->config),
            ]);

            $this->cleanup();
            sleep(1);

            $this->createBridgeAndVlans();
            sleep(1);

            $this->ensureInterfaceLists();
            sleep(1);

            $this->setupWanInterface();
            sleep(1);

            $this->setupHotspot();
            sleep(1);

            $this->setupPppoe();
            sleep(1);

            $this->setupRadius();
            sleep(1);

            $this->setupFirewall();
            sleep(1);

            $this->setupNat();
            sleep(1);

            $this->setConnectionTracking();

            Log::info('Hybrid API configuration completed', [
                'service_id' => $this->serviceId,
                'results' => $this->results,
            ]);

            return [
                'success' => true,
                'message' => 'Configuration applied via API',
                'results' => $this->results,
            ];
        } catch (\Exception $e) {
            Log::error('Hybrid API configuration failed', [
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
        $hotspotInterface = $this->hotspotInterface();

        $this->api->removeFirewallFilterByComment('hyb-fw-' . $this->serviceId);
        $this->api->removeFirewallFilterByComment('hyb-mgmt-' . $this->serviceId);
        $this->api->removeFirewallFilterByComment('GLOBAL-DEFAULT-DROP-');

        $this->api->removeNatByComment('hyb-nat-' . $this->serviceId . '-hs');
        $this->api->removeNatByComment('hyb-nat-' . $this->serviceId . '-pp');
        $this->api->removeNatByComment('hyb-redir80-' . $this->serviceId);
        $this->api->removeNatByComment('hyb-redir443-' . $this->serviceId);

        $this->api->removeRadiusByComment('hyb-hs-rad-' . $this->serviceId);
        $this->api->removeRadiusByComment('hyb-pp-rad-' . $this->serviceId);

        $this->removeByName('/ip/hotspot', 'name', $this->config['hotspot_server'] ?? null);
        $this->removeByName('/ip/hotspot/profile', 'name', $this->config['hotspot_profile'] ?? null);
        $this->removeByName('/ip/dhcp-server', 'name', $this->config['hotspot_dhcp_name'] ?? $this->config['dhcp_name'] ?? null);
        $this->removeByField('/ip/dhcp-server/network', 'comment', $this->config['hotspot_dhcp_network_comment'] ?? null);
        $this->removeByName('/ip/pool', 'name', $this->config['hotspot_pool'] ?? null);
        $this->removeByName('/ip/pool', 'name', $this->config['pppoe_pool'] ?? null);
        $this->removeByName('/ppp/profile', 'name', $this->config['pppoe_profile'] ?? null);

        if (!empty($this->config['service_name'])) {
            $this->api->removePppoeServer($this->config['service_name']);
        }

        if ($hotspotInterface) {
            $this->removeByField('/ip/address', 'interface', $hotspotInterface);
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
            $this->api->createBridge($bridge, 'hyb-br-' . $this->serviceId);
        }

        foreach ($vlans as $vlan) {
            if (!empty($vlan['name']) && !empty($vlan['vlan_id']) && !empty($vlan['interface'])) {
                $this->api->addVlan($vlan['name'], (int) $vlan['vlan_id'], $vlan['interface'], 'hyb-vlan-' . $this->serviceId);
            }
        }

        if ($bridge) {
            foreach ($bridgePorts as $port) {
                $this->api->addBridgePort($bridge, $port, 'hyb-port-' . $this->serviceId);
            }
        }

        $this->results['bridge'] = 'success';
    }

    private function ensureInterfaceLists(): void
    {
        $this->results['last_phase'] = 'interface_lists';

        $wanList = $this->config['wan_list'] ?? 'WAN';
        $wanInterface = $this->config['wan_interface'] ?? 'ether1';
        $pppoeActiveList = $this->config['pppoe_active_list']
            ?? $this->config['pal_list']
            ?? 'PPPOE-ACTIVE-HYB-' . $this->shortId();

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

        try {
            $this->api->executeCommand('/interface/list/add', ['name' => $pppoeActiveList]);
        } catch (\Exception $e) {
            // list may already exist
        }

        $this->results['interface_lists'] = 'success';
    }

    private function setupWanInterface(): void
    {
        $this->results['last_phase'] = 'wan_interface';

        $wanInterface = $this->config['wan_interface']
            ?? $this->config['wan_dhcp_client_interface']
            ?? 'ether1';

        $runningCheckInterfaces = $this->config['disable_running_check_interfaces'] ?? [];
        if (!empty($runningCheckInterfaces)) {
            foreach ($runningCheckInterfaces as $interface) {
                $this->setEthernetRunningCheck($interface, false);
            }
        } elseif (array_key_exists('wan_disable_running_check', $this->config) && $wanInterface) {
            $this->setEthernetRunningCheck(
                $wanInterface,
                $this->toBoolean($this->config['wan_disable_running_check'], false)
            );
        }

        if (!empty($this->config['wan_dhcp_client']) && $wanInterface) {
            // Check if DHCP client already exists for this interface
            $existingClients = $this->api->executeCommand('/ip/dhcp-client/print', [
                '?interface' => $wanInterface,
            ]);
            
            if (!empty($existingClients)) {
                // Enable existing DHCP client if disabled
                foreach ($existingClients as $client) {
                    if (isset($client['.id']) && ($client['disabled'] ?? 'false') === 'true') {
                        $this->api->executeCommand('/ip/dhcp-client/set', [
                            '.id' => $client['.id'],
                            'disabled' => 'no',
                        ]);
                    }
                }
            } else {
                // No DHCP client exists - add new one
                $this->api->executeCommand('/ip/dhcp-client/add', [
                    'interface' => $wanInterface,
                    'disabled' => 'no',
                ]);
            }
        }

        $this->results['wan_interface'] = 'success';
    }

    private function setupHotspot(): void
    {
        $this->results['last_phase'] = 'hotspot';

        $shortId = $this->shortId();
        $interface = $this->hotspotInterface();
        $gatewayIp = $this->config['hotspot_gateway_ip'] ?? $this->config['gateway_ip'] ?? null;
        $cidr = (int) ($this->config['hotspot_cidr'] ?? 24);
        $networkCidr = $this->config['hotspot_network_cidr'] ?? $this->config['network_cidr'] ?? null;
        $rangeStart = $this->config['hotspot_range_start'] ?? $this->config['range_start'] ?? null;
        $rangeEnd = $this->config['hotspot_range_end'] ?? $this->config['range_end'] ?? null;
        $poolName = $this->config['hotspot_pool'] ?? "hyb-hs-pool-{$shortId}";
        $dhcpName = $this->config['hotspot_dhcp_name'] ?? "hyb-hs-dhcp-{$shortId}";
        $networkComment = $this->config['hotspot_dhcp_network_comment'] ?? "hyb-hs-net-{$shortId}";
        $profile = $this->config['hotspot_profile'] ?? "hyb-hs-prof-{$shortId}";
        $server = $this->config['hotspot_server'] ?? "hyb-hs-srv-{$shortId}";
        $dnsServers = $this->config['hotspot_dns_servers'] ?? $this->config['dns_servers'] ?? null;

        if (!$interface || !$gatewayIp || !$rangeStart || !$rangeEnd) {
            throw new \Exception('Missing hotspot configuration parameters');
        }

        if (!$networkCidr) {
            $networkCidr = $this->calculateNetworkCidr($gatewayIp, $cidr);
        }

        $this->removeByField('/ip/address', 'interface', $interface);
        $this->api->executeCommand('/ip/address/add', [
            'address' => "{$gatewayIp}/{$cidr}",
            'interface' => $interface,
            'comment' => 'hyb-hs-gw-' . $shortId,
        ]);

        $this->removeByName('/ip/pool', 'name', $poolName);
        $this->api->executeCommand('/ip/pool/add', [
            'name' => $poolName,
            'ranges' => "{$rangeStart}-{$rangeEnd}",
            'comment' => 'hyb-hs-' . $shortId,
        ]);

        $this->removeByName('/ip/dhcp-server', 'name', $dhcpName);
        $this->api->executeCommand('/ip/dhcp-server/add', [
            'name' => $dhcpName,
            'interface' => $interface,
            'address-pool' => $poolName,
            'lease-time' => '1h',
            'disabled' => 'no',
        ]);

        if ($networkCidr) {
            $this->removeByField('/ip/dhcp-server/network', 'comment', $networkComment);
            $networkParams = [
                'address' => $networkCidr,
                'gateway' => $gatewayIp,
                'comment' => $networkComment,
            ];
            if ($dnsServers) {
                $networkParams['dns-server'] = $dnsServers;
            }
            $this->api->executeCommand('/ip/dhcp-server/network/add', $networkParams);
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
            'interface' => $interface,
            'profile' => $profile,
            'address-pool' => $poolName,
            'addresses-per-mac' => 2,
            'idle-timeout' => '5m',
            'keepalive-timeout' => '2m',
            'disabled' => 'no',
        ]);

        $portalHost = $this->config['portal_host'] ?? null;
        if ($portalHost) {
            $this->removeByField('/ip/hotspot/walled-garden', 'comment', 'hyb-wg-' . $shortId);
            $this->api->executeCommand('/ip/hotspot/walled-garden/add', [
                'dst-host' => $portalHost,
                'action' => 'allow',
                'comment' => 'hyb-wg-' . $shortId,
            ]);
        }

        $this->results['hotspot'] = 'success';
    }

    private function setupPppoe(): void
    {
        $this->results['last_phase'] = 'pppoe';

        $shortId = $this->shortId();
        $interface = $this->pppoeInterface();
        $poolName = $this->config['pppoe_pool'] ?? "hyb-pp-pool-{$shortId}";
        $profile = $this->config['pppoe_profile'] ?? "hyb-pp-prof-{$shortId}";
        $serviceName = $this->config['service_name'] ?? "hyb-pp-svc-{$shortId}";
        $palList = $this->config['pppoe_active_list']
            ?? $this->config['pal_list']
            ?? 'PPPOE-ACTIVE-HYB-' . $shortId;
        $gatewayIp = $this->config['pppoe_gateway_ip'] ?? $this->config['pppoe_local_address'] ?? null;
        $remoteAddress = $this->config['pppoe_remote_address'] ?? null;
        $rangeStart = $this->config['pppoe_range_start'] ?? null;
        $rangeEnd = $this->config['pppoe_range_end'] ?? null;
        $dnsServers = $this->config['pppoe_dns_servers'] ?? $this->config['dns_servers'] ?? null;

        if (!$interface || !$gatewayIp || !$rangeStart || !$rangeEnd) {
            throw new \Exception('Missing PPPoE configuration parameters');
        }

        if (!$remoteAddress) {
            $remoteAddress = $poolName;
        }

        $this->removeByName('/ip/pool', 'name', $poolName);
        $this->api->executeCommand('/ip/pool/add', [
            'name' => $poolName,
            'ranges' => "{$rangeStart}-{$rangeEnd}",
            'comment' => 'hyb-pp-' . $shortId,
        ]);

        $this->removeByName('/ppp/profile', 'name', $profile);
        $profileParams = [
            'name' => $profile,
            'local-address' => $gatewayIp,
            'remote-address' => $remoteAddress,
            'interface-list' => $palList,
        ];
        if (array_key_exists('pppoe_only_one', $this->config) && $this->config['pppoe_only_one'] !== null) {
            $profileParams['only-one'] = $this->toBoolean($this->config['pppoe_only_one'], true) ? 'yes' : 'no';
        }
        if (array_key_exists('pppoe_change_tcp_mss', $this->config) && $this->config['pppoe_change_tcp_mss'] !== null) {
            $profileParams['change-tcp-mss'] = $this->toBoolean($this->config['pppoe_change_tcp_mss'], true) ? 'yes' : 'no';
        }
        if (array_key_exists('pppoe_use_compression', $this->config) && $this->config['pppoe_use_compression'] !== null) {
            $profileParams['use-compression'] = $this->toBoolean($this->config['pppoe_use_compression'], false) ? 'yes' : 'no';
        }
        if ($dnsServers) {
            $profileParams['dns-server'] = $dnsServers;
        }
        $this->api->executeCommand('/ppp/profile/add', $profileParams);

        $this->api->createPppoeServer(
            $serviceName,
            $interface,
            $profile,
            $this->toInteger($this->config['pppoe_max_mtu'] ?? $this->config['max_mtu'] ?? 1480, 1480),
            $this->toInteger($this->config['pppoe_max_mru'] ?? $this->config['max_mru'] ?? 1480, 1480),
            $this->toBoolean($this->config['pppoe_one_session_per_host'] ?? $this->config['one_session_per_host'] ?? true, true),
            $this->toInteger($this->config['pppoe_keepalive_timeout'] ?? $this->config['keepalive_timeout'] ?? 10, 10),
            (string) ($this->config['pppoe_authentication'] ?? 'pap,chap,mschap2')
        );

        $this->results['pppoe'] = 'success';
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
                'hyb-hs-rad-' . $this->serviceId
            );

            $this->api->addRadiusServer(
                'ppp',
                $server['address'],
                $server['secret'],
                $server['timeout'] ?? 3,
                'hyb-pp-rad-' . $this->serviceId
            );
        }

        $this->api->executeCommand('/ppp/aaa/set', [
            'use-radius' => 'yes',
            'accounting' => 'yes',
            'interim-update' => '5m',
        ]);

        $this->results['radius'] = 'success';
    }

    private function setupFirewall(): void
    {
        $this->results['last_phase'] = 'firewall';

        $shortId = $this->shortId();
        $bridge = $this->config['bridge'] ?? null;
        $hotspotInterface = $this->hotspotInterface();
        $pppoeInterface = $this->pppoeInterface();
        $wanList = $this->config['wan_list'] ?? 'WAN';
        $palList = $this->config['pppoe_active_list'] ?? $this->config['pal_list'] ?? 'PPPOE-ACTIVE-HYB-' . $shortId;
        $mgmtSubnet = $this->config['mgmt_subnet'] ?? '10.0.0.0/8';
        $mgmtPorts = $this->config['mgmt_ports'] ?? '22,8291,8728,8729';
        $radiusServer = $this->config['radius_server'] ?? ($this->config['radius_servers'][0]['address'] ?? null);

        $hotspotDropInterface = $bridge ?: $hotspotInterface;
        $pppoeDropInterface = $bridge ?: $pppoeInterface;

        // INPUT chain — ACCEPTs must precede DROP for same traffic class.
        // 1. Accept established/related (return traffic, fast path)
        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'hyb-mgmt-' . $this->serviceId . '-est',
        ]);

        // 2. Accept mgmt ports from allowed management subnet
        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'protocol' => 'tcp',
            'dst-port' => $mgmtPorts,
            'src-address' => $mgmtSubnet,
            'action' => 'accept',
            'comment' => 'hyb-mgmt-' . $this->serviceId . '-allow',
        ]);

        // 3. Accept SNMP from RADIUS server
        if ($radiusServer) {
            $this->api->addFirewallFilterRule([
                'chain' => 'input',
                'protocol' => 'udp',
                'dst-port' => '161',
                'src-address' => $radiusServer,
                'action' => 'accept',
                'comment' => 'hyb-mgmt-' . $this->serviceId . '-snmp',
            ]);
        }

        // 4. Drop mgmt ports from all other sources (after ACCEPTs above)
        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'protocol' => 'tcp',
            'dst-port' => $mgmtPorts,
            'action' => 'drop',
            'comment' => 'hyb-mgmt-' . $this->serviceId . '-drop',
        ]);

        // FORWARD chain — hotspot side: EST/RELATED → INVALID-DROP → AUTH-ACCEPT → WAN-RETURN → DROP-UNAUTH
        if ($hotspotDropInterface) {
            $this->api->addFirewallFilterRule([
                'chain' => 'forward',
                'in-interface' => $hotspotDropInterface,
                'connection-state' => 'established,related',
                'action' => 'accept',
                'comment' => 'hyb-fw-' . $this->serviceId . '-hs-EST',
            ]);

            $this->api->addFirewallFilterRule([
                'chain' => 'forward',
                'in-interface' => $hotspotDropInterface,
                'connection-state' => 'invalid',
                'action' => 'drop',
                'comment' => 'hyb-fw-' . $this->serviceId . '-hs-INV',
            ]);

            $this->api->addFirewallFilterRule([
                'chain' => 'forward',
                'in-interface' => $hotspotDropInterface,
                'hotspot' => 'auth',
                'out-interface-list' => $wanList,
                'action' => 'accept',
                'comment' => 'hyb-fw-' . $this->serviceId . '-hs-AUTH-INET',
            ]);

            $this->api->addFirewallFilterRule([
                'chain' => 'forward',
                'in-interface-list' => $wanList,
                'out-interface' => $hotspotDropInterface,
                'connection-state' => 'established,related',
                'action' => 'accept',
                'comment' => 'hyb-fw-' . $this->serviceId . '-hs-WAN',
            ]);

            $this->api->addFirewallFilterRule([
                'chain' => 'forward',
                'in-interface' => $hotspotDropInterface,
                'action' => 'drop',
                'comment' => 'hyb-fw-' . $this->serviceId . '-hs-DROP-UNAUTH',
            ]);
        }

        // FORWARD chain — PPPoE side: EST/RELATED → INVALID-DROP → INET-ACCEPT → WAN-RETURN → DROP-UNAUTH
        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $palList,
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'hyb-fw-' . $this->serviceId . '-pp-EST',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $palList,
            'connection-state' => 'invalid',
            'action' => 'drop',
            'comment' => 'hyb-fw-' . $this->serviceId . '-pp-INV',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $palList,
            'out-interface-list' => $wanList,
            'action' => 'accept',
            'comment' => 'hyb-fw-' . $this->serviceId . '-pp-AUTH-INET',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $wanList,
            'out-interface-list' => $palList,
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'hyb-fw-' . $this->serviceId . '-pp-WAN',
        ]);

        if ($pppoeDropInterface) {
            $this->api->addFirewallFilterRule([
                'chain' => 'forward',
                'in-interface' => $pppoeDropInterface,
                'action' => 'drop',
                'comment' => 'hyb-fw-' . $this->serviceId . '-pp-DROP-UNAUTH',
            ]);
        }

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

        $shortId = $this->shortId();
        $wanList = $this->config['wan_list'] ?? 'WAN';
        $palList = $this->config['pppoe_active_list'] ?? $this->config['pal_list'] ?? 'PPPOE-ACTIVE-HYB-' . $shortId;
        $hotspotInterface = $this->hotspotInterface();
        $hotspotNetworkCidr = $this->config['hotspot_network_cidr'] ?? $this->config['network_cidr'] ?? null;

        if (!$hotspotNetworkCidr) {
            $gatewayIp = $this->config['hotspot_gateway_ip'] ?? $this->config['gateway_ip'] ?? null;
            $cidr = (int) ($this->config['hotspot_cidr'] ?? 24);
            if ($gatewayIp) {
                $hotspotNetworkCidr = $this->calculateNetworkCidr($gatewayIp, $cidr);
            }
        }

        if ($hotspotNetworkCidr) {
            $this->api->addNatRule([
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'src-address' => $hotspotNetworkCidr,
                'out-interface-list' => $wanList,
                'comment' => 'hyb-nat-' . $this->serviceId . '-hs',
            ]);
        }

        $this->api->addNatRule([
            'chain' => 'srcnat',
            'action' => 'masquerade',
            'in-interface-list' => $palList,
            'out-interface-list' => $wanList,
            'comment' => 'hyb-nat-' . $this->serviceId . '-pp',
        ]);

        if ($hotspotInterface) {
            $this->api->addNatRule([
                'chain' => 'dstnat',
                'action' => 'redirect',
                'to-ports' => 64872,
                'protocol' => 'tcp',
                'dst-port' => 80,
                'in-interface' => $hotspotInterface,
                'comment' => 'hyb-redir80-' . $this->serviceId,
            ]);

            $this->api->addNatRule([
                'chain' => 'dstnat',
                'action' => 'redirect',
                'to-ports' => 64875,
                'protocol' => 'tcp',
                'dst-port' => 443,
                'in-interface' => $hotspotInterface,
                'comment' => 'hyb-redir443-' . $this->serviceId,
            ]);
        }

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
        $hotspotName = $this->config['hotspot_server'] ?? "hyb-hs-srv-{$shortId}";
        $pppoeService = $this->config['service_name'] ?? "hyb-pp-svc-{$shortId}";

        $hotspotOk = false;
        $pppoeOk = false;

        $hotspots = $this->api->fetch('/ip/hotspot');
        foreach ($hotspots as $hotspot) {
            if (($hotspot['name'] ?? null) === $hotspotName) {
                $hotspotOk = true;
                break;
            }
        }

        $pppoeServers = $this->api->fetch('/interface/pppoe-server/server');
        foreach ($pppoeServers as $server) {
            if (($server['service-name'] ?? null) === $pppoeService) {
                $pppoeOk = true;
                break;
            }
        }

        if ($hotspotOk && $pppoeOk) {
            return [
                'valid' => true,
                'message' => 'Hybrid services configured successfully',
            ];
        }

        return [
            'valid' => false,
            'error' => 'Hybrid services not found after configuration',
        ];
    }

    private function shortId(): string
    {
        return substr(str_replace('-', '', $this->serviceId), 0, 8);
    }

    private function hotspotInterface(): ?string
    {
        return $this->config['hotspot_interface'] ?? $this->config['bridge'] ?? null;
    }

    private function pppoeInterface(): ?string
    {
        return $this->config['pppoe_interface'] ?? $this->config['bridge'] ?? null;
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

    private function setEthernetRunningCheck(string $interface, bool $disabled): void
    {
        try {
            $items = $this->api->fetch('/interface/ethernet');
            foreach ($items as $item) {
                $name = $item['default-name'] ?? $item['name'] ?? null;
                if ($name === $interface) {
                    $this->api->executeCommand('/interface/ethernet/set', [
                        '.id' => $item['.id'],
                        'disable-running-check' => $disabled ? 'yes' : 'no',
                    ]);
                    break;
                }
            }
        } catch (\Exception $e) {
            // ignore running-check failures
        }
    }

    private function toInteger($value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^(\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return $default;
    }

    private function toBoolean($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower($value);
            if (in_array($normalized, ['yes', 'true', '1', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['no', 'false', '0', 'off'], true)) {
                return false;
            }
        }

        return $default;
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
