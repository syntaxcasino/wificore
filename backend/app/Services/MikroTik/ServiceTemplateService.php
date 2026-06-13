<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\TenantAwareService;
use Illuminate\Support\Facades\Log;

/**
 * Service Template Service
 * 
 * Provides pre-configured templates for common service types:
 * - Hotspot (captive portal)
 * - PPPoE (point-to-point over ethernet)
 * - Hybrid (both Hotspot and PPPoE)
 */
class ServiceTemplateService extends TenantAwareService
{
    /**
     * Get available service templates
     * 
     * @return array
     */
    public function getAvailableTemplates(): array
    {
        return [
            'hotspot' => [
                'name' => 'Hotspot Only',
                'description' => 'Captive portal with RADIUS authentication',
                'features' => ['Web login', 'RADIUS auth', 'Bandwidth control', 'User isolation'],
                'use_cases' => ['Public WiFi', 'Guest networks', 'Cafes/Hotels']
            ],
            'pppoe' => [
                'name' => 'PPPoE Only',
                'description' => 'Point-to-Point over Ethernet with RADIUS',
                'features' => ['PPPoE server', 'RADIUS auth', 'Static IPs', 'Per-user queues'],
                'use_cases' => ['ISP subscribers', 'Residential broadband', 'Business connections']
            ],
            'hybrid' => [
                'name' => 'Hybrid (Hotspot + PPPoE)',
                'description' => 'Combined Hotspot and PPPoE services',
                'features' => ['Both Hotspot and PPPoE', 'VLAN separation', 'Dual RADIUS', 'Flexible deployment'],
                'use_cases' => ['Mixed environments', 'ISP with public WiFi', 'Multi-service networks']
            ]
        ];
    }
    
    /**
     * Generate Hotspot service configuration
     * 
     * @param Router $router
     * @param array $config
     * @return string
     */
    public function generateHotspotTemplate(Router $router, array $config): string
    {
        $interface = $config['interface'] ?? 'ether2';
        $bridgeName = $config['bridge_name'] ?? 'bridge-hotspot';
        $ipPool = $config['ip_pool'] ?? '192.168.88.10-192.168.88.254';
        $network = $config['network'] ?? '192.168.88.0/24';
        $gateway = $config['gateway'] ?? '192.168.88.1';
        $radiusServer = $config['radius_server'] ?? '10.100.1.1';
        $radiusSecret = $config['radius_secret'] ?? 'wificore123';
        $hotspotName = $config['hotspot_name'] ?? 'WiFiCore-Hotspot';
        $dnsServers = $config['dns_servers'] ?? '8.8.8.8,8.8.4.4';
        $wanInterface = $this->resolveWanInterface($config['wan_interface'] ?? $router->wan_interface ?? null);
        
        $lines = [
            '# ============================================================',
            '# WiFiCore Hotspot Service Template',
            "# Router: {$router->name}",
            '# ============================================================',
            '',
            '# 1. Bridge',
            "/interface bridge remove [find name=\"{$bridgeName}\"]",
            "/interface bridge add name=\"{$bridgeName}\" protocol-mode=rstp igmp-snooping=yes unknown-unicast-flood=no comment=\"Hotspot Bridge with STP/IGMP\"",
            '',
            '# 2. Bridge port',
            "/interface bridge port remove [find bridge=\"{$bridgeName}\" interface=\"{$interface}\"]",
            "/interface bridge port add bridge=\"{$bridgeName}\" interface=\"{$interface}\" bpdu-guard=yes edge=yes point-to-point=yes comment=\"Hotspot Port with BPDU Guard\"",
            '',
            '# 3. IP address',
            "/ip address remove [find interface=\"{$bridgeName}\" comment=\"Hotspot Gateway\"]",
            "/ip address add address=\"{$gateway}/24\" interface=\"{$bridgeName}\" comment=\"Hotspot Gateway\"",
            '',
            '# 4. IP pool',
            '/ip pool remove [find name="hotspot-pool"]',
            "/ip pool add name=\"hotspot-pool\" ranges=\"{$ipPool}\" comment=\"Hotspot DHCP Pool\"",
            '',
            '# 5. DHCP',
            "/ip dhcp-server network remove [find address=\"{$network}\"]",
            "/ip dhcp-server network add address=\"{$network}\" gateway=\"{$gateway}\" dns-server=\"{$dnsServers}\" comment=\"Hotspot Network\"",
            '/ip dhcp-server remove [find name="hotspot-dhcp"]',
            "/ip dhcp-server add name=\"hotspot-dhcp\" interface=\"{$bridgeName}\" address-pool=\"hotspot-pool\" lease-time=1h disabled=no",
            '',
            '# 6. Hotspot profile',
            '/ip hotspot profile remove [find name="hotspot-profile"]',
            "/ip hotspot profile add name=\"hotspot-profile\" hotspot-address=\"{$gateway}\" dns-name=\"login.wificore.local\" html-directory=hotspot http-cookie-lifetime=1d login-by=http-chap,http-pap use-radius=yes",
            '',
            '# 7. Hotspot server',
            "/ip hotspot remove [find name=\"{$hotspotName}\"]",
            "/ip hotspot add name=\"{$hotspotName}\" interface=\"{$bridgeName}\" address-pool=\"hotspot-pool\" profile=\"hotspot-profile\" disabled=no",
            '',
            '# 8. RADIUS',
            "/radius remove [find address=\"{$radiusServer}\" service=\"hotspot,login\"]",
            "/radius add address=\"{$radiusServer}\" secret=\"{$radiusSecret}\" service=\"hotspot,login\" timeout=3s comment=\"WiFiCore RADIUS\"",
            '/ip hotspot profile set [find name="hotspot-profile"] use-radius=yes',
            '',
            '# 9. NAT',
            "/ip firewall nat remove [find comment=\"Hotspot NAT\"]",
            "/ip firewall nat add chain=srcnat out-interface=\"{$wanInterface}\" action=masquerade comment=\"Hotspot NAT\"",
            '',
            '# 10. User isolation',
            '/ip hotspot user profile set [find name="default"] address-pool=hotspot-pool transparent-proxy=yes',
        ];
        return implode("\n", $lines) . "\n";
    }
    
