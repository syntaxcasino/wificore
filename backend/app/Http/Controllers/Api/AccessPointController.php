<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\AccessPoint;
use App\Services\AccessPointManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AccessPointController extends Controller
{
    protected AccessPointManager $apManager;

    public function __construct(AccessPointManager $apManager)
    {
        $this->apManager = $apManager;
    }

    /**
     * Get all access points for a router
     */
    public function index(Router $router): JsonResponse
    {
        try {
            $accessPoints = $router->accessPoints()->with('activeSessions')->get();

            return response()->json([
                'success' => true,
                'data' => $accessPoints,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get access points',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a new access point
     */
    public function store(Request $request, Router $router): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'vendor' => 'required|string|in:ruijie,tenda,tplink,mikrotik,ubiquiti,other',
                'model' => 'nullable|string|max:100',
                'ip_address' => 'required|ip',
                'mac_address' => 'nullable|string|max:17',
                'management_protocol' => 'nullable|string|in:snmp,ssh,api,telnet,http',
                'credentials' => 'nullable|array',
                'location' => 'nullable|string|max:255',
                'total_capacity' => 'nullable|integer|min:1',
            ]);

            $ap = $this->apManager->addAccessPoint($router, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Access point added successfully',
                'data' => $ap,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to add access point', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add access point',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get access point details
     */
    public function show(AccessPoint $accessPoint): JsonResponse
    {
        try {
            $accessPoint->load('router', 'activeSessions');

            return response()->json([
                'success' => true,
                'data' => $accessPoint,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get access point details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update access point
     */
    public function update(Request $request, AccessPoint $accessPoint): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'vendor' => 'sometimes|string|in:ruijie,tenda,tplink,mikrotik,ubiquiti,other',
                'model' => 'nullable|string|max:100',
                'ip_address' => 'sometimes|ip',
                'mac_address' => 'nullable|string|max:17',
                'management_protocol' => 'nullable|string|in:snmp,ssh,api,telnet,http',
                'credentials' => 'nullable|array',
                'location' => 'nullable|string|max:255',
                'total_capacity' => 'nullable|integer|min:1',
            ]);

            $ap = $this->apManager->updateAccessPoint($accessPoint, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Access point updated successfully',
                'data' => $ap,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update access point',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete access point
     */
    public function destroy(AccessPoint $accessPoint): JsonResponse
    {
        try {
            $success = $this->apManager->removeAccessPoint($accessPoint);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Access point removed successfully' : 'Failed to remove access point',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove access point',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active sessions for an access point
     */
    public function sessions(AccessPoint $accessPoint): JsonResponse
    {
        try {
            $sessions = $this->apManager->getActiveSessions($accessPoint);

            return response()->json([
                'success' => true,
                'data' => $sessions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get sessions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get access point statistics
     */
    public function statistics(AccessPoint $accessPoint): JsonResponse
    {
        try {
            $stats = $this->apManager->getStatistics($accessPoint);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync access point status
     */
    public function sync(AccessPoint $accessPoint): JsonResponse
    {
        try {
            $stats = $this->apManager->syncAccessPointStatus($accessPoint);

            return response()->json([
                'success' => true,
                'message' => 'Access point status synced successfully',
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync access point status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Discover access points on network
     */
    public function discover(Router $router): JsonResponse
    {
        try {
            $discovered = $this->apManager->discoverAccessPoints($router);

            return response()->json([
                'success' => true,
                'message' => 'Access point discovery completed',
                'data' => $discovered,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to discover access points',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
