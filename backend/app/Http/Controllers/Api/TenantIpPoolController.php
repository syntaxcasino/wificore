<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantIpPool;
use App\Services\TenantIpamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Tenant IP Pool Management Controller
 * For advanced users who want to manage IP pools manually
 */
class TenantIpPoolController extends Controller
{
    protected TenantIpamService $ipamService;

    public function __construct(TenantIpamService $ipamService)
    {
        $this->ipamService = $ipamService;
    }

    /**
     * List all IP pools for tenant
     * GET /api/tenant/ip-pools
     */
    public function index(Request $request)
    {
        $pools = TenantIpPool::with('services')
            ->when($request->service_type, function ($query, $serviceType) {
                $query->forService($serviceType);
            })
            ->when($request->status === 'active', function ($query) {
                $query->active();
            })
            ->when($request->status === 'available', function ($query) {
                $query->available();
            })
            ->orderBy('service_type')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'pools' => $pools,
        ]);
    }

    /**
     * Get pool statistics
     * GET /api/tenant/ip-pools/stats
     */
    public function stats()
    {
        $tenant = auth()->user()->tenant;
        $stats = $this->ipamService->getPoolStats($tenant);

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Get specific pool details
     * GET /api/tenant/ip-pools/{pool}
     */
    public function show(TenantIpPool $pool)
    {
        $pool->load('services');

        return response()->json([
            'success' => true,
            'pool' => $pool,
            'usage_percentage' => $pool->getUsagePercentage(),
            'needs_expansion' => $pool->needsExpansion(),
        ]);
    }

    /**
     * Create new IP pool (Advanced)
     * POST /api/tenant/ip-pools
     */
    public function store(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'service_type' => 'required|in:hotspot,pppoe,management',
            'network_cidr' => 'required|string',
            'gateway_ip' => 'required|ip',
            'range_start' => 'required|ip',
            'range_end' => 'required|ip',
            'dns_primary' => 'nullable|ip',
            'dns_secondary' => 'nullable|ip',
        ])->validate();

        try {
            $tenant = auth()->user()->tenant;

            // Validate network doesn't overlap with existing pools
            $overlapping = TenantIpPool::where('tenant_id', $tenant->id)
                ->where('network_cidr', $validated['network_cidr'])
                ->exists();

            if ($overlapping) {
                return response()->json([
                    'success' => false,
                    'message' => 'Network CIDR overlaps with existing pool',
                ], 422);
            }

            $pool = TenantIpPool::create(array_merge($validated, [
                'tenant_id' => $tenant->id,
                'total_ips' => $this->calculateTotalIps($validated['range_start'], $validated['range_end']),
                'allocated_ips' => 0,
            ]));

            return response()->json([
                'success' => true,
                'message' => 'IP pool created successfully',
                'pool' => $pool,
            ], 201);

        } catch (\Exception $e) {
            Log::error('IP pool creation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'IP pool creation failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update IP pool
     * PUT /api/tenant/ip-pools/{pool}
     */
    public function update(Request $request, TenantIpPool $pool)
    {
        $validated = Validator::make($request->all(), [
            'dns_primary' => 'nullable|ip',
            'dns_secondary' => 'nullable|ip',
            'is_active' => 'nullable|boolean',
        ])->validate();

        try {
            $pool->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'IP pool updated successfully',
                'pool' => $pool->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('IP pool update failed', [
                'pool_id' => $pool->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'IP pool update failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete IP pool
     * DELETE /api/tenant/ip-pools/{pool}
     */
    public function destroy(TenantIpPool $pool)
    {
        // Check if pool is in use
        if ($pool->services()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete pool - it is assigned to active services',
            ], 422);
        }

        try {
            $pool->delete();

            return response()->json([
                'success' => true,
                'message' => 'IP pool deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('IP pool deletion failed', [
                'pool_id' => $pool->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'IP pool deletion failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Expand IP pool
     * POST /api/tenant/ip-pools/{pool}/expand
     */
    public function expand(TenantIpPool $pool)
    {
        try {
            $this->ipamService->expandPool($pool);

            return response()->json([
                'success' => true,
                'message' => 'IP pool expanded successfully',
                'pool' => $pool->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('IP pool expansion failed', [
                'pool_id' => $pool->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'IP pool expansion failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Calculate total IPs in range
     */
    private function calculateTotalIps(string $start, string $end): int
    {
        $startLong = ip2long($start);
        $endLong = ip2long($end);
        
        return $endLong - $startLong + 1;
    }
}
