<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterService;
use App\Models\ServiceVlan;
use App\Models\TenantIpPool;
use Illuminate\Support\Facades\Log;

/**
 * Pre-Deployment Validation Service
 * Validates service configuration before deployment to prevent failures
 */
class ServiceDeploymentValidator extends TenantAwareService
{
    protected VlanManager $vlanManager;
    protected TenantIpamService $ipamService;

    public function __construct(VlanManager $vlanManager, TenantIpamService $ipamService)
    {
        $this->vlanManager = $vlanManager;
        $this->ipamService = $ipamService;
    }

    /**
     * Validate service deployment
     * Returns: ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validate(RouterService $service): array
    {

        $errors = [];
        $warnings = [];

        // 1. Interface Eligibility
        $interfaceCheck = $this->validateInterface($service);
        if (!$interfaceCheck['valid']) {
            $errors = array_merge($errors, $interfaceCheck['errors']);
        }

        // 2. VLAN Configuration
        if ($service->requiresVlan()) {
            $vlanCheck = $this->validateVlanConfiguration($service);
            if (!$vlanCheck['valid']) {
                $errors = array_merge($errors, $vlanCheck['errors']);
            }
        }

        // 3. IP Pool Capacity
        $poolCheck = $this->validatePoolCapacity($service);
        if (!$poolCheck['valid']) {
            $errors = array_merge($errors, $poolCheck['errors']);
        }
        if (isset($poolCheck['warnings'])) {
            $warnings = array_merge($warnings, $poolCheck['warnings']);
        }

        // 4. RADIUS Reachability
        $radiusCheck = $this->validateRadiusReachability($service);
        if (!$radiusCheck['valid']) {
            $errors = array_merge($errors, $radiusCheck['errors']);
        }

        // 5. RouterOS Compatibility
        $routerOsCheck = $this->validateRouterOsCompatibility($service);
        if (!$routerOsCheck['valid']) {
            $errors = array_merge($errors, $routerOsCheck['errors']);
        }
        if (isset($routerOsCheck['warnings'])) {
            $warnings = array_merge($warnings, $routerOsCheck['warnings']);
        }

        $valid = empty($errors);

        Log::info('Service deployment validation', [
            'service_id' => $service->id,
            'router_id' => $service->router_id,
            'valid' => $valid,
            'error_count' => count($errors),
            'warning_count' => count($warnings),
        ]);

        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate interface eligibility
     */
    private function validateInterface(RouterService $service): array
    {
        $errors = [];
        $router = $service->router;
        $interface = $service->interface_name;

        // Check if interface exists on router
        // This would require querying router's discovered interfaces
        // For now, basic validation
        if (empty($interface)) {
            $errors[] = 'Interface name is required';
        }

        // Check if interface is already assigned to another service
        $existingService = RouterService::where('router_id', $router->id)
            ->where('interface_name', $interface)
            ->where('id', '!=', $service->id)
            ->where('service_type', '!=', 'none')
            ->first();

        if ($existingService && $service->service_type !== RouterService::TYPE_HYBRID) {
            $errors[] = "Interface {$interface} is already assigned to {$existingService->service_type} service";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate VLAN configuration
     */
    private function validateVlanConfiguration(RouterService $service): array
    {
        $errors = [];
        $router = $service->router;

        if ($service->service_type === RouterService::TYPE_HYBRID) {
            // Hybrid requires 2 VLANs
            $vlans = $service->vlans;
            
            if ($vlans->count() < 2) {
                $errors[] = 'Hybrid service requires both hotspot and pppoe VLANs';
            }

            // Check for VLAN conflicts
            $vlanIds = $vlans->pluck('vlan_id')->toArray();
            foreach ($vlanIds as $vlanId) {
                $conflict = ServiceVlan::whereHas('routerService', function ($query) use ($router) {
                    $query->where('router_id', $router->id);
                })
                    ->where('vlan_id', $vlanId)
                    ->where('router_service_id', '!=', $service->id)
                    ->exists();

                if ($conflict) {
                    $errors[] = "VLAN {$vlanId} is already in use on this router";
                }
            }

            // Validate VLAN ID ranges
            foreach ($vlans as $vlan) {
                if ($vlan->vlan_id < 1 || $vlan->vlan_id > 4094) {
                    $errors[] = "Invalid VLAN ID: {$vlan->vlan_id} (must be 1-4094)";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate IP pool capacity
     */
    private function validatePoolCapacity(RouterService $service): array
    {
        $errors = [];
        $warnings = [];

        if ($service->service_type === RouterService::TYPE_HYBRID) {
            // Check both pools
            $advancedConfig = $service->advanced_config ?? [];
            $hotspotPool = TenantIpPool::find($advancedConfig['hotspot_pool_id'] ?? null);
            $pppoePool = TenantIpPool::find($advancedConfig['pppoe_pool_id'] ?? null);

            if (!$hotspotPool) {
                $errors[] = 'Hotspot IP pool not found';
            } elseif ($hotspotPool->isExhausted()) {
                $errors[] = 'Hotspot IP pool is exhausted';
            } elseif ($hotspotPool->needsExpansion(20)) {
                $warnings[] = "Hotspot pool utilization is {$hotspotPool->getUsagePercentage()}% - consider expansion";
            }

            if (!$pppoePool) {
                $errors[] = 'PPPoE IP pool not found';
            } elseif ($pppoePool->isExhausted()) {
                $errors[] = 'PPPoE IP pool is exhausted';
            } elseif ($pppoePool->needsExpansion(20)) {
                $warnings[] = "PPPoE pool utilization is {$pppoePool->getUsagePercentage()}% - consider expansion";
            }
        } else {
            // Single service pool
            $pool = $service->ipPool;
            
            if (!$pool) {
                $errors[] = 'IP pool not assigned to service';
            } elseif ($pool->isExhausted()) {
                $errors[] = 'IP pool is exhausted';
            } elseif ($pool->needsExpansion(20)) {
                $warnings[] = "Pool utilization is {$pool->getUsagePercentage()}% - consider expansion";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate RADIUS reachability
     */
    private function validateRadiusReachability(RouterService $service): array
    {
        $errors = [];
        $router = $service->router;

        // Check if router has VPN IP (required for RADIUS)
        if (empty($router->vpn_ip)) {
            $errors[] = 'Router VPN IP not configured - RADIUS server unreachable';
        }

        // Check if RADIUS secret is configured
        $radiusSecret = env('RADIUS_SECRET');
        if (empty($radiusSecret)) {
            $errors[] = 'RADIUS secret not configured in environment';
        }

        // TODO: Actual RADIUS connectivity test
        // This would require sending a test auth request to RADIUS server

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate RouterOS compatibility
     */
    private function validateRouterOsCompatibility(RouterService $service): array
    {
        $errors = [];
        $warnings = [];
        $router = $service->router;

        // Check RouterOS version
        if (!empty($router->os_version)) {
            $version = $router->os_version;
            
            // Extract major version (e.g., "7.15.3" -> 7)
            preg_match('/^(\d+)\./', $version, $matches);
            $majorVersion = isset($matches[1]) ? (int)$matches[1] : 0;

            if ($majorVersion < 6) {
                $errors[] = "RouterOS version {$version} is too old (minimum v6.x required)";
            } elseif ($majorVersion < 7) {
                $warnings[] = "RouterOS version {$version} is outdated - consider upgrading to v7.x";
            }
        }

        // Check if router is reachable
        // Consider router available if:
        // 1. Status is explicitly 'online', OR
        // 2. VPN is connected and router was seen recently (within 5 minutes)
        $isReachable = $router->status === 'online' || 
                      ($router->vpn_status === 'connected' && 
                       $router->last_seen && 
                       $router->last_seen->diffInMinutes(now()) < 5);
        
        if (!$isReachable) {
            $lastSeenInfo = $router->last_seen 
                ? " (last seen " . $router->last_seen->diffForHumans() . ")" 
                : " (never seen)";
            $errors[] = "Router is {$router->status} - must be reachable for deployment{$lastSeenInfo}";
        }

        // Check license level (if available)
        // Different RouterOS licenses support different features
        // This would require querying router for license info

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Quick validation - just checks critical errors
     */
    public function quickValidate(RouterService $service): bool
    {
        $result = $this->validate($service);
        return $result['valid'];
    }
}