    /**
     * Generate PPPoE service configuration
     * 
     * @param Router $router
     * @param array $config
     * @return string
     */
    public function generatePppoeTemplate(Router $router, array $config): string
    {
        $interface = $config['interface'] ?? 'ether3';
        $bridgeName = $config['bridge_name'] ?? 'bridge-pppoe';
        $ipPool = $config['ip_pool'] ?? '10.10.10.2-10.10.10.254';
        $localAddress = $config['local_address'] ?? '10.10.10.1';
        $radiusServer = $config['radius_server'] ?? '10.100.1.1';
        $radiusSecret = $config['radius_secret'] ?? 'wificore123';
        $dnsServers = $config['dns_servers'] ?? '8.8.8.8,8.8.4.4';
        $mtu = $config['mtu'] ?? 1480;
        $wanInterface = $this->resolveWanInterface($config['wan_interface'] ?? $router->wan_interface ?? null);
        
        $lines = [
            '# ============================================================',
            '# WiFiCore PPPoE Service Template',
            "# Router: {$router->name}",
            '# ============================================================',
            '',
            '# 1. Bridge',
            "/interface bridge remove [find name=\"{$bridgeName}\"]",
            "/interface bridge add name=\"{$bridgeName}\" protocol-mode=rstp igmp-snooping=yes unknown-unicast-flood=no comment=\"PPPoE Bridge with STP/IGMP\"",
            '',
            '# 2. Bridge port',
            "/interface bridge port remove [find bridge=\"{$bridgeName}\" interface=\"{$interface}\"]",
            "/interface bridge port add bridge=\"{$bridgeName}\" interface=\"{$interface}\" bpdu-guard=yes edge=yes point-to-point=yes comment=\"PPPoE Port with BPDU Guard\"",
            '',
            '# 3. IP pool',
            '/ip pool remove [find name="pppoe-pool"]',
            "/ip pool add name=\"pppoe-pool\" ranges=\"{$ipPool}\" comment=\"PPPoE Client Pool\"",
            '',
            '# 4. PPP profile',
            '/ppp profile remove [find name="pppoe-profile"]',
            "/ppp profile add name=\"pppoe-profile\" local-address=\"{$localAddress}\" remote-address=\"pppoe-pool\" use-compression=no use-encryption=no use-mpls=no use-upnp=no only-one=yes change-tcp-mss=yes dns-server=\"{$dnsServers}\" comment=\"WiFiCore PPPoE Profile\"",
            '',
            '# 5. RADIUS',
            '/radius remove [find service=ppp]',
            "/radius add address=\"{$radiusServer}\" secret=\"{$radiusSecret}\" service=ppp timeout=3s comment=\"WiFiCore RADIUS PPPoE\"",
            '',
            '# 6. PPP AAA',
            '/ppp aaa set use-radius=yes',
            '',
            '# 7. PPPoE server',
            "/interface pppoe-server server remove [find interface=\"{$bridgeName}\"]",
            "/interface pppoe-server server add interface=\"{$bridgeName}\" service-name=wificore-pppoe default-profile=pppoe-profile authentication=pap,chap,mschap1,mschap2 keepalive-timeout=60 max-mtu=\"{$mtu}\" max-mru=\"{$mtu}\" disabled=no comment=\"WiFiCore PPPoE Server\"",
            '',
            '# 8. NAT',
            "/ip firewall nat remove [find comment=\"PPPoE NAT\"]",
            "/ip firewall nat add chain=srcnat out-interface=\"{$wanInterface}\" src-address=\"{$ipPool}\" action=masquerade comment=\"PPPoE NAT\"",
            '',
            '# 9. Queue (disabled - RADIUS controls)',
            '/queue simple remove [find name="pppoe-default"]',
            '/queue simple add name=pppoe-default target=pppoe-pool max-limit=100M/100M disabled=yes comment="Default PPPoE Queue (disabled, RADIUS controls)"',
        ];
        return implode("\n", $lines) . "\n";
    }
    
