<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Models\QueueMetric;
use App\Models\SystemHealthMetric;
use App\Models\PerformanceMetric;
use App\Events\SystemMetricsUpdated;
use App\Services\QueueMetricsService;

class CollectSystemMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('monitoring');
    }

    public function handle(QueueMetricsService $queueMetrics): void
    {
        try {
            $now = now();
            
            // Collect all metrics
            $queueMetrics = $this->collectQueueMetrics($queueMetrics);
            $healthMetrics = $this->collectHealthMetrics();
            $performanceMetrics = $this->collectPerformanceMetrics();
            
            // Persist to database
            QueueMetric::create(array_merge($queueMetrics, ['recorded_at' => $now]));
            SystemHealthMetric::create(array_merge($healthMetrics, ['recorded_at' => $now]));
            // Performance metrics already includes recorded_at from collectPerformanceMetrics()
            PerformanceMetric::create($performanceMetrics);
            
            // Cache for API reads (TTL matches collection interval)
            Cache::put('metrics:queue:latest', $queueMetrics, now()->addSeconds(90));
            Cache::put('metrics:health:latest', $healthMetrics, now()->addSeconds(90));
            Cache::put('metrics:performance:latest', $performanceMetrics, now()->addSeconds(90));

            // Push to all connected system-admin SSE clients immediately
            broadcast(new SystemMetricsUpdated($queueMetrics, $healthMetrics, $performanceMetrics));

            \Log::info('System metrics collected and persisted', [
                'queue_workers' => $queueMetrics['active_workers'] ?? 0,
                'db_connections' => $healthMetrics['db_connections'] ?? 0
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to collect system metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function collectQueueMetrics(QueueMetricsService $queueMetrics): array
    {
        try {
            return $queueMetrics->getRealtimeMetrics();
        } catch (\Exception $e) {
            \Log::error('Failed to collect queue metrics', ['error' => $e->getMessage()]);
            return [
                'pending_jobs' => 0,
                'processing_jobs' => 0,
                'delayed_jobs' => 0,
                'failed_jobs' => 0,
                'completed_jobs' => 0,
                'active_workers' => 0,
                'configured_queues' => [],
                'configured_workers_by_queue' => [],
                'workers_by_queue' => [],
                'pending_by_queue' => [],
                'processing_by_queue' => [],
                'delayed_by_queue' => [],
                'failed_by_queue' => [],
                'oldest_pending_age_by_queue' => [],
                'oldest_pending_job_age_seconds' => null,
            ];
        }
    }

    private function collectHealthMetrics(): array
    {
        try {
            // Database metrics
            $dbConnections = DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'")[0]->count ?? 0;
            $dbMaxConnections = DB::select("SELECT setting::int as max FROM pg_settings WHERE name = 'max_connections'")[0]->max ?? 100;
            
            // Redis metrics
            $redis = Redis::connection();
            $redisInfo = $redis->info();
            $redisHitRate = isset($redisInfo['Stats']['keyspace_hits'], $redisInfo['Stats']['keyspace_misses']) 
                ? ($redisInfo['Stats']['keyspace_hits'] / max(1, $redisInfo['Stats']['keyspace_hits'] + $redisInfo['Stats']['keyspace_misses'])) * 100
                : 0;
            $redisMemory = $redisInfo['Memory']['used_memory'] ?? 0;
            
            // Disk metrics
            $diskTotal = disk_total_space('/');
            $diskFree = disk_free_space('/');
            $diskUsed = $diskTotal - $diskFree;
            $diskPercentage = ($diskUsed / $diskTotal) * 100;
            
            return [
                'db_connections' => $dbConnections,
                'db_max_connections' => $dbMaxConnections,
                'db_response_time' => 0, // Will be calculated from query logs
                'db_slow_queries' => 0,
                'redis_hit_rate' => round($redisHitRate, 2),
                'redis_memory_used' => $redisMemory,
                'redis_memory_peak' => $redisInfo['Memory']['used_memory_peak'] ?? 0,
                'disk_total' => $diskTotal,
                'disk_available' => $diskFree,
                'disk_used_percentage' => round($diskPercentage, 2),
                'uptime_percentage' => 99.9,
                'uptime_duration' => '30 days',
                'last_restart' => now()->subDays(30),
            ];
        } catch (\Exception $e) {
            \Log::error('Failed to collect health metrics', ['error' => $e->getMessage()]);
            return [
                'db_connections' => 0,
                'db_max_connections' => 100,
                'db_response_time' => 0,
                'db_slow_queries' => 0,
                'redis_hit_rate' => 0,
                'redis_memory_used' => 0,
                'redis_memory_peak' => 0,
                'disk_total' => 0,
                'disk_available' => 0,
                'disk_used_percentage' => 0,
                'uptime_percentage' => 0,
                'uptime_duration' => 'Unknown',
                'last_restart' => null,
            ];
        }
    }

    private function collectPerformanceMetrics(): array
    {
        try {
            // Get TPS from MetricsService (uses correct Redis cache keys)
            $tpsCurrent = \App\Services\MetricsService::getTPS();
            $tpsHistory = \App\Services\MetricsService::getTPSHistory();
            $tpsValues = array_column($tpsHistory, 'tps');
            $tpsAverage = !empty($tpsValues) ? round(array_sum($tpsValues) / count($tpsValues), 2) : 0;
            $tpsMax = !empty($tpsValues) ? round(max($tpsValues), 2) : 0;
            $tpsMin = !empty($tpsValues) ? round(min($tpsValues), 2) : 0;

            // Cache operations (Redis INFO stats)
            $cacheOps = 0;
            try {
                $redis = Redis::connection();
                $info = $redis->info();
                $cacheOps = ($info['Stats']['instantaneous_ops_per_sec'] ?? 0);
            } catch (\Throwable $e) {
                // Redis stats may not be available
            }

            // Database metrics
            $dbConnections = DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'")[0]->count ?? 0;
            $dbQueries = DB::select("SELECT sum(calls) as total FROM pg_stat_statements")[0]->total ?? 0;

            // Response time from cache
            $responseTimeAvg = Cache::get('metrics:response_time:avg', 0);
            $responseTimeP95 = Cache::get('metrics:response_time:p95', 0);
            $responseTimeP99 = Cache::get('metrics:response_time:p99', 0);

            // System load (CPU / memory)
            $cpuUsage = 0;
            $memoryUsage = 0;
            try {
                $meminfo = @file_get_contents('/proc/meminfo');
                if ($meminfo
                    && preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m1)
                    && preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m2)) {
                    $memoryUsage = (int) round((($m1[1] - $m2[1]) / $m1[1]) * 100);
                }
                $load = sys_getloadavg();
                if ($load && isset($load[0])) {
                    $cores = (int) trim(@shell_exec('nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo') ?: '4');
                    $cpuUsage = min(100, (int) round(($load[0] / max(1, $cores)) * 100));
                }
            } catch (\Throwable $e) {
                // Ignore system load errors in containers
            }

            // Match existing performance_metrics table structure from 2025_10_17 migration
            return [
                'recorded_at' => now(),
                'tps_current' => $tpsCurrent,
                'tps_average' => $tpsAverage,
                'tps_max' => $tpsMax,
                'tps_min' => $tpsMin,
                'ops_current' => $cacheOps,
                'db_active_connections' => $dbConnections,
                'db_slow_queries' => 0,
                'db_total_queries' => $dbQueries,
                'cache_keys' => 0,
                'cache_memory_used' => '0 MB',
                'cache_hit_rate' => 0,
                'active_sessions' => 0,
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'system' => [
                    'cpu' => $cpuUsage,
                    'memory' => $memoryUsage,
                ],
            ];
        } catch (\Exception $e) {
            \Log::error('Failed to collect performance metrics', ['error' => $e->getMessage()]);
            return [
                'recorded_at' => now(),
                'tps_current' => 0,
                'tps_average' => 0,
                'tps_max' => 0,
                'tps_min' => 0,
                'ops_current' => 0,
                'db_active_connections' => 0,
                'db_slow_queries' => 0,
                'db_total_queries' => 0,
                'cache_keys' => 0,
                'cache_memory_used' => '0 MB',
                'cache_hit_rate' => 0,
                'active_sessions' => 0,
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'system' => [
                    'cpu' => 0,
                    'memory' => 0,
                ],
            ];
        }
    }
}
