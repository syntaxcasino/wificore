<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateDashboardStatsJob;
use App\Jobs\UpdateTenantHealthScoreJob;
use App\Models\HealthScoreSnapshot;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\UserSession;
use App\Services\RevenueAssuranceEngine;
use App\Models\UserSubscription;
use App\Models\PppoeUser;
use App\Models\HotspotUser;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TenantDashboardController extends Controller
{
    private const CACHE_TTL_SECONDS = 30;
    private const CACHE_LOCK_SECONDS = 10;
    private const LIST_CACHE_TTL_SECONDS = 5; // Short TTL for list endpoints
    private const REFRESH_DISPATCH_LOCK_SECONDS = 15;
    private const VERSION_SUFFIX = ':version';

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

        $cacheKey = self::versionedDashboardCacheKey((string) $tenantId);
        $lockKey  = "tenant_dashboard_lock:{$tenantId}:v" . self::getVersion(self::dashboardVersionKey((string) $tenantId));

        // Fast path: return cached data immediately if available.
        // Wrapped in try/catch so a Redis outage degrades gracefully to a DB query.
        try {
            $cached = $this->cacheGet($cacheKey);
            if ($cached !== null) {
                return response()->json(['success' => true, 'data' => $cached, 'cached' => true]);
            }

            $precomputed = $this->cacheGet($this->getPrecomputedCacheKey($tenantId));
            if (is_array($precomputed) && !empty($precomputed)) {
                $this->cachePut($cacheKey, $precomputed, self::CACHE_TTL_SECONDS);
                $this->dispatchDashboardRefresh($tenantId);

                return response()->json([
                    'success' => true,
                    'data' => $precomputed,
                    'cached' => true,
                    'precomputed' => true,
                ]);
            }

            // Cache stampede protection: only one process regenerates
            $lock = $this->cacheLock($lockKey, self::CACHE_LOCK_SECONDS);
            $stats = $lock
                ? $lock->get(function () use ($tenantId, $cacheKey) {
                    $cached = $this->cacheGet($cacheKey);
                    if ($cached !== null) {
                        return $cached;
                    }
                    return $this->computeDashboardStats($tenantId, $cacheKey);
                })
                : null;

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

    private function dispatchDashboardRefresh(string $tenantId): void
    {
        $lockKey = "dashboard:request-refresh-lock:{$tenantId}";
        if ($this->cacheAdd($lockKey, 1, self::REFRESH_DISPATCH_LOCK_SECONDS)) {
            UpdateDashboardStatsJob::dispatch($tenantId)->onQueue('dashboard');
        }
    }

    private function getPrecomputedCacheKey(string $tenantId): string
    {
        return self::versionedPrecomputedDashboardCacheKey($tenantId);
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
        $hasUserSessions = $this->tenantTableExists('user_sessions');
        $hasPayments = $this->tenantTableExists('payments');
        $hasRouters = $this->tenantTableExists('routers');

        // Q1: user counts (public schema, filter by tenant_id)
        // NOTE: Tenant schema users table doesn't have soft deletes (no deleted_at column)
        $userCounts = $this->tenantUsersQuery($tenantId)
            ->selectRaw('COUNT(*) as total')
            ->first();

        // Q2: session counts + hotspot/pppoe split (tenant schema)
        $sessionCounts = $hasUserSessions
            ? UserSession::query()
                ->selectRaw("
                    SUM(CASE WHEN status='active' AND (end_time IS NULL OR end_time > NOW()) THEN 1 ELSE 0 END) as active_total,
                    SUM(CASE WHEN status='active' AND (end_time IS NULL OR end_time > NOW()) AND voucher IS NOT NULL THEN 1 ELSE 0 END) as hotspot,
                    SUM(CASE WHEN status='active' AND (end_time IS NULL OR end_time > NOW()) AND voucher IS NULL THEN 1 ELSE 0 END) as pppoe,
                    COALESCE(SUM(data_used), 0) as data_used_bytes,
                    COALESCE(SUM(data_upload), 0) as data_upload_bytes,
                    COALESCE(SUM(data_download), 0) as data_download_bytes
                ")
                ->first()
            : (object) [
                'active_total' => 0,
                'hotspot' => 0,
                'pppoe' => 0,
                'data_used_bytes' => 0,
                'data_upload_bytes' => 0,
                'data_download_bytes' => 0,
            ];

        // Q3: all revenue aggregations in one pass
        $revenueCounts = $hasPayments
            ? Payment::query()
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
                ->first()
            : (object) [
                'total' => 0,
                'daily' => 0,
                'weekly' => 0,
                'monthly' => 0,
                'yearly' => 0,
                'daily_count' => 0,
                'weekly_count' => 0,
                'monthly_count' => 0,
                'yearly_count' => 0,
                'avg_30d' => 0,
                'peak_30d' => 0,
                'current_month_users' => 0,
                'last_month_users' => 0,
            ];

        // Q4: router counts
        $routerCounts = $hasRouters
            ? Router::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) as online,
                    SUM(CASE WHEN status='offline' THEN 1 ELSE 0 END) as offline,
                    SUM(CASE WHEN status='provisioning' THEN 1 ELSE 0 END) as provisioning
                ")
                ->first()
            : (object) [
                'total' => 0,
                'online' => 0,
                'offline' => 0,
                'provisioning' => 0,
            ];

        // Q5: 7-day daily trend (sessions + payments) in batched selects
        $trendRows = $hasUserSessions && $hasPayments
            ? DB::select("
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
            ")
            : ($hasPayments
                ? DB::select("
                    SELECT
                        g.day::date AS date,
                        0 AS sessions,
                        COALESCE(p.amt, 0) AS revenue
                    FROM generate_series(
                        (NOW() - INTERVAL '6 days')::date,
                        NOW()::date,
                        INTERVAL '1 day'
                    ) AS g(day)
                    LEFT JOIN (
                        SELECT DATE(created_at) AS d, SUM(amount) AS amt
                        FROM payments
                        WHERE status = 'completed' AND created_at >= NOW() - INTERVAL '6 days'
                        GROUP BY DATE(created_at)
                    ) p ON p.d = g.day::date
                    ORDER BY g.day ASC
                ")
                : $this->emptyTrendRows());

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
        $retainedUsers     = 0;
        $retentionRate     = $lastMonthUsers > 0 ? round(($currentMonthUsers / $lastMonthUsers) * 100, 2) : 0;

        $revenueAssurance = $this->buildRevenueAssuranceReport($tenantId, $hasPayments, $hasUserSessions, $hasRouters);

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

        $smsBalance        = (int) $this->cacheGet('sms_balance', 0);
        $smsTotalPurchased = (int) $this->cacheGet('sms_total_purchased', 0);
        $smsUsed           = $smsTotalPurchased - $smsBalance;

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
            'weekly_users_trend'   => $weeklyUsersTrend,
            'weekly_revenue_trend' => $weeklyRevenueTrend,
            'recent_activities'    => [],
            'online_users'         => [],
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
                'businessKpis'   => $revenueAssurance['kpis'] ?? [],
            ],
            'revenue_assurance' => $revenueAssurance,
            'business_kpis' => $revenueAssurance['kpis'] ?? [],
            'last_updated' => $now->toIso8601String(),
        ];

        if ($cacheKey) {
            try {
                $this->cachePut($cacheKey, $stats, self::CACHE_TTL_SECONDS);
                $this->cachePut($this->getPrecomputedCacheKey($tenantId), $stats, self::CACHE_TTL_SECONDS);
            } catch (\Exception $e) {
                // Redis unavailable — stats are still returned, just not cached
            }
        }

        return $stats;
    }

    private function buildRevenueAssuranceReport(string $tenantId, bool $hasPayments, bool $hasUserSessions, bool $hasRouters): array
    {
        $now = now();
        $signals = [
            'payments' => [
                'monthly_completed_amount' => 0.0,
                'monthly_completed_count' => 0,
                'daily_completed_amount' => 0.0,
                'completed_today' => 0,
                'failed_today' => 0,
                'callback_mismatch' => 0,
                'missing_accounting' => 0,
                'pending_overdue' => 0,
                'callback_mismatch_examples' => [],
                'missing_accounting_examples' => [],
                'pending_overdue_examples' => [],
            ],
            'subscriptions' => [
                'active_count' => 0,
                'expired_count' => 0,
            ],
            'pppoe' => [
                'active_count' => 0,
                'active_not_billed' => 0,
                'duplicate_usernames' => 0,
                'expired_online' => 0,
                'active_not_billed_users' => [],
                'duplicate_username_examples' => [],
                'expired_online_examples' => [],
            ],
            'hotspot' => [
                'active_count' => 0,
                'active_not_billed' => 0,
                'duplicate_usernames' => 0,
                'expired_online' => 0,
                'active_not_billed_users' => [],
                'duplicate_username_examples' => [],
                'expired_online_examples' => [],
            ],
            'sessions' => [
                'unmatched_active' => 0,
                'unmatched_examples' => [],
            ],
            'revenue_by_area' => [],
        ];

        if ($hasPayments) {
            $signals['payments']['monthly_completed_amount'] = (float) (Payment::query()
                ->where('status', 'completed')
                ->where('created_at', '>=', $now->copy()->startOfMonth())
                ->sum('amount'));
            $signals['payments']['monthly_completed_count'] = (int) Payment::query()
                ->where('status', 'completed')
                ->where('created_at', '>=', $now->copy()->startOfMonth())
                ->count();
            $signals['payments']['daily_completed_amount'] = (float) Payment::query()
                ->where('status', 'completed')
                ->whereDate('created_at', $now->toDateString())
                ->sum('amount');
            $signals['payments']['completed_today'] = (int) Payment::query()
                ->where('status', 'completed')
                ->whereDate('created_at', $now->toDateString())
                ->count();
            $signals['payments']['failed_today'] = (int) Payment::query()
                ->where('status', 'failed')
                ->whereDate('created_at', $now->toDateString())
                ->count();
            $signals['payments']['callback_mismatch'] = (int) Payment::query()
                ->where('status', 'completed')
                ->where(function ($query) {
                    $query->whereNull('callback_response')
                        ->orWhereNull('mpesa_receipt')
                        ->orWhere('mpesa_receipt', '');
                })
                ->count();
            $signals['payments']['missing_accounting'] = (int) Payment::query()
                ->where('status', 'completed')
                ->whereDoesntHave('subscription')
                ->count();
            $signals['payments']['pending_overdue'] = (int) Payment::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $now->copy()->subHours(24))
                ->count();
            $signals['payments']['callback_mismatch_examples'] = Payment::query()
                ->where('status', 'completed')
                ->where(function ($query) {
                    $query->whereNull('callback_response')
                        ->orWhereNull('mpesa_receipt')
                        ->orWhere('mpesa_receipt', '');
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->pluck('transaction_id')
                ->filter()
                ->values()
                ->all();
            $signals['payments']['missing_accounting_examples'] = Payment::query()
                ->where('status', 'completed')
                ->whereDoesntHave('subscription')
                ->orderByDesc('created_at')
                ->limit(10)
                ->pluck('transaction_id')
                ->filter()
                ->values()
                ->all();
            $signals['payments']['pending_overdue_examples'] = Payment::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $now->copy()->subHours(24))
                ->orderBy('created_at')
                ->limit(10)
                ->pluck('transaction_id')
                ->filter()
                ->values()
                ->all();

            if ($hasRouters) {
                $signals['revenue_by_area'] = Payment::query()
                    ->leftJoin('routers', 'payments.router_id', '=', 'routers.id')
                    ->where('payments.status', 'completed')
                    ->selectRaw("COALESCE(routers.location, 'Unspecified') as label, COALESCE(SUM(payments.amount), 0) as amount, COUNT(*) as count")
                    ->groupByRaw("COALESCE(routers.location, 'Unspecified')")
                    ->orderByDesc('amount')
                    ->limit(5)
                    ->get()
                    ->map(fn ($row) => ['label' => $row->label, 'amount' => (float) $row->amount, 'count' => (int) $row->count])
                    ->all();
            }
        }

        if (DB::connection()->getSchemaBuilder()->hasTable('user_subscriptions')) {
            $signals['subscriptions']['active_count'] = UserSubscription::query()->where('status', 'active')->count();
            $signals['subscriptions']['expired_count'] = UserSubscription::query()->where('status', 'expired')->count();
        }

        if (DB::connection()->getSchemaBuilder()->hasTable('pppoe_users')) {
            $signals['pppoe']['active_count'] = PppoeUser::query()->where('status', 'active')->count();
            $signals['pppoe']['active_not_billed'] = PppoeUser::query()->where('status', 'active')->where('payment_status', '!=', 'paid')->count();
            $signals['pppoe']['expired_online'] = PppoeUser::query()->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', $now)->count();
            $signals['pppoe']['duplicate_usernames'] = DB::table('pppoe_users')->select('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->get()->count();
            $signals['pppoe']['active_not_billed_users'] = PppoeUser::query()->where('status', 'active')->where('payment_status', '!=', 'paid')->orderByDesc('updated_at')->limit(10)->pluck('username')->filter()->values()->all();
            $signals['pppoe']['duplicate_username_examples'] = DB::table('pppoe_users')->select('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->orderBy('username')->limit(10)->pluck('username')->filter()->values()->all();
            $signals['pppoe']['expired_online_examples'] = PppoeUser::query()->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', $now)->orderBy('expires_at')->limit(10)->pluck('username')->filter()->values()->all();
        }

        if (DB::connection()->getSchemaBuilder()->hasTable('hotspot_users')) {
            $signals['hotspot']['active_count'] = HotspotUser::query()->where('status', 'active')->count();
            $signals['hotspot']['active_not_billed'] = HotspotUser::query()->where('status', 'active')->where('has_active_subscription', false)->count();
            $signals['hotspot']['expired_online'] = HotspotUser::query()->where('status', 'active')->whereNotNull('subscription_expires_at')->where('subscription_expires_at', '<', $now)->count();
            $signals['hotspot']['duplicate_usernames'] = DB::table('hotspot_users')->select('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->get()->count();
            $signals['hotspot']['active_not_billed_users'] = HotspotUser::query()->where('status', 'active')->where('has_active_subscription', false)->orderByDesc('updated_at')->limit(10)->pluck('username')->filter()->values()->all();
            $signals['hotspot']['duplicate_username_examples'] = DB::table('hotspot_users')->select('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->orderBy('username')->limit(10)->pluck('username')->filter()->values()->all();
            $signals['hotspot']['expired_online_examples'] = HotspotUser::query()->where('status', 'active')->whereNotNull('subscription_expires_at')->where('subscription_expires_at', '<', $now)->orderBy('subscription_expires_at')->limit(10)->pluck('username')->filter()->values()->all();
        }

        if ($hasUserSessions) {
            $signals['sessions']['unmatched_active'] = (int) UserSession::query()->where('status', 'active')->whereNull('payment_id')->count();
            $signals['sessions']['unmatched_examples'] = UserSession::query()->where('status', 'active')->whereNull('payment_id')->orderByDesc('start_time')->limit(10)->pluck('voucher')->filter()->values()->all();
        }

        return app(RevenueAssuranceEngine::class)->evaluate($signals, $now);
    }

    private function tenantTableExists(string $tableName): bool
    {
        $result = DB::selectOne(
            "SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = current_schema()
                  AND table_name = ?
            ) AS exists",
            [$tableName]
        );

        return (bool) ($result->exists ?? false);
    }

    public function healthScore(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any tenant',
            ], 403);
        }

        $cacheKey = 'tenant_health_score_latest:' . (string) $tenantId;
        $cached = $this->cacheGet($cacheKey);

        if (is_array($cached) && ! empty($cached)) {
            return response()->json([
                'success' => true,
                'data' => $cached,
                'cached' => true,
            ]);
        }

        $latest = HealthScoreSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('calculated_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $latest) {
            UpdateTenantHealthScoreJob::dispatch((string) $tenantId, [
                'source_event' => 'health_score_request',
                'source_reference' => 'dashboard',
            ])->onQueue('dashboard');

            return response()->json([
                'success' => true,
                'data' => [
                    'score' => 100,
                    'grade' => 'healthy',
                    'summary' => 'Health score is being calculated.',
                    'factors' => [],
                    'signals' => [],
                    'history' => [],
                    'calculated_at' => now()->toIso8601String(),
                ],
                'cached' => false,
                'refreshing' => true,
            ]);
        }

        if ($latest->calculated_at && $latest->calculated_at->lt(now()->subMinutes(5))) {
            UpdateTenantHealthScoreJob::dispatch((string) $tenantId, [
                'source_event' => 'health_score_refresh',
                'source_reference' => (string) $latest->id,
            ])->onQueue('dashboard');
        }

        $history = HealthScoreSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->select('score', 'grade', 'calculated_at', 'source_event')
            ->orderByDesc('calculated_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (HealthScoreSnapshot $snapshot) => [
                'score' => $snapshot->score,
                'grade' => $snapshot->grade,
                'calculated_at' => optional($snapshot->calculated_at)->toIso8601String(),
                'source_event' => $snapshot->source_event,
            ])
            ->values();

        $data = [
            'score' => $latest->score,
            'grade' => $latest->grade,
            'summary' => $this->healthScoreSummary($latest),
            'factors' => $latest->factors ?? [],
            'signals' => $latest->signals ?? [],
            'calculated_at' => optional($latest->calculated_at)->toIso8601String(),
            'source_event' => $latest->source_event,
            'source_reference' => $latest->source_reference,
            'history' => $history,
        ];

        try {
            $this->cachePut($cacheKey, $data, self::CACHE_TTL_SECONDS);
        } catch (\Exception $e) {
            // Cache is optional; return fresh DB data regardless.
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'cached' => false,
        ]);
    }

    private function healthScoreSummary(HealthScoreSnapshot $snapshot): string
    {
        $summary = $snapshot->factors ?? [];
        $contributors = collect($summary)
            ->filter(fn (array $factor) => (float) ($factor['penalty'] ?? 0) > 0)
            ->sortByDesc('penalty')
            ->take(3)
            ->pluck('label')
            ->all();

        if ($contributors === []) {
            return 'No significant health degradations detected.';
        }

        return 'Top negative contributors: ' . implode(', ', $contributors);
    }

    private function emptyTrendRows(): array
    {
        $rows = [];

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $rows[] = (object) [
                'date' => now()->subDays($daysAgo)->toDateString(),
                'sessions' => 0,
                'revenue' => 0,
            ];
        }

        return $rows;
    }

    private function emptyPaginator(Request $request, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: $perPage,
            currentPage: (int) ($request->page ?? 1),
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
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

        $users = $this->cacheRemember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($tenantId, $perPage) {
            return $this->tenantUsersQuery($tenantId)
                ->select('id', 'name', 'email', 'username', 'role', 'is_active', 'created_at')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'data' => $users,
            'cached' => $this->cacheHas($cacheKey),
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

        $packages = $this->cacheRemember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($activeOnly) {
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
            'cached' => $this->cacheHas($cacheKey),
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

        if (! $this->tenantTableExists('routers')) {
            return response()->json([
                'success' => true,
                'data' => [],
                'cached' => false,
                'schema_incomplete' => true,
            ]);
        }

        $routers = $this->cacheRemember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () {
            return Router::select('id', 'name', 'ip_address', 'model', 'status', 'last_seen', 'location')
                ->orderByRaw("CASE WHEN status='online' THEN 0 ELSE 1 END")
                ->orderBy('last_seen', 'desc')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $routers,
            'cached' => $this->cacheHas($cacheKey),
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

        $perPage = $request->per_page ?? 15;

        if (! $this->tenantTableExists('payments')) {
            return response()->json([
                'success' => true,
                'data' => $this->emptyPaginator($request, $perPage),
                'cached' => false,
                'schema_incomplete' => true,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
        }

        $payments = $this->fetchPaymentsQuery($request, $perPage);

        return response()->json([
            'success' => true,
            'data' => $payments,
            'cached' => false,
        ])
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
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

        $cacheKey = $this->getListCacheKey(self::KEY_SESSIONS, $tenantId, $request);
        $perPage = $request->per_page ?? 15;

        if (! $this->tenantTableExists('user_sessions')) {
            return response()->json([
                'success' => true,
                'data' => $this->emptyPaginator($request, $perPage),
                'cached' => false,
                'schema_incomplete' => true,
            ]);
        }

        $sessions = $this->cacheRemember($cacheKey, 3, function () use ($perPage) {
            return UserSession::with(['user:id,name,username', 'router:id,name'])
                ->where('status', 'active')
                ->select('id', 'user_id', 'router_id', 'ip_address', 'mac_address', 'upload_bytes', 'download_bytes', 'started_at')
                ->orderBy('started_at', 'desc')
                ->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'data' => $sessions,
            'cached' => $this->cacheHas($cacheKey),
        ]);
    }

    /**
     * Generate cache key for list endpoints
     */
    private function getListCacheKey(string $type, string $tenantId, Request $request): string
    {
        $page = $request->page ?? 1;
        $perPage = $request->per_page ?? 15;
        $version = self::getVersion(self::entityVersionKey($tenantId, $type));

        return "{$type}:{$tenantId}:v{$version}:p{$page}:pp{$perPage}";
    }

    private function cacheGet(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $default;
        }
    }

    private function cachePut(string $key, mixed $value, mixed $ttl = null): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache put failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function cacheHas(string $key): bool
    {
        try {
            return Cache::has($key);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache has failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function cacheRemember(string $key, mixed $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache remember failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $callback();
        }
    }

    private function cacheAdd(string $key, mixed $value, mixed $ttl = null): bool
    {
        try {
            return Cache::add($key, $value, $ttl);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache add failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function cacheLock(string $key, int $seconds): mixed
    {
        try {
            return Cache::lock($key, $seconds);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache lock failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
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
        self::bumpVersion(self::dashboardVersionKey($tenantId));
    }

    /**
     * Clear all tenant list caches (users, packages, routers, payments)
     * Call this when any list data changes
     */
    public static function bustAllListCaches(string $tenantId): void
    {
        foreach ([self::KEY_USERS, self::KEY_PACKAGES, self::KEY_ROUTERS, self::KEY_PAYMENTS, self::KEY_SESSIONS] as $entityKey) {
            self::bumpVersion(self::entityVersionKey($tenantId, $entityKey));
        }

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

        if ($entityType === 'dashboard') {
            self::bustDashboardCache($tenantId);
            return;
        }

        if (!isset($keyMap[$entityType])) {
            return;
        }

        self::bumpVersion(self::entityVersionKey($tenantId, $keyMap[$entityType]));
    }

    public static function versionedPrecomputedDashboardCacheKey(string $tenantId): string
    {
        $version = self::getVersion(self::dashboardVersionKey($tenantId));

        return "dashboard_stats_{$tenantId}:v{$version}";
    }

    public static function versionedDashboardCacheKey(string $tenantId): string
    {
        $version = self::getVersion(self::dashboardVersionKey($tenantId));

        return "tenant_dashboard_v2:{$tenantId}:v{$version}";
    }

    private static function entityVersionKey(string $tenantId, string $entityKey): string
    {
        return "tenant_cache_version:{$entityKey}:{$tenantId}";
    }

    private static function dashboardVersionKey(string $tenantId): string
    {
        return "tenant_cache_version:dashboard:{$tenantId}";
    }

    private static function getVersion(string $versionKey): int
    {
        try {
            return (int) Cache::rememberForever($versionKey, static fn (): int => 1);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache rememberForever failed', ['key' => $versionKey, 'error' => $e->getMessage()]);
            return 1;
        }
    }

    private static function bumpVersion(string $versionKey): int
    {
        $nextVersion = self::getVersion($versionKey) + 1;

        try {
            Cache::forever($versionKey, $nextVersion);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TenantDashboardController cache forever failed', ['key' => $versionKey, 'error' => $e->getMessage()]);
        }

        return $nextVersion;
    }

    /**
     * Get cache TTL for external reference
     */
    public static function getCacheTtl(): int
    {
        return self::CACHE_TTL_SECONDS;
    }
}