    /**
     * Generate Hybrid (Hotspot + PPPoE) service configuration
     * 
     * @param Router $router
     * @param array $config
     * @return string
     */
    public function generateHybridTemplate(Router $router, array $config): string
    {
        // Hotspot config
        $hotspotInterface = $config['hotspot_interface'] ?? 'ether2';
        $hotspotBridge = $config['hotspot_bridge'] ?? 'bridge-hotspot';
        $hotspotVlan = $config['hotspot_vlan'] ?? 10;
        $hotspotPool = $config['hotspot_pool'] ?? '192.168.10.10-192.168.10.254';
        $hotspotNetwork = $config['hotspot_network'] ?? '192.168.10.0/24';
        $hotspotGateway = $config['hotspot_gateway'] ?? '192.168.10.1';
        
        // PPPoE config
        $pppoeInterface = $config['pppoe_interface'] ?? 'ether3';
        $pppoeBridge = $config['pppoe_bridge'] ?? 'bridge-pppoe';
        $pppoeVlan = $config['pppoe_vlan'] ?? 20;
        $pppoePool = $config['pppoe_pool'] ?? '10.20.20.2-10.20.20.254';
        $pppoeLocal = $config['pppoe_local'] ?? '10.20.20.1';
        
        // Shared config
        $radiusServer = $config['radius_server'] ?? '10.100.1.1';
        $radiusSecret = $config['radius_secret'] ?? 'wificore123';
        $dnsServers = $config['dns_servers'] ?? '8.8.8.8,8.8.4.4';
        
        $lines = [
            '# ============================================================',
            '# WiFiCore Hybrid Service Template (Hotspot + PPPoE)',
            "# Router: {$router->name}",
            '# ============================================================',
            '',
            '# PART 1: Bridges',
            "/interface bridge remove [find name=\"{$hotspotBridge}\"]",
            "/interface bridge add name=\"{$hotspotBridge}\" protocol-mode=rstp igmp-snooping=yes unknown-unicast-flood=no comment=\"Hotspot Bridge with STP/IGMP\"",
            "/interface bridge remove [find name=\"{$pppoeBridge}\"]",
            "/interface bridge add name=\"{$pppoeBridge}\" protocol-mode=rstp igmp-snooping=yes unknown-unicast-flood=no comment=\"PPPoE Bridge with STP/IGMP\"",
            '',
            '# PART 2: VLANs',
            '/interface vlan remove [find name="vlan-hotspot"]',
            "/interface vlan add name=\"vlan-hotspot\" vlan-id=\"{$hotspotVlan}\" interface=\"{$hotspotInterface}\" comment=\"Hotspot VLAN\"",
            "/interface bridge port remove [find bridge=\"{$hotspotBridge}\" interface=\"vlan-hotspot\"]",
            "/interface bridge port add bridge=\"{$hotspotBridge}\" interface=vlan-hotspot comment=\"Hotspot VLAN Port\"",
            '/interface vlan remove [find name="vlan-pppoe"]',
            "/interface vlan add name=\"vlan-pppoe\" vlan-id=\"{$pppoeVlan}\" interface=\"{$pppoeInterface}\" comment=\"PPPoE VLAN\"",
            "/interface bridge port remove [find bridge=\"{$pppoeBridge}\" interface=\"vlan-pppoe\"]",
            "/interface bridge port add bridge=\"{$pppoeBridge}\" interface=vlan-pppoe comment=\"PPPoE VLAN Port\"",
            '',
            '# PART 3: Hotspot',
            "/ip address remove [find interface=\"{$hotspotBridge}\" comment=\"Hotspot Gateway\"]",
            "/ip address add address=\"{$hotspotGateway}/24\" interface=\"{$hotspotBridge}\" comment=\"Hotspot Gateway\"",
            '/ip pool remove [find name="hotspot-pool"]',
            "/ip pool add name=\"hotspot-pool\" ranges=\"{$hotspotPool}\" comment=\"Hotspot Pool\"",
            "/ip dhcp-server network remove [find address=\"{$hotspotNetwork}\"]",
            "/ip dhcp-server network add address=\"{$hotspotNetwork}\" gateway=\"{$hotspotGateway}\" dns-server=\"{$dnsServers}\" comment=\"Hotspot Network\"",
            '/ip dhcp-server remove [find name="hotspot-dhcp"]',
            "/ip dhcp-server add name=\"hotspot-dhcp\" interface=\"{$hotspotBridge}\" address-pool=hotspot-pool lease-time=1h disabled=no",
            '/ip hotspot profile remove [find name="hotspot-profile"]',
            "/ip hotspot profile add name=\"hotspot-profile\" hotspot-address=\"{$hotspotGateway}\" dns-name=login.wificore.local html-directory=hotspot login-by=http-chap,http-pap use-radius=yes",
            '/ip hotspot remove [find name="wificore-hotspot"]',
            "/ip hotspot add name=\"wificore-hotspot\" interface=\"{$hotspotBridge}\" address-pool=hotspot-pool profile=hotspot-profile disabled=no",
            '',
            '# PART 4: PPPoE',
            '/ip pool remove [find name="pppoe-pool"]',
            "/ip pool add name=\"pppoe-pool\" ranges=\"{$pppoePool}\" comment=\"PPPoE Pool\"",
            '/ppp profile remove [find name="pppoe-profile"]',
            "/ppp profile add name=\"pppoe-profile\" local-address=\"{$pppoeLocal}\" remote-address=pppoe-pool use-compression=no only-one=yes change-tcp-mss=yes dns-server=\"{$dnsServers}\" comment=\"PPPoE Profile\"",
            "/interface pppoe-server server remove [find interface=\"{$pppoeBridge}\"]",
            "/interface pppoe-server server add interface=\"{$pppoeBridge}\" service-name=wificore-pppoe default-profile=pppoe-profile authentication=pap,chap,mschap1,mschap2 keepalive-timeout=60 max-mtu=1480 max-mru=1480 disabled=no",
            '',
            '# PART 5: RADIUS',
            "/radius remove [find address=\"{$radiusServer}\" service=\"hotspot,ppp,login\"]",
            "/radius add address=\"{$radiusServer}\" secret=\"{$radiusSecret}\" service=\"hotspot,ppp,login\" timeout=3s comment=\"WiFiCore RADIUS (Shared)\"",
            '/ppp aaa set use-radius=yes',
            '',
            '# PART 6: NAT + Firewall',
            "/ip firewall nat remove [find comment=\"Hotspot NAT\"]",
            "/ip firewall nat add chain=srcnat out-interface=\"{$wanInterface}\" src-address=\"{$hotspotNetwork}\" action=masquerade comment=\"Hotspot NAT\"",
            "/ip firewall nat remove [find comment=\"PPPoE NAT\"]",
            "/ip firewall nat add chain=srcnat out-interface=\"{$wanInterface}\" src-address=\"{$pppoePool}\" action=masquerade comment=\"PPPoE NAT\"",
            "/ip firewall filter remove [find comment=\"Block Hotspot -> PPPoE\"]",
            "/ip firewall filter add chain=forward src-address=\"{$hotspotNetwork}\" dst-address=\"{$pppoePool}\" action=drop comment=\"Block Hotspot -> PPPoE\"",
            "/ip firewall filter remove [find comment=\"Block PPPoE -> Hotspot\"]",
            "/ip firewall filter add chain=forward src-address=\"{$pppoePool}\" dst-address=\"{$hotspotNetwork}\" action=drop comment=\"Block PPPoE -> Hotspot\"",
        ];
        return implode("\n", $lines) . "\n";
    }
    
