<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Models\RouterConfig;
use Illuminate\Support\Facades\Log;

/**
 * MikroTik Configuration Service
 * 
 * Orchestrates configuration generation for MikroTik routers
 * Delegates to specialized services (Hotspot, PPPoE) for specific configurations
 */
class ConfigurationService extends TenantAwareService
{
    protected HotspotService $hotspotService;
    protected PPPoEService $pppoeService;
    
    public function __construct()
    {
        $this->hotspotService = new HotspotService();
        $this->pppoeService = new PPPoEService();
    }
    
    /**
     * Generate service configuration based on user selection
     * 
     * @param Router $router
     * @param array $data Configuration data from frontend
     * @return array ['service_script' => string]
     * @throws \Exception
     */
    public function generateServiceConfig(Router $router, array $data): array
    {
        Log::info('ConfigurationService: Generating service configuration', [
            'router_id' => $router->id,
            'enable_hotspot' => $data['enable_hotspot'] ?? false,
            'enable_pppoe' => $data['enable_pppoe'] ?? false,
        ]);
        
        $scripts = [];
        
        // Generate Hotspot configuration
        if ($data['enable_hotspot'] ?? false) {
            $scripts[] = $this->generateHotspotConfig($router, $data);
        }
        
        // Generate PPPoE configuration
        if ($data['enable_pppoe'] ?? false) {
            $scripts[] = $this->generatePPPoEConfig($router, $data);
        }
        
        if (empty($scripts)) {
            throw new \Exception('No service selected. Please enable at least one service (Hotspot or PPPoE).');
        }
        
        // Combine scripts with proper line breaks for RouterOS - no escaping needed
        $serviceScript = implode("\n\n", $scripts);
        
        // Save to database
        $this->saveConfiguration($router, $serviceScript);
        
        Log::info('Service configuration generated and saved', [
            'router_id' => $router->id,
            'script_size' => strlen($serviceScript),
            'has_hotspot' => $data['enable_hotspot'] ?? false,
            'has_pppoe' => $data['enable_pppoe'] ?? false,
        ]);
        
        return ['service_script' => $serviceScript];
    }
    
    /**
     * Generate hotspot configuration
     */
    private function generateHotspotConfig(Router $router, array $data): string
    {
        if (empty($data['hotspot_interfaces'])) {
            throw new \Exception('Hotspot interfaces are required when hotspot is enabled');
        }
        
        $options = [
            'bridge_name' => $data['hotspot_bridge_name'] ?? "br-hotspot-{$router->id}",
            'gateway' => $data['hotspot_gateway'] ?? '192.168.88.1',
            'ip_pool' => $data['hotspot_ip_pool'] ?? '192.168.88.10-192.168.88.254',
            'network' => $data['hotspot_network'] ?? '192.168.88.0/24',
            'dns_servers' => $data['dns_servers'] ?? '8.8.8.8,1.1.1.1',
            'rate_limit' => $data['hotspot_rate_limit'] ?? '10M/10M',
            'session_timeout' => $data['session_timeout'] ?? '4h',
            'idle_timeout' => $data['idle_timeout'] ?? '15m',
            'profile_name' => $data['hotspot_profile_name'] ?? "hs-profile-{$router->id}",
            'radius_ip' => env('RADIUS_SERVER_HOST', 'wificore-freeradius'),
            'radius_secret' => env('RADIUS_SECRET', 'testing123'),
            'portal_url' => $data['portal_url'] ?? 'https://wificore.traidsolutions.com/hotspot/login',
        ];
        
        Log::info('Generating hotspot configuration', [
            'router_id' => $router->id,
            'interfaces' => $data['hotspot_interfaces'],
            'options' => $options,
        ]);
        
        return $this->hotspotService->generateConfig(
            $data['hotspot_interfaces'],
            $router->id,
            $options
        );
    }
    
    /**
     * Generate PPPoE configuration
     */
    private function generatePPPoEConfig(Router $router, array $data): string
    {
        if (empty($data['pppoe_interfaces'])) {
            throw new \Exception('PPPoE interfaces are required when PPPoE is enabled');
        }
        
        $options = [
            'gateway' => $data['pppoe_gateway'] ?? '192.168.89.1',
            'ip_pool' => $data['pppoe_ip_pool'] ?? '192.168.89.10-192.168.89.254',
            'dns_servers' => $data['dns_servers'] ?? '8.8.8.8,1.1.1.1',
            'auth_methods' => $data['pppoe_auth_methods'] ?? 'chap,mschap2',
            'service_name' => $data['pppoe_service_name'] ?? 'pppoe-service',
            'use_radius' => $data['pppoe_use_radius'] ?? true,
            'mtu' => $data['pppoe_mtu'] ?? '1480',
            'mru' => $data['pppoe_mru'] ?? '1480',
            'keepalive_timeout' => $data['pppoe_keepalive_timeout'] ?? '10',
            'max_sessions' => $data['pppoe_max_sessions'] ?? '0',
        ];
        
        Log::info('Generating PPPoE configuration', [
            'router_id' => $router->id,
            'interfaces' => $data['pppoe_interfaces'],
            'options' => $options,
        ]);
        
        return $this->pppoeService->generateConfig(
            $data['pppoe_interfaces'],
            $router->id,
            $options
        );
    }
    
    /**
     * Save configuration to database
     */
    private function saveConfiguration(Router $router, string $script): void
    {
        try {
            $config = RouterConfig::updateOrCreate(
                [
                    'router_id' => $router->id,
                    'config_type' => 'service'
                ],
                [
                    'config_content' => $script
                ]
            );
            
            Log::info('Configuration saved to database', [
                'router_id' => $router->id,
                'config_id' => $config->id,
                'script_size' => strlen($script),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save configuration to database', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to save configuration: ' . $e->getMessage());
        }
    }
    
    /**
     * Get saved configuration from database
     */
    public function getSavedConfiguration(Router $router): ?string
    {
        $config = RouterConfig::where('router_id', $router->id)
            ->where('config_type', 'service')
            ->first();
        
        return $config?->config_content;
    }
    
    /**
     * Validate configuration before deployment
     */
    public function validateConfiguration(string $script): bool
    {
        // Basic validation
        if (empty(trim($script))) {
            return false;
        }
        
        // Check for dangerous commands (optional)
        $dangerousCommands = [
            '/system reset-configuration',
            '/system reboot',
            '/file remove',
            '/user remove',
        ];
        
        foreach ($dangerousCommands as $cmd) {
            if (stripos($script, $cmd) !== false) {
                Log::warning('Configuration contains potentially dangerous command', [
                    'command' => $cmd,
                ]);
                // Don't reject, just warn
            }
        }
        
        return true;
    }
}
