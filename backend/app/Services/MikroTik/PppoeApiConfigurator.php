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
    private MikroTikRestApiService $api;
    private string $serviceId;
    private array $config;
    private array $results = [];

    public function __construct(MikroTikRestApiService $api, string $serviceId, array $config)
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

            // Phase 4: Create PPPoE server
            $this->createPppoeServer();
            sleep(1);

            // Phase 5: Setup RADIUS
            $this->setupRadius();
            sleep(1);

            // Phase 6: Setup firewall
            $this->setupFirewall();
            sleep(1);

            // Phase 7: Setup NAT
            $this->setupNat();
            sleep(1);

            // Phase 8: Connection tracking
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

        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $this->serviceId;
        $serviceName = $this->config['service_name'] ?? 'pppoe-svc-' . $this->serviceId;

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

        $this->results['cleanup'] = 'success';
    }

    /**
     * Create bridge and add ports
     */
    private function createBridge(): void
    {
        $this->results['last_phase'] = 'bridge';

        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $this->serviceId;

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

        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $this->serviceId;
        $wanList = $this->config['wan_list'] ?? 'pppoe-wan-list';
        $palList = $this->config['pal_list'] ?? 'pppoe-pal-list';

        // Add bridge to PAL list
        $this->api->addInterfaceListMember($palList, $bridge);

        // Ensure WAN list exists (may already be created)
        try {
            $this->api->addInterfaceListMember($wanList, $this->config['wan_interface'] ?? 'ether1');
        } catch (\Exception $e) {
            // WAN may already be configured, ignore
        }

        $this->results['interface_lists'] = 'success';
    }

    /**
     * Create PPPoE server
     */
    private function createPppoeServer(): void
    {
        $this->results['last_phase'] = 'pppoe_server';

        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $this->serviceId;
        $serviceName = $this->config['service_name'] ?? 'pppoe-svc-' . $this->serviceId;
        $profile = $this->config['profile'] ?? 'pppoe-default';

        $this->api->createPppoeServer(
            $serviceName,
            $bridge,
            $profile,
            $this->config['max_mtu'] ?? 1480,
            $this->config['max_mru'] ?? 1480,
            $this->config['one_session_per_host'] ?? true,
            $this->config['keepalive_timeout'] ?? 30
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

        $this->results['radius'] = 'success';
    }

    /**
     * Setup firewall rules
     */
    private function setupFirewall(): void
    {
        $this->results['last_phase'] = 'firewall';

        $palList = $this->config['pal_list'] ?? 'pppoe-pal-list';
        $wanList = $this->config['wan_list'] ?? 'pppoe-wan-list';
        $bridge = $this->config['bridge'] ?? 'pppoe-br-' . $this->serviceId;
        $mgmtSubnet = $this->config['mgmt_subnet'] ?? '10.0.0.0/8';
        $mgmtPorts = $this->config['mgmt_ports'] ?? '8291,22,80,443';

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

        $palList = $this->config['pal_list'] ?? 'pppoe-pal-list';
        $wanList = $this->config['wan_list'] ?? 'pppoe-wan-list';

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
        $serviceName = $this->config['service_name'] ?? 'pppoe-svc-' . $this->serviceId;

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
}
