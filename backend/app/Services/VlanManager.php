<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterService;
use App\Models\ServiceVlan;
use Illuminate\Support\Facades\Log;

class VlanManager extends TenantAwareService
{
    private const VLAN_RANGES = [
        'hotspot' => [100, 199],
        'pppoe' => [200, 299],
        'management' => [10, 99],
    ];

    /**
     * Allocate VLAN ID for a service on a router
     */
    public function allocateVlanForService(Router $router, string $serviceType): int
    {
        $this->setTenant($router->tenant_id);

        [$rangeStart, $rangeEnd] = self::VLAN_RANGES[$serviceType] ?? [100, 199];

        // Get all VLANs already used on this router
        $usedVlans = ServiceVlan::whereHas('routerService', function ($query) use ($router) {
            $query->where('router_id', $router->id);
        })->pluck('vlan_id')->toArray();

        // Find first available VLAN in range
        for ($vlanId = $rangeStart; $vlanId <= $rangeEnd; $vlanId++) {
            if (!in_array($vlanId, $usedVlans)) {
                Log::info('Allocated VLAN for service', [
                    'router_id' => $router->id,
                    'service_type' => $serviceType,
                    'vlan_id' => $vlanId,
                ]);
                return $vlanId;
            }
        }

        throw new \Exception("No available VLANs in range for service type: {$serviceType}");
    }

    /**
     * Create VLAN configuration for a service
     */
    public function createServiceVlan(
        RouterService $service,
        int $vlanId,
        string $parentInterface,
        string $serviceType
    ): ServiceVlan {
        $this->setTenant($service->router->tenant_id);

        $vlan = ServiceVlan::create([
            'router_service_id' => $service->id,
            'vlan_id' => $vlanId,
            'vlan_name' => "vlan-{$serviceType}-{$vlanId}",
            'parent_interface' => $parentInterface,
            'service_type' => $serviceType,
            'auto_generated' => true,
        ]);

        Log::info('Created service VLAN', [
            'service_id' => $service->id,
            'vlan_id' => $vlanId,
            'interface' => $parentInterface,
        ]);

        return $vlan;
    }

    /**
     * Validate VLAN configuration for a router
     */
    public function validateVlanConfiguration(Router $router, array $vlans): bool
    {
        $this->setTenant($router->tenant_id);

        // Check for duplicate VLAN IDs
        $vlanIds = array_column($vlans, 'vlan_id');
        if (count($vlanIds) !== count(array_unique($vlanIds))) {
            Log::error('Duplicate VLAN IDs detected', [
                'router_id' => $router->id,
                'vlans' => $vlans,
            ]);
            return false;
        }

        // Check if VLANs are within valid ranges
        foreach ($vlans as $vlan) {
            if ($vlan['vlan_id'] < 1 || $vlan['vlan_id'] > 4094) {
                Log::error('Invalid VLAN ID', [
                    'router_id' => $router->id,
                    'vlan_id' => $vlan['vlan_id'],
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Get available VLAN range for a service type
     */
    public function getAvailableVlanRange(Router $router, string $serviceType): array
    {
        $this->setTenant($router->tenant_id);

        [$rangeStart, $rangeEnd] = self::VLAN_RANGES[$serviceType] ?? [100, 199];

        $usedVlans = ServiceVlan::whereHas('routerService', function ($query) use ($router) {
            $query->where('router_id', $router->id);
        })->pluck('vlan_id')->toArray();

        $availableVlans = [];
        for ($vlanId = $rangeStart; $vlanId <= $rangeEnd; $vlanId++) {
            if (!in_array($vlanId, $usedVlans)) {
                $availableVlans[] = $vlanId;
            }
        }

        return $availableVlans;
    }

    /**
     * Check if VLAN is available on router
     */
    public function isVlanAvailable(Router $router, int $vlanId): bool
    {
        $this->setTenant($router->tenant_id);

        return !ServiceVlan::whereHas('routerService', function ($query) use ($router) {
            $query->where('router_id', $router->id);
        })->where('vlan_id', $vlanId)->exists();
    }

    /**
     * Get VLAN statistics for router
     */
    public function getVlanStats(Router $router): array
    {
        $this->setTenant($router->tenant_id);

        $vlans = ServiceVlan::whereHas('routerService', function ($query) use ($router) {
            $query->where('router_id', $router->id);
        })->with('routerService')->get();

        return [
            'total_vlans' => $vlans->count(),
            'vlans_by_service' => $vlans->groupBy('service_type')->map->count(),
            'auto_generated' => $vlans->where('auto_generated', true)->count(),
            'manual' => $vlans->where('auto_generated', false)->count(),
            'vlan_list' => $vlans->map(function ($vlan) {
                return [
                    'vlan_id' => $vlan->vlan_id,
                    'name' => $vlan->vlan_name,
                    'service_type' => $vlan->service_type,
                    'interface' => $vlan->parent_interface,
                ];
            }),
        ];
    }
}
