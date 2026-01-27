<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterService;
use App\Models\TenantIpPool;
use App\Services\MikroTik\HotspotService;
use App\Services\MikroTik\PPPoEService;
use App\Services\TenantContext;
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
    protected TenantContext $tenantContext;

    public function __construct(
        InterfaceManagementService $interfaceManager,
        HotspotService $hotspotService,
        PPPoEService $pppoeService,
        TenantIpamService $ipamService,
        VlanManager $vlanManager,
        TenantContext $tenantContext
    ) {
        $this->interfaceManager = $interfaceManager;
        $this->hotspotService = $hotspotService;
        $this->pppoeService = $pppoeService;
        $this->ipamService = $ipamService;
        $this->vlanManager = $vlanManager;
        $this->tenantContext = $tenantContext;
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
    ): ?RouterService {
        DB::beginTransaction();
        
        try {
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

            // Ensure one service per interface (deterministic + idempotent)
            $existingService = RouterService::where('router_id', $router->id)
                ->where('interface_name', $interface)
                ->first();

            if ($existingService && $existingService->service_type !== $serviceType) {
                $existingService->delete();
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
        // Get tenant from context (schema-based multi-tenancy)
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \Exception('Tenant context not set. Cannot configure service.');
        }
        
        // Get or create IP pool for this service type (tenant-scoped)
        if (!empty($advancedOptions['ip_pool_id'])) {
            $ipPool = TenantIpPool::where('id', $advancedOptions['ip_pool_id'])
                ->firstOrFail();
        } else {
            $ipPool = $this->ipamService->getOrCreateServicePool($tenant, $serviceType);
        }
        
        // Generate RADIUS profile name
        $radiusProfile = !empty($advancedOptions['radius_profile'])
            ? $advancedOptions['radius_profile']
            : "{$serviceType}-{$tenant->id}";

        $existingService = RouterService::where('router_id', $router->id)
            ->where('interface_name', $interface)
            ->first();

        if ($existingService && $existingService->service_type !== $serviceType) {
            $existingService->delete();
            $existingService = null;
        }
        
        $attributes = [
            'router_id' => $router->id,
            'interface_name' => $interface,
            'interfaces' => [$interface],
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
        ];

        // Upsert: update existing service on this interface instead of creating duplicates
        $service = $existingService
            ? tap($existingService)->update($attributes)
            : RouterService::create($attributes);
        
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
        // Get tenant from context (schema-based multi-tenancy)
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \Exception('Tenant context not set. Cannot configure hybrid service.');
        }
        
        $existingService = RouterService::where('router_id', $router->id)
            ->where('interface_name', $interface)
            ->first();

        if ($existingService) {
            $existingService->delete();
        }

        // Get IP pools for both services
        if (!empty($advancedOptions['hotspot_pool_id'])) {
            $hotspotPool = TenantIpPool::where('id', $advancedOptions['hotspot_pool_id'])
                ->firstOrFail();
        } else {
            $hotspotPool = $this->ipamService->getOrCreateServicePool($tenant, 'hotspot');
        }
            
        if (!empty($advancedOptions['pppoe_pool_id'])) {
            $pppoePool = TenantIpPool::where('id', $advancedOptions['pppoe_pool_id'])
                ->firstOrFail();
        } else {
            $pppoePool = $this->ipamService->getOrCreateServicePool($tenant, 'pppoe');
        }
        
        // Allocate VLANs (auto or manual)
        $hotspotVlan = !empty($advancedOptions['hotspot_vlan'])
            ? (int) $advancedOptions['hotspot_vlan']
            : $this->vlanManager->allocateVlanForService($router, 'hotspot');
            
        $pppoeVlan = !empty($advancedOptions['pppoe_vlan'])
            ? (int) $advancedOptions['pppoe_vlan']
            : $this->vlanManager->allocateVlanForService($router, 'pppoe');

        if ($hotspotVlan === $pppoeVlan) {
            throw new \Exception('Hybrid service requires different VLAN IDs for Hotspot and PPPoE');
        }
        
        // Create hybrid service record
        $service = RouterService::create([
            'router_id' => $router->id,
            'interface_name' => $interface,
            'interfaces' => [$interface],
            'service_type' => RouterService::TYPE_HYBRID,
            'service_name' => $advancedOptions['service_name'] ?? 'Hybrid Service',
            'ip_pool_id' => null, // Hybrid uses multiple pools
            'vlan_id' => null, // Hybrid uses multiple VLANs
            'vlan_required' => true,
            'radius_profile' => "hybrid-{$tenant->id}",
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
     * Generate configuration script for service
     * Used by deployment job
     */
    public function generateConfigurationScript(RouterService $service): string
    {
        switch ($service->service_type) {
            case RouterService::TYPE_HOTSPOT:
                $generator = new \App\Services\MikroTik\ZeroConfigHotspotGenerator();
                return $generator->generate($service);
                
            case RouterService::TYPE_PPPOE:
                $generator = new \App\Services\MikroTik\ZeroConfigPPPoEGenerator();
                return $generator->generate($service);
                
            case RouterService::TYPE_HYBRID:
                $generator = new \App\Services\MikroTik\ZeroConfigHybridGenerator();
                return $generator->generate($service);
                
            default:
                throw new \Exception("Unsupported service type: {$service->service_type}");
        }
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
