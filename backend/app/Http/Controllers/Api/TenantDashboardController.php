<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $lockKey  = "tenant_dashboard_lock:{$tenantId}";

        // Fast path: return cached data immediately if available.
        // Wrapped in try/catch so a Redis outage degrades gracefully to a DB query.
        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(['success' => true, 'data' => $cached, 'cached' => true]);
            }

            // Cache stampede protection: only one process regenerates
            $lock  = Cache::lock($lockKey, self::CACHE_LOCK_SECONDS);
            $stats = $lock->get(function () use ($tenantId, $cacheKey) {
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
                return $this->computeDashboardStats($tenantId, $cacheKey);
            });

            // $lock->get() returns null if the lock could not be acquired
            if ($stats === null) {
                $stats = $this->computeDashboardStats($tenantId, null);
            }

            return response()->json(['success' => true, 'data' => $stats, 'cached' => false]);

        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $stats = $this->computeDashboardStats($tenantId, null);
            return response()->json(['success' => true, 'data' => $stats, 'cached' => false, 'lock_timeout' => true]);

        } catch (\Exception $e) {
            // Redis unavailable or any other cache/lock failure — skip cache entirely
            \Illuminate\Support\Facades\Log::warning('Dashboard cache unavailable, falling back to direct query', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
            $stats = $this->computeDashboardStats($tenantId, null);
            return response()->json(['success' => true, 'data' => $stats, 'cached' => false, 'cache_error' => true]);
        }
    }

    /**
     * Compute dashboard statistics with optimized queries.
     * Returns a shape compatible with the frontend useDashboard.js mapping.
     * Uses only 5 batched queries + 2 single-pass DB::select for trends.
     */
    private function computeDashboardStats(string $tenantId, ?string $cacheKey): array
    {
        $now        = now();
        $today      = $now->toDateString();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $weekStart  = $now->copy()->subDays(6)->toDateString();
        $yearStart  = $now->copy()->startOfYear()->toDateString();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd   = $now->copy()->subMonth()->endOfMonth()->toDateString();

        // ── Q1: user counts (public schema, filter by tenant_id) ──────────────
        // NOTE: Tenant schema users table doesn't have soft deletes (no deleted_at column)
        $userCounts = $this->tenantUsersQuery($tenantId)
            ->selectRaw('COUNT(*) as total')
            ->first();

        // ── Q2: session counts + hotspot/pppoe split (tenant schema) ──────────
        $sessionCounts = UserSession::query()
            ->selectRaw("
                SUM(CASE WHEN status='active' AND (end_time IS NULL OR end_time > NOW()) THEN 1 ELSE 0 END) as active_total,
                SUM(CASE WHEN status='active' AND (end_time IS NULL OR end_time > NOW()) AND voucher IS NOT NULL THEN 1 ELSE 0 END) as hotspot,
                SUM(CASE WHEN status='active' AND (end_time IS NULL OR end_time > NOW()) AND voucher IS NULL THEN 1 ELSE 0 END) as pppoe,
                COALESCE(SUM(data_used), 0) as data_used_bytes,
                COALESCE(SUM(data_upload), 0) as data_upload_bytes,
                COALESCE(SUM(data_download), 0) as data_download_bytes
            ")
            ->first();

        // ── Q3: all revenue aggregations in one pass ───────────────────────────
        $revenueCounts = Payment::query()
            ->where('status', 'completed')
            ->selectRaw("
                COALESCE(SUM(amount), 0) as total,
                COALESCE(SUM(CASE WHEN DATE(created_at) = ? THEN amount ELSE 0 END), 0) as daily,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) as weekly,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) as monthly,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) as yearly,
                COUNT(CASE WHEN DATE(created_at) = ? THEN 1 END) as daily_count,
                COUNT(CASE WHEN DATE(created_at) >= ? THEN 1 END) as weekly_count,
                COUNT(CASE WHEN DATE(created_at) >= ? THEN 1 END) as monthly_count,
                COUNT(CASE WHEN DATE(created_at) >= ? THEN 1 END) as yearly_count,
                COALESCE(AVG(CASE WHEN DATE(created_at) >= ? THEN amount END), 0) as avg_30d,
                COALESCE(MAX(CASE WHEN DATE(created_at) >= ? THEN amount END), 0) as peak_30d,
                COUNT(DISTINCT CASE WHEN DATE(created_at) >= ? THEN user_id END) as current_month_users,
                COUNT(DISTINCT CASE WHEN DATE(created_at) BETWEEN ? AND ? THEN user_id END) as last_month_users
            ", [
                $today,
                $weekStart, $monthStart, $yearStart,
                $today,
                $weekStart, $monthStart, $yearStart,
                $monthStart, $monthStart,
                $monthStart,
                $lastMonthStart, $lastMonthEnd,
            ])
            ->first();

        // ── Q4: router counts ──────────────────────────────────────────────────
        $routerCounts = Router::query()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) as online,
                SUM(CASE WHEN status='offline' THEN 1 ELSE 0 END) as offline,
                SUM(CASE WHEN status='provisioning' THEN 1 ELSE 0 END) as provisioning
            ")
            ->first();

        // ── Q5: 7-day daily trend (sessions + payments) in 2 batched selects ──
        $trendRows = DB::select("
            SELECT
                g.day::date AS date,
                COALESCE(s.cnt, 0) AS sessions,
                COALESCE(p.amt, 0) AS revenue
            FROM generate_series(
                (NOW() - INTERVAL '6 days')::date,
                NOW()::date,
                INTERVAL '1 day'
            ) AS g(day)
            LEFT JOIN (
                SELECT DATE(start_time) AS d, COUNT(*) AS cnt
                FROM user_sessions
                WHERE start_time >= NOW() - INTERVAL '6 days'
                GROUP BY DATE(start_time)
            ) s ON s.d = g.day::date
            LEFT JOIN (
                SELECT DATE(created_at) AS d, SUM(amount) AS amt
                FROM payments
                WHERE status = 'completed' AND created_at >= NOW() - INTERVAL '6 days'
                GROUP BY DATE(created_at)
            ) p ON p.d = g.day::date
            ORDER BY g.day ASC
        ");

        // ── Build trend arrays ─────────────────────────────────────────────────
        $weeklyUsersTrend   = [];
        $weeklyRevenueTrend = [];
        $revenueTrendData   = [];
        $userTrendData      = [];
        $maxRev = 1;
        $maxUsr = 1;

        foreach ($trendRows as $row) {
            $day = date('D', strtotime($row->date));
            $weeklyUsersTrend[]   = ['date' => $day, 'count' => (int) $row->sessions];
            $weeklyRevenueTrend[] = ['date' => $day, 'amount' => (float) $row->revenue];
            $maxRev = max($maxRev, $row->revenue);
            $maxUsr = max($maxUsr, $row->sessions);
        }

        foreach ($trendRows as $row) {
            $day = date('D', strtotime($row->date));
            $revenueTrendData[] = ['label' => $day, 'amount' => (float) $row->revenue, 'percentage' => round(($row->revenue / $maxRev) * 100, 2)];
            $userTrendData[]    = ['label' => $day, 'count'  => (int) $row->sessions,  'percentage' => round(($row->sessions / $maxUsr) * 100, 2)];
        }

        // ── Derived metrics ────────────────────────────────────────────────────
        $totalRevenue    = (float) ($revenueCounts->total ?? 0);
        $dailyIncome     = (float) ($revenueCounts->daily ?? 0);
        $weeklyIncome    = (float) ($revenueCounts->weekly ?? 0);
        $monthlyIncome   = (float) ($revenueCounts->monthly ?? 0);
        $yearlyIncome    = (float) ($revenueCounts->yearly ?? 0);
        $dataUsageGb     = round(($sessionCounts->data_used_bytes ?? 0) / (1024 ** 3), 2);
        $dataUploadGb    = round(($sessionCounts->data_upload_bytes ?? 0) / (1024 ** 3), 2);
        $dataDownloadGb  = round(($sessionCounts->data_download_bytes ?? 0) / (1024 ** 3), 2);

        $currentMonthUsers = (int) ($revenueCounts->current_month_users ?? 0);
        $lastMonthUsers    = (int) ($revenueCounts->last_month_users ?? 0);
        $retainedUsers     = 0; // Would need a subquery — kept as 0 to avoid extra query
        $retentionRate     = $lastMonthUsers > 0 ? round(($currentMonthUsers / $lastMonthUsers) * 100, 2) : 0;

        $revenueAverage = count($revenueTrendData) > 0
            ? round(array_sum(array_column($revenueTrendData, 'amount')) / count($revenueTrendData), 2)
            : 0;
        $revenueGrowth = 0;
        if (count($revenueTrendData) >= 2) {
            $first = $revenueTrendData[0]['amount'];
            $last  = $revenueTrendData[count($revenueTrendData) - 1]['amount'];
            if ($first > 0) {
                $revenueGrowth = round((($last - $first) / $first) * 100, 2);
            }
        }

        $userAverage = count($userTrendData) > 0
            ? round(array_sum(array_column($userTrendData, 'count')) / count($userTrendData), 0)
            : 0;
        $userGrowth = 0;
        if (count($userTrendData) >= 2) {
            $first = $userTrendData[0]['count'];
            $last  = $userTrendData[count($userTrendData) - 1]['count'];
            if ($first > 0) {
                $userGrowth = round((($last - $first) / $first) * 100, 2);
            }
        }

        // ── SMS data from cache (no extra DB query) ────────────────────────────
        $smsBalance        = (int) Cache::get('sms_balance', 0);
        $smsTotalPurchased = (int) Cache::get('sms_total_purchased', 0);
        $smsUsed           = $smsTotalPurchased - $smsBalance;

        // ── Build weekly payment breakdown (derived from trend) ────────────────
        $weeklyDailyBreakdown = [];
        $maxWeeklyAmt = max(array_column($weeklyRevenueTrend, 'amount') ?: [1]);
        foreach ($weeklyRevenueTrend as $row) {
            $weeklyDailyBreakdown[] = [
                'date'       => $row['date'],
                'day'        => $row['date'],
                'amount'     => $row['amount'],
                'percentage' => $maxWeeklyAmt > 0 ? round(($row['amount'] / $maxWeeklyAmt) * 100, 2) : 0,
            ];
        }

        $stats = [
            // ── KPI fields (legacy shape for useDashboard.js) ──────────────────
            'total_routers'        => (int) ($routerCounts->total ?? 0),
            'online_routers'       => (int) ($routerCounts->online ?? 0),
            'offline_routers'      => (int) ($routerCounts->offline ?? 0),
            'provisioning_routers' => (int) ($routerCounts->provisioning ?? 0),
            'active_sessions'      => (int) ($sessionCounts->active_total ?? 0),
            'hotspot_users'        => (int) ($sessionCounts->hotspot ?? 0),
            'pppoe_users'          => (int) ($sessionCounts->pppoe ?? 0),
            'total_users'          => (int) ($userCounts->total ?? 0),
            'total_revenue'        => round($totalRevenue, 2),
            'daily_income'         => round($dailyIncome, 2),
            'weekly_income'        => round($weeklyIncome, 2),
            'monthly_income'       => round($monthlyIncome, 2),
            'yearly_income'        => round($yearlyIncome, 2),
            'monthly_revenue'      => round($monthlyIncome, 2),
            'data_usage'           => $dataUsageGb,
            'data_usage_upload'    => $dataUploadGb,
            'data_usage_download'  => $dataDownloadGb,
            'monthly_data_usage'   => 0,
            'today_data_usage'     => 0,
            'retention_rate'       => $retentionRate,
            'sms_balance'          => $smsBalance,
            'last_month_users'     => $lastMonthUsers,
            'retained_users'       => $retainedUsers,

            // ── Trend data ─────────────────────────────────────────────────────
            'weekly_users_trend'   => $weeklyUsersTrend,
            'weekly_revenue_trend' => $weeklyRevenueTrend,
            'recent_activities'    => [],
            'online_users'         => [],

            // ── Payment details widget ─────────────────────────────────────────
            'payment_details' => [
                'daily' => [
                    'amount' => round($dailyIncome, 2),
                    'date'   => $now->format('F d, Y'),
                    'count'  => (int) ($revenueCounts->daily_count ?? 0),
                ],
                'weekly' => [
                    'amount'         => round($weeklyIncome, 2),
                    'startDate'      => $now->copy()->subDays(6)->format('M d'),
                    'endDate'        => $now->format('M d'),
                    'count'          => (int) ($revenueCounts->weekly_count ?? 0),
                    'dailyBreakdown' => $weeklyDailyBreakdown,
                ],
                'monthly' => [
                    'amount'          => round($monthlyIncome, 2),
                    'month'           => $now->format('F'),
                    'year'            => $now->format('Y'),
                    'count'           => (int) ($revenueCounts->monthly_count ?? 0),
                    'weeklyBreakdown' => [],
                ],
                'yearly' => [
                    'amount'           => round($yearlyIncome, 2),
                    'year'             => $now->format('Y'),
                    'count'            => (int) ($revenueCounts->yearly_count ?? 0),
                    'monthlyBreakdown' => [],
                ],
            ],

            // ── SMS expenses widget ────────────────────────────────────────────
            'sms_expenses' => [
                'sms' => [
                    'totalPurchased' => $smsTotalPurchased,
                    'used'           => $smsUsed,
                    'remaining'      => $smsBalance,
                    'dailyUsage'     => 0,
                    'weeklyUsage'    => 0,
                    'monthlyUsage'   => 0,
                    'usageTrend'     => [],
                    'recentPurchases'=> [],
                ],
                'costs' => [
                    'totalSpent'        => 0,
                    'thisMonth'         => 0,
                    'lastMonth'         => 0,
                    'averageCostPerSMS' => 0,
                ],
            ],

            // ── Business analytics widget ──────────────────────────────────────
            'business_analytics' => [
                'retention' => [
                    'rate'           => $retentionRate,
                    'lastMonthUsers' => $lastMonthUsers,
                    'retainedUsers'  => $retainedUsers,
                ],
                'accessPoints'   => [],
                'revenueTrend'   => $revenueTrendData,
                'revenueAverage' => $revenueAverage,
                'revenuePeak'    => (float) ($revenueCounts->peak_30d ?? 0),
                'revenueGrowth'  => $revenueGrowth,
                'userTrend'      => $userTrendData,
                'userAverage'    => $userAverage,
                'userPeak'       => $maxUsr === 1 ? 0 : $maxUsr,
                'userGrowth'     => $userGrowth,
            ],

            'last_updated' => $now->toIso8601String(),
        ];

        if ($cacheKey) {
            try {
                Cache::put($cacheKey, $stats, self::CACHE_TTL_SECONDS);
            } catch (\Exception $e) {
                // Redis unavailable — stats are still returned, just not cached
            }
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
            return $this->tenantUsersQuery($tenantId)
                ->select('id', 'name', 'email', 'username', 'role', 'is_active', 'created_at')
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
     * The landlord/public users table has no deleted_at column.
     */
    private function tenantUsersQuery(string $tenantId)
    {
        return DB::table('users')->where('tenant_id', $tenantId);
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
