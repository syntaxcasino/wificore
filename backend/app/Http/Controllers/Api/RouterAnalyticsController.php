<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Payment;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class RouterAnalyticsController extends Controller
{
    /**
     * Get revenue analytics for all routers
     */
    public function getRouterRevenue(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }

        $cacheKey = "router_revenue_tenant_{$tenantId}";

        $analytics = Cache::remember($cacheKey, 600, function () use ($tenantId) {
            $routers = Router::where('tenant_id', $tenantId)
                ->with(['payments' => function($query) {
                    $query->where('status', 'completed');
                }])
                ->get();

            $routerAnalytics = [];

            foreach ($routers as $router) {
                $totalRevenue = $router->payments->sum('amount');
                $transactionCount = $router->payments->count();
                
                // Get revenue by package for this router
                $packageRevenue = Payment::where('router_id', $router->id)
                    ->where('status', 'completed')
                    ->select('package_id', DB::raw('SUM(amount) as total_revenue'), DB::raw('COUNT(*) as transaction_count'))
                    ->groupBy('package_id')
                    ->with('package:id,name')
                    ->get()
                    ->map(function($item) {
                        return [
                            'package_id' => $item->package_id,
                            'package_name' => $item->package->name ?? 'Unknown',
                            'revenue' => (float) $item->total_revenue,
                            'transactions' => $item->transaction_count
                        ];
                    });

                $routerAnalytics[] = [
                    'router_id' => $router->id,
                    'router_name' => $router->name,
                    'router_location' => $router->location,
                    'total_revenue' => (float) $totalRevenue,
                    'transaction_count' => $transactionCount,
                    'package_breakdown' => $packageRevenue,
                    'status' => $router->status
                ];
            }

            // Sort by revenue (highest first)
            usort($routerAnalytics, function($a, $b) {
                return $b['total_revenue'] <=> $a['total_revenue'];
            });

            return $routerAnalytics;
        });

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Get detailed analytics for a specific router
     */
    public function getRouterDetails($routerId)
    {
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }

        $router = Router::where('id', $routerId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $cacheKey = "router_details_{$routerId}";

        $analytics = Cache::remember($cacheKey, 300, function () use ($router) {
            // Total revenue
            $totalRevenue = $router->payments()
                ->where('status', 'completed')
                ->sum('amount');

            // Transaction count
            $transactionCount = $router->payments()
                ->where('status', 'completed')
                ->count();

            // Revenue by package
            $packageRevenue = Payment::where('router_id', $router->id)
                ->where('status', 'completed')
                ->select('package_id', DB::raw('SUM(amount) as total_revenue'), DB::raw('COUNT(*) as transaction_count'))
                ->groupBy('package_id')
                ->with('package:id,name,price')
                ->get()
                ->map(function($item) {
                    return [
                        'package_id' => $item->package_id,
                        'package_name' => $item->package->name ?? 'Unknown',
                        'package_price' => $item->package->price ?? 0,
                        'revenue' => (float) $item->total_revenue,
                        'transactions' => $item->transaction_count
                    ];
                });

            // Revenue by date (last 30 days)
            $dailyRevenue = Payment::where('router_id', $router->id)
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(30))
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as revenue'), DB::raw('COUNT(*) as transactions'))
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            // Assigned packages (global + specific)
            $globalPackages = Package::where('tenant_id', $router->tenant_id)
                ->where('is_global', true)
                ->where('is_active', true)
                ->select('id', 'name', 'price', 'is_global')
                ->get();

            $specificPackages = $router->packages()
                ->where('is_active', true)
                ->select('id', 'name', 'price', 'is_global')
                ->get();

            $allPackages = $globalPackages->merge($specificPackages)->unique('id');

            return [
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'location' => $router->location,
                    'ip_address' => $router->ip_address,
                    'status' => $router->status
                ],
                'revenue' => [
                    'total' => (float) $totalRevenue,
                    'transaction_count' => $transactionCount,
                    'average_per_transaction' => $transactionCount > 0 ? (float) ($totalRevenue / $transactionCount) : 0
                ],
                'package_breakdown' => $packageRevenue,
                'daily_revenue' => $dailyRevenue,
                'assigned_packages' => $allPackages
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Compare revenue across routers
     */
    public function compareRouters(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }

        $request->validate([
            'router_ids' => 'required|array|min:2',
            'router_ids.*' => 'exists:routers,id',
            'period' => 'nullable|in:7days,30days,90days,all'
        ]);

        $period = $request->input('period', '30days');
        $routerIds = $request->input('router_ids');

        // Determine date filter
        $dateFilter = null;
        switch ($period) {
            case '7days':
                $dateFilter = now()->subDays(7);
                break;
            case '30days':
                $dateFilter = now()->subDays(30);
                break;
            case '90days':
                $dateFilter = now()->subDays(90);
                break;
        }

        $comparison = [];

        foreach ($routerIds as $routerId) {
            $router = Router::where('id', $routerId)
                ->where('tenant_id', $tenantId)
                ->first();

            if (!$router) {
                continue;
            }

            $query = Payment::where('router_id', $routerId)
                ->where('status', 'completed');

            if ($dateFilter) {
                $query->where('created_at', '>=', $dateFilter);
            }

            $revenue = $query->sum('amount');
            $transactions = $query->count();

            $comparison[] = [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'revenue' => (float) $revenue,
                'transactions' => $transactions,
                'average_per_transaction' => $transactions > 0 ? (float) ($revenue / $transactions) : 0
            ];
        }

        // Sort by revenue
        usort($comparison, function($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });

        return response()->json([
            'success' => true,
            'period' => $period,
            'data' => $comparison
        ]);
    }
}
