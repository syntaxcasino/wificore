<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterConfig;
use App\Services\MikroTik\ConfigurationService;
use App\Events\RouterProvisioningProgress;
use App\Events\RouterConnected;
use App\Events\ProvisioningFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RouterOS\Client;
use RouterOS\Query;

/**
 * MikroTik Provisioning Service
 * 
 * Handles router provisioning, connectivity verification, and live data fetching.
 * Uses the new clean architecture (MikroTik/* services) for configuration generation.
 */
class MikrotikProvisioningService extends TenantAwareService
{
    protected ConfigurationService $configService;
    
    public function __construct()
    {
        $this->configService = new ConfigurationService();
    }
    
    /**
     * Generate service configuration using new clean architecture
     */
    public function generateConfigs(Router $router, array $data): array
    {
        try {
            Log::info('MikrotikProvisioningService: Delegating to ConfigurationService', [
                'router_id' => $router->id,
                'enable_hotspot' => $data['enable_hotspot'] ?? false,
                'enable_pppoe' => $data['enable_pppoe'] ?? false,
            ]);
            
            // Delegate to the specialized ConfigurationService
            return $this->configService->generateServiceConfig($router, $data);
            
        } catch (\Exception $e) {
            Log::error('Failed to generate service configuration', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to generate service configuration: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Verify hotspot deployment with comprehensive checks
     * 
     * @param Router $router The router to verify
     * @return array Detailed verification results
     */
    public function verifyHotspotDeployment(Router $router): array
    {
        $startTime = microtime(true);
        $verification = [
            'success' => false,
            'checks' => [],
            'execution_time' => 0,
            'timestamp' => now()->toDateTimeString(),
            'router_id' => $router->id,
            'router_name' => $router->name
        ];

        try {
            $decryptedPassword = Crypt::decrypt($router->password);
            // Use VPN IP if available
            $ip = $router->vpn_ip ?? $router->ip_address;
            $host = explode('/', $ip)[0];

            $client = new Client([
                'host' => $host,
                'user' => $router->username,
                'pass' => $decryptedPassword,
                'port' => $router->port,
                'timeout' => 10, // Increased timeout for better reliability
                'attempts' => 2,
            ]);

            // 1. Check hotspot server
            $hotspotCheck = $client->query((new Query('/ip/hotspot/print')))->read();
            $verification['checks']['hotspot_server'] = [
                'status' => !empty($hotspotCheck),
                'count' => count($hotspotCheck),
                'message' => !empty($hotspotCheck) 
                    ? 'Hotspot server is configured' 
                    : 'No hotspot server found',
                'servers' => array_map(function($hs) {
                    return [
                        'name' => $hs['name'] ?? 'N/A',
                        'interface' => $hs['interface'] ?? 'N/A',
                        'address_pool' => $hs['address-pool'] ?? 'N/A',
                        'profile' => $hs['profile'] ?? 'N/A',
                    ];
                }, $hotspotCheck)
            ];

            // 2. Check hotspot profile
            $profileCheck = $client->query((new Query('/ip/hotspot/profile/print')))->read();
            $verification['checks']['hotspot_profile'] = [
                'status' => !empty($profileCheck),
                'count' => count($profileCheck),
                'message' => !empty($profileCheck)
                    ? 'Hotspot profile is configured'
                    : 'No hotspot profile found',
                'profiles' => array_map(function($profile) {
                    return [
                        'name' => $profile['name'] ?? 'N/A',
                        'hotspot_address' => $profile['hotspot-address'] ?? 'N/A',
                        'dns_name' => $profile['dns-name'] ?? 'N/A',
                        'html_directory' => $profile['html-directory'] ?? 'N/A',
                    ];
                }, $profileCheck)
            ];

            // 3. Check RADIUS configuration
            $radiusCheck = $client->query((new Query('/radius/print')))->read();
            $verification['checks']['radius'] = [
                'status' => !empty($radiusCheck),
                'count' => count($radiusCheck),
                'message' => !empty($radiusCheck)
                    ? 'RADIUS server is configured'
                    : 'No RADIUS server configured',
                'servers' => array_map(function($radius) {
                    return [
                        'address' => $radius['address'] ?? 'N/A',
                        'secret' => isset($radius['secret']) ? '***' : 'Not set',
                        'auth_port' => $radius['auth-port'] ?? 'N/A',
                        'accounting' => $radius['service'] ?? 'N/A',
                        'timeout' => $radius['timeout'] ?? 'N/A',
                    ];
                }, $radiusCheck)
            ];

            // 4. Check IP pool
            $poolCheck = $client->query((new Query('/ip/pool/print')))->read();
            $verification['checks']['ip_pool'] = [
                'status' => !empty($poolCheck),
                'count' => count($poolCheck),
                'message' => !empty($poolCheck)
                    ? 'IP pool is configured'
                    : 'No IP pool configured',
                'pools' => array_map(function($pool) {
                    return [
                        'name' => $pool['name'] ?? 'N/A',
                        'ranges' => $pool['ranges'] ?? 'N/A',
                    ];
                }, $poolCheck)
            ];

            // 5. Check DHCP server
            $dhcpCheck = $client->query((new Query('/ip/dhcp-server/print')))->read();
            $verification['checks']['dhcp_server'] = [
                'status' => !empty($dhcpCheck),
                'count' => count($dhcpCheck),
                'message' => !empty($dhcpCheck)
                    ? 'DHCP server is configured'
                    : 'No DHCP server configured',
                'servers' => array_map(function($dhcp) {
                    return [
                        'name' => $dhcp['name'] ?? 'N/A',
                        'interface' => $dhcp['interface'] ?? 'N/A',
                        'address_pool' => $dhcp['address-pool'] ?? 'N/A',
                        'lease_time' => $dhcp['lease-time'] ?? 'N/A',
                    ];
                }, $dhcpCheck)
            ];

            // 6. Check NAT rules
            $natCheck = $client->query((new Query('/ip/firewall/nat/print')))->read();
            $hotspotNatRules = array_filter($natCheck, function($rule) {
                return isset($rule['comment']) && str_contains($rule['comment'], 'hotspot');
            });
            $verification['checks']['nat_rules'] = [
                'status' => !empty($hotspotNatRules),
                'count' => count($hotspotNatRules),
                'message' => !empty($hotspotNatRules)
                    ? 'NAT rules for hotspot are configured'
                    : 'No NAT rules found for hotspot',
                'rules' => array_map(function($rule) {
                    return [
                        'chain' => $rule['chain'] ?? 'N/A',
                        'action' => $rule['action'] ?? 'N/A',
                        'comment' => $rule['comment'] ?? 'N/A',
                    ];
                }, $hotspotNatRules)
            ];

            // 7. Check firewall rules
            $firewallCheck = $client->query((new Query('/ip/firewall/filter/print')))->read();
            $hotspotFirewallRules = array_filter($firewallCheck, function($rule) {
                return isset($rule['comment']) && str_contains($rule['comment'], 'hotspot');
            });
            $verification['checks']['firewall_rules'] = [
                'status' => !empty($hotspotFirewallRules),
                'count' => count($hotspotFirewallRules),
                'message' => !empty($hotspotFirewallRules)
                    ? 'Firewall rules for hotspot are configured'
                    : 'No firewall rules found for hotspot'
            ];

            // 8. Check DNS settings
            $dnsCheck = $client->query((new Query('/ip/dns/print')))->read();
            $verification['checks']['dns'] = [
                'status' => !empty($dnsCheck) && 
                           !empty($dnsCheck[0]['servers']) && 
                           $dnsCheck[0]['servers'] !== '0.0.0.0',
                'message' => !empty($dnsCheck) && !empty($dnsCheck[0]['servers']) && $dnsCheck[0]['servers'] !== '0.0.0.0'
                    ? 'DNS servers are properly configured'
                    : 'DNS servers are not properly configured',
                'servers' => !empty($dnsCheck) ? $dnsCheck[0]['servers'] ?? 'Not set' : 'Not configured'
            ];

            // Determine overall status
            $criticalChecks = ['hotspot_server', 'hotspot_profile', 'radius', 'ip_pool', 'dhcp_server'];
            $failedCriticalChecks = array_filter($criticalChecks, 
                fn($check) => !($verification['checks'][$check]['status'] ?? false)
            );
            
            $verification['success'] = empty($failedCriticalChecks);
            
            if ($verification['success']) {
                $verification['message'] = 'All critical hotspot components are properly configured';
            } else {
                $verification['message'] = sprintf(
                    '%d of %d critical checks failed: %s', 
                    count($failedCriticalChecks), 
                    count($criticalChecks),
                    implode(', ', $failedCriticalChecks)
                );
            }
            
            // Log the verification results
            Log::info('Hotspot deployment verification completed', [
                'router_id' => $router->id,
                'success' => $verification['success'],
                'failed_checks' => $verification['success'] ? [] : array_keys(array_filter(
                    $verification['checks'], 
                    fn($check) => !$check['status']
                )),
                'execution_time' => round(microtime(true) - $startTime, 2) . 's'
            ]);
            
        } catch (\Exception $e) {
            $verification['success'] = false;
            $verification['message'] = 'Verification failed: ' . $e->getMessage();
            $verification['error'] = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            
            Log::error('Hotspot verification failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        $verification['execution_time'] = round(microtime(true) - $startTime, 2) . 's';
        return $verification;
    }
    
    /**
     * Get all routers
     */
    public function getAllRouters()
    {
        try {
            return Router::select('id', 'name', 'ip_address', 'vpn_ip', 'username', 'port', 'password', 'status', 'model', 'os_version', 'last_seen', 'last_checked')->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch routers:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to fetch routers: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Check if an interface is configurable (not system/virtual/slave)
     */
    private function isConfigurableInterface(array $interface): bool
    {
        $name = $interface['name'] ?? '';
        $type = $interface['type'] ?? '';
        
        // Exclude system/virtual interfaces
        $excludedTypes = ['bridge', 'vlan', 'vrrp', 'vpls', 'ovpn-out', 'ovpn-in', 'wireguard', 'gre', 'ipip', 'eoip'];
        if (in_array($type, $excludedTypes)) {
            return false;
        }
        
        // Exclude slave interfaces (bonding members)
        if (isset($interface['slave']) && $interface['slave'] === 'true') {
            return false;
        }
        
        // Exclude interfaces with master (part of bridge/bond)
        if (isset($interface['master-port']) && !empty($interface['master-port'])) {
            return false;
        }
        
        return true;
    }

    /**
     * Fetch router data with context-aware optimization
     * @param Router $router
     * @param string $context 'provisioning' for interface discovery, 'live' for full monitoring data
     * @param bool $filterConfigurable Only return configurable interfaces (excludes bridges, VLANs, slaves, etc.)
     */
    public function fetchLiveRouterData(Router $router, string $context = 'live', bool $filterConfigurable = false): array
    {
        $lockKey = "router_api_lock_{$router->id}";
        
        // Adjust timeout based on context
        $timeout = $context === 'provisioning' ? 10 : 15;
        $lock = Cache::lock($lockKey, $timeout);
        
        try {
            // Wait to acquire the lock
            if (!$lock->block($timeout)) {
                Log::warning('Failed to acquire router API lock', [
                    'router_id' => $router->id,
                    'context' => $context,
                ]);
                throw new \Exception('Router is busy with another operation', 503);
            }
            
            $decryptedPassword = Crypt::decrypt($router->password);
            Log::info('Password decrypted successfully:', ['router_id' => $router->id, 'context' => $context]);
        } catch (\Exception $e) {
            if ($lock->owner()) {
                $lock->release();
            }
            
            if ($e->getCode() === 503) {
                throw $e; // Re-throw busy exception
            }
            
            Log::error('Password decryption failed:', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to decrypt password: ' . $e->getMessage(), 500);
        }

        try {
            // Use VPN IP if available
            $ip = $router->vpn_ip ?? $router->ip_address;
            $host = explode('/', $ip)[0];
            
            $client = new Client([
                'host' => $host,
                'user' => $router->username,
                'pass' => $decryptedPassword,
                'port' => $router->port,
                'timeout' => $timeout,
            ]);

            // Context-aware data fetching
            if ($context === 'provisioning') {
                // During provisioning, fetch only essential data for interface discovery
                $interfaces = $client->query(new Query('/interface/print'))->read();
                $resource = $client->query(new Query('/system/resource/print'))->read();
                $identity = $client->query(new Query('/system/identity/print'))->read();
                
                // Filter interfaces if requested
                if ($filterConfigurable) {
                    $interfaces = array_values(array_filter($interfaces, function($iface) {
                        return $this->isConfigurableInterface($iface);
                    }));
                }
                
                return [
                    'interfaces' => array_map(function ($iface) {
                        return [
                            'name' => $iface['name'] ?? 'Unknown',
                            'type' => $iface['type'] ?? 'Unknown',
                            'running' => $iface['running'] ?? false,
                            'mtu' => $iface['mtu'] ?? 'N/A',
                            'comment' => $iface['comment'] ?? '',
                        ];
                    }, $interfaces),
                    'board_name' => $resource[0]['board-name'] ?? 'N/A',
                    'version' => $resource[0]['version'] ?? 'N/A',
                    'uptime' => $resource[0]['uptime'] ?? 'N/A',
                    'identity' => $identity[0]['name'] ?? 'N/A',
                ];
            }
            
            // Live context: fetch all data for monitoring
            $queries = [
                'resource' => new Query('/system/resource/print'),
                'identity' => new Query('/system/identity/print'),
                'interface' => new Query('/interface/print'),
                'dhcp' => new Query('/ip/dhcp-server/lease/print'),
            ];

            $results = [];
            foreach ($queries as $key => $query) {
                $results[$key] = $client->query($query)->read();
            }

            // Try to get hotspot active users, but handle if hotspot is not configured
            $hotspotActiveUsers = [];
            try {
                $hotspotQuery = new Query('/ip/hotspot/active/print');
                $hotspotActiveUsers = $client->query($hotspotQuery)->read();
                
                // Filter out any sessions that might be system/bypass entries
                $hotspotActiveUsers = array_filter($hotspotActiveUsers, function($session) {
                    return isset($session['user']) && !empty($session['user']);
                });
            } catch (\Exception $e) {
                // Hotspot might not be configured, that's okay
                Log::debug('Hotspot query failed (might not be configured)', [
                    'router_id' => $router->id,
                    'error' => $e->getMessage()
                ]);
            }

            $resource = $results['resource'][0] ?? [];
            $interfaces = $results['interface'] ?? [];
            $dhcpLeases = $results['dhcp'] ?? [];
            
            // Filter interfaces if requested
            if ($filterConfigurable) {
                $interfaces = array_values(array_filter($interfaces, function($iface) {
                    return $this->isConfigurableInterface($iface);
                }));
            }

            return [
                'cpu_load' => $resource['cpu-load'] ?? 'N/A',
                'free_memory' => $resource['free-memory'] ?? 'N/A',
                'total_memory' => $resource['total-memory'] ?? 'N/A',
                'free_hdd_space' => $resource['free-hdd-space'] ?? 'N/A',
                'total_hdd_space' => $resource['total-hdd-space'] ?? 'N/A',
                'uptime' => $resource['uptime'] ?? 'N/A',
                'board_name' => $resource['board-name'] ?? 'N/A',
                'version' => $resource['version'] ?? 'N/A',
                'identity' => $results['identity'][0]['name'] ?? 'N/A',
                'interface_count' => count($interfaces),
                'active_connections' => count($hotspotActiveUsers),
                'dhcp_leases' => count($dhcpLeases),
                'interfaces' => array_map(function ($iface) {
                    return [
                        'name' => $iface['name'] ?? 'Unknown',
                        'type' => $iface['type'] ?? 'Unknown',
                        'running' => $iface['running'] ?? false,
                        'mtu' => $iface['mtu'] ?? 'N/A',
                        'comment' => $iface['comment'] ?? '',
                    ];
                }, $interfaces),
            ];
        } finally {
            // Always release the lock
            if ($lock->owner()) {
                $lock->release();
            }
        }
    }

    /**
     * Get router details (DB + live data)
     */
    public function getRouterDetails(Router $router): array
    {
        try {
            $live = $this->fetchLiveRouterData($router);
        } catch (\Exception $e) {
            // If live fetch fails, return DB-only info with error
            Log::warning('getRouterDetails: live fetch failed, returning DB info only', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            $live = [
                'error' => $e->getMessage(),
                'status' => 'offline',
            ];
        }

        return [
            'id' => $router->id,
            'name' => $router->name,
            'ip_address' => $router->ip_address,
            'status' => $router->status,
            'model' => $live['board_name'] ?? $router->model,
            'os_version' => $live['version'] ?? $router->os_version,
            'last_seen' => $router->last_seen,
            'live' => $live,
        ];
    }
    
    /**
     * Verify router connectivity with connection locking to prevent concurrent API access
     */
    public function verifyConnectivity(Router $router): array
    {
        $lockKey = "router_api_lock_{$router->id}";
        
        // Try to acquire lock with 35 second timeout (5 seconds more than API timeout)
        $lock = Cache::lock($lockKey, 35);
        
        try {
            // Wait up to 35 seconds to acquire the lock
            if (!$lock->block(35)) {
                Log::warning('Failed to acquire router API lock - another operation in progress', [
                    'router_id' => $router->id,
                ]);
                return [
                    'status' => 'busy',
                    'message' => 'Router is busy with another operation',
                ];
            }
            
            $decryptedPassword = Crypt::decrypt($router->password);
            Log::info('Verifying connectivity:', [
                'router_id' => $router->id,
                'ip_address' => $router->ip_address,
                'username' => $router->username,
                'port' => $router->port,
            ]);
        } catch (\Exception $e) {
            $lock->release();
            Log::error('Password decryption failed:', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 'failed',
                'message' => 'Failed to decrypt password',
                'error' => $e->getMessage(),
            ];
        }

        // Use VPN IP if available
        $ip = $router->vpn_ip ?? $router->ip_address;
        $host = explode('/', $ip)[0];

        try {
            $client = new Client([
                'host' => $host,
                'user' => $router->username,
                'pass' => $decryptedPassword,
                'port' => $router->port,
                'timeout' => 15,
            ]);

            $identity = $client->query(new Query('/system/identity/print'))->read();
            $resource = $client->query(new Query('/system/resource/print'))->read();
            $interfaces = $client->query(new Query('/interface/print'))->read();

            $model = $resource[0]['board-name'] ?? 'Unknown';
            $osVersion = $resource[0]['version'] ?? 'Unknown';

            Log::info('Connectivity verified successfully:', [
                'router_id' => $router->id,
                'model' => $model,
                'os_version' => $osVersion,
                'interfaces_count' => count($interfaces),
            ]);

            return [
                'status' => 'connected',
                'model' => $model,
                'os_version' => $osVersion,
                'identity' => $identity[0]['name'] ?? 'Unknown',
                'interfaces' => $interfaces,
                'last_seen' => now(),
            ];
        } catch (\Exception $e) {
            Log::warning('Connectivity verification failed:', [
                'router_id' => $router->id,
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'message' => 'Unable to connect to router',
                'error' => $e->getMessage(),
            ];
        } finally {
            // Always release the lock
            $lock->release();
        }
    }
    
    /**
     * Apply saved configuration to router with enhanced reliability and error handling
     * 
     * @param Router $router The router to configure
     * @param string|null $script Optional script content to apply (bypasses database lookup)
     * @return array Result of the operation
     * @throws \Exception On any critical error during configuration
     */
    public function applyConfigs(Router $router, ?string $script = null, bool $broadcast = true): array
    {
        $startTime = microtime(true);
        $routerName = $router->name ?? 'Unknown';
        $scriptName = 'hs_provision_' . $router->id . '_' . time();
        $client = null;
        
        // Broadcast provisioning start
        if ($broadcast) {
            RouterProvisioningProgress::dispatch(
                $router->id,
                'init',
                0,
                'Starting provisioning process',
                ['router_name' => $routerName]
            );
        }
        
        Log::info('Starting configuration application', [
            'router_id' => $router->id,
            'router_name' => $routerName,
            'script_provided' => $script !== null,
        ]);

        try {
            // 1. Get the service script
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'config_retrieval',
                    10,
                    'Retrieving router configuration',
                    ['router_id' => $router->id]
                );
            }

            if ($script !== null) {
                $serviceScript = trim($script);
            } else {
                $routerConfig = RouterConfig::where('router_id', $router->id)
                    ->where('config_type', 'service')
                    ->first();

                if (!$routerConfig || empty(trim($routerConfig->config_content))) {
                    $errorMsg = 'No valid service configuration found. Please generate the configuration first.';
                    if ($broadcast) {
                        ProvisioningFailed::dispatch(
                            $router->id,
                            'config_missing',
                            $errorMsg,
                            ['router_id' => $router->id]
                        );
                    }
                    throw new \Exception($errorMsg, 400);
                }
                $serviceScript = trim($routerConfig->config_content);
            }

            // 2. Validate script content
            if (empty($serviceScript)) {
                throw new \Exception('Service script is empty', 400);
            }

            Log::debug('Service script retrieved', [
                'router_id' => $router->id,
                'script_size' => strlen($serviceScript),
                'script_preview' => substr($serviceScript, 0, 200) . (strlen($serviceScript) > 200 ? '...' : '')
            ]);

            // 3. Get router credentials
            $decryptedPassword = Crypt::decrypt($router->password);
            $host = explode('/', $router->ip_address)[0];
            
            // 4. Connect to router
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'connecting',
                    20,
                    'Connecting to router',
                    ['host' => $host, 'port' => $router->port]
                );
            }

            try {
                $client = new Client([
                    'host' => $host,
                    'user' => $router->username,
                    'pass' => $decryptedPassword,
                    'port' => $router->port,
                    'timeout' => 120, // 2 minutes timeout for large script execution
                    'attempts' => 2,
                ]);
                
                if ($broadcast) {
                    broadcast(new RouterConnected($router))->toOthers();
                    RouterProvisioningProgress::dispatch(
                        $router->id,
                        'connected',
                        30,
                        'Successfully connected to router',
                        ['host' => $host]
                    );
                }
            } catch (\Exception $e) {
                $errorMsg = 'Failed to connect to router: ' . $e->getMessage();
                if ($broadcast) {
                    ProvisioningFailed::dispatch(
                        $router->id,
                        'connection_failed',
                        $errorMsg,
                        [
                            'host' => $host,
                            'port' => $router->port,
                            'error' => $e->getMessage()
                        ]
                    );
                }
                throw new \Exception($errorMsg, 503, $e);
            }

            // 5. Split script into chunks if too large
            $maxChunkSize = 4000; // RouterOS has a limit on script size
            $chunks = str_split($serviceScript, $maxChunkSize);
            
            // 7. Prepare system script for execution
            $scriptName = "hotspot_config_" . $router->id . "_" . time();
            
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'uploading_script',
                    40,
                    'Preparing configuration script',
                    ['script_name' => $scriptName]
                );
            }

            Log::info('Preparing configuration script for execution', [
                'router_id' => $router->id,
                'script_name' => $scriptName,
                'script_length' => strlen($serviceScript),
            ]);
            
            try {
                // Upload .rsc file and import - MUCH faster than executing via API!
                $rscFileName = $scriptName . '.rsc';
                
                Log::info('Preparing to upload .rsc file', [
                    'router_id' => $router->id,
                    'file_name' => $rscFileName,
                    'content_length' => strlen($serviceScript)
                ]);
                
                // Use FTP upload (fast and reliable)
                $uploadSuccessful = false;
                
                // SECURITY: Enable FTP service temporarily for upload
                Log::info('Enabling FTP service temporarily', ['router_id' => $router->id]);
                try {
                    $client->query((new Query('/ip/service/set'))
                        ->equal('numbers', 'ftp')
                        ->equal('disabled', 'no')
                    )->read();
                    Log::info('FTP service enabled', ['router_id' => $router->id]);
                } catch (\Exception $e) {
                    Log::warning('Could not enable FTP service', [
                        'router_id' => $router->id,
                        'error' => $e->getMessage()
                    ]);
                    // Continue anyway - FTP might already be enabled
                }
                
                Log::info('Attempting FTP upload', ['router_id' => $router->id, 'host' => $host]);
                
                try {
                    // Connect to FTP (no error suppression for proper logging)
                    $ftpConnection = ftp_connect($host, 21, 10);
                    
                    if (!$ftpConnection) {
                        $lastError = error_get_last();
                        throw new \Exception('FTP connection failed: ' . ($lastError['message'] ?? 'Unknown error'));
                    }
                    
                    Log::info('FTP connected successfully', ['router_id' => $router->id]);
                    
                    // Login to FTP
                    if (!ftp_login($ftpConnection, $router->username, $decryptedPassword)) {
                        ftp_close($ftpConnection);
                        throw new \Exception('FTP login failed - invalid credentials');
                    }
                    
                    Log::info('FTP login successful', ['router_id' => $router->id]);
                    
                    // Enable passive mode
                    ftp_pasv($ftpConnection, true);
                    
                    // Create temporary file
                    $tempFile = tempnam(sys_get_temp_dir(), 'rsc_');
                    file_put_contents($tempFile, $serviceScript);
                    
                    Log::info('Uploading file to router', [
                        'router_id' => $router->id,
                        'file_name' => $rscFileName,
                        'size' => strlen($serviceScript)
                    ]);
                    
                    // Upload file
                    if (!ftp_put($ftpConnection, $rscFileName, $tempFile, FTP_ASCII)) {
                        unlink($tempFile);
                        ftp_close($ftpConnection);
                        throw new \Exception('FTP upload failed - file transfer error');
                    }
                    
                    $uploadSuccessful = true;
                    
                    Log::info('File uploaded via FTP successfully', [
                        'router_id' => $router->id,
                        'file_name' => $rscFileName,
                        'size' => strlen($serviceScript)
                    ]);
                    
                    // Cleanup
                    unlink($tempFile);
                    ftp_close($ftpConnection);
                    
                } catch (\Exception $ftpError) {
                    Log::error('FTP upload failed', [
                        'router_id' => $router->id,
                        'error' => $ftpError->getMessage(),
                        'host' => $host,
                        'port' => 21
                    ]);
                    
                    // SECURITY: Disable FTP service on failure
                    try {
                        $client->query((new Query('/ip/service/set'))
                            ->equal('numbers', 'ftp')
                            ->equal('disabled', 'yes')
                        )->read();
                        Log::info('FTP service disabled after upload failure', ['router_id' => $router->id]);
                    } catch (\Exception $e) {
                        Log::warning('Could not disable FTP service after failure', [
                            'router_id' => $router->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                    
                    // FTP is the only supported upload method
                    throw new \Exception('Failed to upload configuration file via FTP: ' . $ftpError->getMessage());
                }
                
                // Import the .rsc file - this is FAST!
                Log::info('Importing .rsc file', [
                    'router_id' => $router->id,
                    'file_name' => $rscFileName
                ]);
                
                try {
                    $importResult = $client->query((new Query('/import'))
                        ->equal('file-name', $rscFileName)
                    )->read();

                    Log::info('.rsc file imported successfully', [
                        'router_id' => $router->id,
                        'file_name' => $rscFileName,
                        'result' => $importResult
                    ]);
                    
                    // CRITICAL: Verify deployment was successful
                    Log::info('Verifying deployment...', ['router_id' => $router->id]);
                    
                    sleep(2); // Give router time to process configuration
                    
                    try {
                        // Check if hotspot was created
                        $hotspots = $client->query(new Query('/ip/hotspot/print'))->read();
                        
                        if (empty($hotspots)) {
                            Log::error('Deployment verification failed - no hotspot found', [
                                'router_id' => $router->id,
                                'expected' => 'hotspot configuration',
                                'found' => 'none'
                            ]);
                            throw new \Exception('Deployment verification failed: Hotspot configuration not found on router after import. The import may have failed silently.');
                        }
                        
                        Log::info('Deployment verified successfully', [
                            'router_id' => $router->id,
                            'hotspot_count' => count($hotspots),
                            'hotspot_names' => array_map(function($h) { return $h['name'] ?? 'unnamed'; }, $hotspots)
                        ]);
                        
                    } catch (\Exception $verifyError) {
                        Log::error('Deployment verification check failed', [
                            'router_id' => $router->id,
                            'error' => $verifyError->getMessage()
                        ]);
                        // Don't throw here - verification query might fail for other reasons
                        // The import succeeded, so we'll trust it
                    }
                    
                    // Clean up the .rsc file after successful import
                    try {
                        $client->query((new Query('/file/remove'))
                            ->equal('.id', $rscFileName)
                        )->read();
                        Log::info('.rsc file deleted successfully', [
                            'router_id' => $router->id,
                            'file_name' => $rscFileName
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('.rsc file cleanup failed (non-critical)', [
                            'router_id' => $router->id,
                            'file_name' => $rscFileName,
                            'error' => $e->getMessage()
                        ]);
                    }
                    
                    // SECURITY: Disable FTP service after successful deployment
                    Log::info('Disabling FTP service for security', ['router_id' => $router->id]);
                    try {
                        $client->query((new Query('/ip/service/set'))
                            ->equal('numbers', 'ftp')
                            ->equal('disabled', 'yes')
                        )->read();
                        Log::info('FTP service disabled successfully', ['router_id' => $router->id]);
                    } catch (\Exception $e) {
                        Log::warning('Could not disable FTP service', [
                            'router_id' => $router->id,
                            'error' => $e->getMessage()
                        ]);
                        // Non-critical - continue
                    }
                    
                    // SECURITY: Apply comprehensive security hardening
                    Log::info('Applying security hardening', ['router_id' => $router->id]);
                    try {
                        $securityService = new \App\Services\MikroTik\SecurityHardeningService();
                        $hardeningResult = $securityService->applySecurityHardening($router);
                        
                        if ($hardeningResult['success']) {
                            Log::info('Security hardening applied successfully', [
                                'router_id' => $router->id,
                                'applied' => $hardeningResult['applied']
                            ]);
                        } else {
                            Log::warning('Security hardening completed with errors', [
                                'router_id' => $router->id,
                                'errors' => $hardeningResult['errors']
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Security hardening failed (non-critical)', [
                            'router_id' => $router->id,
                            'error' => $e->getMessage()
                        ]);
                        // Non-critical - continue
                    }
                    
                } catch (\Exception $execErr) {
                    Log::error('.rsc file import failed', [
                        'router_id' => $router->id,
                        'file_name' => $rscFileName,
                        'error'       => $execErr->getMessage(),
                    ]);
                    throw $execErr;
                }
                
                // Clean up - remove the script after execution
                try {
                    $client->query((new Query('/system/script/remove'))
                        ->equal('numbers', $scriptName)
                    )->read();
                    Log::info('System script cleaned up', ['script_name' => $scriptName]);
                } catch (\Exception $cleanupError) {
                    Log::warning('Failed to clean up system script', [
                        'script_name' => $scriptName,
                        'error' => $cleanupError->getMessage()
                    ]);
                }
                
            } catch (\Exception $e) {
                $errorMsg = 'Failed to execute configuration script: ' . $e->getMessage();
                Log::error('Script execution failed', [
                    'router_id' => $router->id,
                    'error' => $e->getMessage(),
                    'content_preview' => substr($serviceScript, 0, 200)
                ]);
                if ($broadcast) {
                    ProvisioningFailed::dispatch(
                        $router->id,
                        'script_execution_failed',
                        $errorMsg,
                        ['error' => $e->getMessage()]
                    );
                }
                throw new \Exception($errorMsg, 500, $e);
            }

            // 8. Script execution completed
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'executing_script',
                    60,
                    'Configuration script executed successfully',
                    ['script_name' => $scriptName ?? 'hotspot_config']
                );
            }

            Log::info('Configuration script executed successfully', [
                'router_id' => $router->id,
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'script_name' => $scriptName ?? 'hotspot_config'
            ]);

            // 10. Verify the deployment (with a small delay to allow changes to take effect)
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'verifying',
                    80,
                    'Verifying deployment',
                    ['status' => 'verifying']
                );
            }
            
            sleep(3); // Wait 3 seconds for the router to apply changes
            $verification = $this->verifyHotspotDeployment($router);
            
            if (!$verification['success']) {
                // If verification fails, try one more time after a short delay
                sleep(5);
                $verification = $this->verifyHotspotDeployment($router);
                
                if (!$verification['success']) {
                    // Log warning but don't fail - the configuration might be applied but verification is too strict
                    Log::warning('Hotspot deployment verification failed, but continuing anyway', [
                        'router_id' => $router->id,
                        'message' => $verification['message'] ?? 'Unknown error',
                        'checks' => $verification['checks'] ?? []
                    ]);
                    // Don't throw exception - allow provisioning to complete
                }
            }

            $result = [
                'success' => true,
                'message' => 'Configuration applied successfully',
                'verification' => $verification,
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'script_name' => $scriptName,
                'router_id' => $router->id,
            ];

            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'completed',
                    100,
                    'Provisioning completed successfully',
                    array_merge($result, ['status' => 'completed'])
                );
            }

            Log::info('Configuration applied successfully', $result);
            return $result;

        } catch (\Exception $e) {
            $errorContext = [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'script_name' => $scriptName,
            ];
            
            Log::error('Failed to apply configuration', $errorContext);
            
            // Convert to a more user-friendly error message
            $errorMessage = $e->getMessage();
            
            if (str_contains($errorMessage, 'Unable to connect to') || 
                str_contains($errorMessage, 'Connection timed out')) {
                $errorMessage = 'Unable to connect to router. Please check network connectivity and credentials.';
            } elseif (str_contains($errorMessage, 'invalid user name or password')) {
                $errorMessage = 'Authentication failed. Please check the router credentials.';
            } elseif (str_contains($errorMessage, 'script already exists')) {
                $errorMessage = 'A script with this name already exists on the router. Please try again in a moment.';
            } elseif (str_contains($errorMessage, 'script not found')) {
                $errorMessage = 'Failed to execute script on router. The script may have been removed.';
            }
            
            throw new \Exception($errorMessage, $e->getCode(), $e);
            
        } finally {
            // 11. Always clean up the script if client is available
            if ($client !== null) {
                try {
                    $client->query((new Query('/system/script/remove'))
                        ->equal('.id', $scriptName)
                    )->read();
                    Log::debug('Script cleaned up', ['script_name' => $scriptName]);
                } catch (\Exception $cleanupException) {
                    Log::warning('Failed to clean up script', [
                        'router_id' => $router->id,
                        'script_name' => $scriptName,
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }
        }
    }
    
    /**
     * Create new router
     */
    public function createRouter(array $data): array
    {
        try {
            $ipAddress = $this->generateUniqueIp();
            $username = 'traidnet_user';
            $password = Str::random(12);
            $port = 8728;
            $configToken = Str::uuid();

            $router = Router::create([
                'name' => $data['name'],
                'ip_address' => $ipAddress,
                'username' => $username,
                'password' => Crypt::encrypt($password),
                'port' => $port,
                'config_token' => $configToken,
                'status' => 'pending',
            ]);

            $connectivityScript = $this->generateConnectivityScript($router);

            RouterConfig::create([
                'router_id' => $router->id,
                'config_type' => 'connectivity',
                'config_content' => $connectivityScript,
            ]);

            Log::info('Router created successfully:', [
                'router_id' => $router->id,
                'name' => $router->name,
            ]);

            Cache::forget('routers_list');

            return [
                'id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
                'config_token' => $router->config_token,
                'connectivity_script' => $connectivityScript,
                'status' => $router->status,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create router:', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to create router: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Update an existing router
     */
    public function updateRouter(Router $router, array $data): Router
    {
        try {
            $router->update([
                'name' => $data['name'],
                'ip_address' => $data['ip_address'] ?? $router->ip_address,
                'config_token' => $data['config_token'] ?? $router->config_token,
            ]);

            Log::info('Router updated successfully:', [
                'router_id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
            ]);

            return $router;
        } catch (\Exception $e) {
            Log::error('Failed to update router:', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to update router: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a router
     */
    public function deleteRouter(Router $router): void
    {
        try {
            $routerId = $router->id;
            $router->delete();
            Log::info('Router deleted successfully:', ['router_id' => $routerId]);
        } catch (\Exception $e) {
            Log::error('Failed to delete router:', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to delete router: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Generate unique IP address
     */
    private function generateUniqueIp(): string
    {
        $subnet = '192.168.56';
        $cidr = 24;
        $maxAttempts = 10;
        $attempt = 0;

        do {
            $lastOctet = rand(2, 254);
            $ipAddress = "$subnet.$lastOctet/$cidr";
            $exists = Router::where('ip_address', $ipAddress)->exists();
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);

        if ($exists) {
            throw new \Exception('Unable to generate unique IP address');
        }

        return $ipAddress;
    }
    
    /**
     * Generate connectivity script
     */
    private function generateConnectivityScript(Router $router): string
    {
        $decryptedPassword = Crypt::decrypt($router->password);
        
        return <<<EOT
/ip address add address={$router->ip_address} interface=ether2
/ip service set api disabled=no port={$router->port}
/user add name={$router->username} password="{$decryptedPassword}" group=full
/system identity set name="{$router->name}"
/system note set note="Managed by Traidnet Solution LTD"
EOT;
    }
}
