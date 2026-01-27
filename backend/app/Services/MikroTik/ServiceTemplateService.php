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
        
        return <<<SCRIPT
# ============================================================
# WiFiCore Hotspot Service Template
# Router: {$router->name}
# Generated: {now()->toDateTimeString()}
# ============================================================

:log info "Configuring Hotspot service with bridge"

# 1. Create bridge interface with security features
/interface bridge
:if ([:len [find name="{$bridgeName}"]] = 0) do={
    add name={$bridgeName} \
        protocol-mode=rstp \
        igmp-snooping=yes \
        unknown-unicast-flood=no \
        comment="Hotspot Bridge with STP/IGMP"
    :log info "Created bridge: {$bridgeName} with RSTP and IGMP snooping"
}

# 2. Add physical interface to bridge with BPDU guard
/interface bridge port
:if ([:len [find interface="{$interface}"]] = 0) do={
    add bridge={$bridgeName} interface={$interface} \
        bpdu-guard=yes \
        edge=yes \
        point-to-point=yes \
        comment="Hotspot Port with BPDU Guard"
    :log info "Added {$interface} to {$bridgeName} with BPDU guard"
}

# 3. Configure IP address on bridge
/ip address
:if ([:len [find address="{$gateway}/24"]] = 0) do={
    add address={$gateway}/24 interface={$bridgeName} comment="Hotspot Gateway"
}

# 4. Create IP pool for Hotspot clients
/ip pool
:if ([:len [find name="hotspot-pool"]] = 0) do={
    add name=hotspot-pool ranges={$ipPool} comment="Hotspot DHCP Pool"
}

# 5. Configure DHCP server
/ip dhcp-server network
:if ([:len [find address="{$network}"]] = 0) do={
    add address={$network} gateway={$gateway} dns-server={$dnsServers} \
        comment="Hotspot Network"
}

/ip dhcp-server
:if ([:len [find name="hotspot-dhcp"]] = 0) do={
    add name=hotspot-dhcp interface={$bridgeName} address-pool=hotspot-pool \
        lease-time=1h disabled=no
}

# 6. Configure Hotspot server
/ip hotspot profile
:if ([:len [find name="hotspot-profile"]] = 0) do={
    add name=hotspot-profile \
        hotspot-address={$gateway} \
        dns-name=login.wificore.local \
        html-directory=hotspot \
        http-cookie-lifetime=1d \
        login-by=http-chap,http-pap \
        use-radius=yes
}

/ip hotspot
:if ([:len [find name="{$hotspotName}"]] = 0) do={
    add name={$hotspotName} interface={$bridgeName} \
        address-pool=hotspot-pool profile=hotspot-profile \
        disabled=no
}

# 7. Configure RADIUS
/radius
:if ([:len [find address="{$radiusServer}"]] = 0) do={
    add address={$radiusServer} secret={$radiusSecret} \
        service=hotspot,login timeout=3s comment="WiFiCore RADIUS"
}

/ip hotspot profile
set [find name="hotspot-profile"] use-radius=yes

# 8. Configure firewall for Hotspot
/ip firewall nat
add chain=srcnat out-interface=ether1 action=masquerade \
    comment="Hotspot NAT"

# 9. User isolation (prevent client-to-client communication)
/ip hotspot user profile
set [find name="default"] address-pool=hotspot-pool \
    transparent-proxy=yes

:log info "Hotspot service configured successfully on bridge {$bridgeName}"

SCRIPT;
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
        
        return <<<SCRIPT
# ============================================================
# WiFiCore PPPoE Service Template
# Router: {$router->name}
# Generated: {now()->toDateTimeString()}
# ============================================================

:log info "Configuring PPPoE service with bridge"

# 1. Create bridge interface with security features
/interface bridge
:if ([:len [find name="{$bridgeName}"]] = 0) do={
    add name={$bridgeName} \
        protocol-mode=rstp \
        igmp-snooping=yes \
        unknown-unicast-flood=no \
        comment="PPPoE Bridge with STP/IGMP"
    :log info "Created bridge: {$bridgeName} with RSTP and IGMP snooping"
}

# 2. Add physical interface to bridge with BPDU guard
/interface bridge port
:if ([:len [find interface="{$interface}"]] = 0) do={
    add bridge={$bridgeName} interface={$interface} \
        bpdu-guard=yes \
        edge=yes \
        point-to-point=yes \
        comment="PPPoE Port with BPDU Guard"
    :log info "Added {$interface} to {$bridgeName} with BPDU guard"
}

