<?php

namespace App\Services\MikroTik;

use App\Services\InterfaceManagementService;
use App\Models\Router;

/**
 * MikroTik PPPoE Service
 * 
 * Generates production-ready PPPoE server configurations for MikroTik routers
 * with RADIUS integration and proper authentication
 */
class PPPoEService extends BaseMikroTikService
{
    protected ?InterfaceManagementService $interfaceManager = null;

    /**
     * Set interface manager for validation (optional)
     */
    public function setInterfaceManager(InterfaceManagementService $manager): void
    {
        $this->interfaceManager = $manager;
    }
    /**
     * Generate complete PPPoE configuration
     */
    public function generateConfig(array $interfaces, string $routerId, array $options = []): string
    {
        $this->logStep('Generating PPPoE configuration', [
            'router_id' => $routerId,
            'interfaces' => $interfaces,
        ]);
        
        // Validate inputs
        if (empty($interfaces)) {
            throw new \Exception('At least one interface is required for PPPoE configuration');
        }

        // NEW: Validate interfaces if interface manager is set
        if ($this->interfaceManager) {
            $router = Router::find($routerId);
            if ($router) {
                $validation = $this->interfaceManager->validateInterfaceAssignment(
                    $router,
                    'pppoe',
                    $interfaces
                );
                
                if (!$validation['valid']) {
                    throw new \Exception('Interface validation failed: ' . implode(', ', $validation['errors']));
                }
            }
        }
        
        // Extract options with defaults
        $authMethods = $options['auth_methods'] ?? 'chap,mschap2';
        $dnsServers = $options['dns_servers'] ?? '8.8.8.8,1.1.1.1';
        $useRadius = $options['use_radius'] ?? true;
        $radiusIp = $options['radius_ip'] ?? ($options['radius_server'] ?? '172.20.0.6');
        $radiusSecret = $options['radius_secret'] ?? 'testing123';
        
        // Build configuration script
        $script = [];
        
        // Header
        $script = array_merge($script, $this->generateHeader('PPPoE Configuration', $routerId));

        // Ensure interface lists exist (single-line commands for SSH exec compatibility)
        $script[] = ':do { :if ([/interface list find name="LAN"] = "") do={ /interface list add name=LAN comment="Local Area Network" } } on-error={}';
        $script[] = ':do { :if ([/interface list find name="WAN"] = "") do={ /interface list add name=WAN comment="Wide Area Network" } } on-error={}';
        $script[] = ':do { :if ([/interface list member find list=WAN interface=ether1] = "") do={ /interface list member add list=WAN interface=ether1 } } on-error={}';
        $script[] = '';
        
        $safeInterfaces = array_values(array_unique(array_map(fn ($i) => $this->validateInterface((string) $i), $interfaces)));
        $bridgeName = $this->validateInterface($options['bridge_name'] ?? "br-pppoe-{$routerId}");
        $serviceName = $options['service_name'] ?? "pppoe-{$routerId}";

        // Configure PPPoE access on a bridge so multiple selected interfaces work cleanly
        $script[] = "# PPPoE Access Bridge";
        $script[] = ":do { :if ([:len [/interface bridge find name=\"{$bridgeName}\"]] = 0) do={ /interface bridge add name=\"{$bridgeName}\" comment=\"WiFiCore PPPoE bridge ({$routerId})\" } } on-error={ :error \"PPPoE: bridge create failed ({$bridgeName})\" }";
        $script[] = ":do { /interface bridge port remove [find bridge=\"{$bridgeName}\" comment~\"WiFiCore PPPoE port \\\\({$routerId}\\\\)\"]; } on-error={}";

        foreach ($safeInterfaces as $iface) {
            $script[] = ":if ([:len [/interface find name=\"{$iface}\"]] = 0) do={ :error \"PPPoE: interface not found ({$iface})\" }";
            $script[] = ":do { /interface bridge port add bridge=\"{$bridgeName}\" interface=\"{$iface}\" comment=\"WiFiCore PPPoE port ({$routerId})\" } on-error={ :error \"PPPoE: bridge port add failed ({$iface})\" }";
        }
        $script[] = '';

        // Single PPPoE service/profile/pool bound to the bridge
        $ipPool = $this->validateIpPool($options['ip_pool'] ?? '192.168.89.10-192.168.89.254');
        $gateway = $options['gateway'] ?? $this->getGatewayFromPool($ipPool);
        $profileName = "pppoe-prof-{$routerId}";
        $poolName = "pool-pppoe-{$routerId}";
        $mtu = $options['mtu'] ?? '1480';
        $mru = $options['mru'] ?? '1480';
        $keepaliveTimeout = $options['keepalive_timeout'] ?? '10';

        // IP Pool (single-line, idempotent)
        $script[] = ":do { /ip pool remove [find name=\"{$poolName}\"]; /ip pool add name=\"{$poolName}\" ranges={$ipPool} comment=\"WiFiCore PPPoE pool ({$routerId})\"; } on-error={ :error \"PPPoE: pool create failed ({$poolName})\" }";
        $script[] = ":if ([:len [/ip pool find name=\"{$poolName}\"]] = 0) do={ :error \"PPPoE: pool missing ({$poolName})\" }";
        $script[] = '';

        // Gateway IP on the PPPoE bridge (single-line, scoped by comment)
        $script[] = ":do { /ip address remove [find comment=\"WiFiCore PPPoE gateway ({$routerId})\"]; /ip address add address={$gateway}/24 interface=\"{$bridgeName}\" comment=\"WiFiCore PPPoE gateway ({$routerId})\"; } on-error={ :error \"PPPoE: gateway IP set failed ({$bridgeName})\" }";
        $script[] = '';

        // PPP Profile (single-line)
        $script[] = ":do { /ppp profile remove [find name=\"{$profileName}\"]; /ppp profile add name=\"{$profileName}\" use-radius=yes local-address={$gateway} remote-address=\"{$poolName}\" dns-server=\"{$dnsServers}\" use-compression=no use-encryption=no only-one=no change-tcp-mss=yes; } on-error={ :error \"PPPoE: PPP profile create failed ({$profileName})\" }";
        $script[] = ":if ([:len [/ppp profile find name=\"{$profileName}\"]] = 0) do={ :error \"PPPoE: PPP profile missing ({$profileName})\" }";
        $script[] = '';

        // PPPoE Server on bridge (RouterOS 7 property names)
        $script[] = ":do { /interface pppoe-server server remove [find comment=\"WiFiCore PPPoE ({$routerId})\"]; } on-error={}";
        $script[] = ":do { /interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$bridgeName}\" default-profile=\"{$profileName}\" authentication={$authMethods} one-session-per-host=yes keepalive-timeout={$keepaliveTimeout} max-mtu={$mtu} max-mru={$mru} disabled=no comment=\"WiFiCore PPPoE ({$routerId})\"; } on-error={ :error \"PPPoE: PPPoE server create failed ({$serviceName})\" }";
        $script[] = ":if ([:len [/interface pppoe-server server find comment=\"WiFiCore PPPoE ({$routerId})\"]] = 0) do={ :error \"PPPoE: PPPoE server missing ({$serviceName})\" }";
        $script[] = '';

        // Add PPPoE bridge to LAN list (single-line)
        $script[] = ":do { :if ([/interface list member find list=LAN interface=\"{$bridgeName}\"] = \"\") do={ /interface list member add list=LAN interface=\"{$bridgeName}\" comment=\"WiFiCore PPPoE bridge\" } } on-error={}";
        $script[] = '';
        
        // RADIUS configuration
        if ($useRadius) {
            $script[] = ':do { /radius remove [find service=ppp comment~"WiFiCore PPPoE"]; } on-error={}';
            $script[] = ":do { /radius add service=ppp address={$radiusIp} secret={$radiusSecret} authentication-port=1812 accounting-port=1813 timeout=3s comment=\"WiFiCore PPPoE ({$routerId})\"; } on-error={ :error \"PPPoE: RADIUS configure failed\" }";
            $script[] = '';
        }
        
        // Firewall and NAT
        $script[] = ':do { /ip firewall nat remove [find comment="WiFiCore PPPoE NAT"]; } on-error={}';
        $script[] = ':do { /ip firewall nat add chain=srcnat action=masquerade out-interface-list=WAN comment="WiFiCore PPPoE NAT"; } on-error={ :error "PPPoE: NAT add failed" }';
        $script[] = '';
        
        // DNS
        $script[] = "/ip dns set allow-remote-requests=yes servers=\"{$dnsServers}\"";
        $script[] = '';
        
        // Footer
        $script = array_merge($script, $this->generateFooter());
        
        $this->logStep('PPPoE configuration generated', [
            'router_id' => $routerId,
            'script_lines' => count($script),
            'script_size' => strlen(implode("\n", $script)),
        ]);
        
        // Join with actual line breaks for RouterOS - no escaping needed
        return implode("\n", $script);
    }
    
