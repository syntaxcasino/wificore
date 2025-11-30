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

class CollectSystemMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('monitoring');
    }

    public function handle(): void
    {
        try {
            $now = now();
            
            // Collect all metrics
            $queueMetrics = $this->collectQueueMetrics();
            $healthMetrics = $this->collectHealthMetrics();
            $performanceMetrics = $this->collectPerformanceMetrics();
            
            // Persist to database
            QueueMetric::create(array_merge($queueMetrics, ['recorded_at' => $now]));
            SystemHealthMetric::create(array_merge($healthMetrics, ['recorded_at' => $now]));
            // Performance metrics already includes recorded_at from collectPerformanceMetrics()
            PerformanceMetric::create($performanceMetrics);
            
            // Cache for real-time display (TTL: 2 minutes)
            Cache::put('metrics:queue:latest', $queueMetrics, now()->addMinutes(2));
            Cache::put('metrics:health:latest', $healthMetrics, now()->addMinutes(2));
            Cache::put('metrics:performance:latest', $performanceMetrics, now()->addMinutes(2));
            
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

    private function collectQueueMetrics(): array
    {
        try {
            // Get queue statistics
            $pending = DB::table('jobs')->whereNull('reserved_at')->count();
            $processing = DB::table('jobs')->whereNotNull('reserved_at')->count();
            $failed = DB::table('failed_jobs')->count();
            $completed = Cache::get('queue:completed:last_hour', 0);
            
            // Get workers by queue using exec()
            $workersByQueue = $this->getWorkersByQueue();
            $activeWorkers = array_sum($workersByQueue);
            
            // Get pending by queue
            $pendingByQueue = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->whereNull('reserved_at')
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray();
            
            // Get failed by queue
            $failedByQueue = DB::table('failed_jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray();
            
            return [
                'pending_jobs' => $pending,
                'processing_jobs' => $processing,
                'failed_jobs' => $failed,
                'completed_jobs' => $completed,
                'active_workers' => $activeWorkers,
                'workers_by_queue' => $workersByQueue,
                'pending_by_queue' => $pendingByQueue,
                'failed_by_queue' => $failedByQueue,
            ];
        } catch (\Exception $e) {
            \Log::error('Failed to collect queue metrics', ['error' => $e->getMessage()]);
            return [
                'pending_jobs' => 0,
                'processing_jobs' => 0,
                'failed_jobs' => 0,
                'completed_jobs' => 0,
                'active_workers' => 0,
                'workers_by_queue' => [],
                'pending_by_queue' => [],
                'failed_by_queue' => [],
            ];
        }
    }

    private function getWorkersByQueue(): array
    {
        try {
            // Use sudo to run supervisorctl as www-data user
            // This requires sudoers configuration: www-data ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl status
            $output = [];
            $return_var = 0;
            
            // Try with sudo first (configured in /etc/sudoers.d/supervisorctl)
            $command = 'sudo /usr/bin/supervisorctl status 2>&1';
            exec($command, $output, $return_var);
            
            // Fallback: Try without sudo (in case we're running as root)
            if ($return_var !== 0 || empty($output)) {
                $output = [];
                $command = '/usr/bin/supervisorctl status 2>&1';
                exec($command, $output, $return_var);
            }
            
            // If still failing, log error and return empty
            if ($return_var !== 0 || empty($output)) {
                \Log::error('Failed to execute supervisorctl', [
                    'return_code' => $return_var,
                    'output' => implode(' | ', array_slice($output, 0, 3)),
                    'user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown'
                ]);
                return [];
            }
            
            $workersByQueue = [];
            $totalWorkers = 0;
            
            foreach ($output as $line) {
                if (empty($line)) continue;
                
                // Only process RUNNING workers
                if (!preg_match('/RUNNING/', $line)) {
                    continue;
                }
                
                // Match queue name - handle both grouped and ungrouped workers
                if (preg_match('/laravel-queue(?:s)?:laravel-queue-([a-z0-9\-]+)_\d+/i', $line, $matches)) {
                    $queueName = $matches[1];
                    if (!isset($workersByQueue[$queueName])) {
                        $workersByQueue[$queueName] = 0;
                    }
                    $workersByQueue[$queueName]++;
                    $totalWorkers++;
                } elseif (preg_match('/laravel-queue-([a-z0-9\-]+):laravel-queue-\1_\d+/i', $line, $matches)) {
                    // Handle standalone queue workers (not in group)
                    $queueName = $matches[1];
                    if (!isset($workersByQueue[$queueName])) {
                        $workersByQueue[$queueName] = 0;
                    }
                    $workersByQueue[$queueName]++;
                    $totalWorkers++;
                }
            }
            
            \Log::info('Collected workers in job', [
                'total_workers' => $totalWorkers,
                'queues' => count($workersByQueue),
                'output_lines' => count($output),
                'method' => 'sudo supervisorctl'
            ]);
            
            return $workersByQueue;
        } catch (\Exception $e) {
            \Log::error('Failed to get workers by queue in job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return empty array on error - don't use static fallback
            // This way we know when workers are actually down
            return [];
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
            // Get TPS from cache
            $tpsCurrent = Cache::get('metrics:tps:current', 0);
            $tpsAverage = Cache::get('metrics:tps:average', 0);
            $tpsMax = Cache::get('metrics:tps:max', 0);
            $tpsMin = Cache::get('metrics:tps:min', 0);
            
            // Cache operations
            $cacheOps = Cache::get('metrics:cache_ops', 0);
            
            // Database metrics
            $dbConnections = DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'")[0]->count ?? 0;
            $dbQueries = DB::select("SELECT sum(calls) as total FROM pg_stat_statements")[0]->total ?? 0;
            
            // Response time from cache
            $responseTimeAvg = Cache::get('metrics:response_time:avg', 0);
            $responseTimeP95 = Cache::get('metrics:response_time:p95', 0);
            $responseTimeP99 = Cache::get('metrics:response_time:p99', 0);
            
            // Match existing performance_metrics table structure from 2025_10_17 migration
            return [
                'recorded_at' => now(),
                'tps_current' => $tpsCurrent,
                'tps_average' => $tpsAverage,
                'tps_max' => $tpsMax,
                'tps_min' => $tpsMin,
                'ops_current' => $cacheOps, // Renamed to match existing schema
                'db_active_connections' => $dbConnections,
                'db_slow_queries' => 0,
                'db_total_queries' => $dbQueries,
                'cache_keys' => 0,
                'cache_memory_used' => '0 MB',
                'cache_hit_rate' => 0,
                'active_sessions' => 0,
                'pending_jobs' => 0,
                'failed_jobs' => 0,
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
            ];
        }
    }
}
