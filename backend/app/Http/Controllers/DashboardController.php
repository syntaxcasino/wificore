<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Jobs\UpdateDashboardStatsJob;

class DashboardController extends Controller
{
    private function dispatchDashboardRefresh(string $tenantId): void
    {
        // Throttle dispatches per tenant to prevent queue storms.
        $lockKey = "dashboard:manual-dispatch-lock:{$tenantId}";
        if (Cache::add($lockKey, 1, 30)) {
            UpdateDashboardStatsJob::dispatch($tenantId)->onQueue('dashboard');
        }
    }

    /**
     * Get dashboard statistics — served synchronously via TenantDashboardController's
     * optimised compute path (30 s cache, stampede protection, 4 batched queries).
     * No queue dispatch on the hot path; background job is only triggered for
     * SSE-driven cache warming (see refreshStats).
     */
    public function getStats(Request $request)
    {
        // Delegate entirely to the optimised TenantDashboardController which owns
        // the 30 s cache, lock-based stampede protection, and batched queries.
        return app(\App\Http\Controllers\Api\TenantDashboardController::class)->index($request);
    }

    /**
     * Force refresh dashboard statistics
     */
    public function refreshStats(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        // Dispatch job to queue with high priority for this tenant
        $this->dispatchDashboardRefresh($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics refresh initiated',
        ]);
    }
}
