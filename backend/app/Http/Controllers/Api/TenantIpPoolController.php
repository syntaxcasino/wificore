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
     * List all IP pools
     * System admins see all pools (optionally filtered by tenant_id).
     * Tenant users see only their own pools (via TenantScope).
     * GET /api/system/tenant/ip-pools or GET /api/tenant/ip-pools
     */
    public function index(Request $request)
    {
        $query = TenantIpPool::query();

        // System admin can filter by tenant_id
        if ($request->tenant_id && auth()->user()->role === 'system_admin') {
            $query->where('tenant_id', $request->tenant_id);
        }

        $pools = $query
            ->when($request->service_type, function ($q, $serviceType) {
                $q->forService($serviceType);
            })
            ->when($request->status === 'active', function ($q) {
                $q->active();
            })
            ->when($request->status === 'available', function ($q) {
                $q->available();
            })
            ->with('tenant:id,name,slug')
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
     * GET /api/system/tenant/ip-pools/stats or GET /api/tenant/ip-pools/stats
     */
    public function stats(Request $request)
    {
        $user = auth()->user();

        // System admin can get stats for a specific tenant or all tenants
        if ($user->role === 'system_admin') {
            if ($request->tenant_id) {
                $tenant = \App\Models\Tenant::findOrFail($request->tenant_id);
                $stats = $this->ipamService->getPoolStats($tenant);
            } else {
                // Aggregate stats across all tenants
                $pools = TenantIpPool::withoutGlobalScopes()->get();
                $stats = [
                    'total_pools' => $pools->count(),
                    'total_ips' => $pools->sum('total_ips'),
                    'allocated_ips' => $pools->sum('allocated_ips'),
                    'available_ips' => $pools->sum('available_ips'),
                    'utilization_percentage' => $pools->sum('total_ips') > 0
                        ? round(($pools->sum('allocated_ips') / $pools->sum('total_ips')) * 100, 2)
                        : 0,
                    'pools_by_tenant' => $pools->groupBy('tenant_id')->map(fn($tp) => $tp->count()),
                ];
            }
        } else {
            $tenant = $user->tenant;
            $stats = $this->ipamService->getPoolStats($tenant);
        }

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
        $pool->load('tenant:id,name,slug');

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
        $rules = [
            'service_type' => 'required|in:hotspot,pppoe,management',
            'network_cidr' => 'required|string',
            'gateway_ip' => 'required|ip',
            'range_start' => 'required|ip',
            'range_end' => 'required|ip',
            'dns_primary' => 'nullable|ip',
            'dns_secondary' => 'nullable|ip',
        ];

        // System admin must specify which tenant the pool belongs to
        $user = auth()->user();
        if ($user->role === 'system_admin') {
            $rules['tenant_id'] = 'required|uuid|exists:tenants,id';
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        try {
            $tenantId = ($user->role === 'system_admin')
                ? $validated['tenant_id']
                : $user->tenant_id;

            // Validate network doesn't overlap with existing pools for this tenant
            $overlapping = TenantIpPool::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('network_cidr', $validated['network_cidr'])
                ->exists();

            if ($overlapping) {
                return response()->json([
                    'success' => false,
                    'message' => 'Network CIDR overlaps with existing pool',
                ], 422);
            }

            $pool = TenantIpPool::create(array_merge($validated, [
                'tenant_id' => $tenantId,
                'total_ips' => $this->calculateTotalIps($validated['range_start'], $validated['range_end']),
                'allocated_ips' => 0,
                'available_ips' => $this->calculateTotalIps($validated['range_start'], $validated['range_end']),
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
        if ($pool->routerServices()->exists()) {
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
