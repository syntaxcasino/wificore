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
            $totalRouters = Router::withoutGlobalScopes()->count();
            
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
                    'online' => Router::withoutGlobalScopes()->where('status', 'online')->count(),
                    'offline' => Router::withoutGlobalScopes()->where('status', 'offline')->count(),
                ],
                'packages' => [
                    'total' => Package::withoutGlobalScopes()->count(),
                    'active' => Package::withoutGlobalScopes()->where('is_active', true)->count(),
                ],
                'revenue' => [
                    'total' => Payment::withoutGlobalScopes()
                        ->where('status', 'completed')
                        ->sum('amount'),
                    'monthly' => Payment::withoutGlobalScopes()
                        ->where('status', 'completed')
                        ->whereMonth('created_at', now()->month)
                        ->sum('amount'),
                    'today' => Payment::withoutGlobalScopes()
                        ->where('status', 'completed')
                        ->whereDate('created_at', now())
                        ->sum('amount'),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get tenant performance metrics
     */
    public function getTenantMetrics(Request $request)
    {
        $tenants = Tenant::withCount([
            'users',
            'routers',
            'packages',
            'payments'
        ])
        ->with(['users' => function ($query) {
            $query->select('tenant_id', DB::raw('count(*) as count'))
                ->groupBy('tenant_id');
        }])
        ->get()
        ->map(function ($tenant) {
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_active' => $tenant->is_active,
                'users_count' => $tenant->users_count,
                'routers_count' => $tenant->routers_count,
                'packages_count' => $tenant->packages_count,
                'revenue' => Payment::where('tenant_id', $tenant->id)
                    ->where('status', 'completed')
                    ->sum('amount'),
                'monthly_revenue' => Payment::where('tenant_id', $tenant->id)
                    ->where('status', 'completed')
                    ->whereMonth('created_at', now()->month)
                    ->sum('amount'),
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
        $tenant = Tenant::with(['users', 'routers', 'packages'])
            ->withCount(['users', 'routers', 'packages', 'payments'])
            ->findOrFail($tenantId);

        $stats = [
            'users' => [
                'total' => $tenant->users_count,
                'active' => $tenant->users()->where('is_active', true)->count(),
                'admins' => $tenant->users()->where('role', 'admin')->count(),
            ],
            'routers' => [
                'total' => $tenant->routers_count,
                'online' => $tenant->routers()->where('status', 'online')->count(),
            ],
            'packages' => [
                'total' => $tenant->packages_count,
                'active' => $tenant->packages()->where('is_active', true)->count(),
            ],
            'revenue' => [
                'total' => $tenant->payments()->where('status', 'completed')->sum('amount'),
                'monthly' => $tenant->payments()
                    ->where('status', 'completed')
                    ->whereMonth('created_at', now()->month)
                    ->sum('amount'),
            ],
        ];

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
     * Create system administrator user
     */
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
