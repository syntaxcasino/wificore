<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterService;
use App\Services\RouterServiceManager;
use App\Services\InterfaceManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RouterServiceController extends Controller
{
    protected RouterServiceManager $serviceManager;
    protected InterfaceManagementService $interfaceManager;

    public function __construct(
        RouterServiceManager $serviceManager,
        InterfaceManagementService $interfaceManager
    ) {
        $this->serviceManager = $serviceManager;
        $this->interfaceManager = $interfaceManager;
    }

    /**
     * Get all services for a router
     */
    public function index(Router $router): JsonResponse
    {
        try {
            $services = $this->serviceManager->getRouterServices($router);

            return response()->json([
                'success' => true,
                'data' => $services,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get router services', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get services',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deploy a new service
     */
    public function store(Request $request, Router $router): JsonResponse
    {
        try {
            $validated = $request->validate([
                'service_type' => 'required|string|in:hotspot,pppoe,vpn,firewall,dhcp,dns',
                'service_name' => 'required|string|max:100',
                'interfaces' => 'required|array|min:1',
                'interfaces.*' => 'required|string',
                'configuration' => 'nullable|array',
            ]);

            $service = $this->serviceManager->deployService(
                $router,
                $validated['service_type'],
                $validated
            );

            return response()->json([
                'success' => true,
                'message' => 'Service deployed successfully',
                'data' => $service,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to deploy service', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to deploy service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get service details
     */
    public function show(Router $router, RouterService $service): JsonResponse
    {
        try {
            if ($service->router_id !== $router->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service does not belong to this router',
                ], 404);
            }

            $status = $this->serviceManager->getServiceStatus($service);

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get service details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update service configuration
     */
    public function update(Request $request, Router $router, RouterService $service): JsonResponse
    {
        try {
            if ($service->router_id !== $router->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service does not belong to this router',
                ], 404);
            }

            $validated = $request->validate([
                'service_name' => 'sometimes|string|max:100',
                'interfaces' => 'sometimes|array|min:1',
                'interfaces.*' => 'required|string',
                'configuration' => 'sometimes|array',
            ]);

            $service = $this->serviceManager->updateService($service, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Service updated successfully',
                'data' => $service,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update service', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start a service
     */
    public function start(Router $router, RouterService $service): JsonResponse
    {
        try {
            if ($service->router_id !== $router->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service does not belong to this router',
                ], 404);
            }

            $success = $this->serviceManager->startService($service);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Service started successfully' : 'Failed to start service',
                'data' => $service->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stop a service
     */
    public function stop(Router $router, RouterService $service): JsonResponse
    {
        try {
            if ($service->router_id !== $router->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service does not belong to this router',
                ], 404);
            }

            $success = $this->serviceManager->stopService($service);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Service stopped successfully' : 'Failed to stop service',
                'data' => $service->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to stop service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restart a service
     */
    public function restart(Router $router, RouterService $service): JsonResponse
    {
        try {
            if ($service->router_id !== $router->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service does not belong to this router',
                ], 404);
            }

            $success = $this->serviceManager->restartService($service);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Service restarted successfully' : 'Failed to restart service',
                'data' => $service->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restart service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a service
     */
    public function destroy(Router $router, RouterService $service): JsonResponse
    {
        try {
            if ($service->router_id !== $router->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service does not belong to this router',
                ], 404);
            }

            $success = $this->serviceManager->removeService($service);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Service removed successfully' : 'Failed to remove service',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync service status
     */
    public function sync(Router $router): JsonResponse
    {
        try {
            $synced = $this->serviceManager->syncServiceStatus($router);

            return response()->json([
                'success' => true,
                'message' => 'Service status synced successfully',
                'data' => $synced,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync service status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available interfaces
     */
    public function interfaces(Router $router): JsonResponse
    {
        try {
            $summary = $this->interfaceManager->getInterfaceReservationSummary($router);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get interfaces',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