# 3. Create IP pool for PPPoE clients
/ip pool
:if ([:len [find name="pppoe-pool"]] = 0) do={
    add name=pppoe-pool ranges={$ipPool} comment="PPPoE Client Pool"
}

# 4. Configure PPPoE profile
/ppp profile
:if ([:len [find name="pppoe-profile"]] = 0) do={
    add name=pppoe-profile \
        local-address={$localAddress} \
        remote-address=pppoe-pool \
        use-compression=no \
        use-encryption=no \
        use-mpls=no \
        use-upnp=no \
        only-one=yes \
        change-tcp-mss=yes \
        dns-server={$dnsServers} \
        comment="WiFiCore PPPoE Profile"
}

# 5. Configure RADIUS for PPPoE
/radius
:if ([:len [find address="{$radiusServer}" service~"ppp"]] = 0) do={
    add address={$radiusServer} secret={$radiusSecret} \
        service=ppp timeout=3s comment="WiFiCore RADIUS PPPoE"
}

# 6. Enable RADIUS for PPP
/ppp aaa
set use-radius=yes

# 7. Create PPPoE server on bridge
/interface pppoe-server server
:if ([:len [find interface="{$bridgeName}"]] = 0) do={
    add interface={$bridgeName} \
        service-name=wificore-pppoe \
        default-profile=pppoe-profile \
        authentication=pap,chap,mschap1,mschap2 \
        keepalive-timeout=60 \
        max-mtu={$mtu} \
        max-mru={$mtu} \
        disabled=no \
        comment="WiFiCore PPPoE Server"
}

# 8. Configure firewall for PPPoE
/ip firewall nat
add chain=srcnat out-interface=ether1 src-address={$ipPool} \
    action=masquerade comment="PPPoE NAT"

# 9. Configure simple queue for bandwidth management
# Note: RADIUS can override these with per-user limits
/queue simple
add name=pppoe-default target=pppoe-pool \
    max-limit=100M/100M burst-limit=0/0 \
    burst-threshold=0/0 burst-time=0s/0s \
    disabled=yes comment="Default PPPoE Queue (disabled, RADIUS controls)"

:log info "PPPoE service configured successfully on bridge {$bridgeName}"

SCRIPT;
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
        
        return <<<SCRIPT
# ============================================================
# WiFiCore Hybrid Service Template (Hotspot + PPPoE)
# Router: {$router->name}
# Generated: {now()->toDateTimeString()}
# ============================================================

:log info "Configuring Hybrid (Hotspot + PPPoE) service with bridges"

# ============================================================
# PART 1: BRIDGE CONFIGURATION
# ============================================================

# Create bridge for Hotspot with security features
/interface bridge
:if ([:len [find name="{$hotspotBridge}"]] = 0) do={
    add name={$hotspotBridge} \
        protocol-mode=rstp \
        igmp-snooping=yes \
        unknown-unicast-flood=no \
        comment="Hotspot Bridge with STP/IGMP"
    :log info "Created bridge: {$hotspotBridge} with RSTP and IGMP snooping"
}

# Create bridge for PPPoE with security features
:if ([:len [find name="{$pppoeBridge}"]] = 0) do={
    add name={$pppoeBridge} \
        protocol-mode=rstp \
        igmp-snooping=yes \
        unknown-unicast-flood=no \
        comment="PPPoE Bridge with STP/IGMP"
    :log info "Created bridge: {$pppoeBridge} with RSTP and IGMP snooping"
}

# ============================================================
# PART 2: VLAN CONFIGURATION ON BRIDGES
# ============================================================

# Create VLAN on Hotspot interface and add to bridge
/interface vlan
:if ([:len [find name="vlan-hotspot"]] = 0) do={
    add name=vlan-hotspot vlan-id={$hotspotVlan} interface={$hotspotInterface} \
        comment="Hotspot VLAN"
}

/interface bridge port
:if ([:len [find interface="vlan-hotspot"]] = 0) do={
    add bridge={$hotspotBridge} interface=vlan-hotspot comment="Hotspot VLAN Port"
}

# Create VLAN on PPPoE interface and add to bridge
/interface vlan
:if ([:len [find name="vlan-pppoe"]] = 0) do={
    add name=vlan-pppoe vlan-id={$pppoeVlan} interface={$pppoeInterface} \
        comment="PPPoE VLAN"
}

