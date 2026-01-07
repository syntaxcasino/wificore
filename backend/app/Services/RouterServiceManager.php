<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterService;
use App\Services\MikroTik\HotspotService;
use App\Services\MikroTik\PPPoEService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RouterServiceManager extends TenantAwareService
{
    protected InterfaceManagementService $interfaceManager;
    protected HotspotService $hotspotService;
    protected PPPoEService $pppoeService;
    protected TenantIpamService $ipamService;
    protected VlanManager $vlanManager;

    public function __construct(
        InterfaceManagementService $interfaceManager,
        HotspotService $hotspotService,
        PPPoEService $pppoeService,
        TenantIpamService $ipamService,
        VlanManager $vlanManager
    ) {
        $this->interfaceManager = $interfaceManager;
        $this->hotspotService = $hotspotService;
        $this->pppoeService = $pppoeService;
        $this->ipamService = $ipamService;
        $this->vlanManager = $vlanManager;
    }

    /**
     * Configure service with zero-config defaults
     * User only provides: interface + service type
     * SaaS handles: IP pools, VLANs, RADIUS, everything else
     * 
     * @param Router $router
     * @param string $interface
     * @param string $serviceType (hotspot|pppoe|hybrid|none)
     * @param array $advancedOptions (optional)
     * @return RouterService
     */
    public function configureService(
        Router $router,
        string $interface,
        string $serviceType,
        array $advancedOptions = []
    ): RouterService {
        DB::beginTransaction();
        
        try {
            $this->setTenant($router->tenant_id);
            
            // Validate service type
            if (!in_array($serviceType, ['hotspot', 'pppoe', 'hybrid', 'none'])) {
                throw new \Exception("Invalid service type: {$serviceType}");
            }
            
            // Handle 'none' - remove any existing service
            if ($serviceType === 'none') {
                $this->removeServiceFromInterface($router, $interface);
                DB::commit();
                return null;
            }
            
            // Handle hybrid service (requires VLAN enforcement)
            if ($serviceType === 'hybrid') {
                return $this->configureHybridService($router, $interface, $advancedOptions);
            }
            
            // Handle single service (hotspot or pppoe)
            return $this->configureSingleService($router, $interface, $serviceType, $advancedOptions);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to configure service', [
                'router_id' => $router->id,
                'interface' => $interface,
                'service_type' => $serviceType,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Configure single service (hotspot or pppoe)
     */
    private function configureSingleService(
        Router $router,
        string $interface,
        string $serviceType,
        array $advancedOptions = []
    ): RouterService {
        // Get or create IP pool for this service type
        $ipPool = $advancedOptions['ip_pool_id'] 
            ? TenantIpPool::find($advancedOptions['ip_pool_id'])
            : $this->ipamService->getOrCreateServicePool($router->tenant, $serviceType);
        
        // Generate RADIUS profile name
        $radiusProfile = $advancedOptions['radius_profile'] 
            ?? "{$serviceType}-{$router->tenant_id}";
        
        // Create service record
        $service = RouterService::create([
            'router_id' => $router->id,
            'interface_name' => $interface,
            'service_type' => $serviceType,
            'service_name' => $advancedOptions['service_name'] ?? ucfirst($serviceType) . ' Service',
            'ip_pool_id' => $ipPool->id,
            'vlan_id' => null,
            'vlan_required' => false,
            'radius_profile' => $radiusProfile,
            'advanced_config' => $advancedOptions,
            'deployment_status' => RouterService::DEPLOYMENT_PENDING,
            'status' => RouterService::STATUS_INACTIVE,
            'enabled' => true,
        ]);
        
        Log::info('Service configured (zero-config)', [
            'router_id' => $router->id,
            'service_id' => $service->id,
            'service_type' => $serviceType,
            'interface' => $interface,
            'ip_pool' => $ipPool->network_cidr,
        ]);
        
        DB::commit();
        
        return $service;
    }
    
    /**
     * Configure hybrid service with VLAN enforcement
     */
    private function configureHybridService(
        Router $router,
        string $interface,
        array $advancedOptions = []
    ): RouterService {
        // Get IP pools for both services
        $hotspotPool = $advancedOptions['hotspot_pool_id']
            ? TenantIpPool::find($advancedOptions['hotspot_pool_id'])
            : $this->ipamService->getOrCreateServicePool($router->tenant, 'hotspot');
            
        $pppoePool = $advancedOptions['pppoe_pool_id']
            ? TenantIpPool::find($advancedOptions['pppoe_pool_id'])
            : $this->ipamService->getOrCreateServicePool($router->tenant, 'pppoe');
        
        // Allocate VLANs (auto or manual)
        $hotspotVlan = $advancedOptions['hotspot_vlan'] 
            ?? $this->vlanManager->allocateVlanForService($router, 'hotspot');
            
        $pppoeVlan = $advancedOptions['pppoe_vlan']
            ?? $this->vlanManager->allocateVlanForService($router, 'pppoe');
        
        // Create hybrid service record
        $service = RouterService::create([
            'router_id' => $router->id,
            'interface_name' => $interface,
            'service_type' => RouterService::TYPE_HYBRID,
            'service_name' => $advancedOptions['service_name'] ?? 'Hybrid Service',
            'ip_pool_id' => null, // Hybrid uses multiple pools
            'vlan_id' => null, // Hybrid uses multiple VLANs
            'vlan_required' => true,
            'radius_profile' => "hybrid-{$router->tenant_id}",
            'advanced_config' => array_merge($advancedOptions, [
                'hotspot_pool_id' => $hotspotPool->id,
                'pppoe_pool_id' => $pppoePool->id,
                'hotspot_vlan' => $hotspotVlan,
                'pppoe_vlan' => $pppoeVlan,
            ]),
            'deployment_status' => RouterService::DEPLOYMENT_PENDING,
            'status' => RouterService::STATUS_INACTIVE,
            'enabled' => true,
        ]);
        
        // Create VLAN records
        $this->vlanManager->createServiceVlan($service, $hotspotVlan, $interface, 'hotspot');
        $this->vlanManager->createServiceVlan($service, $pppoeVlan, $interface, 'pppoe');
        
        Log::info('Hybrid service configured with VLAN separation', [
            'router_id' => $router->id,
            'service_id' => $service->id,
            'interface' => $interface,
            'hotspot_vlan' => $hotspotVlan,
            'pppoe_vlan' => $pppoeVlan,
        ]);
        
        DB::commit();
        
        return $service;
    }
    
    /**
     * Remove service from interface
     */
    private function removeServiceFromInterface(Router $router, string $interface): void
    {
        RouterService::where('router_id', $router->id)
            ->where('interface_name', $interface)
            ->delete();
            
        Log::info('Removed service from interface', [
            'router_id' => $router->id,
            'interface' => $interface,
        ]);
    }
    
    /**
     * Deploy a service to a router (legacy method - kept for compatibility)
     * 
     * @param Router $router
     * @param string $serviceType
     * @param array $config
     * @return RouterService
     */
    public function deployService(Router $router, string $serviceType, array $config): RouterService
    {
        DB::beginTransaction();
        
        try {
            $interfaces = $config['interfaces'] ?? [];
            
            // Validate interfaces
            $validation = $this->interfaceManager->validateInterfaceAssignment(
                $router,
                $serviceType,
                $interfaces
            );
            
            if (!$validation['valid']) {
                throw new \Exception('Interface validation failed: ' . implode(', ', $validation['errors']));
            }
            
            // Reserve interfaces
            $this->interfaceManager->reserveInterfaces($router, $serviceType, $interfaces);
            
            // Generate service configuration
            $serviceConfig = $this->generateServiceConfig($router, $serviceType, $config);
            
            // Create service record
            $service = RouterService::create([
                'router_id' => $router->id,
                'service_type' => $serviceType,
                'service_name' => $config['service_name'] ?? ucfirst($serviceType) . ' Service',
                'interfaces' => $interfaces,
                'configuration' => $config,
                'status' => RouterService::STATUS_INACTIVE,
                'enabled' => true,
            ]);
            
            Log::info("Service deployed successfully", [
                'router_id' => $router->id,
                'service_id' => $service->id,
                'service_type' => $serviceType,
            ]);
            
            DB::commit();
            
            return $service;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to deploy service", [
                'router_id' => $router->id,
                'service_type' => $serviceType,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Update service configuration
     * 
     * @param RouterService $service
     * @param array $config
     * @return RouterService
     */
    public function updateService(RouterService $service, array $config): RouterService
    {
        DB::beginTransaction();
        
        try {
            $newInterfaces = $config['interfaces'] ?? $service->interfaces;
            $oldInterfaces = $service->interfaces;
            
            // If interfaces changed, validate and update reservations
            if ($newInterfaces !== $oldInterfaces) {
                // Release old interfaces
                $this->interfaceManager->releaseInterfaces(
                    $service->router,
                    $service->service_type
                );
                
                // Validate and reserve new interfaces
                $validation = $this->interfaceManager->validateInterfaceAssignment(
                    $service->router,
                    $service->service_type,
                    $newInterfaces
                );
                
                if (!$validation['valid']) {
                    throw new \Exception('Interface validation failed: ' . implode(', ', $validation['errors']));
                }
                
                $this->interfaceManager->reserveInterfaces(
                    $service->router,
                    $service->service_type,
                    $newInterfaces
                );
            }
            
            // Update service
            $service->update([
                'interfaces' => $newInterfaces,
                'configuration' => array_merge($service->configuration ?? [], $config),
                'service_name' => $config['service_name'] ?? $service->service_name,
            ]);
            
            Log::info("Service updated successfully", [
                'service_id' => $service->id,
                'router_id' => $service->router_id,
            ]);
            
            DB::commit();
            
            return $service->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to update service", [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Stop/disable a service
     * 
     * @param RouterService $service
     * @return bool
     */
    public function stopService(RouterService $service): bool
    {
        try {
            $service->update([
                'status' => RouterService::STATUS_STOPPING,
            ]);
            
            // TODO: Send stop command to router
            
            $service->update([
                'status' => RouterService::STATUS_INACTIVE,
                'enabled' => false,
            ]);
            
            Log::info("Service stopped successfully", [
                'service_id' => $service->id,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to stop service", [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
            
            $service->update(['status' => RouterService::STATUS_ERROR]);
            
            return false;
        }
    }

    /**
     * Start a service
     * 
     * @param RouterService $service
     * @return bool
     */
    public function startService(RouterService $service): bool
    {
        try {
            $service->update([
                'status' => RouterService::STATUS_STARTING,
            ]);
            
            // TODO: Send start command to router
            
            $service->update([
                'status' => RouterService::STATUS_ACTIVE,
                'enabled' => true,
            ]);
            
            Log::info("Service started successfully", [
                'service_id' => $service->id,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to start service", [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
            
            $service->update(['status' => RouterService::STATUS_ERROR]);
            
            return false;
        }
    }

    /**
     * Restart a service
     * 
     * @param RouterService $service
     * @return bool
     */
    public function restartService(RouterService $service): bool
    {
        return $this->stopService($service) && $this->startService($service);
    }

    /**
     * Get all services for a router
     * 
     * @param Router $router
     * @return Collection
     */
    public function getRouterServices(Router $router): Collection
    {
        return $router->services()->with('router')->get();
    }

    /**
     * Get service status
     * 
     * @param RouterService $service
     * @return array
     */
    public function getServiceStatus(RouterService $service): array
    {
        return [
            'id' => $service->id,
            'service_type' => $service->service_type,
            'service_name' => $service->service_name,
            'status' => $service->status,
            'enabled' => $service->enabled,
            'active_users' => $service->active_users,
            'total_sessions' => $service->total_sessions,
            'interfaces' => $service->interfaces,
            'last_checked_at' => $service->last_checked_at,
        ];
    }

    /**
     * Sync service status from router
     * 
     * @param Router $router
     * @return array
     */
    public function syncServiceStatus(Router $router): array
    {
        // TODO: Implement actual status sync from router
        
        $services = $router->services;
        $synced = [];
        
        foreach ($services as $service) {
            $service->update([
                'last_checked_at' => now(),
            ]);
            
            $synced[] = $this->getServiceStatus($service);
        }
        
        Log::info("Service status synced", [
            'router_id' => $router->id,
            'services_count' => count($synced),
        ]);
        
        return $synced;
    }

    /**
     * Remove a service
     * 
     * @param RouterService $service
     * @return bool
     */
    public function removeService(RouterService $service): bool
    {
        DB::beginTransaction();
        
        try {
            // Release interfaces
            $this->interfaceManager->releaseInterfaces(
                $service->router,
                $service->service_type
            );
            
            // Delete service
            $service->delete();
            
            Log::info("Service removed successfully", [
                'service_id' => $service->id,
                'router_id' => $service->router_id,
            ]);
            
            DB::commit();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to remove service", [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Generate service configuration
     * 
     * @param Router $router
     * @param string $serviceType
     * @param array $config
     * @return string
     */
    protected function generateServiceConfig(Router $router, string $serviceType, array $config): string
    {
        $interfaces = $config['interfaces'] ?? [];
        
        return match($serviceType) {
            'hotspot' => $this->hotspotService->generateConfig($interfaces, $router->id, $config),
            'pppoe' => $this->pppoeService->generateConfig($interfaces, $router->id, $config),
            default => '',
        };
    }
}
