<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Models\PerformanceMetric;

/**
 * Metrics Service
 * Tracks system performance metrics including TPS, OPS, and other KPIs
 */
class MetricsService extends TenantAwareService
{
    const CACHE_KEY_TPS = 'metrics:tps';
    const CACHE_KEY_TPS_HISTORY = 'metrics:tps:history';
    const CACHE_KEY_TRANSACTION_COUNT = 'metrics:transaction_count';
    const CACHE_KEY_LAST_RESET = 'metrics:last_reset';

    /**
     * Increment transaction counter
     */
    public static function incrementTransactions(int $count = 1): void
    {
        try {
            Cache::increment(self::CACHE_KEY_TRANSACTION_COUNT, $count);
        } catch (\Exception $e) {
            Log::error('Failed to increment transaction counter', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Calculate current TPS (Transactions Per Second)
     */
    public static function calculateTPS(): float
    {
        try {
            $lastReset = Cache::get(self::CACHE_KEY_LAST_RESET);
            $transactionCount = Cache::get(self::CACHE_KEY_TRANSACTION_COUNT, 0);

            if (!$lastReset) {
                // First time - initialize
                Cache::put(self::CACHE_KEY_LAST_RESET, now(), 3600);
                Cache::put(self::CACHE_KEY_TRANSACTION_COUNT, 0, 3600);
                return 0;
            }

            $secondsElapsed = now()->diffInSeconds($lastReset);
            
            if ($secondsElapsed === 0) {
                return 0;
            }

            $tps = round($transactionCount / $secondsElapsed, 2);

            // Store current TPS
            Cache::put(self::CACHE_KEY_TPS, $tps, 60);

            return $tps;
        } catch (\Exception $e) {
            Log::error('Failed to calculate TPS', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get current TPS
     */
    public static function getTPS(): float
    {
        return Cache::get(self::CACHE_KEY_TPS, 0);
    }

    /**
     * Reset TPS counter (called every minute)
     */
    public static function resetTPSCounter(): void
    {
        try {
            $currentCount = Cache::get(self::CACHE_KEY_TRANSACTION_COUNT, 0);
            $lastReset = Cache::get(self::CACHE_KEY_LAST_RESET, now());
            
            $secondsElapsed = now()->diffInSeconds($lastReset);
            
            if ($secondsElapsed > 0) {
                $tps = round($currentCount / $secondsElapsed, 2);
                
                // Store in history
                self::addTPSToHistory($tps);
                
                // Store current TPS
                Cache::put(self::CACHE_KEY_TPS, $tps, 60);
            }

            // Reset counters
            Cache::put(self::CACHE_KEY_TRANSACTION_COUNT, 0, 3600);
            Cache::put(self::CACHE_KEY_LAST_RESET, now(), 3600);
        } catch (\Exception $e) {
            Log::error('Failed to reset TPS counter', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Add TPS to history (last 60 data points = 1 hour if reset every minute)
     */
    private static function addTPSToHistory(float $tps): void
    {
        try {
            $history = Cache::get(self::CACHE_KEY_TPS_HISTORY, []);
            
            // Add timestamp and value
            $history[] = [
                'timestamp' => now()->timestamp,
                'tps' => $tps,
            ];

            // Keep only last 60 entries (1 hour of data)
            if (count($history) > 60) {
                $history = array_slice($history, -60);
            }

            Cache::put(self::CACHE_KEY_TPS_HISTORY, $history, 3600);
        } catch (\Exception $e) {
            Log::error('Failed to add TPS to history', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get TPS history
     */
    public static function getTPSHistory(): array
    {
        return Cache::get(self::CACHE_KEY_TPS_HISTORY, []);
    }

    /**
     * Get database performance metrics
     */
    public static function getDatabaseMetrics(): array
    {
        try {
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            
            // Get database stats based on driver
            $driver = $connection->getDriverName();
            
            if ($driver === 'pgsql') {
                return self::getPostgresMetrics($connection);
            } elseif ($driver === 'mysql') {
                return self::getMySQLMetrics($connection);
            }

            return [
                'active_connections' => 0,
                'total_queries' => 0,
                'slow_queries' => 0,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get database metrics', ['error' => $e->getMessage()]);
            return [
                'active_connections' => 0,
                'total_queries' => 0,
                'slow_queries' => 0,
            ];
        }
    }

    /**
     * Get PostgreSQL specific metrics
     */
    private static function getPostgresMetrics($connection): array
    {
        try {
            // Active connections
            $activeConnections = DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'")[0]->count ?? 0;
            
            // Total queries (from pg_stat_database)
            $dbStats = DB::select("SELECT xact_commit + xact_rollback as total_queries FROM pg_stat_database WHERE datname = current_database()")[0] ?? null;
            $totalQueries = $dbStats->total_queries ?? 0;

            return [
                'active_connections' => $activeConnections,
                'total_queries' => $totalQueries,
                'slow_queries' => 0, // Would need pg_stat_statements extension
            ];
        } catch (\Exception $e) {
            return [
                'active_connections' => 0,
                'total_queries' => 0,
                'slow_queries' => 0,
            ];
        }
    }

    /**
     * Get MySQL specific metrics
     */
    private static function getMySQLMetrics($connection): array
    {
        try {
            $status = DB::select("SHOW STATUS WHERE Variable_name IN ('Threads_connected', 'Questions', 'Slow_queries')");
            
            $metrics = [
                'active_connections' => 0,
                'total_queries' => 0,
                'slow_queries' => 0,
            ];

            foreach ($status as $stat) {
                if ($stat->Variable_name === 'Threads_connected') {
                    $metrics['active_connections'] = (int)$stat->Value;
                } elseif ($stat->Variable_name === 'Questions') {
                    $metrics['total_queries'] = (int)$stat->Value;
                } elseif ($stat->Variable_name === 'Slow_queries') {
                    $metrics['slow_queries'] = (int)$stat->Value;
                }
            }

            return $metrics;
        } catch (\Exception $e) {
            return [
                'active_connections' => 0,
                'total_queries' => 0,
                'slow_queries' => 0,
            ];
        }
    }

    /**
     * Get Redis OPS (Operations Per Second)
     */
    public static function getRedisOPS(): float
    {
        try {
            $redis = Redis::connection('cache');
            $info = $redis->info();
            
            return (float)($info['instantaneous_ops_per_sec'] ?? 0);
        } catch (\Exception $e) {
            Log::error('Failed to get Redis OPS', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get comprehensive performance metrics
     */
    public static function getPerformanceMetrics(): array
    {
        try {
            $tps = self::calculateTPS();
            $ops = self::getRedisOPS();
            $dbMetrics = self::getDatabaseMetrics();
            $tpsHistory = self::getTPSHistory();

            // Calculate averages
            $avgTPS = 0;
            $maxTPS = 0;
            $minTPS = PHP_FLOAT_MAX;

            if (!empty($tpsHistory)) {
                $tpsValues = array_column($tpsHistory, 'tps');
                $avgTPS = round(array_sum($tpsValues) / count($tpsValues), 2);
                $maxTPS = round(max($tpsValues), 2);
                $minTPS = round(min($tpsValues), 2);
            }

            return [
                'tps' => [
                    'current' => $tps,
                    'average' => $avgTPS,
                    'max' => $maxTPS,
                    'min' => $minTPS === PHP_FLOAT_MAX ? 0 : $minTPS,
                    'history' => $tpsHistory,
                ],
                'ops' => [
                    'current' => $ops,
                ],
                'database' => $dbMetrics,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get performance metrics', ['error' => $e->getMessage()]);
            return [
                'tps' => [
                    'current' => 0,
                    'average' => 0,
                    'max' => 0,
                    'min' => 0,
                    'history' => [],
                ],
                'ops' => [
                    'current' => 0,
                ],
                'database' => [
                    'active_connections' => 0,
                    'total_queries' => 0,
                    'slow_queries' => 0,
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Store current metrics to database for historical tracking
     */
    public static function storeMetrics(): void
    {
        try {
            $metrics = self::getPerformanceMetrics();
            
            // Try to get cache stats, fallback to defaults if service doesn't exist
            try {
                $cacheStats = CacheService::getStats();
            } catch (\Exception $e) {
                $cacheStats = [
                    'keys' => 0,
                    'memory_used' => 0,
                    'hit_rate' => 0,
                ];
            }
            
            // Get additional system metrics (only public schema tables)
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            PerformanceMetric::create([
                'recorded_at' => now(),
                'tps_current' => $metrics['tps']['current'],
                'tps_average' => $metrics['tps']['average'],
                'tps_max' => $metrics['tps']['max'],
                'tps_min' => $metrics['tps']['min'],
                'ops_current' => $metrics['ops']['current'],
                'db_active_connections' => $metrics['database']['active_connections'],
                'db_total_queries' => $metrics['database']['total_queries'],
                'db_slow_queries' => $metrics['database']['slow_queries'],
                'cache_keys' => $cacheStats['keys'],
                'cache_memory_used' => $cacheStats['memory_used'],
                'cache_hit_rate' => $cacheStats['hit_rate'],
                'active_sessions' => 0, // Removed: user_sessions is tenant-specific
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
            ]);

            Log::info('Performance metrics stored successfully');
        } catch (\Exception $e) {
            Log::error('Failed to store performance metrics', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get historical metrics for a time range
     */
    public static function getHistoricalMetrics(string $period = '1h', ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $query = PerformanceMetric::query();

            if ($startDate && $endDate) {
                $query->withinRange($startDate, $endDate);
            } else {
                // Predefined periods
                switch ($period) {
                    case '1h':
                        $query->recent(60);
                        break;
                    case '6h':
                        $query->recent(360);
                        break;
                    case '24h':
                        $query->recent(1440);
                        break;
                    case '7d':
                        $query->where('recorded_at', '>=', now()->subDays(7));
                        break;
                    case '30d':
                        $query->where('recorded_at', '>=', now()->subDays(30));
                        break;
                    default:
                        $query->recent(60);
                }
            }

            $metrics = $query->orderBy('recorded_at', 'asc')->get();

            return [
                'period' => $period,
                'start_date' => $metrics->first()?->recorded_at,
                'end_date' => $metrics->last()?->recorded_at,
                'data_points' => $metrics->count(),
                'metrics' => $metrics->map(function ($metric) {
                    return [
                        'timestamp' => $metric->recorded_at->timestamp,
                        'datetime' => $metric->recorded_at->toIso8601String(),
                        'tps' => [
                            'current' => (float)$metric->tps_current,
                            'average' => (float)$metric->tps_average,
                            'max' => (float)$metric->tps_max,
                            'min' => (float)$metric->tps_min,
                        ],
                        'ops' => (float)$metric->ops_current,
                        'database' => [
                            'active_connections' => $metric->db_active_connections,
                            'total_queries' => $metric->db_total_queries,
                            'slow_queries' => $metric->db_slow_queries,
                        ],
                        'cache' => [
                            'keys' => $metric->cache_keys,
                            'memory_used' => $metric->cache_memory_used,
                            'hit_rate' => (float)$metric->cache_hit_rate,
                        ],
                        'system' => [
                            'active_sessions' => $metric->active_sessions,
                            'pending_jobs' => $metric->pending_jobs,
                            'failed_jobs' => $metric->failed_jobs,
                        ],
                    ];
                }),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get historical metrics', ['error' => $e->getMessage()]);
            return [
                'period' => $period,
                'start_date' => null,
                'end_date' => null,
                'data_points' => 0,
                'metrics' => [],
            ];
        }
    }

    /**
     * Get aggregated metrics summary for a period
     */
    public static function getMetricsSummary(string $period = '24h'): array
    {
        try {
            $query = PerformanceMetric::query();

            switch ($period) {
                case '1h':
                    $query->recent(60);
                    break;
                case '24h':
                    $query->recent(1440);
                    break;
                case '7d':
                    $query->where('recorded_at', '>=', now()->subDays(7));
                    break;
                case '30d':
                    $query->where('recorded_at', '>=', now()->subDays(30));
                    break;
                default:
                    $query->recent(1440);
            }

            $metrics = $query->get();

            if ($metrics->isEmpty()) {
                return [
                    'period' => $period,
                    'summary' => 'No data available',
                ];
            }

            return [
                'period' => $period,
                'tps' => [
                    'average' => round($metrics->avg('tps_current'), 2),
                    'max' => round($metrics->max('tps_current'), 2),
                    'min' => round($metrics->min('tps_current'), 2),
                    'current' => round($metrics->last()->tps_current, 2),
                ],
                'ops' => [
                    'average' => round($metrics->avg('ops_current'), 2),
                    'max' => round($metrics->max('ops_current'), 2),
                    'min' => round($metrics->min('ops_current'), 2),
                    'current' => round($metrics->last()->ops_current, 2),
                ],
                'database' => [
                    'avg_connections' => round($metrics->avg('db_active_connections'), 0),
                    'max_connections' => $metrics->max('db_active_connections'),
                    'avg_slow_queries' => round($metrics->avg('db_slow_queries'), 0),
                ],
                'cache' => [
                    'avg_hit_rate' => round($metrics->avg('cache_hit_rate'), 2),
                    'avg_keys' => round($metrics->avg('cache_keys'), 0),
                ],
                'system' => [
                    'avg_active_sessions' => round($metrics->avg('active_sessions'), 0),
                    'avg_pending_jobs' => round($metrics->avg('pending_jobs'), 0),
                    'total_failed_jobs' => $metrics->sum('failed_jobs'),
                ],
                'data_points' => $metrics->count(),
                'start_date' => $metrics->first()->recorded_at->toIso8601String(),
                'end_date' => $metrics->last()->recorded_at->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get metrics summary', ['error' => $e->getMessage()]);
            return [
                'period' => $period,
                'error' => 'Failed to calculate summary',
            ];
        }
    }

    /**
     * Clean up old metrics (keep only last 30 days)
     */
    public static function cleanupOldMetrics(): int
    {
        try {
            $deleted = PerformanceMetric::where('recorded_at', '<', now()->subDays(30))->delete();
            Log::info('Old performance metrics cleaned up', ['deleted_count' => $deleted]);
            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup old metrics', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
