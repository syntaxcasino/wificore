<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class EnvironmentHealthController extends Controller
{
    /**
     * Get comprehensive environment health status
     * Only accessible by system administrators
     */
    public function getHealthStatus(Request $request)
    {
        $health = [
            'timestamp' => now()->toIso8601String(),
            'overall_status' => 'healthy',
            'components' => []
        ];

        // Database Health
        $health['components']['database'] = $this->checkDatabase();
        
        // Redis Health
        $health['components']['redis'] = $this->checkRedis();
        
        // Storage Health
        $health['components']['storage'] = $this->checkStorage();
        
        // Queue Health
        $health['components']['queue'] = $this->checkQueue();
        
        // Cache Health
        $health['components']['cache'] = $this->checkCache();
        
        // Application Health
        $health['components']['application'] = $this->checkApplication();

        // Determine overall status
        $statuses = collect($health['components'])->pluck('status')->toArray();
        if (in_array('critical', $statuses)) {
            $health['overall_status'] = 'critical';
        } elseif (in_array('degraded', $statuses)) {
            $health['overall_status'] = 'degraded';
        }

        return response()->json([
            'success' => true,
            'health' => $health
        ]);
    }

    /**
     * Get database metrics
     */
    public function getDatabaseMetrics(Request $request)
    {
        $metrics = [
            'connections' => $this->getDatabaseConnections(),
            'size' => $this->getDatabaseSize(),
            'tables' => $this->getTableSizes(),
            'slow_queries' => $this->getSlowQueries(),
            'partitions' => $this->getPartitionInfo(),
        ];

        return response()->json([
            'success' => true,
            'metrics' => $metrics
        ]);
    }

    /**
     * Get system performance metrics
     */
    public function getPerformanceMetrics(Request $request)
    {
        $metrics = [
            'memory' => [
                'used' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit'),
            ],
            'cpu' => $this->getCpuUsage(),
            'disk' => [
                'total' => disk_total_space('/'),
                'free' => disk_free_space('/'),
                'used' => disk_total_space('/') - disk_free_space('/'),
            ],
            'response_times' => $this->getAverageResponseTimes(),
        ];

        return response()->json([
            'success' => true,
            'metrics' => $metrics
        ]);
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(Request $request)
    {
        try {
            $redis = Redis::connection();
            $info = $redis->info();
            
            $stats = [
                'status' => 'healthy',
                'memory_used' => $info['used_memory_human'] ?? 'N/A',
                'memory_peak' => $info['used_memory_peak_human'] ?? 'N/A',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands' => $info['total_commands_processed'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
            ];
        } catch (\Exception $e) {
            $stats = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        return response()->json([
            'success' => true,
            'stats' => $stats
        ]);
    }

    // =========================================================================
    // Private Helper Methods
    // =========================================================================

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $latency = (microtime(true) - $start) * 1000;

            $activeConnections = DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'")[0]->count;
            $maxConnections = DB::select("SHOW max_connections")[0]->max_connections;

            return [
                'status' => $latency < 100 ? 'healthy' : 'degraded',
                'latency_ms' => round($latency, 2),
                'active_connections' => $activeConnections,
                'max_connections' => $maxConnections,
                'connection_usage' => round(($activeConnections / $maxConnections) * 100, 2) . '%',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage()
            ];
        }
    }

    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $latency = (microtime(true) - $start) * 1000;

            return [
                'status' => $latency < 50 ? 'healthy' : 'degraded',
                'latency_ms' => round($latency, 2),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage()
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $totalSpace = disk_total_space(storage_path());
            $freeSpace = disk_free_space(storage_path());
            $usedSpace = $totalSpace - $freeSpace;
            $usagePercent = ($usedSpace / $totalSpace) * 100;

            return [
                'status' => $usagePercent < 80 ? 'healthy' : ($usagePercent < 90 ? 'degraded' : 'critical'),
                'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
                'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
                'used_gb' => round($usedSpace / 1024 / 1024 / 1024, 2),
                'usage_percent' => round($usagePercent, 2),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage()
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            return [
                'status' => $failed < 10 ? 'healthy' : 'degraded',
                'pending_jobs' => $pending,
                'failed_jobs' => $failed,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage()
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'test', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            return [
                'status' => $value === 'test' ? 'healthy' : 'degraded',
                'driver' => config('cache.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage()
            ];
        }
    }

    private function checkApplication(): array
    {
        return [
            'status' => 'healthy',
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    private function getDatabaseConnections(): array
    {
        try {
            $connections = DB::select("
                SELECT 
                    count(*) as total,
                    count(*) FILTER (WHERE state = 'active') as active,
                    count(*) FILTER (WHERE state = 'idle') as idle
                FROM pg_stat_activity
                WHERE datname = current_database()
            ")[0];

            return [
                'total' => $connections->total,
                'active' => $connections->active,
                'idle' => $connections->idle,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getDatabaseSize(): array
    {
        try {
            $size = DB::select("
                SELECT pg_size_pretty(pg_database_size(current_database())) as size
            ")[0]->size;

            return ['size' => $size];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getTableSizes(): array
    {
        try {
            $tables = DB::select("
                SELECT 
                    schemaname,
                    tablename,
                    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size,
                    pg_total_relation_size(schemaname||'.'||tablename) AS size_bytes
                FROM pg_tables
                WHERE schemaname = 'public'
                ORDER BY size_bytes DESC
                LIMIT 10
            ");

            return array_map(function($table) {
                return [
                    'table' => $table->tablename,
                    'size' => $table->size,
                ];
            }, $tables);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getSlowQueries(): array
    {
        try {
            $queries = DB::select("
                SELECT 
                    query,
                    calls,
                    total_exec_time,
                    mean_exec_time,
                    max_exec_time
                FROM pg_stat_statements
                WHERE mean_exec_time > 100
                ORDER BY mean_exec_time DESC
                LIMIT 10
            ");

            return array_map(function($query) {
                return [
                    'query' => substr($query->query, 0, 100) . '...',
                    'calls' => $query->calls,
                    'avg_time_ms' => round($query->mean_exec_time, 2),
                    'max_time_ms' => round($query->max_exec_time, 2),
                ];
            }, $queries);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getPartitionInfo(): array
    {
        try {
            $partitions = DB::select("
                SELECT 
                    parent.relname AS parent_table,
                    child.relname AS partition_name,
                    pg_size_pretty(pg_total_relation_size(child.oid)) AS size
                FROM pg_inherits
                JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
                JOIN pg_class child ON pg_inherits.inhrelid = child.oid
                WHERE parent.relname IN ('payments', 'user_sessions', 'system_logs', 'hotspot_sessions')
                ORDER BY parent.relname, child.relname
            ");

            return array_map(function($partition) {
                return [
                    'parent' => $partition->parent_table,
                    'partition' => $partition->partition_name,
                    'size' => $partition->size,
                ];
            }, $partitions);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getCpuUsage(): array
    {
        // This is a simplified version, actual implementation depends on OS
        try {
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                return [
                    '1min' => $load[0],
                    '5min' => $load[1],
                    '15min' => $load[2],
                ];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return ['unavailable' => true];
    }

    private function getAverageResponseTimes(): array
    {
        // This would typically come from APM or logging system
        return [
            'api' => 'N/A',
            'database' => 'N/A',
            'cache' => 'N/A',
        ];
    }

    private function calculateHitRate($info): string
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return '0%';
        }

        return round(($hits / $total) * 100, 2) . '%';
    }
}
