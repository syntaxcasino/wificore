<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterService;
use App\Services\RouterServiceManager;
use App\Services\ServiceDeploymentValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Zero-Config Service Configuration Controller
 * Handles interface-to-service mapping with automatic IPAM and VLAN allocation
 */
class ServiceConfigurationController extends Controller
{
    protected RouterServiceManager $serviceManager;
    protected ServiceDeploymentValidator $validator;

    public function __construct(
        RouterServiceManager $serviceManager,
        ServiceDeploymentValidator $validator
    ) {
        $this->serviceManager = $serviceManager;
        $this->validator = $validator;
    }

    /**
     * Configure service on router interface (Zero-Config)
     * POST /api/routers/{router}/services/configure
     */
    public function configure(Request $request, Router $router)
    {
        $validated = Validator::make($request->all(), [
            'interface' => 'required|string',
            'service_type' => 'required|in:hotspot,pppoe,hybrid,none',
            'advanced_options' => 'nullable|array',
            'advanced_options.service_name' => 'nullable|string|max:255',
            'advanced_options.ip_pool_id' => 'nullable|exists:tenant_ip_pools,id',
            'advanced_options.radius_profile' => 'nullable|string|max:255',
            'advanced_options.hotspot_pool_id' => 'nullable|exists:tenant_ip_pools,id',
            'advanced_options.pppoe_pool_id' => 'nullable|exists:tenant_ip_pools,id',
            'advanced_options.hotspot_vlan' => 'nullable|integer|min:1|max:4094',
            'advanced_options.pppoe_vlan' => 'nullable|integer|min:1|max:4094',
        ])->validate();

        try {
            $service = $this->serviceManager->configureService(
                $router,
                $validated['interface'],
                $validated['service_type'],
                $validated['advanced_options'] ?? []
            );

            // If service type is 'none', return success without service
            if ($validated['service_type'] === 'none') {
                return response()->json([
                    'success' => true,
                    'message' => 'Service removed from interface',
                    'interface' => $validated['interface'],
                ]);
            }

            // Validate configuration
            $validationResult = $this->validator->validate($service);

            return response()->json([
                'success' => true,
                'message' => 'Service configured successfully',
                'service' => $service->load(['ipPool', 'vlans']),
                'validation' => $validationResult,
            ]);

        } catch (\Exception $e) {
            Log::error('Service configuration failed', [
                'router_id' => $router->id,
                'interface' => $validated['interface'],
                'service_type' => $validated['service_type'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service configuration failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get service configuration for router
     * GET /api/routers/{router}/services
     */
    public function index(Router $router)
    {
        $services = RouterService::where('router_id', $router->id)
            ->with(['ipPool', 'vlans'])
            ->get();

        return response()->json([
            'success' => true,
            'services' => $services,
        ]);
    }

    /**
     * Get service details
     * GET /api/routers/{router}/services/{service}
     */
    public function show(Router $router, RouterService $service)
    {
        if ($service->router_id !== $router->id) {
            return response()->json([
                'success' => false,
                'message' => 'Service does not belong to this router',
            ], 404);
        }

        $service->load(['ipPool', 'vlans']);

        // Get validation status
        $validation = $this->validator->validate($service);

        return response()->json([
            'success' => true,
            'service' => $service,
            'validation' => $validation,
        ]);
    }

    /**
     * Update service configuration
     * PUT /api/routers/{router}/services/{service}
     */
    public function update(Request $request, Router $router, RouterService $service)
    {
        if ($service->router_id !== $router->id) {
            return response()->json([
                'success' => false,
                'message' => 'Service does not belong to this router',
            ], 404);
        }

        $validated = Validator::make($request->all(), [
            'service_name' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
            'advanced_config' => 'nullable|array',
        ])->validate();

        try {
            $service->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Service updated successfully',
                'service' => $service->fresh(['ipPool', 'vlans']),
            ]);

        } catch (\Exception $e) {
            Log::error('Service update failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service update failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete service
     * DELETE /api/routers/{router}/services/{service}
     */
    public function destroy(Router $router, RouterService $service)
    {
        if ($service->router_id !== $router->id) {
            return response()->json([
                'success' => false,
                'message' => 'Service does not belong to this router',
            ], 404);
        }

        try {
            $interface = $service->interface_name;
            $service->delete();

            return response()->json([
                'success' => true,
                'message' => 'Service deleted successfully',
                'interface' => $interface,
            ]);

        } catch (\Exception $e) {
            Log::error('Service deletion failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service deletion failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Validate service configuration
     * POST /api/routers/{router}/services/{service}/validate
     */
    public function validateService(Router $router, RouterService $service)
    {
        if ($service->router_id !== $router->id) {
            return response()->json([
                'success' => false,
                'message' => 'Service does not belong to this router',
            ], 404);
        }

        $validation = $this->validator->validate($service);

        return response()->json([
            'success' => true,
            'validation' => $validation,
        ]);
    }

    /**
     * Deploy service to router
     * POST /api/routers/{router}/services/{service}/deploy
     */
    public function deploy(Router $router, RouterService $service)
    {
        if ($service->router_id !== $router->id) {
            return response()->json([
                'success' => false,
                'message' => 'Service does not belong to this router',
            ], 404);
        }

        // Validate before deployment
        $validation = $this->validator->validate($service);
        
        if (!$validation['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Service validation failed',
                'validation' => $validation,
            ], 422);
        }

        try {
            // Mark as deploying
            $service->update(['deployment_status' => RouterService::DEPLOYMENT_IN_PROGRESS]);

            // Dispatch deployment job (async)
            \App\Jobs\DeployRouterServiceJob::dispatch($service->id, auth()->user()->tenant_id);

            return response()->json([
                'success' => true,
                'message' => 'Service deployment started',
                'service' => $service->fresh(),
                'validation' => $validation,
            ]);

        } catch (\Exception $e) {
            Log::error('Service deployment failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            $service->update(['deployment_status' => RouterService::DEPLOYMENT_FAILED]);

            return response()->json([
                'success' => false,
                'message' => 'Service deployment failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
