<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Package;
use App\Models\Router;
use App\Models\Payment;
use App\Services\SystemMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SystemAdminController extends Controller
{
    /**
     * Get system-wide dashboard statistics
     */
    public function getDashboardStats(Request $request)
    {
        $stats = Cache::remember('system_admin_dashboard_stats', 60, function () {
            // Single query for all tenant counts
            $tenantCounts = DB::table('tenants')
                ->whereNull('deleted_at')
                ->where('is_landlord', false)
                ->where('is_default', false)
                ->selectRaw("
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE is_active = true AND suspended_at IS NULL) as active,
                    COUNT(*) FILTER (WHERE suspended_at IS NOT NULL) as suspended,
                    COUNT(*) FILTER (WHERE trial_ends_at IS NOT NULL AND trial_ends_at > NOW()) as on_trial,
                    COUNT(*) FILTER (WHERE is_active = true AND (subscription_ends_at IS NULL OR subscription_ends_at > NOW())) as sub_active,
                    COUNT(*) FILTER (WHERE is_active = true AND subscription_ends_at IS NOT NULL AND subscription_ends_at > NOW() AND subscription_ends_at <= NOW() + INTERVAL '5 days') as sub_expiring,
                    COUNT(*) FILTER (WHERE subscription_ends_at IS NOT NULL AND subscription_ends_at < NOW()) as sub_expired
                ")
                ->first();

            // Single query for all user counts
            // Note: users table does NOT have soft deletes (no deleted_at column)
            $userCounts = DB::table('users')
                ->selectRaw("
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE is_active = true) as active,
                    COUNT(*) FILTER (WHERE role = 'admin') as admins
                ")
                ->first();

            // Single cross-schema query for routers, packages, service users, revenue
            $schemaStats = $this->aggregateAllTenantSchemas();

            $totalServiceUsers = ($schemaStats['hotspot_users'] ?? 0) + ($schemaStats['pppoe_users'] ?? 0);
            $totalUsers = ($userCounts->total ?? 0) + $totalServiceUsers;

            // System metrics cached separately at shorter TTL
            $systemMetrics = Cache::remember('system_metrics', 30, fn() => SystemMetricsService::getAllMetrics());

            return [
                'totalTenants'   => (int) ($tenantCounts->total ?? 0),
                'activeTenants'  => (int) ($tenantCounts->active ?? 0),
                'totalUsers'     => $totalUsers,
                'totalRouters'   => $schemaStats['routers_total'] ?? 0,
                'totalRevenue'   => $schemaStats['revenue_total'] ?? 0,
                'monthlyRevenue' => $schemaStats['revenue_monthly'] ?? 0,
                'avgResponseTime' => number_format($systemMetrics['average_response_time'], 2),
                'uptime'         => number_format($systemMetrics['uptime'], 1),
                'systemHealth' => [
                    'uptime'               => $systemMetrics['uptime'],
                    'disk_space'           => $systemMetrics['disk_space'],
                    'database_connections' => $systemMetrics['database_connections'],
                    'memory_usage'         => $systemMetrics['memory_usage'],
                    'redis_cache_hit_ratio'=> $systemMetrics['redis_cache_hit_ratio'],
                    'average_response_time'=> $systemMetrics['average_response_time'],
                ],
                'tenants' => [
                    'total'    => (int) ($tenantCounts->total ?? 0),
                    'active'   => (int) ($tenantCounts->active ?? 0),
                    'suspended'=> (int) ($tenantCounts->suspended ?? 0),
                    'on_trial' => (int) ($tenantCounts->on_trial ?? 0),
                ],
                'users' => [
                    'total'         => $totalUsers,
                    'admin_users'   => (int) ($userCounts->total ?? 0),
                    'service_users' => $totalServiceUsers,
                    'active'        => (int) ($userCounts->active ?? 0),
                    'admins'        => (int) ($userCounts->admins ?? 0),
                    'hotspot_users' => $schemaStats['hotspot_users'] ?? 0,
                    'pppoe_users'   => $schemaStats['pppoe_users'] ?? 0,
                ],
                'routers' => [
                    'total'  => $schemaStats['routers_total'] ?? 0,
                    'online' => $schemaStats['routers_online'] ?? 0,
                    'offline'=> $schemaStats['routers_offline'] ?? 0,
                ],
                'packages' => [
                    'total'  => $schemaStats['packages_total'] ?? 0,
                    'active' => $schemaStats['packages_active'] ?? 0,
                ],
                'revenue' => [
                    'total'   => $schemaStats['revenue_total'] ?? 0,
                    'monthly' => $schemaStats['revenue_monthly'] ?? 0,
                ],
                'subscriptions' => [
                    'active'        => (int) ($tenantCounts->sub_active ?? 0),
                    'expiring_soon' => (int) ($tenantCounts->sub_expiring ?? 0),
                    'expired'       => (int) ($tenantCounts->sub_expired ?? 0),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get tenant performance metrics (aggregate counts only - no sensitive data)
     */
    public function getTenantMetrics(Request $request)
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        $tenants = Tenant::where('is_landlord', false)
            ->where('is_default', false)
            ->withCount(['users'])
            ->get()
            ->map(function ($tenant) use ($tenantContext) {
                $routersCount = 0;
                $packagesCount = 0;

                if ($tenant->schema_created && $tenant->schema_name) {
                    try {
                        $tenantContext->runInTenantContext($tenant, function () use (&$routersCount, &$packagesCount) {
                            $routersCount = Router::count();
                            $packagesCount = Package::count();
                        });
                    } catch (\Exception $e) {
                        \Log::warning('Failed to get tenant metrics', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
                    }
                }

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'is_active' => $tenant->is_active,
                    'subscription_status' => $tenant->subscription_status,
                    'subscription_ends_at' => $tenant->subscription_ends_at,
                    'users_count' => $tenant->users_count,
                    'routers_count' => $routersCount,
                    'packages_count' => $packagesCount,
                    'has_override' => $tenant->landlord_override ?? false,
                ];
            });

        return response()->json([
            'success' => true,
            'tenants' => $tenants
        ]);
    }

    /**
     * Get system activity logs
     */
    public function getActivityLogs(Request $request)
    {
        $logs = DB::table('system_logs')
            ->select('tenant_id', 'action', 'details', 'created_at')
            ->orderBy('created_at', 'desc')
            ->limit($request->limit ?? 100)
            ->get();

        return response()->json([
            'success' => true,
            'logs' => $logs
        ]);
    }

    /**
     * Get tenant details with full statistics
     */
    public function getTenantDetails(Request $request, $tenantId)
    {
        $tenant = Tenant::with(['users'])
            ->withCount(['users'])
            ->findOrFail($tenantId);

        $stats = [
            'users' => [
                'total' => $tenant->users_count,
                'active' => $tenant->users()->where('is_active', true)->count(),
                'admins' => $tenant->users()->where('role', 'admin')->count(),
            ],
            'routers' => ['total' => 0, 'online' => 0],
            'packages' => ['total' => 0, 'active' => 0],
            'revenue' => ['total' => 0, 'monthly' => 0],
        ];

        // Fetch tenant-schema stats via context switch
        if ($tenant->schema_created && $tenant->schema_name) {
            try {
                $tenantContext = app(\App\Services\TenantContext::class);
                $tenantContext->runInTenantContext($tenant, function () use (&$stats) {
                    $stats['routers']['total'] = Router::count();
                    $stats['routers']['online'] = Router::where('status', 'online')->count();
                    $stats['packages']['total'] = Package::count();
                    $stats['packages']['active'] = Package::where('is_active', true)->count();
                    $stats['revenue']['total'] = Payment::where('status', 'completed')->sum('amount');
                    $stats['revenue']['monthly'] = Payment::where('status', 'completed')
                        ->whereMonth('created_at', now()->month)
                        ->sum('amount');
                });
            } catch (\Exception $e) {
                \Log::warning('Failed to get tenant detail stats', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'tenant' => $tenant,
            'stats' => $stats
        ]);
    }

    /**
     * Get system health metrics
     */
    public function getSystemHealth(Request $request)
    {
        $metrics = SystemMetricsService::getAllMetrics();
        $dbConnections = $metrics['database_connections'];
        $diskSpace = $metrics['disk_space'];
        
        return response()->json([
            'success' => true,
            'database' => [
                'status' => $dbConnections['active'] < $dbConnections['max'] * 0.8 ? 'healthy' : 'warning',
                'connections' => $dbConnections['active'],
                'maxConnections' => $dbConnections['max'],
                'responseTime' => round($metrics['average_response_time'] * 1000, 0), // Convert to ms
                'healthPercentage' => 100 - $dbConnections['percentage'],
            ],
            'redis' => [
                'status' => $metrics['redis_cache_hit_ratio'] > 80 ? 'healthy' : 'warning',
                'hitRate' => $metrics['redis_cache_hit_ratio'],
                'memoryUsed' => $metrics['memory_usage']['used'],
                'healthPercentage' => $metrics['redis_cache_hit_ratio'],
            ],
            'queue' => [
                'status' => 'healthy', // TODO: Implement queue health check
                'activeWorkers' => 3,
                'failedJobs' => 0,
                'healthPercentage' => 100,
            ],
            'disk' => [
                'total' => $diskSpace['total'],
                'available' => $diskSpace['free'],
                'usedPercentage' => $diskSpace['used_percentage'],
            ],
            'uptime' => [
                'percentage' => $metrics['uptime'],
                'duration' => '30 days', // TODO: Calculate actual duration
                'lastRestart' => Cache::get('app_start_time', now())->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Aggregate all tenant-schema stats in the fewest possible queries.
     * Builds a UNION query across all active tenant schemas to avoid N×queries.
     */
    private function aggregateAllTenantSchemas(): array
    {
        $tenants = Tenant::where('schema_created', true)
            ->whereNotNull('schema_name')
            ->pluck('schema_name');

        $result = [
            'routers_total' => 0, 'routers_online' => 0, 'routers_offline' => 0,
            'packages_total' => 0, 'packages_active' => 0,
            'hotspot_users' => 0, 'pppoe_users' => 0,
            'revenue_total' => 0.0, 'revenue_monthly' => 0.0,
        ];

        if ($tenants->isEmpty()) {
            return $result;
        }

        $monthStart = now()->startOfMonth()->toDateTimeString();
        $routerParts = [];
        $packageParts = [];
        $hotspotParts = [];
        $pppoeParts = [];
        $revenueParts = [];

        foreach ($tenants as $schema) {
            $s = preg_replace('/[^a-zA-Z0-9_]/', '', $schema);
            if (!$s) continue;
            $routerParts[]  = "SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE status='online') as online, COUNT(*) FILTER (WHERE status='offline') as offline FROM {$s}.routers";
            $packageParts[] = "SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE is_active=true) as active FROM {$s}.packages";
            $hotspotParts[] = "SELECT COUNT(*) as cnt FROM {$s}.hotspot_users";
            $pppoeParts[]   = "SELECT COUNT(*) as cnt FROM {$s}.pppoe_users";
            $revenueParts[] = "SELECT COALESCE(SUM(amount),0) as total, COALESCE(SUM(amount) FILTER (WHERE created_at >= '{$monthStart}'),0) as monthly FROM {$s}.payments WHERE status='completed'";
        }

        $runUnion = function (array $parts, callable $accumulate) {
            try {
                $sql = implode(' UNION ALL ', $parts);
                $rows = DB::select($sql);
                foreach ($rows as $row) {
                    $accumulate($row);
                }
            } catch (\Exception $e) {
                \Log::warning('Aggregate query failed: ' . $e->getMessage());
            }
        };

        $runUnion($routerParts, function ($r) use (&$result) {
            $result['routers_total']  += (int) $r->total;
            $result['routers_online'] += (int) $r->online;
            $result['routers_offline']+= (int) $r->offline;
        });
        $runUnion($packageParts, function ($r) use (&$result) {
            $result['packages_total'] += (int) $r->total;
            $result['packages_active']+= (int) $r->active;
        });
        $runUnion($hotspotParts, function ($r) use (&$result) {
            $result['hotspot_users'] += (int) $r->cnt;
        });
        $runUnion($pppoeParts, function ($r) use (&$result) {
            $result['pppoe_users'] += (int) $r->cnt;
        });
        $runUnion($revenueParts, function ($r) use (&$result) {
            $result['revenue_total']   += (float) $r->total;
            $result['revenue_monthly'] += (float) $r->monthly;
        });

        return $result;
    }

    /**
     * Bust the dashboard stats cache and return fresh data immediately.
     */
    public function refreshDashboardStats(Request $request)
    {
        Cache::forget('system_admin_dashboard_stats');
        return $this->getDashboardStats($request);
    }

    public function createSystemAdmin(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
            'tenant_id' => null, // System admins don't belong to any tenant
        ]);

        return response()->json([
            'success' => true,
            'message' => 'System administrator created successfully',
            'user' => $user
        ], 201);
    }
}
