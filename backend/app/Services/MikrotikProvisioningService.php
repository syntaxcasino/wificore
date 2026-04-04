<?php

namespace App\Services;

use App\Events\ProvisioningFailed;
use App\Events\RouterConnected;
use App\Events\RouterProvisioningProgress;
use App\Models\Router;
use App\Models\RouterConfig;
use App\Models\RouterService;
use App\Services\MikroTik\ConfigurationService;
use App\Services\MikroTik\RscFileCleanupService;
use App\Services\MikroTik\SshExecutor;
use App\Services\RouterResourceManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MikroTik Provisioning Service
 * 
 * Handles router provisioning, connectivity verification, and live data fetching.
 * Uses the new clean architecture (MikroTik/* services) for configuration generation.
 * 
 * Network Segmentation: Supports routing operations through provisioning service
 * for enhanced security. Enable with USE_PROVISIONING_SERVICE=true
 */
class MikrotikProvisioningService extends TenantAwareService
{
    protected ConfigurationService $configService;
    protected ?ProvisioningServiceClient $provisioningClient = null;
    protected bool $useProvisioningService = false;
    
    public function __construct()
    {
        $this->configService = new ConfigurationService();
        
        // Check if provisioning service is enabled
        $this->useProvisioningService = env('USE_PROVISIONING_SERVICE', false);
        
        if ($this->useProvisioningService) {
            $this->provisioningClient = new ProvisioningServiceClient();
            // Reduced: Only log provisioning service mode at debug level
            Log::debug('MikrotikProvisioningService: Using provisioning service for network segmentation');
        }
    }
    
    /**
     * Check if router should use provisioning service
     * Allows gradual rollout by router ID
     */
    protected function shouldUseProvisioningService(Router $router): bool
    {
        if (!$this->useProvisioningService) {
            return false;
        }
        
        // Check if specific router is enabled for provisioning service
        $enabledRouters = env('PROVISIONING_SERVICE_ROUTERS', '');
        if ($enabledRouters === 'all') {
            return true;
        }
        
        if (!empty($enabledRouters)) {
            $routerIds = explode(',', $enabledRouters);
            return in_array($router->id, $routerIds);
        }
        
        return false;
    }
    
    /**
     * Generate service configuration using new clean architecture
     */
    public function generateConfigs(Router $router, array $data): array
    {
        try {
            // Reduced: Removed routine delegation log
            return $this->configService->generateServiceConfig($router, $data);
            
        } catch (\Exception $e) {
            Log::error('Failed to generate service configuration', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
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
            // Use SSH-only via SshExecutor (short timeout: 10s)
            $ssh = new SshExecutor($router, 10);
            $ssh->connect();

            // 1. Check hotspot server
            $hotspotOutput = $ssh->exec('/ip hotspot print detail without-paging');
            $hotspotCheck = $this->parseKeyValueList($hotspotOutput);
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
            $profileOutput = $ssh->exec('/ip hotspot profile print detail without-paging');
            $profileCheck = $this->parseKeyValueList($profileOutput);
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
            $radiusOutput = $ssh->exec('/radius print detail without-paging');
            $radiusCheck = $this->parseKeyValueList($radiusOutput);
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
            $poolOutput = $ssh->exec('/ip pool print detail without-paging');
            $poolCheck = $this->parseKeyValueList($poolOutput);
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
            $dhcpOutput = $ssh->exec('/ip dhcp-server print detail without-paging');
            $dhcpCheck = $this->parseKeyValueList($dhcpOutput);
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
            $natOutput = $ssh->exec('/ip firewall nat print detail without-paging');
            $natCheck = $this->parseKeyValueList($natOutput);
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
            $firewallOutput = $ssh->exec('/ip firewall filter print detail without-paging');
            $firewallCheck = $this->parseKeyValueList($firewallOutput);
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
            $dnsOutput = $ssh->exec('/ip dns print');
            $dnsCheck = $this->parseSingleKeyValueBlock($dnsOutput);
            $servers = $dnsCheck['servers'] ?? null;
            $verification['checks']['dns'] = [
                'status' => !empty($servers) && $servers !== '0.0.0.0',
                'message' => !empty($servers) && $servers !== '0.0.0.0'
                    ? 'DNS servers are properly configured'
                    : 'DNS servers are not properly configured',
                'servers' => $servers ?? 'Not configured'
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
            
            // Log the verification results - only log failures or reduced success
            if (!$verification['success']) {
                Log::warning('Hotspot deployment verification failed', [
                    'router_id' => $router->id,
                    'failed_checks' => array_keys(array_filter(
                        $verification['checks'], 
                        fn($check) => !$check['status']
                    )),
                ]);
            }
            // Reduced: Removed success log to reduce noise
            
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
            return Router::select('id', 'name', 'ip_address', 'vpn_ip', 'username', 'port', 'password', 'status', 'model', 'os_version', 'last_seen', 'last_checked', 'created_at')
                ->orderBy('created_at', 'desc')
                ->get();
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
     * API first, SSH fallback for reliability
     * @param Router $router
     * @param string $context 'provisioning' for interface discovery, 'live' for full monitoring data
     * @param bool $filterConfigurable Only return configurable interfaces (excludes bridges, VLANs, slaves, etc.)
     */
    public function fetchLiveRouterData(Router $router, string $context = 'live', bool $filterConfigurable = false): array
    {
        // Use provisioning service if enabled for this router
        if ($this->shouldUseProvisioningService($router)) {
            try {
                // Reduced: Removed routine provisioning service log
                $result = $this->provisioningClient->fetchLiveData(
                    $router,
                    $context,
                    $router->tenant_id
                );
                
                // Cache the result if live context
                if ($context === 'live') {
                    Cache::put(
                        "router_live_fetch_{$router->id}",
                        ['fetched_at' => time(), 'data' => $result],
                        now()->addSeconds(10)
                    );
                }
                
                return $result;
                
            } catch (\Exception $e) {
                Log::warning('Provisioning service fetch failed, falling back to direct SSH', [
                    'router_id' => $router->id,
                    'error' => $e->getMessage()
                ]);
                // Fall through to direct SSH
            }
        }
        
        // Original direct SSH implementation (fallback)
        if ($context === 'live') {
            $cached = Cache::get("router_live_fetch_{$router->id}");
            if (is_array($cached) && isset($cached['data'], $cached['fetched_at']) && (time() - (int) $cached['fetched_at']) <= 10) {
                return $cached['data'];
            }
        }

        $lockKey = "router_api_lock_{$router->id}";
        
        // Adjust timeout based on context
        $timeout = $context === 'provisioning' ? 15 : 20;
        $lockDuration = $timeout + 5; // Lock duration slightly longer than operation timeout
        $lockDuration = min($lockDuration, 30); // Ensure lock duration does not exceed 30 seconds
        $lock = Cache::lock($lockKey, $lockDuration);
        
        try {
            // Wait to acquire the lock with longer wait time
            $waitTime = $context === 'provisioning' ? 5 : 3;
            if (!$lock->block($waitTime)) {
                Log::warning('Failed to acquire router API lock - router is busy', [
                    'router_id' => $router->id,
                    'router_name' => $router->name,
                    'context' => $context,
                    'wait_time' => $waitTime,
                ]);
                throw new \Exception('Router is busy with another operation', 503);
            }
            
            // Password decryption is now handled in SshExecutor constructor
            // This reduces redundant decryption attempts and improves performance
            // Reduced: Removed routine debug log
            
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

        // Use SSH for all operations
        try {
            $sshService = app(\App\Services\MikrotikSshService::class);
            
            if ($context === 'provisioning') {
                // Fetch interfaces for provisioning
                $result = $sshService->fetchInterfaces($router, $filterConfigurable);
                
                $lock->release();
                
                // Reduced: Removed routine success log
                
                return $result;
            } else {
                // For live monitoring context, fetch full data
                $includeInterfaces = $context === 'details';
                $result = $sshService->fetchLiveData($router, $includeInterfaces);

                Cache::put(
                    "router_live_fetch_{$router->id}",
                    ['fetched_at' => time(), 'data' => $result],
                    now()->addSeconds(10)
                );
                
                $lock->release();
                
                // Reduced: Removed routine success log
                
                return $result;
            }
        } catch (\Exception $sshException) {
            $lock->release();
            Log::error('SSH connection failed', [
                'router_id' => $router->id,
                'error' => $sshException->getMessage()
            ]);
            throw new \Exception('Failed to connect via SSH: ' . $sshException->getMessage());
        }
    }


    /**
     * Get SNMP configuration script (SNMPv2c)
     */
    protected function getSnmpConfigScript(Router $router): string
    {
        // Use simple SNMPv2c community string (same for all routers)
        $community = config('telegraf.snmp_community', env('MIKROTIK_SNMP_COMMUNITY', 'traidnet-monitor'));
        // Always allow 10.8.0.1/32 for SNMP monitoring (VPN Server IP)
        $snmpSubnet = '10.8.0.1/32';
        
        // Save SNMP configuration to database
        $router->update([
            'snmp_enabled' => true,
            'snmp_version' => '2c',
            'snmp_community' => $community,
        ]);
        
        Log::info('SNMPv2c configured on router', [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'community' => $community,
        ]);
        
        // Regenerate Telegraf config to include this router
        try {
            Artisan::call('telegraf:generate-config');
            Log::info('Telegraf config regenerated after SNMP setup', ['router_id' => $router->id]);
        } catch (\Exception $e) {
            Log::warning('Failed to regenerate Telegraf config', ['error' => $e->getMessage()]);
        }
        
        return <<<SCRIPT
/snmp set enabled=yes
/snmp set contact="Network Admin"
/snmp set location="Managed by WifiCore"
:if ([:len [/snmp community find name="{$community}"]] > 0) do={/snmp community set [find name="{$community}"] addresses={$snmpSubnet} security=none read-access=yes write-access=no} else={/snmp community add name="{$community}" addresses={$snmpSubnet} security=none read-access=yes write-access=no}
/snmp set trap-community="{$community}"
SCRIPT;
    }

    /**
     * Get router details (DB + live data)
     */
    public function getRouterDetails(Router $router): array
    {
        try {
            $live = $this->fetchLiveRouterData($router, 'details', false);
            
            // Update database with live hardware information
            if (!empty($live)) {
                $updates = [];
                
                if (isset($live['board_name']) && $live['board_name'] !== $router->model) {
                    $updates['model'] = $live['board_name'];
                }
                
                if (isset($live['version']) && $live['version'] !== $router->os_version) {
                    $updates['os_version'] = $live['version'];
                }
                
                if (isset($live['serial_number']) && $live['serial_number'] !== $router->serial_number) {
                    $updates['serial_number'] = $live['serial_number'];
                }
                
                if (isset($live['version']) && $live['version'] !== $router->firmware) {
                    $updates['firmware'] = $live['version'];
                }
                
                if (!empty($updates)) {
                    $router->update($updates);
                    // Reduced: Removed routine update log
                }
            }
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
            'serial_number' => $live['serial_number'] ?? $router->serial_number,
            'firmware' => $live['version'] ?? $router->firmware,
            'last_seen' => $router->last_seen,
            'live' => $live,
        ];
    }
    
    /**
     * Verify router connectivity via SSH (no RouterOS API)
     */
    public function verifyConnectivity(Router $router): array
    {
        // Use provisioning service if enabled for this router
        if ($this->shouldUseProvisioningService($router)) {
            try {
                // Reduced: Removed routine provisioning service log
                $result = $this->provisioningClient->verifyConnectivity(
                    $router,
                    $router->tenant_id
                );
                
                return $result;
                
            } catch (\Exception $e) {
                Log::warning('Provisioning service connectivity check failed, falling back to direct SSH', [
                    'router_id' => $router->id,
                    'error' => $e->getMessage()
                ]);
                // Fall through to direct SSH
            }
        }
        
        // Original direct SSH implementation (fallback)
        try {
            // Reduced: Removed verbose SSH connection details log
            $ssh = new SshExecutor($router, 10);
            $ssh->connect();

            // Identity and system resource info
            $identityOutput = $ssh->exec('/system identity print');
            $resourceOutput = $ssh->exec('/system resource print');
            $interfacesOutput = $ssh->exec('/interface print count-only');

            $identity = $this->parseSingleKeyValueBlock($identityOutput);
            $resource = $this->parseSingleKeyValueBlock($resourceOutput);

            $model = $resource['board-name'] ?? 'Unknown';
            $osVersion = $resource['version'] ?? 'Unknown';

            // Parse interface count (best-effort)
            $interfacesCount = 0;
            if (!empty($interfacesOutput)) {
                $interfacesCount = (int) trim($interfacesOutput);
            }

            // Reduced: Removed verbose connectivity success log

            return [
                'status' => 'connected',
                'model' => $model,
                'os_version' => $osVersion,
                'identity' => $identity['name'] ?? 'Unknown',
                'interfaces' => [], // SSH path does not return full interface list here
                'last_seen' => now(),
            ];
        } catch (\Exception $e) {
            Log::warning('Connectivity verification via SSH failed:', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'message' => 'Unable to connect to router via SSH',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Helper: parse RouterOS key/value print output into array of rows
     */
    private function parseKeyValueList(string $output): array
    {
        $rows = [];
        $current = [];

        $lines = preg_split('/\r?\n/', $output);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';;;')) {
                continue;
            }

            // New row often starts with an index like "0   name=..."
            if (preg_match('/^\d+\s+/', $line)) {
                if (!empty($current)) {
                    $rows[] = $current;
                    $current = [];
                }
            }

            // Extract key=value pairs
            if (preg_match_all('/([\w-]+)=([^\s"]+|"[^"]*")/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $key = $match[1];
                    $value = trim($match[2], '"');
                    $current[$key] = $value;
                }
            }
        }

        if (!empty($current)) {
            $rows[] = $current;
        }

        return $rows;
    }

    /**
     * Helper: parse a single-key/value block (e.g. /system resource print)
     */
    private function parseSingleKeyValueBlock(string $output): array
    {
        $result = [];
        $lines = preg_split('/\r?\n/', $output);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';;;')) {
                continue;
            }

            if (preg_match_all('/([\w-]+):\s*([^\r\n]+)/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $key = $match[1];
                    $value = trim($match[2]);
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Apply configuration via MikroTik REST API for low-end devices
     * This avoids SSH connection timeout issues on hAP lite and similar devices
     * 
     * @param Router $router The router to configure
     * @param array $config Configuration array extracted from service parameters
     * @param bool $broadcast Whether to broadcast progress events
     * @return array Result of the operation
     */
    public function applyConfigsViaApi(Router $router, array $config, bool $broadcast = true): array
    {
        $startTime = microtime(true);
        
        Log::info('Starting API-based configuration for low-end device', [
            'router_id' => $router->id,
            'model' => $router->model,
        ]);

        if ($broadcast) {
            RouterProvisioningProgress::dispatch(
                $router->id,
                'init',
                0,
                'Starting API-based provisioning',
                ['method' => 'REST_API', 'router_model' => $router->model]
            );
        }

        try {
            // Initialize REST API service
            $apiService = new MikroTik\MikroTikRestApiService($router, 30);

            // Test API connectivity
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'connecting',
                    10,
                    'Connecting to router via REST API',
                    ['method' => 'REST_API']
                );
            }

            if (!$apiService->testConnection()) {
                throw new \Exception('Failed to connect to router REST API. Ensure API service is enabled: /ip service enable rest-api');
            }

            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'connected',
                    20,
                    'API connection established',
                    ['method' => 'REST_API']
                );
            }

            // Select appropriate API configurator for the deployment
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'applying_config',
                    30,
                    'Applying configuration via API',
                    ['method' => 'REST_API']
                );
            }

            $serviceId = $config['service_id'] ?? $router->id;
            $serviceType = $config['service_type'] ?? RouterService::TYPE_PPPOE;
            if ($serviceType === RouterService::TYPE_HYBRID) {
                $configurator = new MikroTik\HybridApiConfigurator($apiService, $serviceId, $config);
            } elseif ($serviceType === RouterService::TYPE_HOTSPOT) {
                $configurator = new MikroTik\HotspotApiConfigurator($apiService, $serviceId, $config);
            } else {
                $configurator = new MikroTik\PppoeApiConfigurator($apiService, $serviceId, $config);
            }
            
            $result = $configurator->configure();

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'API configuration failed');
            }

            // Verify the configuration
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'verifying',
                    80,
                    'Verifying API deployment',
                    ['method' => 'REST_API']
                );
            }

            $verification = $configurator->verify();
            if (!$verification['valid']) {
                throw new \Exception($verification['error'] ?? 'API verification failed');
            }

            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'completed',
                    100,
                    'API deployment completed successfully',
                    ['method' => 'REST_API']
                );
            }

            $executionTime = round(microtime(true) - $startTime, 2);
            
            Log::info('API configuration applied successfully', [
                'router_id' => $router->id,
                'execution_time' => $executionTime . 's',
                'results' => $result['results'] ?? [],
            ]);

            return [
                'success' => true,
                'message' => 'Configuration applied successfully via REST API',
                'execution_time' => $executionTime . 's',
                'router_id' => $router->id,
                'method' => 'REST_API',
                'results' => $result['results'] ?? [],
            ];

        } catch (\Exception $e) {
            Log::error('API configuration failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
            ]);

            if ($broadcast) {
                ProvisioningFailed::dispatch(
                    $router->id,
                    'api_failed',
                    'API deployment failed: ' . $e->getMessage(),
                    ['method' => 'REST_API', 'error' => $e->getMessage()]
                );
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'method' => 'REST_API',
                'fallback_to_ssh' => true,
            ];
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
        $provisionLock = null;
        
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

        $lockTtlSeconds = (int) env('MIKROTIK_PROVISION_LOCK_TTL', 60);
        $lockWaitSeconds = (int) env('MIKROTIK_PROVISION_LOCK_WAIT', 10);

        $provisionLock = Cache::lock('router_provision_lock_' . $router->id, $lockTtlSeconds);
        try {
            $provisionLock->block($lockWaitSeconds);
        } catch (LockTimeoutException $e) {
            Log::warning('Provisioning deferred: router busy (lock timeout)', [
                'router_id' => $router->id,
                'router_name' => $routerName,
                'wait_seconds' => $lockWaitSeconds,
                'ttl_seconds' => $lockTtlSeconds,
            ]);

            throw new \Exception('Router is busy with another provisioning operation', 503, $e);
        }

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

            // 3. Connect via SSH (SSH-ONLY approach - credentials decrypted once in SshExecutor)
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'connecting',
                    20,
                    'Connecting to router via SSH',
                    ['method' => 'SSH']
                );
            }

            // Initialize SSH executor (credentials decrypted ONCE here)
            // Use RouterResourceManager for device-appropriate timeout
            $sshTimeout = RouterResourceManager::getSshTimeout($router);
            
            Log::info('SSH connection timeout configured', [
                'router_id' => $router->id,
                'model' => $router->model,
                'timeout_seconds' => $sshTimeout,
            ]);
            
            $ssh = new SshExecutor($router, $sshTimeout);
            
            try {
                $ssh->connect();
                
                if ($broadcast) {
                    broadcast(new RouterConnected($router))->toOthers();
                    RouterProvisioningProgress::dispatch(
                        $router->id,
                        'connected',
                        30,
                        'Successfully connected to router',
                        ['method' => 'SSH']
                    );
                }
                
            } catch (\Exception $e) {
                $errorMsg = 'Failed to connect to router via SSH: ' . $e->getMessage();
                if ($broadcast) {
                    ProvisioningFailed::dispatch(
                        $router->id,
                        'connection_failed',
                        $errorMsg,
                        ['error' => $e->getMessage()]
                    );
                }
                throw new \Exception($errorMsg, 503, $e);
            }

            // Capture hardware/OS details early to drive low-end optimizations
            $detectedModel = null;
            $detectedVersion = null;
            try {
                $resourceOutput = $ssh->exec('/system resource print');
                $resourceInfo = $this->parseSingleKeyValueBlock($resourceOutput);
                $detectedModel = $resourceInfo['board-name'] ?? null;
                $detectedVersion = $resourceInfo['version'] ?? null;

                if ($detectedModel && $detectedModel !== $router->model) {
                    $router->update(['model' => $detectedModel]);
                }

                if ($detectedVersion && $detectedVersion !== $router->os_version) {
                    $router->update(['os_version' => $detectedVersion]);
                }
            } catch (\Exception $e) {
                Log::debug('Unable to read router hardware details (non-fatal)', [
                    'router_id' => $router->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 4. Check VPN/WireGuard status BEFORE deployment (baseline)
            $vpnIp = $router->vpn_ip ? explode('/', $router->vpn_ip)[0] : null;
            $preDeployVpnStatus = null;
            if ($vpnIp) {
                try {
                    $wgOutput = $ssh->exec('/interface wireguard peers print detail without-paging');
                    $preDeployVpnStatus = [
                        'has_wireguard' => !empty(trim($wgOutput)),
                        'output_preview' => substr(trim($wgOutput), 0, 200),
                    ];
                    Log::info('Pre-deployment VPN status captured', [
                        'router_id' => $router->id,
                        'vpn_ip' => $vpnIp,
                        'has_wireguard' => $preDeployVpnStatus['has_wireguard'],
                    ]);
                } catch (\Exception $e) {
                    Log::debug('Could not check pre-deployment VPN status (non-fatal)', [
                        'router_id' => $router->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 5. Apply configuration via SSH file upload/import
            $scriptName = "svc_deploy_" . $router->id . "_" . time();
            
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'applying_config',
                    40,
                    'Applying configuration via SSH',
                    ['script_name' => $scriptName]
                );
            }

            Log::info('Applying configuration script via SSH (direct commands)', [
                'router_id' => $router->id,
                'script_name' => $scriptName,
                'script_length' => strlen($serviceScript),
            ]);
            
            // Add SNMP configuration to the script if not already present
            if (!str_contains($serviceScript, '/snmp set enabled=yes')) {
                $snmpConfig = $this->getSnmpConfigScript($router);
                $serviceScript .= "\n\n# Enable SNMP for monitoring\n" . $snmpConfig;
                Log::info('Added SNMP configuration to provisioning script', ['router_id' => $router->id]);
            }
            
            try {
                $expectsHotspot = str_contains($serviceScript, '/ip hotspot');
                $expectsPppoe = str_contains($serviceScript, '/interface pppoe-server');

                $validator = function($sshExecutor) use ($router, $expectsHotspot, $expectsPppoe, $serviceScript) {
                    // Validate deployment by checking if expected services were created
                    try {
                        // Generic counts can pass due to stale configs. Prefer validating the specific resources
                        // referenced by the generated script.
                        $expectedHotspotServer = null;
                        $expectedHotspotProfile = null;
                        $expectedHotspotBridge = null;
                        $expectedPppoeServer = null;
                        $hotspotCount = null;
                        $pppoeCount = null;

                        if ($expectsHotspot) {
                            if (preg_match('/\/ip hotspot\s+add\s+name=(?:\\"([^\\"]+)\\"|([^\s]+))/i', $serviceScript, $m)) {
                                $expectedHotspotServer = $m[1] ?: ($m[2] ?? null);
                            }
                            if (preg_match('/\/ip hotspot profile\s+add\s+name=(?:\\"([^\\"]+)\\"|([^\s]+))/i', $serviceScript, $m)) {
                                $expectedHotspotProfile = $m[1] ?: ($m[2] ?? null);
                            }
                            if (preg_match('/\/interface bridge\s+add\s+name=(?:\\"([^\\"]+)\\"|([^\s]+))/i', $serviceScript, $m)) {
                                $expectedHotspotBridge = $m[1] ?: ($m[2] ?? null);
                            }
                        }

                        if ($expectsPppoe) {
                            if (preg_match('/\/interface pppoe-server server\s+add\b[^\n]*\bservice-name=(?:"([^"]+)"|([^\s"]+))/i', $serviceScript, $m)) {
                                $expectedPppoeServer = ($m[1] ?? null) ?: ($m[2] ?? null);
                            }
                        }

                        if ($expectsHotspot) {
                            if ($expectedHotspotServer) {
                                $hotspotServerCount = (int) trim($sshExecutor->exec('/ip hotspot print count-only where name="' . $expectedHotspotServer . '"'));
                                if ($hotspotServerCount < 1) {
                                    return [
                                        'valid' => false,
                                        'error' => 'Hotspot deployment validation failed (missing hotspot server: ' . $expectedHotspotServer . ')'
                                    ];
                                }
                            }

                            if ($expectedHotspotProfile) {
                                $hotspotProfileCount = (int) trim($sshExecutor->exec('/ip hotspot profile print count-only where name="' . $expectedHotspotProfile . '"'));
                                if ($hotspotProfileCount < 1) {
                                    return [
                                        'valid' => false,
                                        'error' => 'Hotspot deployment validation failed (missing hotspot profile: ' . $expectedHotspotProfile . ')'
                                    ];
                                }
                            }

                            if ($expectedHotspotBridge) {
                                $bridgeCount = (int) trim($sshExecutor->exec('/interface bridge print count-only where name="' . $expectedHotspotBridge . '"'));
                                $bridgePortCount = (int) trim($sshExecutor->exec('/interface bridge port print count-only where bridge="' . $expectedHotspotBridge . '"'));
                                if ($bridgeCount < 1 || $bridgePortCount < 1) {
                                    return [
                                        'valid' => false,
                                        'error' => 'Hotspot deployment validation failed (bridge not ready: ' . $expectedHotspotBridge . ', ports: ' . $bridgePortCount . ')'
                                    ];
                                }

                                // Count expected bridge ports from the script (each "bridge port add" line)
                                $expectedBridgePorts = preg_match_all('/bridge port add.*bridge="' . preg_quote($expectedHotspotBridge, '/') . '"/', $serviceScript);
                                if ($expectedBridgePorts > 0 && $bridgePortCount < $expectedBridgePorts) {
                                    Log::warning('Bridge port count mismatch', [
                                        'router_id' => $router->id,
                                        'bridge' => $expectedHotspotBridge,
                                        'expected_ports' => $expectedBridgePorts,
                                        'actual_ports' => $bridgePortCount,
                                    ]);
                                    return [
                                        'valid' => false,
                                        'error' => 'Hotspot bridge port count mismatch (expected ' . $expectedBridgePorts . ', got ' . $bridgePortCount . ' for bridge ' . $expectedHotspotBridge . ')'
                                    ];
                                }
                            }
                        }

                        if ($expectsPppoe && $expectedPppoeServer) {
                            $pppoeServerCount = (int) trim($sshExecutor->exec('/interface pppoe-server server print count-only where service-name="' . $expectedPppoeServer . '"'));
                            if ($pppoeServerCount < 1) {
                                return [
                                    'valid' => false,
                                    'error' => 'PPPoE deployment validation failed (missing PPPoE server: ' . $expectedPppoeServer . ')'
                                ];
                            }
                        }

                        if ($expectsHotspot && !$expectsPppoe) {
                            if ($expectedHotspotServer || $expectedHotspotProfile || $expectedHotspotBridge) {
                                // Reduced: Removed routine success log
                                return ['valid' => true];
                            }

                            $hotspotCount = (int) trim($sshExecutor->exec('/ip hotspot print count-only'));
                            if ($hotspotCount > 0) {
                                // Reduced: Removed routine success log
                                return ['valid' => true];
                            }

                            return [
                                'valid' => false,
                                'error' => 'Hotspot deployment validation failed (hotspot: 0)'
                            ];
                        }

                        if ($expectsPppoe && !$expectsHotspot) {
                            if ($expectedPppoeServer) {
                                // Reduced: Removed routine success log
                                return ['valid' => true];
                            }

                            $pppoeCount = (int) trim($sshExecutor->exec('/interface pppoe-server server print count-only'));
                            if ($pppoeCount > 0) {
                                // Reduced: Removed routine success log
                                return ['valid' => true];
                            }

                            return [
                                'valid' => false,
                                'error' => 'PPPoE deployment validation failed (pppoe: 0)'
                            ];
                        }

                        if ($expectsHotspot && $expectsPppoe) {
                            $hotspotCount = (int) trim($sshExecutor->exec('/ip hotspot print count-only'));
                            $pppoeCount = (int) trim($sshExecutor->exec('/interface pppoe-server server print count-only'));
                            if ($hotspotCount > 0 && $pppoeCount > 0) {
                                // Reduced: Removed routine success log
                                return ['valid' => true];
                            }

                            return [
                                'valid' => false,
                                'error' => "Hybrid deployment validation failed (hotspot: {$hotspotCount}, pppoe: {$pppoeCount})"
                            ];
                        }

                        $hotspotCount = $hotspotCount ?? (int) trim($sshExecutor->exec('/ip hotspot print count-only'));
                        $pppoeCount = $pppoeCount ?? (int) trim($sshExecutor->exec('/interface pppoe-server server print count-only'));
                        if ($hotspotCount > 0 || $pppoeCount > 0) {
                            // Reduced: Removed routine success log
                            return ['valid' => true];
                        }
                        
                        return [
                            'valid' => false,
                            'error' => 'No services were created (hotspot: 0, pppoe: 0)'
                        ];
                    } catch (\Exception $e) {
                        return [
                            'valid' => false,
                            'error' => 'Validation check failed: ' . $e->getMessage()
                        ];
                    }
                };

                $routerModel = $detectedModel ?: $router->model;
                $routerTier = RouterResourceManager::getRouterTierByModel($routerModel);
                if (empty($routerModel)) {
                    $routerTier = 'low_end';
                }

                $osMajor = null;
                $routerOsVersion = $detectedVersion ?: $router->os_version;
                if ($routerOsVersion && preg_match('/^(\d+)/', $routerOsVersion, $matches)) {
                    $osMajor = (int) $matches[1];
                }

                $isLowEnd = $routerTier === 'low_end';
                $canUseRestApi = $osMajor === null ? true : $osMajor >= 7;
                if ($isLowEnd && !$canUseRestApi && $broadcast) {
                    RouterProvisioningProgress::dispatch(
                        $router->id,
                        'compatibility_warning',
                        35,
                        'RouterOS < 7 detected; using SSH batching (REST API/WireGuard unavailable).',
                        ['router_os_version' => $routerOsVersion]
                    );
                }

                // For low-end devices, try REST API first, then fall back to SSH batching
                $result = null;
                
                // Initialize these for cleanup (only used by executeSingleScript)
                $remoteScriptFile = null;
                $tempFile = null;
                
                if ($isLowEnd && $serviceScript && $canUseRestApi) {
                    try {
                        // Extract config from script for API deployment
                        $apiConfig = $this->extractConfigFromScript($serviceScript, $router);
                        $apiConfigForLog = $apiConfig;
                        if (!empty($apiConfigForLog['radius_servers'])) {
                            $apiConfigForLog['radius_servers'] = array_map(static function (array $server): array {
                                if (isset($server['secret'])) {
                                    $server['secret'] = '***';
                                }

                                return $server;
                            }, $apiConfigForLog['radius_servers']);
                        }
                        if (isset($apiConfigForLog['radius_secret'])) {
                            $apiConfigForLog['radius_secret'] = '***';
                        }

                        Log::debug('Extracted REST API config for low-end provisioning', [
                            'router_id' => $router->id,
                            'service_type' => $apiConfigForLog['service_type'] ?? null,
                            'config' => $apiConfigForLog,
                        ]);
                        
                        // Try REST API first
                        $apiResult = $this->applyConfigsViaApi($router, $apiConfig, $broadcast);
                        
                        if ($apiResult['success']) {
                            $result = $apiResult;
                            Log::info('Low-end device configured via REST API', [
                                'router_id' => $router->id,
                                'model' => $router->model,
                            ]);
                        } else {
                            // Fall back to SSH batching if API fails
                            Log::warning('REST API failed, falling back to SSH batching', [
                                'router_id' => $router->id,
                                'api_error' => $apiResult['message'],
                            ]);
                            $result = $this->executeBatchedCommands($ssh, $serviceScript, $router, $validator);
                        }
                    } catch (\Exception $apiException) {
                        // API threw an exception - fall back to SSH batching
                        Log::warning('REST API threw exception, falling back to SSH batching', [
                            'router_id' => $router->id,
                            'api_error' => $apiException->getMessage(),
                        ]);
                        $result = $this->executeBatchedCommands($ssh, $serviceScript, $router, $validator);
                    }
                } else {
                    $remoteScriptFile = "svc_deploy_{$router->id}_" . time() . '.rsc';
                    $tempFile = tempnam(sys_get_temp_dir(), 'mikrotik_script_');
                    $result = $this->executeSingleScript($ssh, $serviceScript, $router, $validator, $remoteScriptFile, $tempFile);
                }

                // Brief wait for router to stabilize
                sleep(1);

                // 6. VPN HEALTH CHECK: Verify WireGuard connectivity after deployment
                if ($vpnIp && $preDeployVpnStatus && $preDeployVpnStatus['has_wireguard']) {
                    $vpnHealthy = false;
                    $vpnRetries = 3;
                    
                    for ($vpnAttempt = 1; $vpnAttempt <= $vpnRetries; $vpnAttempt++) {
                        try {
                            // Check if WireGuard peer still has a recent handshake
                            $postWgOutput = $ssh->exec('/interface wireguard peers print detail without-paging');
                            
                            if (!empty(trim($postWgOutput))) {
                                // WireGuard peers still exist — check for handshake
                                $hasHandshake = str_contains($postWgOutput, 'last-handshake');
                                
                                // Reduced: Removed verbose VPN health check log
                                
                                $vpnHealthy = true;
                                break;
                            }
                            
                            // Reduced: Removed verbose no peers log
                            
                        } catch (\Exception $vpnCheckError) {
                            // Reduced: Removed verbose VPN check failure log
                            
                            // If SSH itself failed, VPN may be down — try reconnecting
                            if ($vpnAttempt < $vpnRetries) {
                                sleep(3 * $vpnAttempt);
                                try {
                                    $ssh->disconnect(false);
                                    sleep(2);
                                    $ssh->connect();
                                } catch (\Exception $reconnectError) {
                                    // Reduced: Only log reconnection error once
                                    if ($vpnAttempt === $vpnRetries) {
                                        Log::warning('VPN reconnection failed after retries', [
                                            'router_id' => $router->id,
                                            'error' => $reconnectError->getMessage(),
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                    
                    if (!$vpnHealthy) {
                        Log::error('VPN CONNECTIVITY LOST after deployment', [
                            'router_id' => $router->id,
                            'vpn_ip' => $vpnIp,
                            'retries_exhausted' => $vpnRetries,
                            'action' => 'Deployment succeeded but VPN may need manual recovery',
                        ]);
                        
                        if ($broadcast) {
                            RouterProvisioningProgress::dispatch(
                                $router->id,
                                'vpn_warning',
                                90,
                                'WARNING: VPN connectivity may be degraded after deployment. Check WireGuard handshake.',
                                ['vpn_ip' => $vpnIp, 'vpn_healthy' => false]
                            );
                        }
                    } else {
                        Log::info('VPN health check passed after deployment', [
                            'router_id' => $router->id,
                            'vpn_ip' => $vpnIp,
                        ]);
                    }
                }

                // Final verification
                if ($broadcast) {
                    RouterProvisioningProgress::dispatch(
                        $router->id,
                        'verifying',
                        85,
                        'Verifying deployment',
                        ['status' => 'verifying']
                    );
                }
                
            } catch (\Exception $e) {
                $errorMsg = 'Failed to execute configuration script via SSH commands: ' . $e->getMessage();
                Log::error('Script execution failed (direct SSH)', [
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
            } finally {
                // Always disconnect SSH and cleanup credentials
                $ssh->disconnect();
            }

            // Only override result if not already set by API success
            if (!$result || !isset($result['method']) || $result['method'] !== 'API') {
                $result = [
                    'success' => true,
                    'message' => 'Configuration applied successfully via SSH',
                    'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                    'script_name' => $scriptName,
                    'router_id' => $router->id,
                    'method' => 'SSH'
                ];
            }

            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'completed',
                    100,
                    'Service deployment completed successfully',
                    array_merge($result, ['status' => 'completed'])
                );
            }

            // Schedule cleanup of orphaned RSC files (only if we have a script file)
            if ($remoteScriptFile) {
                try {
                    $cleanupService = app(RscFileCleanupService::class);
                    $cleanupService->scheduleCleanup($router, $remoteScriptFile);
                    Log::debug('Scheduled RSC file cleanup', [
                        'router_id' => $router->id,
                        'file' => $remoteScriptFile,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to schedule RSC cleanup', [
                        'router_id' => $router->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Configuration applied successfully via SSH', $result);
            return $result;

        } catch (\Exception $e) {
            $errorContext = [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'script_name' => $scriptName ?? 'unknown',
            ];
            
            Log::error('Failed to apply configuration via SSH', $errorContext);
            
            // Convert to a more user-friendly error message
            $errorMessage = $e->getMessage();
            
            if (str_contains($errorMessage, 'Unable to connect to') || 
                str_contains($errorMessage, 'Connection timed out') ||
                str_contains($errorMessage, 'SSH connection failed')) {
                $errorMessage = 'Unable to connect to router via SSH. Please check network connectivity and credentials.';
            } elseif (str_contains($errorMessage, 'authentication failed') || 
                      str_contains($errorMessage, 'invalid user name or password')) {
                $errorMessage = 'SSH authentication failed. Please check the router credentials.';
            }
            
            throw new \Exception($errorMessage, $e->getCode(), $e);
        } finally {
            if ($provisionLock && method_exists($provisionLock, 'owner') && $provisionLock->owner()) {
                $provisionLock->release();
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
            $tokenCreatedAt = now();
            $ttlMinutes = (int) config('app.router_config_token_ttl_minutes', 60);
            $tokenExpiresAt = $ttlMinutes > 0 ? $tokenCreatedAt->copy()->addMinutes($ttlMinutes) : null;

            $router = Router::create([
                'name' => $data['name'],
                'ip_address' => $ipAddress,
                'username' => $username,
                'password' => Crypt::encrypt($password),
                'port' => $port,
                'config_token' => $configToken,
                'config_token_created_at' => $tokenCreatedAt,
                'config_token_expires_at' => $tokenExpiresAt,
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
            $updateData = [
                'name' => $data['name'],
                'ip_address' => $data['ip_address'] ?? $router->ip_address,
            ];

            if (!empty($data['config_token'])) {
                $ttlMinutes = (int) config('app.router_config_token_ttl_minutes', 60);
                $updateData['config_token'] = $data['config_token'];
                $updateData['config_token_created_at'] = now();
                $updateData['config_token_expires_at'] = $ttlMinutes > 0
                    ? now()->addMinutes($ttlMinutes)
                    : null;
            }

            $router->update($updateData);

            Log::info('Router updated successfully:', ['router_id' => $router->id]);
            return $router->fresh();
        } catch (\Exception $e) {
            Log::error('Failed to update router:', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
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
        $apiPort = $router->api_port ?? 8729;
        
        return <<<EOT
/ip address add address={$router->ip_address} interface=ether2
/ip service set api disabled=no port={$router->port}
:do { /ip service set rest-api disabled=no port={$apiPort} } on-error={ /log info "rest-api enable failed or already enabled" }
:do { /ip service set rest-api address=10.0.0.0/8 } on-error={ /log info "rest-api address set failed" }
:do { /ip service set api-ssl disabled=no } on-error={ /log info "api-ssl enable failed or already enabled" }
:do { /ip service set api-ssl address=10.0.0.0/8 } on-error={ /log info "api-ssl address set failed" }
/user add name={$router->username} password="{$decryptedPassword}" group=full
/system identity set name="{$router->name}"
/system note set note="Managed by Traidnet Solution LTD"
EOT;
    }

    /**
     * Execute configuration in batches for low-end devices
     * Prevents SSH timeouts by breaking large scripts into smaller chunks
     */
    private function executeBatchedCommands(
        SshExecutor $ssh,
        string $serviceScript,
        Router $router,
        callable $validator
    ): array {
        $batches = $this->splitScriptIntoBatches($serviceScript, $router);
        $totalBatches = count($batches);
        $batchFiles = []; // Track all batch files for cleanup
        
        Log::info('Executing batched commands for low-end device', [
            'router_id' => $router->id,
            'total_batches' => $totalBatches,
        ]);

        foreach ($batches as $index => $batch) {
            $batchNum = $index + 1;
            $batchFile = "batch_{$batchNum}.rsc";
            $batchFiles[] = $batchFile; // Track for cleanup
            
            try {
                // ALWAYS disconnect and reconnect between batches for hAP lite
                // This is critical to prevent memory exhaustion on 16-32MB devices
                if ($index > 0) {
                    $ssh->disconnect(false);
                    Log::debug('Disconnected SSH between batches for memory recovery', [
                        'router_id' => $router->id,
                        'batch' => $batchNum,
                    ]);
                    sleep(10); // 10 second memory recovery for extreme low memory (5MB free)
                    $ssh->connect();
                    Log::debug('Reconnected SSH after memory recovery delay', [
                        'router_id' => $router->id,
                        'batch' => $batchNum,
                    ]);
                }

                // Upload and execute this batch
                $tempFile = tempnam(sys_get_temp_dir(), 'batch_');
                file_put_contents($tempFile, $batch);
                
                $ssh->uploadFile($tempFile, $batchFile);
                
                // Delay after upload before import (let router process)
                sleep(3);
                
                $ssh->importFile($batchFile);
                
                // Wait for RouterOS to process and stabilize memory
                sleep(5);
                
                // Cleanup batch file immediately after import
                @unlink($tempFile);
                try {
                    $ssh->exec('/file remove [find name="' . $batchFile . '"]', 5);
                    // Reduced: Removed batch file removal log
                } catch (\Throwable $e) {
                    // Non-critical, will be cleaned up later
                }

                // Reduced: Removed routine batch completion log

            } catch (\Exception $e) {
                Log::error("Batch {$batchNum}/{$totalBatches} failed", [
                    'router_id' => $router->id,
                    'error' => $e->getMessage(),
                ]);
                
                // Schedule cleanup of all batch files created so far
                $this->scheduleBatchCleanup($router, $batchFiles);
                
                return [
                    'success' => false,
                    'message' => "Batch {$batchNum} failed: " . $e->getMessage(),
                ];
            }
        }

        // Final validation
        try {
            $validationResult = $validator($ssh);
            if (!($validationResult['valid'] ?? false)) {
                $this->scheduleBatchCleanup($router, $batchFiles);
                return [
                    'success' => false,
                    'message' => $validationResult['error'] ?? 'Validation failed',
                ];
            }
        } catch (\Exception $e) {
            $this->scheduleBatchCleanup($router, $batchFiles);
            return [
                'success' => false,
                'message' => 'Final validation failed: ' . $e->getMessage(),
            ];
        }

        // Schedule cleanup of any remaining batch files
        $this->scheduleBatchCleanup($router, $batchFiles);

        return ['success' => true, 'message' => 'Configuration applied via batches'];
    }

    /**
     * Schedule cleanup of batch files
     */
    private function scheduleBatchCleanup(Router $router, array $batchFiles): void
    {
        if (empty($batchFiles)) {
            return;
        }

        $routerId = $router->id;
        $tenantId = app(\App\Services\TenantContext::class)->getTenantId();
        $schemaName = app(\App\Services\TenantContext::class)->getSchemaName();

        dispatch(function () use ($routerId, $tenantId, $schemaName, $batchFiles) {
            $tenantContext = app(\App\Services\TenantContext::class);
            
            if ($tenantId && $schemaName) {
                // First reset to public schema to access tenants table
                \DB::connection('pgsql')->statement("SET search_path TO 'public'");
                $tenantContext->setTenantById($tenantId);
                // Then set to tenant schema for router operations
                \DB::connection('pgsql')->statement("SET search_path TO '{$schemaName}'");
            }

            try {
                $router = \App\Models\Router::on('pgsql')->useWritePdo()->find($routerId);
                if (!$router) {
                    \Illuminate\Support\Facades\Log::warning('Router not found during batch cleanup', [
                        'router_id' => $routerId,
                        'schema' => $schemaName ?? 'unknown',
                    ]);
                    return;
                }

                $ssh = app(SshExecutor::class, [
                    'router' => $router,
                    'timeout' => 10,
                ]);
                $ssh->connect();

                try {
                    foreach ($batchFiles as $batchFile) {
                        try {
                            $ssh->exec('/file remove [find name="' . $batchFile . '"]');
                            // Reduced: Removed routine cleanup log
                        } catch (\Throwable $e) {
                            // File may already be deleted
                        }
                    }
                } finally {
                    $ssh->disconnect();
                }
            } finally {
                if ($tenantId) {
                    $tenantContext->clearTenant();
                }
            }
        })->delay(now()->addSeconds(30));
    }

    /**
     * Split script into logical batches based on command types
     * Ultra-conservative for hAP lite (16-32MB RAM) to prevent memory exhaustion
     */
    private function splitScriptIntoBatches(string $script, Router $router): array
    {
        $lines = explode("\n", $script);
        $batches = [];
        $currentBatch = [];
        $lineCount = 0;
        $maxLinesPerBatch = RouterResourceManager::getCommandBatchSize($router);
        if ($maxLinesPerBatch < 3) {
            $maxLinesPerBatch = 3;
        }
        $inCriticalSection = false; // Bridge + PPPoE server section must stay together
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed) || str_starts_with($trimmed, '#')) {
                continue;
            }
            
            // Check if this is a critical infrastructure command (bridge or PPPoE server)
            $isBridgeCommand = str_contains($trimmed, '/interface bridge');
            $isPppoeServerCommand = str_contains($trimmed, '/interface pppoe-server');
            $isInfrastructureCommand = $isBridgeCommand || $isPppoeServerCommand;
            
            // Track if we're in the critical infrastructure section
            // This keeps bridge creation, bridge ports, and PPPoE server together
            if ($isInfrastructureCommand) {
                $inCriticalSection = true;
            } elseif (str_contains($trimmed, '/ip firewall') || 
                      str_contains($trimmed, '/radius') ||
                      str_contains($trimmed, '/ppp profile') ||
                      (str_contains($trimmed, '/interface') && !$isInfrastructureCommand)) {
                // End of critical infrastructure section
                $inCriticalSection = false;
            }
            
            $currentBatch[] = $line;
            $lineCount++;
            
            // Force new batch every maxLinesPerBatch, EXCEPT when in critical infrastructure section
            // Bridge + bridge ports + PPPoE server must execute together
            $forceNewBatch = $lineCount >= $maxLinesPerBatch && !$inCriticalSection;
            
            // Section boundaries - only at major section transitions (NOT within infrastructure)
            $sectionBoundary = (str_contains($trimmed, '/ip firewall') || 
                               str_contains($trimmed, '/radius')) && !$inCriticalSection;
            
            if ($forceNewBatch || $sectionBoundary) {
                if (!empty($currentBatch)) {
                    $batches[] = implode("\n", $currentBatch);
                    $currentBatch = [];
                    $lineCount = 0;
                    $inCriticalSection = false;
                }
            }
        }
        
        // Add remaining lines
        if (!empty($currentBatch)) {
            $batches[] = implode("\n", $currentBatch);
        }
        
        // If no batches created, use entire script as one batch (shouldn't happen)
        if (empty($batches)) {
            $batches[] = $script;
        }
        
        return $batches;
    }

    /**
     * Extract configuration parameters from RouterOS script for API deployment
     * Parses the script and extracts key settings for REST API configuration
     */
    private function extractConfigFromScript(string $script, Router $router): array
    {
        $config = [
            'service_id' => $router->id,
            'service_type' => null,
            'interfaces' => [],
            'bridge_ports' => [],
            'vlans' => [],
            'vlan_required' => false,
            'vlan_id' => null,
            'radius_servers' => [],
            'profile' => 'pppoe-default',
            'pppoe_profile' => null,
            'pppoe_pool' => null,
            'pppoe_range_start' => null,
            'pppoe_range_end' => null,
            'pppoe_gateway_ip' => null,
            'pppoe_remote_address' => null,
            'pppoe_dns_servers' => null,
            'pppoe_only_one' => null,
            'pppoe_change_tcp_mss' => null,
            'pppoe_use_compression' => null,
            'pppoe_authentication' => null,
            'pppoe_one_session_per_host' => null,
            'pppoe_keepalive_timeout' => null,
            'pppoe_max_mtu' => null,
            'pppoe_max_mru' => null,
            'pppoe_interim_update' => null,
            'pppoe_list' => null,
            'wan_list' => 'WAN',
            'pal_list' => null,
            'mgmt_subnet' => '10.0.0.0/8',
            'mgmt_ports' => '22,8291,8728,8729',
            'wan_dhcp_client' => false,
            'wan_dhcp_client_interface' => null,
            'wan_disable_running_check' => null,
            'disable_running_check_interfaces' => [],
            'tcp_timeout' => 3600,
            'udp_timeout' => 30,
        ];

        $hasHotspot = str_contains($script, '/ip hotspot');
        $hasPppoe = str_contains($script, '/interface pppoe-server');

        if ($hasHotspot && $hasPppoe) {
            $config['service_type'] = RouterService::TYPE_HYBRID;
        } elseif ($hasHotspot) {
            $config['service_type'] = RouterService::TYPE_HOTSPOT;
        } else {
            $config['service_type'] = RouterService::TYPE_PPPOE;
        }

        if ($config['service_type'] !== RouterService::TYPE_PPPOE) {
            $config['wan_list'] = 'WAN';
            $config['mgmt_ports'] = '22,8291,8728,8729';
        }

        $foundBridge = false;

        // Extract bridge name
        if (preg_match('/\/interface bridge add name="?([^"\s]+)"?/i', $script, $matches)) {
            $config['bridge'] = $matches[1];
            $foundBridge = true;
        }

        // Extract service name
        if (preg_match('/\/interface pppoe-server server add .*service-name="?([^"\s]+)"?/i', $script, $matches)) {
            $config['service_name'] = $matches[1];
        }

        // Extract interfaces (both physical and VLAN)
        if (preg_match_all('/\/interface bridge port add .* interface="?([^"\s]+)"?/i', $script, $matches)) {
            foreach ($matches[1] as $interface) {
                if (!in_array($interface, $config['interfaces'])) {
                    $config['interfaces'][] = $interface;
                }
            }
        }

        if (preg_match_all('/\/interface bridge port add .* bridge="?([^"\s]+)"?/i', $script, $matches)) {
            foreach ($matches[1] as $bridgeName) {
                if (!$foundBridge) {
                    $config['bridge'] = $bridgeName;
                    $foundBridge = true;
                }
            }
        }

        $config['bridge_ports'] = $config['interfaces'];

        // Check for VLAN
        if (preg_match_all('/\/interface vlan add .* name="?([^"\s]+)"? .* vlan-id=(\d+).* interface="?([^"\s]+)"?/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $config['vlans'][] = [
                    'name' => $match[1],
                    'vlan_id' => (int) $match[2],
                    'interface' => $match[3],
                ];
            }
        }

        if (preg_match('/\/interface vlan add .* vlan-id=(\d+)/i', $script, $matches)) {
            $config['vlan_required'] = true;
            $config['vlan_id'] = (int) $matches[1];
        }

        // Extract RADIUS servers
        if (preg_match_all('/\/radius add .* address=([^\s]+) secret=([^\s]+)/', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $config['radius_servers'][] = [
                    'address' => $match[1],
                    'secret' => trim($match[2], '"'),
                    'timeout' => 3,
                ];
            }
        }

        // Extract profile
        if (preg_match('/default-profile="?([^"\s]+)"?/i', $script, $matches)) {
            $config['profile'] = $matches[1];
            $config['pppoe_profile'] = $matches[1];
        }

        // Extract interface list members
        if (preg_match_all('/\/interface list member add .* list="?([^"\s]+)"? .* interface="?([^"\s]+)"?/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $listName = $match[1];
                $interface = $match[2];
                if (str_contains(strtolower($listName), 'wan')) {
                    $config['wan_list'] = $listName;
                    $config['wan_interface'] = $interface;
                } else {
                    $config['pppoe_list'] = $config['pppoe_list'] ?? $listName;
                }
            }
        }

        if (preg_match('/\/ppp profile set .* interface-list=([^\s]+)/i', $script, $matches)) {
            $config['pal_list'] = $matches[1];
            $config['pppoe_active_list'] = $matches[1];
        }

        if ($hasHotspot) {
            if (preg_match('/\/ip hotspot profile add .* name="?([^"\s]+)"?/i', $script, $matches)) {
                $config['hotspot_profile'] = $matches[1];
            }

            if (preg_match('/\/ip hotspot add .* name="?([^"\s]+)"?/i', $script, $matches)) {
                $config['hotspot_server'] = $matches[1];
            }

            if (preg_match('/\/ip hotspot add .* interface="?([^"\s]+)"?/i', $script, $matches)) {
                $config['hotspot_interface'] = $matches[1];
            }

            if (preg_match('/\/ip hotspot user profile add .* name="?([^"\s]+)"?/i', $script, $matches)) {
                $config['hotspot_user_profile'] = $matches[1];
            }

            if (preg_match('/\/ip hotspot walled-garden add .* dst-host=([^\s]+)/i', $script, $matches)) {
                $config['portal_host'] = $matches[1];
            }
        }

        if (preg_match_all('/\/ip address add .* address=([0-9\.]+)\/(\d+).* interface="?([^"\s]+)"?/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $gatewayIp = $match[1];
                $cidr = (int) $match[2];
                $iface = $match[3];

                $config['gateway_ip'] = $gatewayIp;
                $config['cidr'] = $cidr;
                $config['hotspot_gateway_ip'] = $gatewayIp;
                $config['hotspot_cidr'] = $cidr;
                $config['hotspot_interface'] = $config['hotspot_interface'] ?? $iface;
                break;
            }
        }

        if (preg_match_all('/\/ip dhcp-server add .* name="?([^"\s]+)"? .* interface="?([^"\s]+)"? .* address-pool=([^\s]+)/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $config['dhcp_name'] = $match[1];
                $config['hotspot_dhcp_name'] = $match[1];
                $config['hotspot_interface'] = $config['hotspot_interface'] ?? $match[2];
                break;
            }
        }

        if (preg_match_all('/\/ip dhcp-server network add .* address=([0-9\.]+\/\d+).* gateway=([0-9\.]+).*?(?:dns-server="?([^"\s]+)"?)?.*comment="?([^"\s]+)"?/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $config['network_cidr'] = $match[1];
                $config['hotspot_network_cidr'] = $match[1];
                $config['gateway_ip'] = $match[2];
                $config['hotspot_gateway_ip'] = $match[2];
                if (!empty($match[3])) {
                    $config['dns_servers'] = $match[3];
                    $config['hotspot_dns_servers'] = $match[3];
                }
                $config['dhcp_network_comment'] = $match[4];
                $config['hotspot_dhcp_network_comment'] = $match[4];
                break;
            }
        }

        if (preg_match_all('/\/ip pool add .* name="?([^"\s]+)"?.* ranges=([0-9\.]+)-([0-9\.]+).*?(?:comment="?([^"\s]+)"?)?/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                $start = $match[2];
                $end = $match[3];
                $comment = $match[4] ?? '';

                $isHotspot = str_contains($name, 'hs-') || str_contains($comment, 'hs-');
                $isPppoe = str_contains($name, 'pppoe') || str_contains($name, 'pp-') || str_contains($comment, 'PPPoE') || str_contains($comment, 'pp-');

                if ($isHotspot) {
                    $config['pool_name'] = $name;
                    $config['hotspot_pool'] = $name;
                    $config['range_start'] = $start;
                    $config['range_end'] = $end;
                    $config['hotspot_range_start'] = $start;
                    $config['hotspot_range_end'] = $end;
                } elseif ($isPppoe) {
                    $config['pppoe_pool'] = $name;
                    $config['pppoe_range_start'] = $start;
                    $config['pppoe_range_end'] = $end;
                }
            }
        }

        if (preg_match('/\/ppp profile add .* name="?([^"\s]+)"?.* local-address=([0-9\.]+).*?(?:remote-address="?([^"\s]+)"?)?.*?(?:dns-server="?([^"\s]+)"?)?/i', $script, $matches)) {
            $config['pppoe_profile'] = $matches[1];
            $config['pppoe_gateway_ip'] = $matches[2];
            if (!empty($matches[3])) {
                $config['pppoe_remote_address'] = trim($matches[3], '"');
            }
            if (!empty($matches[4])) {
                $config['pppoe_dns_servers'] = $matches[4];
            }
        }

        if (preg_match('/\/ppp profile set .* local-address=([0-9\.]+).*?(?:remote-address="?([^"\s]+)"?)?/i', $script, $matches)) {
            $config['pppoe_gateway_ip'] = $matches[1];
            if (!empty($matches[2])) {
                $config['pppoe_remote_address'] = trim($matches[2], '"');
            }
        }

        if (preg_match('/\/ppp profile set .* dns-server="?([^"\s]+)"?/i', $script, $matches)) {
            $config['pppoe_dns_servers'] = $matches[1];
        }

        if (preg_match('/\/ppp profile set .* only-one=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_only_one'] = $matches[1];
        }

        if (preg_match('/\/ppp profile set .* change-tcp-mss=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_change_tcp_mss'] = $matches[1];
        }

        if (preg_match('/\/ppp profile set .* use-compression=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_use_compression'] = $matches[1];
        }

        if (preg_match('/\/interface pppoe-server server set .* authentication=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_authentication'] = $matches[1];
        }

        if (preg_match('/\/interface pppoe-server server set .* one-session-per-host=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_one_session_per_host'] = $matches[1];
        }

        if (preg_match('/\/interface pppoe-server server set .* keepalive-timeout=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_keepalive_timeout'] = $matches[1];
        }

        if (preg_match('/\/interface pppoe-server server set .* max-mtu=([^\s]+) .* max-mru=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_max_mtu'] = $matches[1];
            $config['pppoe_max_mru'] = $matches[2];
        }

        if (preg_match('/\/ppp aaa set .* interim-update=([^\s]+)/i', $script, $matches)) {
            $config['pppoe_interim_update'] = $matches[1];
        }

        if (preg_match('/\/ip dhcp-client add .* interface="?([^"\s]+)"?.*?(?:disabled=([^\s]+))?/i', $script, $matches)) {
            $config['wan_dhcp_client'] = true;
            $config['wan_dhcp_client_interface'] = $matches[1];
            if (!empty($matches[2])) {
                $config['wan_dhcp_client_disabled'] = $matches[2];
            }
        }

        if (preg_match_all('/\/interface ethernet set \[find (?:default-name|name)=("?)([^"\]]+)\1\] .*?disable-running-check=([^\s]+)/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (strtolower($match[3]) === 'no') {
                    $config['disable_running_check_interfaces'][] = trim($match[2]);
                }
            }
        }

        if (preg_match_all('/\/interface ethernet set .*?disable-running-check=([^\s]+).*?\[find (?:default-name|name)=("?)([^"\]]+)\2\]/i', $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (strtolower($match[1]) === 'no') {
                    $config['disable_running_check_interfaces'][] = trim($match[3]);
                }
            }
        }

        if (preg_match('/\/interface pppoe-server server add .* interface="?([^"\s]+)"?/i', $script, $matches)) {
            $config['pppoe_interface'] = $matches[1];
        }

        if (!$foundBridge && $config['service_type'] === RouterService::TYPE_PPPOE) {
            $config['bridge'] = 'pppoe-br-' . $router->id;
        }

        if (!$foundBridge && $config['service_type'] !== RouterService::TYPE_PPPOE) {
            $config['bridge'] = $config['bridge'] ?? null;
        }

        // Reduced: Removed API config extraction log
        
        return $config;
    }

    /**
     * Execute entire script at once (for normal/high-end devices)
     */
    private function executeSingleScript(
        SshExecutor $ssh,
        string $serviceScript,
        Router $router,
        callable $validator,
        string $remoteScriptFile,
        string $tempFile
    ): array {
        $attempt = 0;
        $maxAttempts = 3;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                if ($attempt > 1) {
                    $ssh->disconnect(false);
                    sleep(1);
                    $ssh->connect();
                }

                Log::info('Starting script execution', [
                    'router_id' => $router->id,
                    'attempt' => $attempt,
                    'temp_file' => $tempFile,
                    'remote_file' => $remoteScriptFile,
                ]);

                // Ensure Unix line endings
                $cleanScript = str_replace(["\r\n", "\r"], "\n", $serviceScript);
                $cleanScript = preg_replace('/^\xEF\xBB\xBF/', '', $cleanScript);
                file_put_contents($tempFile, $cleanScript);
                
                Log::info('Script written to temp file', [
                    'router_id' => $router->id,
                    'temp_file' => $tempFile,
                    'size' => strlen($cleanScript),
                ]);

                Log::info('Starting file upload via SFTP', ['router_id' => $router->id]);
                $ssh->uploadFile($tempFile, $remoteScriptFile);
                Log::info('File upload completed', ['router_id' => $router->id]);
                
                Log::info('Starting script import', ['router_id' => $router->id]);
                $ssh->importFile($remoteScriptFile);
                Log::info('Script import completed', ['router_id' => $router->id]);

                // Wait for RouterOS to process
                $sleepTime = RouterResourceManager::getRouterTier($router) === 'low_end' ? 1 : 3;
                Log::info('Waiting for RouterOS to process', ['router_id' => $router->id, 'sleep_seconds' => $sleepTime]);
                sleep($sleepTime);

                Log::info('Starting validation', ['router_id' => $router->id]);
                $validationResult = $validator($ssh);
                Log::info('Validation completed', ['router_id' => $router->id, 'valid' => $validationResult['valid'] ?? false]);
                if (!($validationResult['valid'] ?? false)) {
                    throw new \Exception($validationResult['error'] ?? 'Validation failed');
                }

                return ['success' => true, 'message' => 'Configuration applied successfully'];

            } catch (\Exception $e) {
                Log::warning('Script execution attempt failed', [
                    'router_id' => $router->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= $maxAttempts) {
                    return [
                        'success' => false,
                        'message' => 'Failed after ' . $maxAttempts . ' attempts: ' . $e->getMessage(),
                    ];
                }

                sleep(2 * $attempt);
            }
        }

        return ['success' => false, 'message' => 'All attempts exhausted'];
    }
}
