<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Jobs\UpdateDashboardStatsJob;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function getStats(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        // Try to get cached stats first (cache for 5 seconds, per-tenant)
        $cacheKey = "dashboard_stats_{$tenantId}";
        $stats = Cache::remember($cacheKey, 5, function () use ($tenantId, $cacheKey) {
            // Dispatch job to update stats in background for this tenant
            UpdateDashboardStatsJob::dispatch($tenantId)->onQueue('dashboard');
            
            // Return current cached value or defaults
            return Cache::get($cacheKey, [
                'total_routers' => 0,
                'active_sessions' => 0,
                'hotspot_users' => 0,
                'pppoe_users' => 0,
                'online_users' => [],
                'sms_balance' => 0,
            ]);
        });

        // Also dispatch job to update stats in background if cache is old
        if (!$stats || !isset($stats['total_routers'])) {
            // Dispatch job to queue for processing for this tenant
            UpdateDashboardStatsJob::dispatch($tenantId)->onQueue('dashboard');

            // Return default stats while job is processing
            $stats = [
                'total_routers' => 0,
                'online_routers' => 0,
                'offline_routers' => 0,
                'provisioning_routers' => 0,
                'active_users' => 0,
                'total_revenue' => 0,
                'monthly_revenue' => 0,
                'data_usage' => 0,
                'weekly_users_trend' => [],
                'weekly_revenue_trend' => [],
                'recent_activities' => [],
                'online_users' => [],
                'last_updated' => now()->toIso8601String(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Force refresh dashboard statistics
     */
    public function refreshStats(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        // Dispatch job to queue with high priority for this tenant
        UpdateDashboardStatsJob::dispatch($tenantId)->onQueue('dashboard');

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics refresh initiated',
        ]);
    }
}
