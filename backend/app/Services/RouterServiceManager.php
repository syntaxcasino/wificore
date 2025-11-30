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

    public function __construct(
        InterfaceManagementService $interfaceManager,
        HotspotService $hotspotService,
        PPPoEService $pppoeService
    ) {
        $this->interfaceManager = $interfaceManager;
        $this->hotspotService = $hotspotService;
        $this->pppoeService = $pppoeService;
    }

    /**
     * Deploy a service to a router
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
