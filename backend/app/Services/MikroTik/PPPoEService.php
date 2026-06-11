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

        $router = Router::find($routerId);

        // NEW: Validate interfaces if interface manager is set
        if ($this->interfaceManager && $router) {
            $validation = $this->interfaceManager->validateInterfaceAssignment(
                $router,
                'pppoe',
                $interfaces
            );

            if (!$validation['valid']) {
                throw new \Exception('Interface validation failed: ' . implode(', ', $validation['errors']));
            }
        }
        
        // Extract options with defaults
        $authMethods = $options['auth_methods'] ?? 'chap,mschap2';
        $dnsServers = $options['dns_servers'] ?? '8.8.8.8,1.1.1.1';
        $useRadius = $options['use_radius'] ?? true;
        $radiusIp = $options['radius_ip'] ?? ($options['radius_server'] ?? '172.20.0.6');
        $radiusSecret = $options['radius_secret'] ?? 'testing123';
        $wanInterface = $this->resolveWanInterface(
            $options['wan_interface'] ?? ($router ? $router->wan_interface : null)
        );
        
        // Build configuration script
        $script = [];
        
        // Header
        $script = array_merge($script, $this->generateHeader('PPPoE Configuration', $routerId));

        // Ensure interface lists exist (single-line commands for SSH exec compatibility)
        $script[] = '/interface list add name="LAN" comment="Local Area Network" ';
        $script[] = '/interface list add name="WAN" comment="Wide Area Network" ';
        $script[] = "/interface list member add list=WAN interface=\"{$wanInterface}\"";
        $script[] = '';
        
        $safeInterfaces = array_values(array_unique(array_map(fn ($i) => $this->validateInterface((string) $i), $interfaces)));
        $bridgeName = $this->validateInterface($options['bridge_name'] ?? "br-pppoe-{$routerId}");
        $serviceName = $options['service_name'] ?? "pppoe-{$routerId}";

        // Configure PPPoE access on a bridge so multiple selected interfaces work cleanly
        $script[] = "# PPPoE Access Bridge";
        $script[] = "/interface bridge add name=\"{$bridgeName}\" comment=\"WiFiCore PPPoE bridge ({$routerId})\"";
        $script[] = "/interface bridge port remove [find bridge=\"{$bridgeName}\" comment=\"WiFiCore PPPoE port ({$routerId})\"]";

        foreach ($safeInterfaces as $iface) {
            $script[] = "/interface bridge port add bridge=\"{$bridgeName}\" interface=\"{$iface}\" comment=\"WiFiCore PPPoE port ({$routerId})\"";
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
        $script[] = "/ip pool remove [find name=\"{$poolName}\"]";
        $script[] = "/ip pool add name=\"{$poolName}\" ranges=\"{$ipPool}\" comment=\"WiFiCore PPPoE pool ({$routerId})\"";
        $script[] = '';

        // Gateway IP on the PPPoE bridge (single-line, scoped by comment)
        $script[] = "/ip address remove [find comment=\"WiFiCore PPPoE gateway ({$routerId})\"]";
        $script[] = "/ip address add address=\"{$gateway}/24\" interface=\"{$bridgeName}\" comment=\"WiFiCore PPPoE gateway ({$routerId})\"";
        $script[] = '';

        // PPP Profile (single-line) - rate-limit empty to allow RADIUS override
        // interface-list=PPPOE-ACTIVE: dynamic <pppoe-*> interfaces auto-join on auth
        $script[] = "/ppp profile remove [find name=\"{$profileName}\"]";
        $script[] = "/ppp profile add name=\"{$profileName}\" local-address=\"{$gateway}\" remote-address=\"{$poolName}\" dns-server=\"{$dnsServers}\" interface-list=PPPOE-ACTIVE use-compression=no use-encryption=no only-one=no change-tcp-mss=yes rate-limit=\"\"";

        // Ensure existing profile gets interface-list updated
        $script[] = "/ppp profile set [find name=\"{$profileName}\"] interface-list=PPPOE-ACTIVE";
        $script[] = '';

        // PPPoE Server on bridge (RouterOS 7 property names)
        $script[] = "/interface pppoe-server server remove [find comment=\"WiFiCore PPPoE ({$routerId})\"]";
        $script[] = "/interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$bridgeName}\" default-profile=\"{$profileName}\" authentication=\"{$authMethods}\" one-session-per-host=yes keepalive-timeout=\"{$keepaliveTimeout}\" max-mtu=\"{$mtu}\" max-mru=\"{$mru}\" disabled=no comment=\"WiFiCore PPPoE ({$routerId})\"";
        $script[] = '';

        // Add PPPoE bridge to LAN list (single-line)
        $script[] = "/interface list member add list=LAN interface=\"{$bridgeName}\" comment=\"WiFiCore PPPoE bridge\"";
        $script[] = '';

        // Add PPPoE bridge to PPPOE interface list so we can safely exclude it from FastTrack rules
        $script[] = '/interface list add name=PPPOE; ';
        $script[] = "/interface list member add list=PPPOE interface=\"{$bridgeName}\" comment=\"WiFiCore PPPoE list\"";
        $script[] = '';

        // PPPOE-ACTIVE list: authenticated dynamic <pppoe-*> interfaces auto-join via PPP profile
        // CRITICAL for security: firewall/NAT rules match on this list, NOT src-address subnet
        $script[] = '/interface list add name=PPPOE-ACTIVE; ';
        $script[] = '';

        // Ensure PPPoE traffic is not FastTracked (FastTrack bypasses queues and breaks Mikrotik-Rate-Limit)
        $script[] = "/ip firewall filter remove [find comment=\"WiFiCore PPPoE NO-FT ({$routerId})\"]";
        $script[] = "/ip firewall filter add chain=forward action=accept connection-state=established,related in-interface-list=PPPOE place-before=0 comment=\"WiFiCore PPPoE NO-FT ({$routerId})\"";
        $script[] = '';
        
        // RADIUS configuration with incoming/accounting enabled for rate limiting
        if ($useRadius) {
            $script[] = '/radius remove [find service=ppp]';
            $script[] = "/radius add service=ppp address=\"{$radiusIp}\" secret=\"{$radiusSecret}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"WiFiCore PPPoE ({$routerId})\"";
            $script[] = '';

            // Ensure PPP uses RADIUS and accounting is enabled (required for radacct + session age)
            $script[] = '/ppp aaa set use-radius=yes accounting=yes interim-update=5m; ';
            $script[] = '';
            
            // Enable RADIUS incoming to accept CoA (Change of Authorization) for rate limit changes
            $script[] = '/radius incoming set accept=yes port=3799; ';
            $script[] = '';
        }
        
        // ============ FIREWALL - FORWARD CHAIN (CRITICAL SECURITY) ============
        // SECURITY PRINCIPLE: Never allow traffic from the bridge directly.
        // Allow ONLY traffic from PPPoE dynamic interfaces (authenticated sessions).
        // The PPP profile sets interface-list=PPPOE-ACTIVE, so when a user authenticates,
        // their dynamic <pppoe-*> interface auto-joins that list.
        // An unauthorized client on the bridge can spoof src-address but CANNOT create a PPPoE session.
        //
        // Rule order (inserted in REVERSE with place-before=0):
        // 1. Accept established/related FROM authenticated PPPoE interfaces
        // 2. Drop invalid FROM authenticated PPPoE interfaces
        // 3. Allow authenticated PPPoE interfaces to WAN
        // 4. Accept established/related FROM WAN (return traffic)
        // 5. DROP everything from bridge (unauthenticated devices)
        $script[] = "# Forward chain - authentication enforcement";
        $script[] = "/ip firewall filter remove [find comment=\"WiFiCore PPPoE FW\"]";
        // 5. DROP all traffic from bridge (unauthenticated devices cannot pass)
        $script[] = "/ip firewall filter add chain=forward in-interface=\"{$bridgeName}\" action=drop place-before=0 comment=\"WiFiCore PPPoE FW-DROP ({$routerId})\"";
        // 4. Accept established/related from WAN (return traffic)
        $script[] = "/ip firewall filter add chain=forward in-interface-list=WAN connection-state=established,related action=accept place-before=0 comment=\"WiFiCore PPPoE FW-RETURN ({$routerId})\"";
        // 3. Allow ONLY authenticated PPPoE sessions to WAN (interface-list, NOT src-address)
        $script[] = "/ip firewall filter add chain=forward in-interface-list=PPPOE-ACTIVE out-interface-list=WAN action=accept place-before=0 comment=\"WiFiCore PPPoE FW-INET ({$routerId})\"";
        // 2. Drop invalid from authenticated PPPoE interfaces
        $script[] = "/ip firewall filter add chain=forward in-interface-list=PPPOE-ACTIVE connection-state=invalid action=drop place-before=0 comment=\"WiFiCore PPPoE FW-INV ({$routerId})\"";
        // 1. Accept established/related from authenticated PPPoE interfaces
        $script[] = "/ip firewall filter add chain=forward in-interface-list=PPPOE-ACTIVE connection-state=established,related action=accept place-before=0 comment=\"WiFiCore PPPoE FW-EST ({$routerId})\"";
        $script[] = '';
        
        // ============ NAT - SCOPED MASQUERADE (SECURITY) ============
        // Masquerade ONLY traffic from authenticated PPPoE interfaces (not subnet-based)
        // Subnet-based NAT is a security bypass: unauthorized clients can spoof src-address
        $script[] = '/ip firewall nat remove [find comment="WiFiCore PPPoE NAT"]';
        $script[] = "/ip firewall nat add chain=srcnat in-interface-list=PPPOE-ACTIVE out-interface-list=WAN action=masquerade comment=\"WiFiCore PPPoE NAT\"";
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

        // IP Pool (single-line, idempotent)
        $script[] = "/ip pool remove [find name=\"{$poolName}\"]";
        $script[] = "/ip pool add name=\"{$poolName}\" ranges=\"{$ipPool}\" comment=\"WiFiCore PPPoE pool ({$safeInterface})\"";
        $script[] = '';

        // Optional gateway IP on the access interface (single-line, scoped by comment)
        $script[] = "/ip address remove [find comment=\"WiFiCore PPPoE gateway ({$safeInterface})\"]";
        $script[] = "/ip address add address=\"{$gateway}/24\" interface=\"{$safeInterface}\" comment=\"WiFiCore PPPoE gateway ({$safeInterface})\"";
        $script[] = '';

        // PPP Profile (single-line)
        $script[] = "/ppp profile remove [find name=\"{$profileName}\"]";
        $script[] = "/ppp profile add name=\"{$profileName}\" local-address=\"{$gateway}\" remote-address=\"{$poolName}\" dns-server=\"{$dnsServers}\" use-compression=no use-encryption=no only-one=no change-tcp-mss=yes rate-limit=\"\"";
        $script[] = '';

        // PPPoE Server (RouterOS 7 property names)
        $script[] = "/interface pppoe-server server remove [find service-name=\"{$serviceName}\"]";
        $script[] = "/interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$safeInterface}\" default-profile=\"{$profileName}\" authentication=\"{$authMethods}\" one-session-per-host=yes keepalive-timeout=\"{$keepaliveTimeout}\" max-mtu=\"{$mtu}\" max-mru=\"{$mru}\" disabled=no";
        $script[] = '';

        // Add interface to LAN list (single-line)
        $script[] = "/interface list member add list=\"LAN\" interface=\"{$safeInterface}\" comment=\"WiFiCore PPPoE interface\"";
        $script[] = '';
        
        return $script;
    }
}
