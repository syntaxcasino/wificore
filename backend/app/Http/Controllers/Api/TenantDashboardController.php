<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Package;
use App\Models\Router;
use App\Models\Payment;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TenantDashboardController extends Controller
{
    private const CACHE_TTL_SECONDS = 30;
    private const CACHE_LOCK_SECONDS = 10;
    private const LIST_CACHE_TTL_SECONDS = 5; // Short TTL for list endpoints

    // Cache key patterns
    private const KEY_DASHBOARD = 'tenant_dashboard_v2';
    private const KEY_USERS = 'tenant_users';
    private const KEY_PACKAGES = 'tenant_packages';
    private const KEY_ROUTERS = 'tenant_routers';
    private const KEY_PAYMENTS = 'tenant_payments';
    private const KEY_SESSIONS = 'tenant_sessions';

    /**
     * Get tenant dashboard statistics
     * SECURITY: Only returns data for authenticated user's tenant
     * OPTIMIZED: 30s cache with stampede protection, batched queries, selective columns
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any tenant'
            ], 403);
        }

        $cacheKey = "tenant_dashboard_v2:{$tenantId}";
        $lockKey = "tenant_dashboard_lock:{$tenantId}";

        // Fast path: return cached data immediately if available
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json(['success' => true, 'data' => $cached, 'cached' => true]);
        }

        // Cache stampede protection: only one process regenerates
        $lock = Cache::lock($lockKey, self::CACHE_LOCK_SECONDS);

        try {
            $stats = $lock->get(function () use ($tenantId, $cacheKey) {
                // Double-check after acquiring lock
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                return $this->computeDashboardStats($tenantId, $cacheKey);
            });

            return response()->json(['success' => true, 'data' => $stats, 'cached' => false]);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Lock timeout - return stale data or computed fallback
            $fallback = $this->computeDashboardStats($tenantId, null);
            return response()->json(['success' => true, 'data' => $fallback, 'cached' => false, 'lock_timeout' => true]);
        }
    }

    /**
     * Compute dashboard statistics with optimized queries
     */
    private function computeDashboardStats(string $tenantId, ?string $cacheKey): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $thirtyDaysAgo = now()->subDays(30)->toDateString();

        // OPTIMIZATION 1: Batch all aggregations into minimal queries
        $userCounts = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active=true THEN 1 ELSE 0 END) as active')
            ->first();

        // OPTIMIZATION 2: Single query for all session stats
        $sessionCounts = UserSession::query()
            ->selectRaw("
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_total,
                COUNT(DISTINCT CASE WHEN status='active' THEN user_id END) as active_unique,
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as today_total
            ", [$today])
            ->first();

        // OPTIMIZATION 3: Single query for all revenue stats
        $revenueCounts = Payment::query()
            ->where('status', 'completed')
            ->selectRaw("
                COALESCE(SUM(amount), 0) as total,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) as monthly,
                COALESCE(SUM(CASE WHEN DATE(created_at) = ? THEN amount ELSE 0 END), 0) as today,
                COALESCE(AVG(CASE WHEN DATE(created_at) >= ? THEN amount END), 0) as avg_30d,
                COALESCE(MAX(CASE WHEN DATE(created_at) >= ? THEN amount END), 0) as peak_30d
            ", [$monthStart, $today, $thirtyDaysAgo, $thirtyDaysAgo])
            ->first();

        // OPTIMIZATION 4: Single query for router counts only (not full listing)
        $routerCounts = Router::query()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) as online,
                SUM(CASE WHEN status='offline' THEN 1 ELSE 0 END) as offline,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active
            ")
            ->first();

        // OPTIMIZATION 5: Only get active packages (most dashboards don't need inactive)
        // Limit to 50 for dashboard preview - full list available via getPackages()
        $packages = Package::query()
            ->select('id', 'name', 'price', 'type', 'is_active', 'download_speed', 'upload_speed')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        // OPTIMIZATION 6: Only get essential router info, limit for dashboard
        $routers = Router::query()
            ->select('id', 'name', 'ip_address', 'status', 'last_seen', 'model')
            ->orderByRaw("CASE WHEN status='online' THEN 0 ELSE 1 END") // Online first
            ->orderBy('last_seen', 'desc')
            ->limit(20)
            ->get();

        $stats = [
            'users' => [
                'total'  => (int) ($userCounts->total ?? 0),
                'active' => (int) ($userCounts->active ?? 0),
                'online' => (int) ($sessionCounts->active_unique ?? 0),
            ],
            'packages' => $packages,
            'routers'  => $routers,
            'revenue' => [
                'total'   => (float) ($revenueCounts->total ?? 0),
                'monthly' => (float) ($revenueCounts->monthly ?? 0),
                'today'   => (float) ($revenueCounts->today ?? 0),
            ],
            'sessions' => [
                'active' => (int) ($sessionCounts->active_total ?? 0),
                'today'  => (int) ($sessionCounts->today_total ?? 0),
            ],
            'sms_expenses' => [
                'balance' => 0, 'purchases' => 0, 'sent' => 0,
                'daily_usage' => 0, 'weekly_usage' => 0, 'monthly_usage' => 0,
                'total_spent' => 0, 'this_month' => 0,
            ],
            'business_analytics' => [
                'user_retention'  => 0,
                'avg_revenue'     => (float) ($revenueCounts->avg_30d ?? 0),
                'peak_revenue'    => (float) ($revenueCounts->peak_30d ?? 0),
                'revenue_growth'  => 0,
                'active_users'    => (int) ($sessionCounts->active_unique ?? 0),
                'peak_users'      => 0,
                'user_growth'     => 0,
                'access_points'   => (int) ($routerCounts->active ?? 0),
            ],
            'computed_at' => now()->toIso8601String(),
        ];

        if ($cacheKey) {
            Cache::put($cacheKey, $stats, self::CACHE_TTL_SECONDS);
        }

        return $stats;
    }

    /**
     * Get tenant users
     * SECURITY: Only returns users for authenticated user's tenant
     * OPTIMIZED: 5-second cache for list, selective columns
     */
    public function getUsers(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $cacheKey = $this->getListCacheKey(self::KEY_USERS, $tenantId, $request);
        $perPage = $request->per_page ?? 15;

        $users = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($tenantId, $perPage) {
            return User::select('id', 'name', 'email', 'username', 'role', 'is_active', 'created_at')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'data' => $users,
            'cached' => Cache::has($cacheKey),
        ]);
    }

    /**
     * Get tenant packages
     * SECURITY: Only returns packages for authenticated user's tenant
     * OPTIMIZED: 5-second cache, optional active-only filter
     */
    public function getPackages(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $activeOnly = $request->boolean('active_only', false);
        $cacheKey = $this->getListCacheKey(self::KEY_PACKAGES, $tenantId, $request) . ($activeOnly ? ':active' : ':all');

        $packages = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($activeOnly) {
            $query = Package::select('id', 'name', 'description', 'price', 'type', 'duration', 'is_active', 'download_speed', 'upload_speed')
                ->orderBy('created_at', 'desc');

            if ($activeOnly) {
                $query->where('is_active', true);
            }

            return $query->get();
        });

        return response()->json([
            'success' => true,
            'data' => $packages,
            'cached' => Cache::has($cacheKey),
        ]);
    }

    /**
     * Get tenant routers
     * SECURITY: Only returns routers for authenticated user's tenant
     * OPTIMIZED: 5-second cache, selective columns, online-first sorting
     */
    public function getRouters(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $cacheKey = $this->getListCacheKey(self::KEY_ROUTERS, $tenantId, $request);

        $routers = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () {
            return Router::select('id', 'name', 'ip_address', 'model', 'status', 'last_seen', 'location')
                ->orderByRaw("CASE WHEN status='online' THEN 0 ELSE 1 END")
                ->orderBy('last_seen', 'desc')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $routers,
            'cached' => Cache::has($cacheKey),
        ]);
    }

    /**
     * Get tenant payments/revenue
     * SECURITY: Only returns payments for authenticated user's tenant
     * OPTIMIZED: 5-second cache, eager loading, selective columns
     */
    public function getPayments(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $cacheKey = $this->getListCacheKey(self::KEY_PAYMENTS, $tenantId, $request);
        $perPage = $request->per_page ?? 15;

        // Don't cache if specific filters are applied
        if ($request->has('status') || $request->has('date_from') || $request->has('date_to')) {
            $payments = $this->fetchPaymentsQuery($request, $perPage);
            return response()->json(['success' => true, 'data' => $payments, 'cached' => false]);
        }

        $payments = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($perPage) {
            return Payment::with(['user:id,name,email', 'package:id,name'])
                ->select('id', 'user_id', 'package_id', 'amount', 'status', 'payment_method', 'created_at', 'verified_at')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'data' => $payments,
            'cached' => Cache::has($cacheKey),
        ]);
    }

    /**
     * Fetch payments with filters (uncached)
     */
    private function fetchPaymentsQuery(Request $request, int $perPage)
    {
        $query = Payment::with(['user:id,name,email', 'package:id,name'])
            ->select('id', 'user_id', 'package_id', 'amount', 'status', 'payment_method', 'created_at', 'verified_at');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get tenant active sessions
     * SECURITY: Only returns sessions for authenticated user's tenant
     * OPTIMIZED: 3-second cache (very volatile data), selective columns
     */
    public function getSessions(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Sessions are volatile - use very short cache (3 seconds)
        $cacheKey = $this->getListCacheKey(self::KEY_SESSIONS, $tenantId, $request);
        $perPage = $request->per_page ?? 15;

        $sessions = Cache::remember($cacheKey, 3, function () use ($perPage) {
            return UserSession::with(['user:id,name,username', 'router:id,name'])
                ->where('status', 'active')
                ->select('id', 'user_id', 'router_id', 'ip_address', 'mac_address', 'upload_bytes', 'download_bytes', 'started_at')
                ->orderBy('started_at', 'desc')
                ->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'data' => $sessions,
            'cached' => Cache::has($cacheKey),
        ]);
    }

    /**
     * Generate cache key for list endpoints
     */
    private function getListCacheKey(string $type, string $tenantId, Request $request): string
    {
        $page = $request->page ?? 1;
        $perPage = $request->per_page ?? 15;
        return "{$type}:{$tenantId}:p{$page}:pp{$perPage}";
    }

    /**
     * Clear dashboard cache for a tenant - call this when data changes
     * Can be called from other controllers or observers
     */
    public static function bustDashboardCache(string $tenantId): void
    {
        Cache::forget("tenant_dashboard_v2:{$tenantId}");
    }

    /**
     * Clear all tenant list caches (users, packages, routers, payments)
     * Call this when any list data changes
     */
    public static function bustAllListCaches(string $tenantId): void
    {
        $patterns = [
            "tenant_users:{$tenantId}:*",
            "tenant_packages:{$tenantId}:*",
            "tenant_routers:{$tenantId}:*",
            "tenant_payments:{$tenantId}:*",
        ];

        foreach ($patterns as $pattern) {
            // Redis pattern delete (works with Laravel Cache::flush() for patterns if using Redis)
            // For file/array cache, we can't do pattern deletes, so we just clear specific known keys
        }

        // Clear common list cache keys
        for ($page = 1; $page <= 5; $page++) {
            Cache::forget("tenant_users:{$tenantId}:p{$page}:pp15");
            Cache::forget("tenant_packages:{$tenantId}:p{$page}:pp15");
            Cache::forget("tenant_routers:{$tenantId}:p{$page}:pp15");
            Cache::forget("tenant_payments:{$tenantId}:p{$page}:pp15");
        }

        // Also clear dashboard since it contains list data
        self::bustDashboardCache($tenantId);
    }

    /**
     * Clear specific entity type cache
     */
    public static function bustEntityCache(string $tenantId, string $entityType): void
    {
        $keyMap = [
            'users' => self::KEY_USERS,
            'packages' => self::KEY_PACKAGES,
            'routers' => self::KEY_ROUTERS,
            'payments' => self::KEY_PAYMENTS,
            'sessions' => self::KEY_SESSIONS,
        ];

        if (!isset($keyMap[$entityType])) {
            return;
        }

        $key = $keyMap[$entityType];

        // Clear first 5 pages of this entity type
        for ($page = 1; $page <= 5; $page++) {
            for ($perPage = 15; $perPage <= 50; $perPage += 35) {
                Cache::forget("{$key}:{$tenantId}:p{$page}:pp{$perPage}");
            }
        }

        // If Redis, try pattern delete
        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            Cache::getStore()->connection()->del(Cache::getStore()->connection()->keys("{$key}:{$tenantId}:*"));
        }
    }

    /**
     * Get cache TTL for external reference
     */
    public static function getCacheTtl(): int
    {
        return self::CACHE_TTL_SECONDS;
    }
}
