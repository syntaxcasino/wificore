<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterService;
use Illuminate\Support\Facades\Log;

class InterfaceManagementService extends TenantAwareService
{
    /**
     * Get available interfaces for a router
     * 
     * @param Router $router
     * @return array
     */
    public function getAvailableInterfaces(Router $router): array
    {
        $allInterfaces = $router->interface_list ?? [];
        $reservedInterfaces = array_keys($router->reserved_interfaces ?? []);
        
        return array_values(array_diff($allInterfaces, $reservedInterfaces));
    }

    /**
     * Reserve interfaces for a service
     * 
     * @param Router $router
     * @param string $serviceType
     * @param array $interfaces
     * @return bool
     */
    public function reserveInterfaces(Router $router, string $serviceType, array $interfaces): bool
    {
        try {
            $reserved = $router->reserved_interfaces ?? [];
            
            foreach ($interfaces as $interface) {
                // Check if already reserved
                if (isset($reserved[$interface])) {
                    Log::warning("Interface already reserved", [
                        'router_id' => $router->id,
                        'interface' => $interface,
                        'reserved_by' => $reserved[$interface],
                        'requested_by' => $serviceType,
                    ]);
                    
                    throw new \Exception("Interface {$interface} is already reserved by {$reserved[$interface]}");
                }
                
                $reserved[$interface] = $serviceType;
            }
            
            $router->reserved_interfaces = $reserved;
            $router->save();
            
            Log::info("Interfaces reserved successfully", [
                'router_id' => $router->id,
                'service_type' => $serviceType,
                'interfaces' => $interfaces,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reserve interfaces", [
                'router_id' => $router->id,
                'service_type' => $serviceType,
                'interfaces' => $interfaces,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Release interfaces from a service
     * 
     * @param Router $router
     * @param string $serviceType
     * @return bool
     */
    public function releaseInterfaces(Router $router, string $serviceType): bool
    {
        try {
            $reserved = $router->reserved_interfaces ?? [];
            $released = [];
            
            foreach ($reserved as $interface => $type) {
                if ($type === $serviceType) {
                    unset($reserved[$interface]);
                    $released[] = $interface;
                }
            }
            
            $router->reserved_interfaces = $reserved;
            $router->save();
            
            Log::info("Interfaces released successfully", [
                'router_id' => $router->id,
                'service_type' => $serviceType,
                'released_interfaces' => $released,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to release interfaces", [
                'router_id' => $router->id,
                'service_type' => $serviceType,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Check if interfaces are available
     * 
     * @param Router $router
     * @param array $interfaces
     * @return bool
     */
    public function areInterfacesAvailable(Router $router, array $interfaces): bool
    {
        $reserved = array_keys($router->reserved_interfaces ?? []);
        
        foreach ($interfaces as $interface) {
            if (in_array($interface, $reserved)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get interfaces used by a service
     * 
     * @param Router $router
     * @param string $serviceType
     * @return array
     */
    public function getServiceInterfaces(Router $router, string $serviceType): array
    {
        $reserved = $router->reserved_interfaces ?? [];
        $interfaces = [];
        
        foreach ($reserved as $interface => $type) {
            if ($type === $serviceType) {
                $interfaces[] = $interface;
            }
        }
        
        return $interfaces;
    }

    /**
     * Validate interface assignment
     * 
     * @param Router $router
     * @param string $serviceType
     * @param array $interfaces
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateInterfaceAssignment(Router $router, string $serviceType, array $interfaces): array
    {
        $errors = [];
        
        // Check if interfaces exist in router's interface list
        $availableInterfaces = $router->interface_list ?? [];
        foreach ($interfaces as $interface) {
            if (!in_array($interface, $availableInterfaces)) {
                $errors[] = "Interface {$interface} does not exist on this router";
            }
        }
        
        // Check if interfaces are already reserved
        $reserved = $router->reserved_interfaces ?? [];
        foreach ($interfaces as $interface) {
            if (isset($reserved[$interface]) && $reserved[$interface] !== $serviceType) {
                $errors[] = "Interface {$interface} is already reserved by {$reserved[$interface]}";
            }
        }
        
        // Check for conflicts with specific service types
        if ($serviceType === 'hotspot') {
            // Hotspot cannot use same interfaces as PPPoE
            $pppoeInterfaces = $this->getServiceInterfaces($router, 'pppoe');
            $conflicts = array_intersect($interfaces, $pppoeInterfaces);
            if (!empty($conflicts)) {
                $errors[] = "Hotspot cannot use same interfaces as PPPoE: " . implode(', ', $conflicts);
            }
        } elseif ($serviceType === 'pppoe') {
            // PPPoE cannot use same interfaces as Hotspot
            $hotspotInterfaces = $this->getServiceInterfaces($router, 'hotspot');
            $conflicts = array_intersect($interfaces, $hotspotInterfaces);
            if (!empty($conflicts)) {
                $errors[] = "PPPoE cannot use same interfaces as Hotspot: " . implode(', ', $conflicts);
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Scan router interfaces (placeholder for future implementation)
     * 
     * @param Router $router
     * @return array
     */
    public function scanRouterInterfaces(Router $router): array
    {
        // TODO: Implement actual interface scanning via RouterOS API
        // For now, return the stored interface list
        
        Log::info("Scanning router interfaces", [
            'router_id' => $router->id,
        ]);
        
        return $router->interface_list ?? [];
    }

    /**
     * Get interface reservation summary
     * 
     * @param Router $router
     * @return array
     */
    public function getInterfaceReservationSummary(Router $router): array
    {
        $all = $router->interface_list ?? [];
        $reserved = $router->reserved_interfaces ?? [];
        $available = $this->getAvailableInterfaces($router);
        
        return [
            'total' => count($all),
            'reserved' => count($reserved),
            'available' => count($available),
            'reservations' => $reserved,
            'available_list' => $available,
        ];
    }
}