    /**
     * Configure PPPoE for a single interface
     */
    private function configurePPPoEInterface(
        string $interface,
        string $routerId,
        array $options,
        string $authMethods,
        string $dnsServers
    ): array {
        $safeInterface = $this->validateInterface($interface);
        
        // Get interface-specific options
        $ipPool = $this->validateIpPool($options['ip_pool'] ?? '192.168.89.10-192.168.89.254');
        $gateway = $options['gateway'] ?? $this->getGatewayFromPool($ipPool);
        $serviceName = $options['service_name'] ?? "pppoe-{$safeInterface}-{$routerId}";
        $profileName = "pppoe-profile-{$safeInterface}-{$routerId}";
        $poolName = "pool-pppoe-{$safeInterface}-{$routerId}";
        $mtu = $options['mtu'] ?? '1480';
        $mru = $options['mru'] ?? '1480';
        $keepaliveTimeout = $options['keepalive_timeout'] ?? '10';
        
        $script = [];
        $script[] = "# PPPoE Configuration for interface $safeInterface";
        $script[] = '';

        // Validate interface exists (single-line)
        $script[] = ":if ([:len [/interface find name=\"{$safeInterface}\"]] = 0) do={ :error \"PPPoE: interface not found ({$safeInterface})\" }";
        $script[] = '';

        // IP Pool (single-line, idempotent)
        $script[] = ":do { /ip pool remove [find name=\"{$poolName}\"]; /ip pool add name=\"{$poolName}\" ranges={$ipPool} comment=\"WiFiCore PPPoE pool ({$safeInterface})\"; } on-error={ :error \"PPPoE: pool create failed ({$poolName})\" }";
        $script[] = ":if ([:len [/ip pool find name=\"{$poolName}\"]] = 0) do={ :error \"PPPoE: pool missing ({$poolName})\" }";
        $script[] = '';

        // Optional gateway IP on the access interface (single-line, scoped by comment)
        $script[] = ":do { /ip address remove [find comment=\"WiFiCore PPPoE gateway ({$safeInterface})\"]; /ip address add address={$gateway}/24 interface=\"{$safeInterface}\" comment=\"WiFiCore PPPoE gateway ({$safeInterface})\"; } on-error={ :error \"PPPoE: gateway IP set failed ({$safeInterface})\" }";
        $script[] = '';

        // PPP Profile (single-line)
        $script[] = ":do { /ppp profile remove [find name=\"{$profileName}\"]; /ppp profile add name=\"{$profileName}\" use-radius=yes local-address={$gateway} remote-address=\"{$poolName}\" dns-server=\"{$dnsServers}\" use-compression=no use-encryption=no only-one=no change-tcp-mss=yes; } on-error={ :error \"PPPoE: PPP profile create failed ({$profileName})\" }";
        $script[] = ":if ([:len [/ppp profile find name=\"{$profileName}\"]] = 0) do={ :error \"PPPoE: PPP profile missing ({$profileName})\" }";
        $script[] = '';

        // PPPoE Server (RouterOS 7 property names)
        $script[] = ":do { /interface pppoe-server server remove [find service-name=\"{$serviceName}\"]; /interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$safeInterface}\" default-profile=\"{$profileName}\" authentication={$authMethods} one-session-per-host=yes keepalive-timeout={$keepaliveTimeout} max-mtu={$mtu} max-mru={$mru} disabled=no; } on-error={ :error \"PPPoE: PPPoE server create failed ({$serviceName})\" }";
        $script[] = ":if ([:len [/interface pppoe-server server find service-name=\"{$serviceName}\"]] = 0) do={ :error \"PPPoE: PPPoE server missing ({$serviceName})\" }";
        $script[] = '';

        // Add interface to LAN list (single-line)
        $script[] = ":do { :if ([/interface list member find list=LAN interface=\"{$safeInterface}\"] = \"\") do={ /interface list member add list=LAN interface=\"{$safeInterface}\" comment=\"WiFiCore PPPoE interface\" } } on-error={}";
        $script[] = '';
        
        return $script;
    }
}