    /**
     * Generate Multi-WAN failover configuration.
     */
    public function generateMultiWanFailoverTemplate(Router $router, array $config): string
    {
        $primaryWan = $this->resolveWanInterface($config['primary_wan'] ?? $router->wan_interface ?? null);
        $backupWan = $this->resolveWanInterface($config['backup_wan'] ?? 'ether2');
        $dnsServers = $config['dns_servers'] ?? '8.8.8.8,8.8.4.4';
        $healthCheckHost = $config['health_check_host'] ?? '1.1.1.1';

        $lines = [
            '# ============================================================',
            '# WiFiCore Multi-WAN Failover Template',
            "# Router: {$router->name}",
            '# ============================================================',
            '',
            '# Interface lists',
            '/interface list add name="WAN" comment="Wide Area Network"',
            '/interface list add name="LAN" comment="Local Area Network"',
            "/interface list member remove [find list=\"WAN\" interface=\"{$primaryWan}\"]",
            "/interface list member add list=\"WAN\" interface=\"{$primaryWan}\"",
            "/interface list member remove [find list=\"WAN\" interface=\"{$backupWan}\"]",
            "/interface list member add list=\"WAN\" interface=\"{$backupWan}\"",
            '',
            '# DHCP clients',
            "/ip dhcp-client remove [find interface=\"{$primaryWan}\"]",
            "/ip dhcp-client add interface=\"{$primaryWan}\" add-default-route=yes default-route-distance=1 use-peer-dns=no use-peer-ntp=no comment=\"WiFiCore Primary WAN\"",
            "/ip dhcp-client remove [find interface=\"{$backupWan}\"]",
            "/ip dhcp-client add interface=\"{$backupWan}\" add-default-route=yes default-route-distance=2 use-peer-dns=no use-peer-ntp=no comment=\"WiFiCore Backup WAN\"",
            '',
            '# DNS',
            "/ip dns set allow-remote-requests=yes servers=\"{$dnsServers}\"",
            '',
            '# NAT',
            '/ip firewall nat remove [find comment="WiFiCore Multi-WAN NAT"]',
            '/ip firewall nat add chain=srcnat out-interface-list=WAN action=masquerade comment="WiFiCore Multi-WAN NAT"',
        ];
        return implode("\n", $lines) . "\n";
    }

