<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\AccessPoint;
use App\Services\AccessPointManager;
use App\Events\AccessPointCreated;
use App\Events\AccessPointUpdated;
use App\Events\AccessPointDeleted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AccessPointController extends Controller
{
    protected AccessPointManager $apManager;

    public function __construct(AccessPointManager $apManager)
    {
        $this->apManager = $apManager;
    }

    /**
     * Get all access points for the current tenant
     * OPTIMIZED: Added pagination and selective column loading
     */
    public function list(Request $request): JsonResponse
    {
        try {
            // OPTIMIZATION: Use selective columns in eager loading
            $query = AccessPoint::with([
                'router:id,name,ip_address,status',
                'activeSessions' => fn($q) => $q->select('id', 'access_point_id', 'status')->where('status', 'active')->limit(5)
            ])
            ->select([
                'id', 'name', 'ip_address', 'mac_address', 'serial_number', 'status',
                'router_id', 'model', 'firmware_version', 'active_users', 'last_seen_at', 'created_at'
            ]);

            // Search functionality
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('mac_address', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by router
            if ($request->has('router_id')) {
                $query->where('router_id', $request->router_id);
            }

            // OPTIMIZATION: Add pagination instead of loading all records
            $perPage = min((int) $request->input('per_page', 50), 100);
            $accessPoints = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $accessPoints,
                'access_points' => $accessPoints, // For frontend compatibility
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get access points',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get tenant-wide access point statistics
     */
    public function tenantStatistics(): JsonResponse
    {
        try {
            $row = AccessPoint::selectRaw("
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'online') as online,
                COUNT(*) FILTER (WHERE status = 'offline') as offline,
                COUNT(*) FILTER (WHERE status = 'unknown' OR status IS NULL) as unknown,
                COALESCE(SUM(active_users), 0) as total_users
            ")->first();

            $stats = [
                'total'       => (int)   ($row->total       ?? 0),
                'online'      => (int)   ($row->online      ?? 0),
                'offline'     => (int)   ($row->offline     ?? 0),
                'unknown'     => (int)   ($row->unknown     ?? 0),
                'total_users' => (int)   ($row->total_users ?? 0),
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
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
     * Create new access point (standalone route)
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'router_id' => 'required|exists:routers,id',
                'name' => 'required|string|max:100',
                'vendor' => 'required|string|in:ruijie,tenda,tplink,mikrotik,ubiquiti,other',
                'model' => 'nullable|string|max:100',
                'ip_address' => 'required|ip',
                'mac_address' => 'nullable|string|max:17',
                'serial_number' => 'nullable|string|max:100',
                'management_protocol' => 'nullable|string|in:snmp,ssh,api,telnet,http',
                'credentials' => 'nullable|array',
                'location' => 'nullable|string|max:255',
                'total_capacity' => 'nullable|integer|min:1',
            ]);

            $router = Router::findOrFail($validated['router_id']);
            $ap = $this->apManager->addAccessPoint($router, $validated);

            // Broadcast event for WebSocket
            broadcast(new AccessPointCreated($ap))->toOthers();
            Cache::forget('ap_tenant_stats_' . ($ap->tenant_id ?? 'default'));

            return response()->json([
                'success' => true,
                'message' => 'Access point created successfully',
                'data' => $ap,
                'access_point' => $ap, // For frontend compatibility
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create access point', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create access point',
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
                'access_point' => $accessPoint, // For frontend compatibility
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

            // Broadcast event for WebSocket
            broadcast(new AccessPointUpdated($ap))->toOthers();
            Cache::forget('ap_tenant_stats_' . ($accessPoint->tenant_id ?? 'default'));

            return response()->json([
                'success' => true,
                'message' => 'Access point updated successfully',
                'data' => $ap,
                'access_point' => $ap, // For frontend compatibility
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
            $id = $accessPoint->id;
            $tenantId = $accessPoint->tenant_id;
            $success = $this->apManager->removeAccessPoint($accessPoint);

            if ($success) {
                // Broadcast event for WebSocket
                broadcast(new AccessPointDeleted($id, $tenantId))->toOthers();
                Cache::forget('ap_tenant_stats_' . ($accessPoint->tenant_id ?? 'default'));
            }

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
            $cacheKey = 'ap_stats_' . ($accessPoint->tenant_id ?? 'default') . '_' . $accessPoint->id;
            $stats = Cache::remember($cacheKey, now()->addSeconds(15), function () use ($accessPoint) {
                return $this->apManager->getStatistics($accessPoint);
            });

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

            // Reload the model to get updated status
            $accessPoint->refresh();

            // Broadcast event for WebSocket
            broadcast(new AccessPointUpdated($accessPoint))->toOthers();

            return response()->json([
                'success' => true,
                'message' => 'Access point status synced successfully',
                'data' => $stats,
                'access_point' => $accessPoint, // For frontend compatibility
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
