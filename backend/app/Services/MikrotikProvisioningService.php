<?php

namespace App\Services;

use App\Events\ProvisioningFailed;
use App\Events\RouterProvisioningProgress;
use App\Models\Router;
use App\Models\RouterConfig;
use App\Services\MikroTik\ConfigurationService;
use App\Services\MikroTik\SshExecutor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
            Log::info('MikrotikProvisioningService: Using provisioning service for network segmentation');
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
                Log::info('Fetching live data via provisioning service', [
                    'router_id' => $router->id,
                    'context' => $context
                ]);
                
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
            Log::debug('Preparing to fetch router data', ['router_id' => $router->id, 'context' => $context]);
            
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
                
                Log::info('SSH interface fetch successful', [
                    'router_id' => $router->id,
                    'interface_count' => count($result['interfaces'] ?? [])
                ]);
                
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
                
                Log::info('SSH live data fetch successful', [
                    'router_id' => $router->id
                ]);
                
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
     * Get SNMP configuration script (SNMPv3)
     */
    protected function getSnmpConfigScript(): string
    {
        $user = env('TELEGRAF_SNMPV3_USER', 'snmpmonitor');
        $authPassword = env('TELEGRAF_SNMPV3_AUTH_PASSWORD', bin2hex(random_bytes(16)));
        $privPassword = env('TELEGRAF_SNMPV3_PRIV_PASSWORD', bin2hex(random_bytes(16)));
        
        return <<<SCRIPT
/snmp set enabled=yes
/snmp set contact="Network Admin"
/snmp set location="Managed by WifiCore"
:do { /snmp community remove [find name=public] } on-error={}
/snmp community add name={$user} addresses=0.0.0.0/0 security=private authentication-protocol=SHA256 authentication-password="{$authPassword}" encryption-protocol=aes encryption-password="{$privPassword}"
SCRIPT;
    }

    /**
     * Get router details (DB + live data)
     */
    public function getRouterDetails(Router $router): array
    {
        try {
            $live = $this->fetchLiveRouterData($router, 'details', false);
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
     * Verify router connectivity via SSH (no RouterOS API)
     */
    public function verifyConnectivity(Router $router): array
    {
        // Use provisioning service if enabled for this router
        if ($this->shouldUseProvisioningService($router)) {
            try {
                Log::info('Verifying connectivity via provisioning service', [
                    'router_id' => $router->id
                ]);
                
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
            Log::info('Verifying connectivity via SSH:', [
                'router_id' => $router->id,
                'ip_address' => $router->ip_address,
                'vpn_ip' => $router->vpn_ip,
                'username' => $router->username,
                'port' => $router->port,
            ]);

            // Use short timeout for connectivity check
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

            Log::info('Connectivity verified successfully via SSH:', [
                'router_id' => $router->id,
                'model' => $model,
                'os_version' => $osVersion,
                'interfaces_count' => $interfacesCount,
            ]);

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

        $lockTtlSeconds = (int) env('MIKROTIK_PROVISION_LOCK_TTL', 300);
        $lockWaitSeconds = (int) env('MIKROTIK_PROVISION_LOCK_WAIT', 30);

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
            $ssh = new SshExecutor($router, 30);
            
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

            // 4. Apply configuration directly via SSH commands (no file upload/import)
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
                $snmpConfig = $this->getSnmpConfigScript();
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
                            if (preg_match('/\/interface pppoe-server server\s+add\s+name=(?:\\"([^\\"]+)\\"|([^\s]+))/i', $serviceScript, $m)) {
                                $expectedPppoeServer = $m[1] ?: ($m[2] ?? null);
                            }
                        }

                        $hotspotCount = (int) trim($sshExecutor->exec('/ip hotspot print count-only'));
                        $pppoeCount = (int) trim($sshExecutor->exec('/interface pppoe-server server print count-only'));

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
                            }
                        }

                        if ($expectsPppoe && $expectedPppoeServer) {
                            $pppoeServerCount = (int) trim($sshExecutor->exec('/interface pppoe-server server print count-only where name="' . $expectedPppoeServer . '"'));
                            if ($pppoeServerCount < 1) {
                                return [
                                    'valid' => false,
                                    'error' => 'PPPoE deployment validation failed (missing PPPoE server: ' . $expectedPppoeServer . ')'
                                ];
                            }
                        }

                        if ($expectsHotspot && !$expectsPppoe) {
                            if ($hotspotCount > 0) {
                                Log::info('Service deployment validation passed (hotspot)', [
                                    'router_id' => $router->id,
                                    'hotspot_count' => $hotspotCount,
                                    'pppoe_count' => $pppoeCount,
                                    'expected_hotspot_server' => $expectedHotspotServer,
                                    'expected_hotspot_profile' => $expectedHotspotProfile,
                                    'expected_hotspot_bridge' => $expectedHotspotBridge,
                                ]);
                                return ['valid' => true];
                            }

                            return [
                                'valid' => false,
                                'error' => 'Hotspot deployment validation failed (hotspot: 0)'
                            ];
                        }

                        if ($expectsPppoe && !$expectsHotspot) {
                            if ($pppoeCount > 0) {
                                Log::info('Service deployment validation passed (pppoe)', [
                                    'router_id' => $router->id,
                                    'hotspot_count' => $hotspotCount,
                                    'pppoe_count' => $pppoeCount,
                                    'expected_pppoe_server' => $expectedPppoeServer,
                                ]);
                                return ['valid' => true];
                            }

                            return [
                                'valid' => false,
                                'error' => 'PPPoE deployment validation failed (pppoe: 0)'
                            ];
                        }

                        if ($expectsHotspot && $expectsPppoe) {
                            if ($hotspotCount > 0 && $pppoeCount > 0) {
                                Log::info('Service deployment validation passed (hybrid)', [
                                    'router_id' => $router->id,
                                    'hotspot_count' => $hotspotCount,
                                    'pppoe_count' => $pppoeCount,
                                    'expected_hotspot_server' => $expectedHotspotServer,
                                    'expected_hotspot_profile' => $expectedHotspotProfile,
                                    'expected_hotspot_bridge' => $expectedHotspotBridge,
                                    'expected_pppoe_server' => $expectedPppoeServer,
                                ]);
                                return ['valid' => true];
                            }

                            return [
                                'valid' => false,
                                'error' => "Hybrid deployment validation failed (hotspot: {$hotspotCount}, pppoe: {$pppoeCount})"
                            ];
                        }

                        if ($hotspotCount > 0 || $pppoeCount > 0) {
                            Log::info('Service deployment validation passed', [
                                'router_id' => $router->id,
                                'hotspot_count' => $hotspotCount,
                                'pppoe_count' => $pppoeCount,
                            ]);
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

                $remoteScriptFile = $scriptName . '.rsc';
                $tempFile = tempnam(sys_get_temp_dir(), 'wificore_rsc_');
                if ($tempFile === false) {
                    throw new \Exception('Failed to create temp file for router script');
                }

                try {
                    file_put_contents($tempFile, $serviceScript);

                    if ($broadcast) {
                        RouterProvisioningProgress::dispatch(
                            $router->id,
                            'executing_script',
                            70,
                            'Uploading and importing configuration script (with automatic retry)',
                            ['script_file' => $remoteScriptFile]
                        );
                    }

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

                            $ssh->uploadFile($tempFile, $remoteScriptFile);
                            $ssh->importFile($remoteScriptFile);

                            $validationResult = $validator($ssh);
                            if (!($validationResult['valid'] ?? false)) {
                                throw new \Exception($validationResult['error'] ?? 'Validation failed');
                            }

                            Log::info('Configuration script imported successfully', [
                                'router_id' => $router->id,
                                'script_name' => $scriptName,
                                'attempts' => $attempt,
                            ]);

                            break;
                        } catch (\Exception $e) {
                            Log::warning('Configuration import attempt failed', [
                                'router_id' => $router->id,
                                'script_name' => $scriptName,
                                'attempt' => $attempt,
                                'max_attempts' => $maxAttempts,
                                'error' => $e->getMessage(),
                            ]);

                            if ($attempt >= $maxAttempts) {
                                throw $e;
                            }

                            sleep(2 * $attempt);
                        }
                    }
                } finally {
                    try {
                        $ssh->exec('/file remove [find name="' . $remoteScriptFile . '"]');
                    } catch (\Throwable $e) {
                    }

                    if (is_string($tempFile) && $tempFile !== '' && is_file($tempFile)) {
                        @unlink($tempFile);
                    }
                }

                // Brief wait for router to stabilize
                sleep(1);

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

            $result = [
                'success' => true,
                'message' => 'Configuration applied successfully via SSH',
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'script_name' => $scriptName,
                'router_id' => $router->id,
                'method' => 'SSH'
            ];

            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'completed',
                    100,
                    'Service deployment completed successfully',
                    array_merge($result, ['status' => 'completed'])
                );
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
        
        return <<<EOT
/ip address add address={$router->ip_address} interface=ether2
/ip service set api disabled=no port={$router->port}
/user add name={$router->username} password="{$decryptedPassword}" group=full
/system identity set name="{$router->name}"
/system note set note="Managed by Traidnet Solution LTD"
EOT;
    }
}