    public function generatePccBalancedTemplate(Router $router, array $config): string
    {
        $primaryWan = $this->resolveWanInterface($config['primary_wan'] ?? $router->wan_interface ?? null);
        $backupWan = $this->resolveWanInterface($config['backup_wan'] ?? 'ether2');
        $lanInterface = $config['lan_interface'] ?? 'bridge-lan';
        $primaryGateway = $config['primary_gateway'] ?? '192.0.2.1';
        $backupGateway = $config['backup_gateway'] ?? '198.51.100.1';

        return <<<SCRIPT
# ============================================================
# WiFiCore PCC Balanced Multi-WAN Template
# Router: {$router->name}
# Generated: {now()->toDateTimeString()}
# ============================================================

# Routing tables
/routing table remove [find name="to_wan1"]
/routing table add name="to_wan1" disabled=no
/routing table remove [find name="to_wan2"]
/routing table add name="to_wan2" disabled=no
# Interface lists
/interface list add name="WAN" comment="Wide Area Network"
/interface list add name="LAN" comment="Local Area Network"
/interface list member remove [find list="WAN" interface="{$primaryWan}"]
/interface list member add list="WAN" interface="{$primaryWan}"
/interface list member remove [find list="WAN" interface="{$backupWan}"]
/interface list member add list="WAN" interface="{$backupWan}"
/interface list member remove [find list="LAN" interface="{$lanInterface}"]
/interface list member add list="LAN" interface="{$lanInterface}"
# PCC mangle rules
/ip firewall mangle remove [find comment="WiFiCore PCC WAN1"]
/ip firewall mangle add chain=prerouting in-interface-list=LAN dst-address-type=!local per-connection-classifier=both-addresses-and-ports:2/0 action=mark-connection new-connection-mark=wan1_conn passthrough=yes comment="WiFiCore PCC WAN1"
/ip firewall mangle remove [find comment="WiFiCore PCC WAN2"]
/ip firewall mangle add chain=prerouting in-interface-list=LAN dst-address-type=!local per-connection-classifier=both-addresses-and-ports:2/1 action=mark-connection new-connection-mark=wan2_conn passthrough=yes comment="WiFiCore PCC WAN2"
/ip firewall mangle remove [find comment="WiFiCore PCC Route WAN1"]
/ip firewall mangle add chain=prerouting in-interface-list=LAN connection-mark=wan1_conn action=mark-routing new-routing-mark=to_wan1 passthrough=no comment="WiFiCore PCC Route WAN1"
/ip firewall mangle remove [find comment="WiFiCore PCC Route WAN2"]
/ip firewall mangle add chain=prerouting in-interface-list=LAN connection-mark=wan2_conn action=mark-routing new-routing-mark=to_wan2 passthrough=no comment="WiFiCore PCC Route WAN2"
# Routes
/ip route remove [find comment="WiFiCore PCC Route WAN1"]
/ip route add dst-address=0.0.0.0/0 gateway="{$primaryGateway}" routing-table=to_wan1 distance=1 check-gateway=ping comment="WiFiCore PCC Route WAN1"
/ip route remove [find comment="WiFiCore PCC Route WAN2"]
/ip route add dst-address=0.0.0.0/0 gateway="{$backupGateway}" routing-table=to_wan2 distance=1 check-gateway=ping comment="WiFiCore PCC Route WAN2"
# NAT
/ip firewall nat remove [find comment="WiFiCore PCC NAT"]
/ip firewall nat add chain=srcnat out-interface-list=WAN action=masquerade comment="WiFiCore PCC NAT"
# PCC configuration complete

SCRIPT;
    }

