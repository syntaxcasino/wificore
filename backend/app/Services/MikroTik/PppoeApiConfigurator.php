<?php

declare(strict_types=1);

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * PPPoE Configuration Builder using REST API
 * 
 * This builder uses direct API calls instead of scripts for low-end devices.
 * Each configuration step is executed individually with verification.
 */
class PppoeApiConfigurator
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

    /**
     * Execute full PPPoE configuration via API
     */
    public function configure(): array
    {
        try {
            Log::info('Starting PPPoE API configuration', [
                'service_id' => $this->serviceId,
                'config_keys' => array_keys($this->config),
            ]);

            // Phase 1: Cleanup old configuration
            $this->cleanup();
            sleep(1); // Brief pause for CPU

            // Phase 2: Create bridge and add ports
            $this->createBridge();
            sleep(1);

            // Phase 3: Setup interface lists
            $this->setupInterfaceLists();
            sleep(1);

            // Phase 4: Setup WAN basics
            $this->setupWanInterface();
            sleep(1);

            // Phase 5: Setup PPP profile/pool
            $this->setupPppProfile();
            sleep(1);

            // Phase 6: Create PPPoE server
            $this->createPppoeServer();
            sleep(1);

            // Phase 7: Setup RADIUS
            $this->setupRadius();
            sleep(1);

            // Phase 8: Setup firewall
            $this->setupFirewall();
            sleep(1);

            // Phase 9: Setup NAT
            $this->setupNat();
            sleep(1);

            // Phase 10: Connection tracking
            $this->setConnectionTracking();

            Log::info('PPPoE API configuration completed', [
                'service_id' => $this->serviceId,
                'results' => $this->results,
            ]);

            return [
                'success' => true,
                'message' => 'Configuration applied via API',
                'results' => $this->results,
            ];
        } catch (\Exception $e) {
            Log::error('PPPoE API configuration failed', [
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

    /**
     * Cleanup existing configuration
     */
    private function cleanup(): void
    {
        $this->results['last_phase'] = 'cleanup';

        $shortId = $this->shortId();
        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $shortId;
        $serviceName = $this->config['service_name'] ?? 'pppoe-svc-' . $shortId;
        $poolName = $this->config['pppoe_pool'] ?? $this->config['pool'] ?? "pppoe-pool-{$shortId}";
        $profile = $this->config['pppoe_profile'] ?? $this->config['profile'] ?? "pppoe-prof-{$shortId}";
        if ($profile === 'pppoe-default') {
            $profile = "pppoe-prof-{$shortId}";
        }

        // Remove firewall rules
        $this->api->removeFirewallFilterByComment('PPPoE-' . $this->serviceId);
        $this->api->removeNatByComment('PPPoE-' . $this->serviceId);

        // Remove RADIUS
        $this->api->removeRadiusByComment('PPPoE-' . $this->serviceId);

        // Remove PPPoE server
        $this->api->removePppoeServer($serviceName);

        // Remove bridge ports
        foreach ($this->config['interfaces'] ?? [] as $interface) {
            $this->api->removeBridgePort($interface);
            if (!empty($this->config['vlan_required'])) {
                $vlanName = "vlan{$this->config['vlan_id']}-$interface";
                $this->api->removeVlan($vlanName);
            }
        }

        // Remove bridge
        $this->api->removeBridge($bridge);

        $this->removeByName('/ip/pool', 'name', $poolName);

        if ($profile && !str_starts_with(strtolower($profile), 'default')) {
            $this->removeByName('/ppp/profile', 'name', $profile);
        }

        $this->results['cleanup'] = 'success';
    }

    /**
     * Create bridge and add ports
     */
    private function createBridge(): void
    {
        $this->results['last_phase'] = 'bridge';

        $shortId = $this->shortId();
        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $shortId;

        // Create bridge
        $this->api->createBridge($bridge, 'PPPoE-' . $this->serviceId);

        // Add VLAN interfaces and bridge ports
        foreach ($this->config['interfaces'] ?? [] as $interface) {
            if (!empty($this->config['vlan_required']) && !empty($this->config['vlan_id'])) {
                // Create VLAN first
                $vlanName = "vlan{$this->config['vlan_id']}-$interface";
                $this->api->addVlan($vlanName, $this->config['vlan_id'], $interface, 'PPPoE-' . $this->serviceId);
                
                // Add VLAN to bridge
                $this->api->addBridgePort($bridge, $vlanName, 'PPPoE-' . $this->serviceId);
            } else {
                // Add physical interface directly to bridge
                $this->api->addBridgePort($bridge, $interface, 'PPPoE-' . $this->serviceId);
            }
        }

        $this->results['bridge'] = 'success';
    }

    /**
     * Setup interface lists
     */
    private function setupInterfaceLists(): void
    {
        $this->results['last_phase'] = 'interface_lists';

        $shortId = $this->shortId();
        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $shortId;
        $wanList = $this->config['wan_list'] ?? 'WAN';
        $palList = $this->config['pal_list'] ?? 'PA-' . $shortId;
        $pppoeList = $this->config['pppoe_list'] ?? 'PL-' . $shortId;

        try {
            $this->api->executeCommand('/interface/list/add', ['name' => $wanList]);
        } catch (\Exception $e) {
            // list may already exist
        }

        try {
            $this->api->executeCommand('/interface/list/add', ['name' => $palList]);
        } catch (\Exception $e) {
            // list may already exist
        }

        if ($pppoeList) {
            try {
                $this->api->executeCommand('/interface/list/add', ['name' => $pppoeList]);
            } catch (\Exception $e) {
                // list may already exist
            }
        }

        if ($pppoeList) {
            $this->api->addInterfaceListMember($pppoeList, $bridge);
        }

        try {
            $this->api->addInterfaceListMember($wanList, $this->config['wan_interface'] ?? 'ether1');
        } catch (\Exception $e) {
            // WAN may already be configured, ignore
        }

        $this->results['interface_lists'] = 'success';
    }

    /**
     * Setup WAN interface behaviors (DHCP client, running-check)
     */
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
            $this->removeByField('/ip/dhcp-client', 'interface', $wanInterface);
            $this->api->executeCommand('/ip/dhcp-client/add', [
                'interface' => $wanInterface,
                'disabled' => 'no',
            ]);
        }

        $this->results['wan_interface'] = 'success';
    }

    /**
     * Setup PPP profile + IP pool
     */
    private function setupPppProfile(): void
    {
        $this->results['last_phase'] = 'ppp_profile';

        $shortId = $this->shortId();
        $poolName = $this->config['pppoe_pool'] ?? $this->config['pool'] ?? "pppoe-pool-{$shortId}";
        $rangeStart = $this->config['pppoe_range_start'] ?? $this->config['range_start'] ?? null;
        $rangeEnd = $this->config['pppoe_range_end'] ?? $this->config['range_end'] ?? null;

        if ($poolName && $rangeStart && $rangeEnd) {
            $this->removeByName('/ip/pool', 'name', $poolName);
            $this->api->executeCommand('/ip/pool/add', [
                'name' => $poolName,
                'ranges' => "{$rangeStart}-{$rangeEnd}",
                'comment' => 'PPPoE-' . $this->serviceId,
            ]);
        }

        $profile = $this->config['pppoe_profile'] ?? $this->config['profile'] ?? "pppoe-prof-{$shortId}";
        if ($profile === 'pppoe-default') {
            $profile = "pppoe-prof-{$shortId}";
        }

        $localAddress = $this->config['pppoe_gateway_ip'] ?? $this->config['gateway_ip'] ?? null;
        $remoteAddress = $this->config['pppoe_remote_address'] ?? $poolName;
        $dnsServers = $this->config['pppoe_dns_servers'] ?? $this->config['dns_servers'] ?? null;
        $palList = $this->config['pal_list'] ?? 'PA-' . $shortId;

        if ($profile) {
            $this->removeByName('/ppp/profile', 'name', $profile);
            $params = [
                'name' => $profile,
                'comment' => 'PPPoE-' . $this->serviceId,
                'only-one' => $this->toBoolean($this->config['pppoe_only_one'] ?? true, true) ? 'yes' : 'no',
                'change-tcp-mss' => $this->toBoolean($this->config['pppoe_change_tcp_mss'] ?? true, true) ? 'yes' : 'no',
                'use-compression' => $this->toBoolean($this->config['pppoe_use_compression'] ?? false, false) ? 'yes' : 'no',
            ];

            if ($localAddress) {
                $params['local-address'] = $localAddress;
            }
            if ($remoteAddress) {
                $params['remote-address'] = $remoteAddress;
            }
            if ($dnsServers) {
                $params['dns-server'] = $dnsServers;
            }
            if ($palList) {
                $params['interface-list'] = $palList;
            }

            $this->api->executeCommand('/ppp/profile/add', $params);
        }

        $this->results['ppp_profile'] = 'success';
    }

    /**
     * Create PPPoE server
     */
    private function createPppoeServer(): void
    {
        $this->results['last_phase'] = 'pppoe_server';

        $shortId = $this->shortId();
        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $shortId;
        $serviceName = $this->config['service_name'] ?? 'pppoe-svc-' . $shortId;
        $profile = $this->config['pppoe_profile'] ?? $this->config['profile'] ?? "pppoe-prof-{$shortId}";
        if ($profile === 'pppoe-default') {
            $profile = "pppoe-prof-{$shortId}";
        }

        $this->api->createPppoeServer(
            $serviceName,
            $bridge,
            $profile,
            $this->toInteger($this->config['pppoe_max_mtu'] ?? $this->config['max_mtu'] ?? 1480, 1480),
            $this->toInteger($this->config['pppoe_max_mru'] ?? $this->config['max_mru'] ?? 1480, 1480),
            $this->toBoolean($this->config['pppoe_one_session_per_host'] ?? $this->config['one_session_per_host'] ?? true, true),
            $this->toInteger($this->config['pppoe_keepalive_timeout'] ?? $this->config['keepalive_timeout'] ?? 30, 30),
            (string) ($this->config['pppoe_authentication'] ?? 'chap,mschap2')
        );

        $this->results['pppoe_server'] = 'success';
    }

    /**
     * Setup RADIUS
     */
    private function setupRadius(): void
    {
        $this->results['last_phase'] = 'radius';

        $radiusServers = $this->config['radius_servers'] ?? [];

        foreach ($radiusServers as $server) {
            $this->api->addRadiusServer(
                'ppp',
                $server['address'],
                $server['secret'],
                $server['timeout'] ?? 3,
                'PPPoE-' . $this->serviceId
            );
        }

        $this->api->executeCommand('/ppp/aaa/set', [
            'use-radius' => 'yes',
            'accounting' => 'yes',
            'interim-update' => $this->config['pppoe_interim_update'] ?? '5m',
        ]);

        $this->results['radius'] = 'success';
    }

    /**
     * Setup firewall rules
     */
    private function setupFirewall(): void
    {
        $this->results['last_phase'] = 'firewall';

        $shortId = $this->shortId();
        $palList = $this->config['pal_list'] ?? 'PA-' . $shortId;
        $wanList = $this->config['wan_list'] ?? 'WAN';
        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $shortId;
        $mgmtSubnet = $this->config['mgmt_subnet'] ?? '10.0.0.0/8';
        $mgmtPorts = $this->config['mgmt_ports'] ?? '22,8291,8728,8729';

        // INPUT chain rules
        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-EST-IN',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'in-interface-list' => $palList,
            'protocol' => 'icmp',
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-ICMP',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'protocol' => 'tcp',
            'dst-port' => $mgmtPorts,
            'src-address' => $mgmtSubnet,
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-MGMT-ALLOW',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'protocol' => 'udp',
            'dst-port' => '161',
            'src-address' => $mgmtSubnet,
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-SNMP-ALLOW',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'input',
            'in-interface' => $bridge,
            'protocol' => 'udp',
            'dst-port' => '8863-8864',
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-DISC',
        ]);

        // FORWARD chain rules
        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $wanList,
            'out-interface-list' => $palList,
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-WAN-EST',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $palList,
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-PAL-EST',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $palList,
            'connection-state' => 'invalid',
            'action' => 'drop',
            'comment' => 'PPPoE-' . $this->serviceId . '-PAL-INV',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface-list' => $palList,
            'out-interface-list' => $wanList,
            'action' => 'accept',
            'comment' => 'PPPoE-' . $this->serviceId . '-INET',
        ]);

        $this->api->addFirewallFilterRule([
            'chain' => 'forward',
            'in-interface' => $bridge,
            'action' => 'drop',
            'comment' => 'PPPoE-' . $this->serviceId . '-BLOCK-UNAUTH',
        ]);

        // Global default drop (last rules)
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

    /**
     * Setup NAT rules
     */
    private function setupNat(): void
    {
        $this->results['last_phase'] = 'nat';

        $shortId = $this->shortId();
        $palList = $this->config['pal_list'] ?? 'PA-' . $shortId;
        $wanList = $this->config['wan_list'] ?? 'WAN';

        $this->api->addNatRule([
            'chain' => 'srcnat',
            'in-interface-list' => $palList,
            'out-interface-list' => $wanList,
            'action' => 'masquerade',
            'comment' => 'PPPoE-' . $this->serviceId,
        ]);

        $this->results['nat'] = 'success';
    }

    /**
     * Set connection tracking
     */
    private function setConnectionTracking(): void
    {
        $this->results['last_phase'] = 'connection_tracking';

        $this->api->setConnectionTracking(
            $this->config['tcp_timeout'] ?? 3600,
            $this->config['udp_timeout'] ?? 30
        );

        $this->results['connection_tracking'] = 'success';
    }

    /**
     * Verify PPPoE server exists
     */
    public function verify(): array
    {
        $shortId = $this->shortId();
        $serviceName = $this->config['service_name'] ?? 'pppoe-svc-' . $shortId;

        if ($this->api->pppoeServerExists($serviceName)) {
            return [
                'valid' => true,
                'message' => 'PPPoE server configured successfully',
            ];
        }

        return [
            'valid' => false,
            'error' => 'PPPoE server not found after configuration',
        ];
    }

    private function shortId(): string
    {
        return substr(str_replace('-', '', $this->serviceId), 0, 8);
    }

    private function removeByName(string $resource, string $field, ?string $name): void
    {
        if (!$name) {
            return;
        }

        try {
            $items = $this->api->fetch($resource . '/print');
            foreach ($items as $item) {
                if (($item[$field] ?? null) === $name) {
                    $this->api->executeCommand($resource . '/remove', ['numbers' => $item['.id']]);
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
            $items = $this->api->fetch($resource . '/print');
            foreach ($items as $item) {
                if (($item[$field] ?? null) === $value) {
                    $this->api->executeCommand($resource . '/remove', ['numbers' => $item['.id']]);
                }
            }
        } catch (\Exception $e) {
            // ignore cleanup failures
        }
    }

    private function setEthernetRunningCheck(string $interface, bool $disabled): void
    {
        try {
            $items = $this->api->fetch('/interface/ethernet/print');
            foreach ($items as $item) {
                $name = $item['default-name'] ?? $item['name'] ?? null;
                if ($name === $interface) {
                    $this->api->executeCommand('/interface/ethernet/set', [
                        'numbers' => $item['.id'],
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
}
