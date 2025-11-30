<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Router;
use App\Models\User;
use App\Models\Payment;
use App\Models\UserSession;
use App\Models\Package;
use App\Events\DashboardStatsUpdated;
use App\Services\MetricsService;
use Carbon\Carbon;

class UpdateDashboardStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The tenant ID for this job
     */
    public $tenantId;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('dashboard');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \Log::info('UpdateDashboardStatsJob started', ['tenant_id' => $this->tenantId]);
        
        try {
            // Total routers (tenant-scoped)
            $routerQuery = Router::query();
            if ($this->tenantId) {
                $routerQuery->where('tenant_id', $this->tenantId);
            }
            $totalRouters = $routerQuery->count();
            $onlineRouters = $routerQuery->where('status', 'online')->count();
            $offlineRouters = $routerQuery->where('status', 'offline')->count();
            $provisioningRouters = $routerQuery->where('status', 'provisioning')->count();

            // Fetch user statistics - Active sessions
            $activeSessions = UserSession::where('status', 'active')
                ->where(function($query) {
                    $query->whereNull('end_time')
                          ->orWhere('end_time', '>', now());
                })
                ->count();

            // Count hotspot users (sessions with vouchers)
            $hotspotUsers = UserSession::where('status', 'active')
                ->where(function($query) {
                    $query->whereNull('end_time')
                          ->orWhere('end_time', '>', now());
                })
                ->whereNotNull('voucher')
                ->count();

            // Count PPPoE users (sessions without vouchers or from PPPoE packages)
            $pppoeUsers = UserSession::where('status', 'active')
                ->where(function($query) {
                    $query->whereNull('end_time')
                          ->orWhere('end_time', '>', now());
                })
                ->whereNull('voucher')
                ->count();

            // Total unique users
            $totalUsers = User::count();

            // Fetch revenue statistics
            $totalRevenue = Payment::where('status', 'completed')->sum('amount') ?? 0;
            
            // Daily income with details
            $dailyIncome = Payment::where('status', 'completed')
                ->whereDate('created_at', now()->toDateString())
                ->sum('amount') ?? 0;
            
            $dailyPaymentCount = Payment::where('status', 'completed')
                ->whereDate('created_at', now()->toDateString())
                ->count();
            
            // Weekly income (last 7 days) with daily breakdown
            $weekStartDate = now()->subDays(6)->startOfDay();
            $weekEndDate = now()->endOfDay();
            
            $weeklyIncome = Payment::where('status', 'completed')
                ->whereBetween('created_at', [$weekStartDate, $weekEndDate])
                ->sum('amount') ?? 0;
            
            $weeklyPaymentCount = Payment::where('status', 'completed')
                ->whereBetween('created_at', [$weekStartDate, $weekEndDate])
                ->count();
            
            // Weekly daily breakdown
            $weeklyDailyBreakdown = [];
            $maxWeeklyAmount = 0;
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $amount = Payment::where('status', 'completed')
                    ->whereDate('created_at', $date->toDateString())
                    ->sum('amount');
                $maxWeeklyAmount = max($maxWeeklyAmount, $amount);
                $weeklyDailyBreakdown[] = [
                    'date' => $date->format('Y-m-d'),
                    'day' => $date->format('D'),
                    'amount' => $amount,
                ];
            }
            
            // Calculate percentages for weekly breakdown
            foreach ($weeklyDailyBreakdown as &$day) {
                $day['percentage'] = $maxWeeklyAmount > 0 ? round(($day['amount'] / $maxWeeklyAmount) * 100, 2) : 0;
            }
            
            // Monthly income with weekly breakdown
            $monthlyIncome = Payment::where('status', 'completed')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount') ?? 0;
            
            $monthlyPaymentCount = Payment::where('status', 'completed')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();
            
            // Monthly weekly breakdown
            $monthlyWeeklyBreakdown = [];
            $maxMonthlyWeekAmount = 0;
            $weekNumber = 1;
            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();
            
            for ($weekStart = $monthStart->copy(); $weekStart->lte($monthEnd); $weekStart->addWeek()) {
                $weekEnd = $weekStart->copy()->addDays(6);
                if ($weekEnd->gt($monthEnd)) {
                    $weekEnd = $monthEnd->copy();
                }
                
                $weekAmount = Payment::where('status', 'completed')
                    ->whereBetween('created_at', [$weekStart, $weekEnd])
                    ->sum('amount');
                
                $maxMonthlyWeekAmount = max($maxMonthlyWeekAmount, $weekAmount);
                
                $monthlyWeeklyBreakdown[] = [
                    'week' => $weekNumber,
                    'startDate' => $weekStart->format('M d'),
                    'endDate' => $weekEnd->format('M d'),
                    'amount' => $weekAmount,
                ];
                
                $weekNumber++;
            }
            
            // Calculate percentages for monthly weekly breakdown
            foreach ($monthlyWeeklyBreakdown as &$week) {
                $week['percentage'] = $maxMonthlyWeekAmount > 0 ? round(($week['amount'] / $maxMonthlyWeekAmount) * 100, 2) : 0;
            }
            
            // Yearly income with monthly breakdown
            $yearlyIncome = Payment::where('status', 'completed')
                ->whereYear('created_at', now()->year)
                ->sum('amount') ?? 0;
            
            $yearlyPaymentCount = Payment::where('status', 'completed')
                ->whereYear('created_at', now()->year)
                ->count();
            
            // Yearly monthly breakdown
            $yearlyMonthlyBreakdown = [];
            $maxYearlyMonthAmount = 0;
            for ($month = 1; $month <= 12; $month++) {
                $monthAmount = Payment::where('status', 'completed')
                    ->whereMonth('created_at', $month)
                    ->whereYear('created_at', now()->year)
                    ->sum('amount');
                
                $maxYearlyMonthAmount = max($maxYearlyMonthAmount, $monthAmount);
                
                $yearlyMonthlyBreakdown[] = [
                    'month' => $month,
                    'monthName' => Carbon::create(null, $month)->format('M'),
                    'amount' => $monthAmount,
                ];
            }
            
            // Calculate percentages for yearly monthly breakdown
            foreach ($yearlyMonthlyBreakdown as &$month) {
                $month['percentage'] = $maxYearlyMonthAmount > 0 ? round(($month['amount'] / $maxYearlyMonthAmount) * 100, 2) : 0;
            }

            // Calculate data usage from user_sessions (tenant-scoped)
            $sessionQuery = UserSession::query();
            if ($this->tenantId) {
                $sessionQuery->where('tenant_id', $this->tenantId);
            }
            
            // Total data usage in GB (from user_sessions table)
            $totalDataUsage = $sessionQuery->sum('data_used') / (1024 * 1024 * 1024);
            
            // Data usage breakdown
            $totalDataUpload = $sessionQuery->sum('data_upload') / (1024 * 1024 * 1024);
            $totalDataDownload = $sessionQuery->sum('data_download') / (1024 * 1024 * 1024);
            
            // Active sessions data usage (current month)
            $monthlyDataUsage = UserSession::where('status', 'active')
                ->when($this->tenantId, function($q) {
                    $q->where('tenant_id', $this->tenantId);
                })
                ->whereMonth('start_time', now()->month)
                ->whereYear('start_time', now()->year)
                ->sum('data_used') / (1024 * 1024 * 1024);
            
            // Today's data usage
            $todayDataUsage = UserSession::when($this->tenantId, function($q) {
                    $q->where('tenant_id', $this->tenantId);
                })
                ->whereDate('start_time', now()->toDateString())
                ->sum('data_used') / (1024 * 1024 * 1024);

            // Get weekly active sessions trend (last 7 days)
            $weeklyUsersTrend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $count = UserSession::whereDate('start_time', $date->toDateString())
                    ->count();
                $weeklyUsersTrend[] = [
                    'date' => $date->format('D'),
                    'count' => $count,
                ];
            }

            // Get weekly revenue trend (last 7 days)
            $weeklyRevenueTrend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $amount = Payment::where('status', 'completed')
                    ->whereDate('created_at', $date->toDateString())
                    ->sum('amount');
                $weeklyRevenueTrend[] = [
                    'date' => $date->format('D'),
                    'amount' => $amount,
                ];
            }

            // Get recent activities (last 10 router updates)
            $recentActivities = Router::select('id', 'name', 'status', 'updated_at')
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($router) {
                    return [
                        'id' => $router->id,
                        'message' => "Router {$router->name} status changed to {$router->status}",
                        'timestamp' => $router->updated_at->diffForHumans(),
                    ];
                });

            // Calculate customer retention rate
            // Users who made a purchase this month
            $currentMonthUsers = Payment::where('status', 'completed')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->distinct('user_id')
                ->count('user_id');
            
            // Users who made a purchase last month
            $lastMonthUsers = Payment::where('status', 'completed')
                ->whereMonth('created_at', now()->subMonth()->month)
                ->whereYear('created_at', now()->subMonth()->year)
                ->distinct('user_id')
                ->count('user_id');
            
            // Users who purchased both months (retained)
            $retainedUsers = Payment::where('status', 'completed')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->whereIn('user_id', function($query) {
                    $query->select('user_id')
                        ->from('payments')
                        ->where('status', 'completed')
                        ->whereMonth('created_at', now()->subMonth()->month)
                        ->whereYear('created_at', now()->subMonth()->year);
                })
                ->distinct('user_id')
                ->count('user_id');
            
            $retentionRate = $lastMonthUsers > 0 ? round(($retainedUsers / $lastMonthUsers) * 100, 2) : 0;
            
            // SMS Balance and expenses tracking
            $smsBalance = \Cache::get('sms_balance', 0);
            $smsTotalPurchased = \Cache::get('sms_total_purchased', 0);
            $smsUsed = $smsTotalPurchased - $smsBalance;
            
            // SMS usage statistics
            $smsDailyUsage = \Cache::get('sms_daily_usage', 0);
            $smsWeeklyUsage = \Cache::get('sms_weekly_usage', 0);
            $smsMonthlyUsage = \Cache::get('sms_monthly_usage', 0);
            
            // SMS usage trend (last 7 days)
            $smsUsageTrend = [];
            $maxSmsUsage = 1;
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $usage = \Cache::get('sms_usage_' . $date->format('Y-m-d'), 0);
                $maxSmsUsage = max($maxSmsUsage, $usage);
                $smsUsageTrend[] = [
                    'date' => $date->format('Y-m-d'),
                    'day' => $date->format('D'),
                    'count' => $usage,
                ];
            }
            
            // Calculate percentages for SMS usage trend
            foreach ($smsUsageTrend as &$day) {
                $day['percentage'] = round(($day['count'] / $maxSmsUsage) * 100, 2);
            }
            
            // SMS cost analysis
            $smsTotalCost = \Cache::get('sms_total_cost', 0);
            $smsThisMonthCost = \Cache::get('sms_this_month_cost', 0);
            $smsLastMonthCost = \Cache::get('sms_last_month_cost', 0);
            $smsAverageCost = $smsTotalPurchased > 0 ? round($smsTotalCost / $smsTotalPurchased, 2) : 0;
            
            // SMS recent purchases (placeholder - would come from SMS purchase records)
            $smsRecentPurchases = \Cache::get('sms_recent_purchases', []);

            // Business Analytics - Active users per access point
            try {
                $accessPointsData = Router::where('status', 'online')
                    ->get()
                    ->map(function ($router) {
                        // Count active sessions via payments linked to this router
                        $activeUsers = Payment::where('router_id', $router->id)
                            ->where('status', 'completed')
                            ->whereHas('userSession', function ($query) {
                                $query->where('status', 'active')
                                      ->where(function($q) {
                                          $q->whereNull('end_time')
                                            ->orWhere('end_time', '>', now());
                                      });
                            })
                            ->count();
                        
                        return [
                            'id' => $router->id,
                            'name' => $router->name,
                            'location' => $router->location ?? 'Unknown',
                            'activeUsers' => $activeUsers,
                        ];
                    });
                
                $maxAccessPointUsers = $accessPointsData->max('activeUsers') ?: 1;
                $accessPointsData = $accessPointsData->map(function ($ap) use ($maxAccessPointUsers) {
                    $ap['percentage'] = round(($ap['activeUsers'] / $maxAccessPointUsers) * 100, 2);
                    return $ap;
                })->values();
            } catch (\Exception $e) {
                // Fallback if relationship doesn't exist
                \Log::warning('Could not load access point data: ' . $e->getMessage());
                $accessPointsData = Router::where('status', 'online')
                    ->get()
                    ->map(function ($router) {
                        return [
                            'id' => $router->id,
                            'name' => $router->name,
                            'location' => $router->location ?? 'Unknown',
                            'activeUsers' => 0,
                            'percentage' => 0,
                        ];
                    })
                    ->values();
            }
            
            // Revenue trend for analytics (last 7 days with percentages)
            $revenueTrendData = [];
            $maxRevenueTrend = 1;
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $amount = Payment::where('status', 'completed')
                    ->whereDate('created_at', $date->toDateString())
                    ->sum('amount');
                $maxRevenueTrend = max($maxRevenueTrend, $amount);
                $revenueTrendData[] = [
                    'label' => $date->format('D'),
                    'amount' => $amount,
                ];
            }
            
            foreach ($revenueTrendData as &$point) {
                $point['percentage'] = round(($point['amount'] / $maxRevenueTrend) * 100, 2);
            }
            
            // User trend for analytics (last 7 days with percentages)
            $userTrendData = [];
            $maxUserTrend = 1;
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $count = UserSession::whereDate('start_time', $date->toDateString())
                    ->count();
                $maxUserTrend = max($maxUserTrend, $count);
                $userTrendData[] = [
                    'label' => $date->format('D'),
                    'count' => $count,
                ];
            }
            
            foreach ($userTrendData as &$point) {
                $point['percentage'] = round(($point['count'] / $maxUserTrend) * 100, 2);
            }
            
            // Calculate averages and growth
            $revenueAverage = count($revenueTrendData) > 0 ? round(array_sum(array_column($revenueTrendData, 'amount')) / count($revenueTrendData), 2) : 0;
            $revenuePeak = $maxRevenueTrend;
            $revenueGrowth = 0;
            if (count($revenueTrendData) >= 2) {
                $firstAmount = $revenueTrendData[0]['amount'];
                $lastAmount = $revenueTrendData[count($revenueTrendData) - 1]['amount'];
                if ($firstAmount > 0) {
                    $revenueGrowth = round((($lastAmount - $firstAmount) / $firstAmount) * 100, 2);
                }
            }
            
            $userAverage = count($userTrendData) > 0 ? round(array_sum(array_column($userTrendData, 'count')) / count($userTrendData), 0) : 0;
            $userPeak = $maxUserTrend;
            $userGrowth = 0;
            if (count($userTrendData) >= 2) {
                $firstCount = $userTrendData[0]['count'];
                $lastCount = $userTrendData[count($userTrendData) - 1]['count'];
                if ($firstCount > 0) {
                    $userGrowth = round((($lastCount - $firstCount) / $firstCount) * 100, 2);
                }
            }

            // Get currently active sessions with user info (exclude admin users)
            $onlineUsersList = UserSession::where('status', 'active')
                ->where(function($query) {
                    $query->whereNull('end_time')
                          ->orWhere('end_time', '>', now());
                })
                ->with('payment.user:id,name,email,role')
                ->limit(20)
                ->get()
                ->filter(function ($session) {
                    // Filter out admin users
                    $user = $session->payment->user ?? null;
                    return !$user || $user->role !== 'admin';
                })
                ->take(10)
                ->map(function ($session) {
                    $user = $session->payment->user ?? null;
                    return [
                        'id' => $user->id ?? null,
                        'name' => $user->name ?? ($session->voucher ? 'Voucher User' : 'Guest'),
                        'email' => $user->email ?? '',
                        'type' => $session->voucher ? 'Hotspot' : 'PPPoE',
                    ];
                })
                ->values();

            // Compile all statistics
            $stats = [
                'total_routers' => $totalRouters,
                'online_routers' => $onlineRouters,
                'offline_routers' => $offlineRouters,
                'provisioning_routers' => $provisioningRouters,
                'active_sessions' => $activeSessions,
                'hotspot_users' => $hotspotUsers,
                'pppoe_users' => $pppoeUsers,
                'total_users' => $totalUsers,
                'total_revenue' => round($totalRevenue, 2),
                'daily_income' => round($dailyIncome, 2),
                'weekly_income' => round($weeklyIncome, 2),
                'monthly_income' => round($monthlyIncome, 2),
                'yearly_income' => round($yearlyIncome, 2),
                'monthly_revenue' => round($monthlyIncome, 2), // Keep for backward compatibility
                'data_usage' => round($totalDataUsage, 2),
                'data_usage_upload' => round($totalDataUpload, 2),
                'data_usage_download' => round($totalDataDownload, 2),
                'monthly_data_usage' => round($monthlyDataUsage, 2),
                'today_data_usage' => round($todayDataUsage, 2),
                'retention_rate' => $retentionRate,
                'current_month_users' => $currentMonthUsers,
                'last_month_users' => $lastMonthUsers,
                'retained_users' => $retainedUsers,
                'sms_balance' => $smsBalance,
                'weekly_users_trend' => $weeklyUsersTrend,
                'weekly_revenue_trend' => $weeklyRevenueTrend,
                'recent_activities' => $recentActivities,
                'online_users' => $onlineUsersList,
                // Detailed payment data
                'payment_details' => [
                    'daily' => [
                        'amount' => round($dailyIncome, 2),
                        'date' => now()->format('F d, Y'),
                        'count' => $dailyPaymentCount,
                    ],
                    'weekly' => [
                        'amount' => round($weeklyIncome, 2),
                        'startDate' => $weekStartDate->format('M d'),
                        'endDate' => $weekEndDate->format('M d'),
                        'count' => $weeklyPaymentCount,
                        'dailyBreakdown' => $weeklyDailyBreakdown,
                    ],
                    'monthly' => [
                        'amount' => round($monthlyIncome, 2),
                        'month' => now()->format('F'),
                        'year' => now()->format('Y'),
                        'count' => $monthlyPaymentCount,
                        'weeklyBreakdown' => $monthlyWeeklyBreakdown,
                    ],
                    'yearly' => [
                        'amount' => round($yearlyIncome, 2),
                        'year' => now()->format('Y'),
                        'count' => $yearlyPaymentCount,
                        'monthlyBreakdown' => $yearlyMonthlyBreakdown,
                    ],
                ],
                // SMS expenses data
                'sms_expenses' => [
                    'sms' => [
                        'totalPurchased' => $smsTotalPurchased,
                        'used' => $smsUsed,
                        'remaining' => $smsBalance,
                        'dailyUsage' => $smsDailyUsage,
                        'weeklyUsage' => $smsWeeklyUsage,
                        'monthlyUsage' => $smsMonthlyUsage,
                        'usageTrend' => $smsUsageTrend,
                        'recentPurchases' => $smsRecentPurchases,
                    ],
                    'costs' => [
                        'totalSpent' => $smsTotalCost,
                        'thisMonth' => $smsThisMonthCost,
                        'lastMonth' => $smsLastMonthCost,
                        'averageCostPerSMS' => $smsAverageCost,
                    ],
                ],
                // Business analytics data
                'business_analytics' => [
                    'retention' => [
                        'rate' => $retentionRate,
                        'lastMonthUsers' => $lastMonthUsers,
                        'retainedUsers' => $retainedUsers,
                    ],
                    'accessPoints' => $accessPointsData,
                    'revenueTrend' => $revenueTrendData,
                    'revenueAverage' => $revenueAverage,
                    'revenuePeak' => $revenuePeak,
                    'revenueGrowth' => $revenueGrowth,
                    'userTrend' => $userTrendData,
                    'userAverage' => $userAverage,
                    'userPeak' => $userPeak,
                    'userGrowth' => $userGrowth,
                ],
                'performance_metrics' => MetricsService::getPerformanceMetrics(),
                'last_updated' => now()->toIso8601String(),
            ];

            // Cache the statistics for 30 seconds for near real-time updates (per-tenant)
            $cacheKey = $this->tenantId ? "dashboard_stats_{$this->tenantId}" : 'dashboard_stats_global';
            Cache::put($cacheKey, $stats, now()->addSeconds(30));

            // Broadcast the updated statistics to tenant-specific channel
            if ($this->tenantId) {
                try {
                    broadcast(new DashboardStatsUpdated($stats, $this->tenantId))->toOthers();
                } catch (\Exception $e) {
                    \Log::warning('Failed to broadcast DashboardStatsUpdated event', [
                        'tenant_id' => $this->tenantId,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail the job if broadcasting fails
                }
            }

            \Log::info('Dashboard statistics updated and broadcasted', [
                'tenant_id' => $this->tenantId,
                'total_routers' => $totalRouters,
                'active_sessions' => $activeSessions,
                'hotspot_users' => $hotspotUsers,
                'pppoe_users' => $pppoeUsers,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to update dashboard statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            
            // Don't retry if we've already tried multiple times
            if ($this->attempts() < $this->tries) {
                $this->release(30);
            } else {
                // Mark as failed after max attempts
                $this->fail($e);
            }
        }
    }
    
    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        \Log::error('UpdateDashboardStatsJob permanently failed', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