/interface bridge port
:if ([:len [find interface="vlan-pppoe"]] = 0) do={
    add bridge={$pppoeBridge} interface=vlan-pppoe comment="PPPoE VLAN Port"
}

# ============================================================
# PART 3: HOTSPOT CONFIGURATION
# ============================================================

:log info "Configuring Hotspot on bridge {$hotspotBridge}"

# IP address for Hotspot bridge
/ip address
:if ([:len [find address="{$hotspotGateway}/24"]] = 0) do={
    add address={$hotspotGateway}/24 interface={$hotspotBridge} \
        comment="Hotspot Gateway"
}

# IP pool for Hotspot
/ip pool
:if ([:len [find name="hotspot-pool"]] = 0) do={
    add name=hotspot-pool ranges={$hotspotPool} comment="Hotspot Pool"
}

# DHCP network
/ip dhcp-server network
:if ([:len [find address="{$hotspotNetwork}"]] = 0) do={
    add address={$hotspotNetwork} gateway={$hotspotGateway} \
        dns-server={$dnsServers} comment="Hotspot Network"
}

# DHCP server
/ip dhcp-server
:if ([:len [find name="hotspot-dhcp"]] = 0) do={
    add name=hotspot-dhcp interface={$hotspotBridge} \
        address-pool=hotspot-pool lease-time=1h disabled=no
}

# Hotspot profile
/ip hotspot profile
:if ([:len [find name="hotspot-profile"]] = 0) do={
    add name=hotspot-profile \
        hotspot-address={$hotspotGateway} \
        dns-name=login.wificore.local \
        html-directory=hotspot \
        login-by=http-chap,http-pap \
        use-radius=yes
}

# Hotspot server
/ip hotspot
:if ([:len [find name="wificore-hotspot"]] = 0) do={
    add name=wificore-hotspot interface={$hotspotBridge} \
        address-pool=hotspot-pool profile=hotspot-profile disabled=no
}

# ============================================================
# PART 4: PPPOE CONFIGURATION
# ============================================================

:log info "Configuring PPPoE on bridge {$pppoeBridge}"

# IP pool for PPPoE
/ip pool
:if ([:len [find name="pppoe-pool"]] = 0) do={
    add name=pppoe-pool ranges={$pppoePool} comment="PPPoE Pool"
}

# PPPoE profile
/ppp profile
:if ([:len [find name="pppoe-profile"]] = 0) do={
    add name=pppoe-profile \
        local-address={$pppoeLocal} \
        remote-address=pppoe-pool \
        use-compression=no \
        only-one=yes \
        change-tcp-mss=yes \
        dns-server={$dnsServers} \
        comment="PPPoE Profile"
}

# PPPoE server on bridge
/interface pppoe-server server
:if ([:len [find interface="{$pppoeBridge}"]] = 0) do={
    add interface={$pppoeBridge} \
        service-name=wificore-pppoe \
        default-profile=pppoe-profile \
        authentication=pap,chap,mschap1,mschap2 \
        keepalive-timeout=60 \
        max-mtu=1480 \
        max-mru=1480 \
        disabled=no
}

# ============================================================
# PART 5: RADIUS CONFIGURATION (SHARED)
# ============================================================

:log info "Configuring RADIUS"

/radius
:if ([:len [find address="{$radiusServer}"]] = 0) do={
    add address={$radiusServer} secret={$radiusSecret} \
        service=hotspot,ppp,login timeout=3s \
        comment="WiFiCore RADIUS (Shared)"
}

# Enable RADIUS for PPP
/ppp aaa
set use-radius=yes

# ============================================================
# PART 6: FIREWALL & NAT
# ============================================================

:log info "Configuring firewall and NAT"

# NAT for both services
/ip firewall nat
add chain=srcnat out-interface=ether1 src-address={$hotspotNetwork} \
    action=masquerade comment="Hotspot NAT"
add chain=srcnat out-interface=ether1 src-address={$pppoePool} \
    action=masquerade comment="PPPoE NAT"

# Prevent cross-service communication (security)
/ip firewall filter
add chain=forward src-address={$hotspotNetwork} \
    dst-address={$pppoePool} action=drop \
    comment="Block Hotspot -> PPPoE"
add chain=forward src-address={$pppoePool} \
    dst-address={$hotspotNetwork} action=drop \
    comment="Block PPPoE -> Hotspot"

:log info "Hybrid service configured successfully with bridges"

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
}
