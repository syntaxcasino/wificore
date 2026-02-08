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
        $stats = Cache::remember('system_admin_dashboard_stats', 300, function () {
            $totalTenants = Tenant::count();
            $activeTenants = Tenant::where('is_active', true)
                ->whereNull('suspended_at')
                ->count();
            $totalUsers = User::withoutGlobalScopes()->count();

            // Routers and packages are in tenant schemas - aggregate via cross-schema queries
            $routerStats = $this->aggregateAcrossTenantSchemas('routers', ['status']);
            $packageStats = $this->aggregateAcrossTenantSchemas('packages', ['is_active']);
            $totalRouters = $routerStats['total'] ?? 0;
            
            // Get real system metrics
            $systemMetrics = SystemMetricsService::getAllMetrics();
            
            return [
                // Flat structure for frontend
                'totalTenants' => $totalTenants,
                'activeTenants' => $activeTenants,
                'totalUsers' => $totalUsers,
                'totalRouters' => $totalRouters,
                'avgResponseTime' => number_format($systemMetrics['average_response_time'], 2),
                'uptime' => number_format($systemMetrics['uptime'], 1),
                
                // System health metrics
                'systemHealth' => [
                    'uptime' => $systemMetrics['uptime'],
                    'disk_space' => $systemMetrics['disk_space'],
                    'database_connections' => $systemMetrics['database_connections'],
                    'memory_usage' => $systemMetrics['memory_usage'],
                    'redis_cache_hit_ratio' => $systemMetrics['redis_cache_hit_ratio'],
                    'average_response_time' => $systemMetrics['average_response_time'],
                ],
                
                // Nested structure for detailed stats
                'tenants' => [
                    'total' => $totalTenants,
                    'active' => $activeTenants,
                    'suspended' => Tenant::whereNotNull('suspended_at')->count(),
                    'on_trial' => Tenant::whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '>', now())
                        ->count(),
                ],
                'users' => [
                    'total' => $totalUsers,
                    'active' => User::withoutGlobalScopes()->where('is_active', true)->count(),
                    'admins' => User::withoutGlobalScopes()->where('role', 'admin')->count(),
                    'hotspot_users' => User::withoutGlobalScopes()->where('role', 'hotspot_user')->count(),
                ],
                'routers' => [
                    'total' => $totalRouters,
                    'online' => $routerStats['online'] ?? 0,
                    'offline' => $routerStats['offline'] ?? 0,
                ],
                'packages' => [
                    'total' => $packageStats['total'] ?? 0,
                    'active' => $packageStats['active'] ?? 0,
                ],
                'subscriptions' => [
                    'active' => Tenant::where('is_active', true)
                        ->where('is_landlord', false)
                        ->where(function ($q) {
                            $q->whereNull('subscription_ends_at')
                              ->orWhere('subscription_ends_at', '>', now());
                        })
                        ->count(),
                    'expiring_soon' => Tenant::where('is_active', true)
                        ->where('is_landlord', false)
                        ->whereNotNull('subscription_ends_at')
                        ->where('subscription_ends_at', '>', now())
                        ->where('subscription_ends_at', '<=', now()->addDays(5))
                        ->count(),
                    'expired' => Tenant::where('is_landlord', false)
                        ->whereNotNull('subscription_ends_at')
                        ->where('subscription_ends_at', '<', now())
                        ->count(),
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
     * Aggregate counts across all tenant schemas for a given table.
     * Returns totals and breakdowns by specified columns.
     */
    private function aggregateAcrossTenantSchemas(string $table, array $breakdownColumns = []): array
    {
        $result = ['total' => 0];
        $tenants = Tenant::where('schema_created', true)
            ->whereNotNull('schema_name')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $count = DB::selectOne("SELECT COUNT(*) as cnt FROM {$tenant->schema_name}.{$table}");
                $result['total'] += $count->cnt ?? 0;

                if ($table === 'routers') {
                    $online = DB::selectOne("SELECT COUNT(*) as cnt FROM {$tenant->schema_name}.{$table} WHERE status = 'online'");
                    $offline = DB::selectOne("SELECT COUNT(*) as cnt FROM {$tenant->schema_name}.{$table} WHERE status = 'offline'");
                    $result['online'] = ($result['online'] ?? 0) + ($online->cnt ?? 0);
                    $result['offline'] = ($result['offline'] ?? 0) + ($offline->cnt ?? 0);
                }

                if ($table === 'packages') {
                    $active = DB::selectOne("SELECT COUNT(*) as cnt FROM {$tenant->schema_name}.{$table} WHERE is_active = true");
                    $result['active'] = ($result['active'] ?? 0) + ($active->cnt ?? 0);
                }
            } catch (\Exception $e) {
                \Log::warning("Failed to aggregate {$table} for tenant {$tenant->name}: " . $e->getMessage());
            }
        }

        return $result;
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
