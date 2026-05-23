<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SystemMetricsService
{
    private const GLOBAL_RESPONSE_TIMES_KEY = 'metrics:response_time:samples';
    private const ROUTE_RESPONSE_TIMES_PREFIX = 'metrics:response_time:route:';
    private const ROUTE_SUMMARY_KEY = 'metrics:response_time:routes';
    private const GLOBAL_SAMPLE_SIZE = 500;
    private const ROUTE_SAMPLE_SIZE = 120;
    private const ROUTE_SUMMARY_LIMIT = 25;
    private const AGGREGATE_TTL_SECONDS = 600;

    /**
     * Get real system uptime percentage
     * Calculates based on when the application started tracking
     */
    public static function getSystemUptime(): float
    {
        try {
            // Get the application start time from cache or set it now (30 seconds max)
            $appStartTime = Cache::remember('app_start_time', 30, function () {
                return now();
            });

            // Calculate total time since app started
            $totalSeconds = now()->diffInSeconds($appStartTime);
            
            // Get downtime from cache (accumulated downtime in seconds)
            $downtimeSeconds = Cache::get('system_downtime_seconds', 0);
            
            // Calculate uptime percentage
            if ($totalSeconds > 0) {
                $uptimePercentage = (($totalSeconds - $downtimeSeconds) / $totalSeconds) * 100;
                return round(min(100, max(0, $uptimePercentage)), 1);
            }
            
            return 99.9; // Default for new installations
        } catch (\Exception $e) {
            Log::error('Failed to calculate system uptime', ['error' => $e->getMessage()]);
            return 99.9;
        }
    }

    /**
     * Get disk space usage
     */
    public static function getDiskSpace(): array
    {
        try {
            $path = base_path();
            
            // Get disk space info
            $totalSpace = disk_total_space($path);
            $freeSpace = disk_free_space($path);
            $usedSpace = $totalSpace - $freeSpace;
            
            // Convert to GB
            $totalGB = round($totalSpace / (1024 * 1024 * 1024), 2);
            $usedGB = round($usedSpace / (1024 * 1024 * 1024), 2);
            $freeGB = round($freeSpace / (1024 * 1024 * 1024), 2);
            $usedPercentage = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100, 1) : 0;
            
            return [
                'total' => $totalGB,
                'used' => $usedGB,
                'free' => $freeGB,
                'used_percentage' => $usedPercentage,
                'total_formatted' => number_format($totalGB, 2) . 'GB',
                'available_formatted' => number_format($freeGB, 2) . 'GB',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get disk space', ['error' => $e->getMessage()]);
            return [
                'total' => 100,
                'used' => 15,
                'free' => 85,
                'used_percentage' => 15.0,
                'total_formatted' => '100.00GB',
                'available_formatted' => '85.00GB',
            ];
        }
    }

    /**
     * Get average API response time
     * This represents the average time for API requests
     */
    public static function getAverageResponseTime(): float
    {
        try {
            $averageMs = Cache::get('metrics:response_time:avg');

            if ($averageMs !== null) {
                return round(((float) $averageMs) / 1000, 4);
            }

            return 0.03;
        } catch (\Exception $e) {
            Log::error('Failed to calculate average response time', ['error' => $e->getMessage()]);
            return 0.03;
        }
    }

    /**
     * Get Redis cache hit ratio
     */
    public static function getRedisCacheHitRatio(): float
    {
        try {
            // Get Redis statistics
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $info = $redis->info('stats');
            
            if (isset($info['keyspace_hits']) && isset($info['keyspace_misses'])) {
                $hits = (int) $info['keyspace_hits'];
                $misses = (int) $info['keyspace_misses'];
                $total = $hits + $misses;
                
                if ($total > 0) {
                    return round(($hits / $total) * 100, 1);
                }
            }
            
            return 98.0; // Default good ratio
        } catch (\Exception $e) {
            Log::error('Failed to get Redis cache hit ratio', ['error' => $e->getMessage()]);
            return 98.0;
        }
    }

    /**
     * Get database connection count
     */
    public static function getDatabaseConnections(): array
    {
        try {
            $result = Cache::remember('db_connections_stat', 10, function () {
                return DB::select("
                    SELECT 
                        count(*) as active_connections,
                        (SELECT setting::int FROM pg_settings WHERE name = 'max_connections') as max_connections
                    FROM pg_stat_activity 
                    WHERE state = 'active'
                ");
            });
            
            if (!empty($result)) {
                $active = $result[0]->active_connections ?? 1;
                $max = $result[0]->max_connections ?? 100;
                
                return [
                    'active' => $active,
                    'max' => $max,
                    'percentage' => round(($active / $max) * 100, 1),
                ];
            }
            
            return ['active' => 1, 'max' => 100, 'percentage' => 1.0];
        } catch (\Exception $e) {
            Log::error('Failed to get database connections', ['error' => $e->getMessage()]);
            return ['active' => 1, 'max' => 100, 'percentage' => 1.0];
        }
    }

    /**
     * Get memory usage
     */
    public static function getMemoryUsage(): array
    {
        try {
            $memoryUsed = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            // Convert memory limit to bytes
            $memoryLimitBytes = self::convertToBytes($memoryLimit);
            
            $usedMB = round($memoryUsed / (1024 * 1024), 2);
            $limitMB = round($memoryLimitBytes / (1024 * 1024), 2);
            $percentage = $memoryLimitBytes > 0 ? round(($memoryUsed / $memoryLimitBytes) * 100, 1) : 0;
            
            return [
                'used' => $usedMB,
                'limit' => $limitMB,
                'percentage' => $percentage,
                'used_formatted' => number_format($usedMB, 2) . 'MB',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get memory usage', ['error' => $e->getMessage()]);
            return [
                'used' => 128,
                'limit' => 512,
                'percentage' => 25.0,
                'used_formatted' => '128.00MB',
            ];
        }
    }

    /**
     * Convert PHP memory limit string to bytes
     */
    private static function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get all system health metrics
     */
    public static function getAllMetrics(): array
    {
        return [
            'uptime' => self::getSystemUptime(),
            'disk_space' => self::getDiskSpace(),
            'average_response_time' => self::getAverageResponseTime(),
            'redis_cache_hit_ratio' => self::getRedisCacheHitRatio(),
            'database_connections' => self::getDatabaseConnections(),
            'memory_usage' => self::getMemoryUsage(),
            'last_updated' => now()->toIso8601String(),
        ];
    }

    /**
     * Record API response time
     * Call this from middleware to track response times
     */
    public static function recordResponseTime(float $durationMs, ?string $routeKey = null): void
    {
        if ($durationMs <= 0) {
            return;
        }

        try {
            $roundedDuration = round($durationMs, 2);
            $globalKey = self::GLOBAL_RESPONSE_TIMES_KEY;
            $sanitizedRouteKey = self::sanitizeRouteKey($routeKey);
            $routeListKey = $sanitizedRouteKey !== null ? self::ROUTE_RESPONSE_TIMES_PREFIX.$sanitizedRouteKey : null;

            Redis::pipeline(function ($pipe) use ($globalKey, $roundedDuration, $routeListKey) {
                $pipe->lpush($globalKey, (string) $roundedDuration);
                $pipe->ltrim($globalKey, 0, self::GLOBAL_SAMPLE_SIZE - 1);
                $pipe->expire($globalKey, self::AGGREGATE_TTL_SECONDS);

                if ($routeListKey !== null) {
                    $pipe->lpush($routeListKey, (string) $roundedDuration);
                    $pipe->ltrim($routeListKey, 0, self::ROUTE_SAMPLE_SIZE - 1);
                    $pipe->expire($routeListKey, self::AGGREGATE_TTL_SECONDS);
                }
            });

            if (Cache::add('metrics:response_time:recompute', 1, 5)) {
                self::refreshResponseTimeAggregates($sanitizedRouteKey);
            }
        } catch (\Throwable $e) {
            Log::debug('Failed to record response time metric', ['error' => $e->getMessage()]);
        }
    }

    private static function refreshResponseTimeAggregates(?string $routeKey = null): void
    {
        $globalSamples = self::readSamples(self::GLOBAL_RESPONSE_TIMES_KEY);
        $globalMetrics = self::calculateLatencyMetrics($globalSamples);

        Cache::put('metrics:response_time:avg', $globalMetrics['avg'], now()->addSeconds(self::AGGREGATE_TTL_SECONDS));
        Cache::put('metrics:response_time:p95', $globalMetrics['p95'], now()->addSeconds(self::AGGREGATE_TTL_SECONDS));
        Cache::put('metrics:response_time:p99', $globalMetrics['p99'], now()->addSeconds(self::AGGREGATE_TTL_SECONDS));
        Cache::put('api_response_times', array_slice($globalSamples, 0, 100), now()->addSeconds(self::AGGREGATE_TTL_SECONDS));

        if ($routeKey === null) {
            return;
        }

        $routeSamples = self::readSamples(self::ROUTE_RESPONSE_TIMES_PREFIX.$routeKey);
        if ($routeSamples === []) {
            return;
        }

        $routeMetrics = self::calculateLatencyMetrics($routeSamples);
        $summary = Cache::get(self::ROUTE_SUMMARY_KEY, []);
        $summary[$routeKey] = [
            'avg' => $routeMetrics['avg'],
            'p95' => $routeMetrics['p95'],
            'p99' => $routeMetrics['p99'],
            'samples' => count($routeSamples),
            'updated_at' => now()->toIso8601String(),
        ];

        uasort($summary, static fn (array $left, array $right): int => $right['p99'] <=> $left['p99']);
        $summary = array_slice($summary, 0, self::ROUTE_SUMMARY_LIMIT, true);

        Cache::put(self::ROUTE_SUMMARY_KEY, $summary, now()->addSeconds(self::AGGREGATE_TTL_SECONDS));
    }

    private static function readSamples(string $key): array
    {
        $samples = Redis::lrange($key, 0, self::GLOBAL_SAMPLE_SIZE - 1);

        return array_values(array_filter(array_map(static function ($value): float {
            return round((float) $value, 2);
        }, $samples), static fn (float $value): bool => $value >= 0));
    }

    private static function calculateLatencyMetrics(array $samples): array
    {
        if ($samples === []) {
            return [
                'avg' => 30.0,
                'p95' => 45.0,
                'p99' => 78.0,
            ];
        }

        sort($samples, SORT_NUMERIC);

        return [
            'avg' => round(array_sum($samples) / count($samples), 2),
            'p95' => self::percentile($samples, 95),
            'p99' => self::percentile($samples, 99),
        ];
    }

    private static function percentile(array $sortedSamples, int $percentile): float
    {
        if ($sortedSamples === []) {
            return 0.0;
        }

        $index = (int) ceil((count($sortedSamples) * $percentile) / 100) - 1;
        $index = max(0, min($index, count($sortedSamples) - 1));

        return round((float) $sortedSamples[$index], 2);
    }

    private static function sanitizeRouteKey(?string $routeKey): ?string
    {
        if ($routeKey === null || $routeKey === '') {
            return null;
        }

        return substr(preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $routeKey) ?? 'unknown', 0, 120);
    }

    /**
     * Record system downtime
     * Call this when system goes down or comes back up
     */
    public static function recordDowntime(int $seconds): void
    {
        try {
            $currentDowntime = Cache::get('system_downtime_seconds', 0);
            Cache::put('system_downtime_seconds', $currentDowntime + $seconds, now()->addSeconds(30));
        } catch (\Exception $e) {
            Log::error('Failed to record downtime', ['error' => $e->getMessage()]);
        }
    }
}