    /**
     * Generate service configuration based on template type
     * 
     * @param Router $router
     * @param string $templateType
     * @param array $config
     * @return string
     */
    public function generateFromTemplate(Router $router, string $templateType, array $config = []): string
    {
        Log::info('Generating service configuration from template', [
            'router_id' => $router->id,
            'template_type' => $templateType
        ]);
        
        $script = match($templateType) {
            'hotspot' => $this->generateHotspotTemplate($router, $config),
            'pppoe' => $this->generatePppoeTemplate($router, $config),
            'hybrid' => $this->generateHybridTemplate($router, $config),
            'multi-wan-failover' => $this->generateMultiWanFailoverTemplate($router, $config),
            'pcc-balanced' => $this->generatePccBalancedTemplate($router, $config),
            default => throw new \InvalidArgumentException("Unknown template type: {$templateType}")
        };
        
        Log::info('Service configuration generated from template', [
            'router_id' => $router->id,
            'template_type' => $templateType,
            'script_length' => strlen($script)
        ]);
        
        return $script;
    }
    
    /**
     * Generate and save service configuration
     * 
     * @param Router $router
     * @param string $templateType
     * @param array $config
     * @return string
     */
    public function generateAndSave(Router $router, string $templateType, array $config = []): string
    {
        $script = $this->generateFromTemplate($router, $templateType, $config);
        
        // Save to router_configs table
        \App\Models\RouterConfig::updateOrCreate(
            [
                'router_id' => $router->id,
                'config_type' => 'service'
            ],
            [
                'config_content' => $script
            ]
        );
        
        Log::info('Service configuration saved to database', [
            'router_id' => $router->id,
            'template_type' => $templateType,
            'config_type' => 'service'
        ]);
        
        return $script;
    }

    /**
     * Normalize WAN interface selection for generated templates.
     *
     * @param string|null $wanInterface
     * @return string
     */
    protected function resolveWanInterface(?string $wanInterface): string
    {
        $wanInterface = trim((string) $wanInterface);
        if ($wanInterface === '' || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $wanInterface)) {
            return 'ether1';
        }

        return $wanInterface;
    }
}
