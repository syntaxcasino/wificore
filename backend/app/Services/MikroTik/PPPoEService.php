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
        
        // Build configuration script
        $script = [];
        
        // Header
        $script = array_merge($script, $this->generateHeader('PPPoE Configuration', $routerId));
        
        // Interface lists
        $script = array_merge($script, $this->createInterfaceLists());
        
        // Configure each interface
        foreach ($interfaces as $interface) {
            $script = array_merge($script, $this->configurePPPoEInterface(
                $interface,
                $routerId,
                $options,
                $authMethods,
                $dnsServers
            ));
        }
        
        // RADIUS configuration
        if ($useRadius) {
            $radiusIp = $options['radius_ip'] ?? '172.20.0.6';
            $radiusSecret = $options['radius_secret'] ?? 'testing123';
            $script = array_merge($script, $this->configureRADIUS($radiusIp, $radiusSecret, 'ppp'));
        }
        
        // Firewall and NAT
        $script = array_merge($script, $this->configureMasquerade());
        
        // DNS
        $script = array_merge($script, $this->configureDNS($dnsServers));
        
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
        $serviceName = $options['service_name'] ?? "pppoe-service-$safeInterface";
        $profileName = "ppp-profile-$safeInterface-$routerId";
        $poolName = "pool-pppoe-$safeInterface-$routerId";
        $mtu = $options['mtu'] ?? '1480';
        $mru = $options['mru'] ?? '1480';
        $keepaliveTimeout = $options['keepalive_timeout'] ?? '10';
        $maxSessions = $options['max_sessions'] ?? '0'; // 0 = unlimited
        
        $script = [];
        $script[] = "# PPPoE Configuration for interface $safeInterface";
        $script[] = '';
        
        // Validate interface exists
        $script = array_merge($script, $this->generateInterfaceCheck($safeInterface));
        $script[] = '';
        
        // IP Pool
        $script[] = '# IP Pool';
        $script[] = ":local poolName \"$poolName\"";
        $script[] = ':local existingPool [/ip pool find name=$poolName]';
        $script[] = ':if ([:len $existingPool] > 0) do={';
        $script[] = '  /ip pool remove $existingPool';
        $script[] = '}';
        $script[] = "/ip pool add name=\$poolName ranges=$ipPool comment=\"PPPoE pool for $safeInterface\"";
        $script[] = ':log info "Created IP pool: $poolName"';
        $script[] = '';
        
        // IP Address on interface
        $script[] = '# IP Address';
        $script[] = ":local iface \"$safeInterface\"";
        $script[] = ':local existingIp [/ip address find interface=$iface]';
        $script[] = ':if ([:len $existingIp] > 0) do={';
        $script[] = '  /ip address remove $existingIp';
        $script[] = '}';
        $script[] = "/ip address add address=$gateway/24 interface=\$iface comment=\"PPPoE gateway\"";
        $script[] = ':log info "Configured gateway on $iface: ' . $gateway . '"';
        $script[] = '';
        
        // PPP Profile
        $script[] = '# PPP Profile';
        $script[] = ":local profileName \"$profileName\"";
        $script[] = ':local existingProfile [/ppp profile find name=$profileName]';
        $script[] = ':if ([:len $existingProfile] > 0) do={';
        $script[] = '  /ppp profile remove $existingProfile';
        $script[] = '}';
        $script[] = '';
        $script[] = '/ppp profile add name=$profileName \\';
        $script[] = "  local-address=$gateway \\";
        $script[] = '  remote-address=$poolName \\';
        $script[] = "  dns-server=\"$dnsServers\" \\";
        $script[] = '  use-radius=yes \\';
        $script[] = '  use-compression=no \\';
        $script[] = '  use-encryption=no \\';
        $script[] = "  only-one=no \\";
        $script[] = "  change-tcp-mss=yes";
        $script[] = ':log info "Created PPP profile: $profileName"';
        $script[] = '';
        
        // PPPoE Server
        $script[] = '# PPPoE Server';
        $script[] = ":local serviceName \"$serviceName\"";
        $script[] = ':local existingServer [/interface pppoe-server server find service=$serviceName]';
        $script[] = ':if ([:len $existingServer] > 0) do={';
        $script[] = '  /interface pppoe-server server remove $existingServer';
        $script[] = '}';
        $script[] = '';
        $script[] = '/interface pppoe-server server add \\';
        $script[] = '  service=$serviceName \\';
        $script[] = '  interface=$iface \\';
        $script[] = "  authentication=$authMethods \\";
        $script[] = '  default-profile=$profileName \\';
        $script[] = '  one-session-per-host=yes \\';
        $script[] = "  max-sessions=$maxSessions \\";
        $script[] = "  max-mtu=$mtu \\";
        $script[] = "  max-mru=$mru \\";
        $script[] = "  keepalive-timeout=$keepaliveTimeout \\";
        $script[] = '  disabled=no';
        $script[] = ':log info "Created PPPoE server: $serviceName on $iface"';
        $script[] = '';
        
        // Add interface to LAN list
        $script[] = '# Add to LAN list';
        $script[] = ':if ([/interface list member find list=LAN interface=$iface] = "") do={';
        $script[] = '  /interface list member add list=LAN interface=$iface comment="PPPoE interface"';
        $script[] = '}';
        $script[] = '';
        
        return $script;
    }
}
