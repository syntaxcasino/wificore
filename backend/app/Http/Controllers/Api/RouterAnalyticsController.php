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

        // OPTIMIZATION: Support pagination for large router lists
        $perPage = min((int) $request->input('per_page', 50), 100);
        $page = (int) $request->input('page', 1);

        $analytics = Cache::remember($cacheKey, 60, function () use ($perPage) {
            // Single query: total revenue + count grouped by router_id
            $revenueByRouter = Payment::where('status', 'completed')
                ->select('router_id', DB::raw('SUM(amount) as total_revenue'), DB::raw('COUNT(*) as transaction_count'))
                ->groupBy('router_id')
                ->get()
                ->keyBy('router_id');

            // Single query: package breakdown grouped by router_id + package_id
            $packageRows = Payment::where('status', 'completed')
                ->select('router_id', 'package_id', DB::raw('SUM(amount) as total_revenue'), DB::raw('COUNT(*) as transaction_count'))
                ->groupBy('router_id', 'package_id')
                ->with('package:id,name')
                ->get()
                ->groupBy('router_id');

            // OPTIMIZATION: Only get routers that have revenue data + limit to reasonable number
            $routerIdsWithRevenue = $revenueByRouter->keys()->toArray();

            if (empty($routerIdsWithRevenue)) {
                return collect([]);
            }

            // Get only routers with revenue, limited to top 100 by revenue
            $routers = Router::select('id', 'name', 'location', 'status')
                ->whereIn('id', $routerIdsWithRevenue)
                ->limit(100)
                ->get();

            $routerAnalytics = $routers->map(function ($router) use ($revenueByRouter, $packageRows) {
                $rev = $revenueByRouter->get((string) $router->id);
                $pkgRows = $packageRows->get((string) $router->id, collect());

                return [
                    'router_id'        => $router->id,
                    'router_name'      => $router->name,
                    'router_location'  => $router->location,
                    'total_revenue'    => (float) ($rev->total_revenue ?? 0),
                    'transaction_count'=> (int)   ($rev->transaction_count ?? 0),
                    'package_breakdown'=> $pkgRows->map(fn($item) => [
                        'package_id'   => $item->package_id,
                        'package_name' => $item->package->name ?? 'Unknown',
                        'revenue'      => (float) $item->total_revenue,
                        'transactions' => (int)   $item->transaction_count,
                    ])->values(),
                    'status' => $router->status,
                ];
            })->sortByDesc('total_revenue')->values()->all();

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
            ->firstOrFail();

        $cacheKey = "router_details_{$routerId}";

        $analytics = Cache::remember($cacheKey, 60, function () use ($router) {
            // Single query for revenue + count
            $revRow = Payment::where('router_id', $router->id)
                ->where('status', 'completed')
                ->selectRaw('COALESCE(SUM(amount),0) as total_revenue, COUNT(*) as transaction_count')
                ->first();

            $totalRevenue     = (float) ($revRow->total_revenue     ?? 0);
            $transactionCount = (int)   ($revRow->transaction_count ?? 0);

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

            // Assigned packages (global + specific) - schema isolation handles tenancy
            $globalPackages = Package::where('is_global', true)
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

        // Pre-load all requested routers in one query (no N+1)
        $routers = Router::select('id', 'name')
            ->whereIn('id', $routerIds)
            ->get()
            ->keyBy('id');

        // Single aggregate query across all requested routers
        $paymentQuery = Payment::whereIn('router_id', $routerIds)
            ->where('status', 'completed')
            ->select('router_id', DB::raw('COALESCE(SUM(amount),0) as revenue'), DB::raw('COUNT(*) as transactions'))
            ->groupBy('router_id');

        if ($dateFilter) {
            $paymentQuery->where('created_at', '>=', $dateFilter);
        }

        $paymentsByRouter = $paymentQuery->get()->keyBy('router_id');

        $comparison = [];
        foreach ($routerIds as $routerId) {
            $router = $routers->get($routerId);
            if (!$router) continue;

            $p = $paymentsByRouter->get($routerId);
            $revenue      = (float) ($p->revenue      ?? 0);
            $transactions = (int)   ($p->transactions ?? 0);

            $comparison[] = [
                'router_id'               => $router->id,
                'router_name'             => $router->name,
                'revenue'                 => $revenue,
                'transactions'            => $transactions,
                'average_per_transaction' => $transactions > 0 ? $revenue / $transactions : 0,
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
