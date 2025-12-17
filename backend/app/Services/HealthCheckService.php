<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use App\Models\Router;
use App\Models\User;
use App\Models\Payment;
use App\Models\HotspotUser;

class HealthCheckService
{
    /**
     * Get complete system health status
     */
    public function getSystemHealth(): array
    {
        $startTime = microtime(true);
        
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'disk_space' => $this->checkDiskSpace(),
            'memory' => $this->checkMemory(),
            'environment' => $this->checkEnvironment(),
            'queues' => $this->checkQueues(),
            'logs' => $this->checkLogs(),
        ];
        
        $overallStatus = $this->determineOverallStatus($checks);
        $duration = round(microtime(true) - $startTime, 3);
        
        return [
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'duration' => $duration,
            'checks' => $checks,
            'summary' => $this->generateSummary($checks)
        ];
    }
    
    /**
     * Get router health status
     */
    public function getRouterHealth(): array
    {
        try {
            $totalRouters = Router::count();
            $onlineRouters = Router::where('status', 'online')->count();
            $offlineRouters = Router::where('status', 'offline')->count();
            $deployingRouters = Router::where('status', 'deploying')->count();
            
            $recentlyUpdated = Router::where('last_seen', '>=', now()->subMinutes(5))->count();
            
            $routers = Router::select('id', 'name', 'status', 'ip_address', 'last_seen', 'model', 'os_version')
                ->orderBy('last_seen', 'desc')
                ->limit(10)
                ->get();
            
            $status = $offlineRouters > ($totalRouters / 2) ? 'unhealthy' : 
                     ($offlineRouters > 0 ? 'warning' : 'healthy');
            
            return [
                'status' => $status,
                'total' => $totalRouters,
                'online' => $onlineRouters,
                'offline' => $offlineRouters,
                'deploying' => $deployingRouters,
                'recently_active' => $recentlyUpdated,
                'uptime_percentage' => $totalRouters > 0 ? round(($onlineRouters / $totalRouters) * 100, 2) : 0,
                'recent_routers' => $routers
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get database health status
     */
    public function getDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            $stats = [
                'users' => User::count(),
                'routers' => Router::count(),
                'hotspot_users' => HotspotUser::count(),
                'payments_today' => Payment::whereDate('created_at', today())->count(),
                'payments_total' => Payment::count(),
            ];
            
            // Check table sizes
            $tables = DB::select("
                SELECT 
                    table_name,
                    pg_size_pretty(pg_total_relation_size(quote_ident(table_name))) AS size
                FROM information_schema.tables
                WHERE table_schema = 'public'
                ORDER BY pg_total_relation_size(quote_ident(table_name)) DESC
                LIMIT 5
            ");
            
            return [
                'status' => 'healthy',
                'response_time' => $responseTime . 'ms',
                'connection' => 'active',
                'stats' => $stats,
                'largest_tables' => $tables
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get security health status
     */
    public function getSecurityHealth(): array
    {
        $checks = [];
        $score = 0;
        $maxScore = 0;
        
        // Check 1: APP_KEY is set
        $maxScore += 10;
        if (!empty(env('APP_KEY'))) {
            $checks['app_key'] = ['status' => 'pass', 'message' => 'APP_KEY is configured'];
            $score += 10;
        } else {
            $checks['app_key'] = ['status' => 'fail', 'message' => 'APP_KEY is not set'];
        }
        
        // Check 2: APP_DEBUG is false in production
        $maxScore += 10;
        if (env('APP_ENV') === 'production' && !env('APP_DEBUG')) {
            $checks['debug_mode'] = ['status' => 'pass', 'message' => 'Debug mode disabled'];
            $score += 10;
        } else {
            $checks['debug_mode'] = ['status' => 'warning', 'message' => 'Debug mode enabled'];
            $score += 5;
        }
        
        // Check 3: HTTPS enabled
        $maxScore += 10;
        if (str_starts_with(env('APP_URL', ''), 'https://')) {
            $checks['https'] = ['status' => 'pass', 'message' => 'HTTPS enabled'];
            $score += 10;
        } else {
            $checks['https'] = ['status' => 'warning', 'message' => 'HTTPS not configured'];
            $score += 5;
        }
        
        // Check 4: Database credentials
        $maxScore += 10;
        if (!empty(env('DB_PASSWORD')) && env('DB_PASSWORD') !== 'password') {
            $checks['db_password'] = ['status' => 'pass', 'message' => 'Database password set'];
            $score += 10;
        } else {
            $checks['db_password'] = ['status' => 'fail', 'message' => 'Weak database password'];
        }
        
        // Check 5: Failed login attempts
        $maxScore += 10;
        $failedLogins = Cache::get('failed_logins_count', 0);
        if ($failedLogins < 10) {
            $checks['failed_logins'] = ['status' => 'pass', 'message' => "Failed logins: $failedLogins"];
            $score += 10;
        } else {
            $checks['failed_logins'] = ['status' => 'warning', 'message' => "High failed logins: $failedLogins"];
            $score += 5;
        }
        
        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100) : 0;
        $status = $percentage >= 80 ? 'healthy' : ($percentage >= 60 ? 'warning' : 'unhealthy');
        
        return [
            'status' => $status,
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'checks' => $checks
        ];
    }
    
    /**
     * Check database connectivity
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            $pdo = DB::connection()->getPdo();
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            // Get current connections
            $connections = DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE state = 'active'");
            $currentConnections = $connections[0]->count ?? 0;
            
            // Get max connections
            $maxConn = DB::select("SHOW max_connections");
            $maxConnections = $maxConn[0]->max_connections ?? 100;
            
            // Get database size
            $dbName = env('DB_DATABASE');
            $dbSize = DB::select("SELECT pg_size_pretty(pg_database_size(?)) as size", [$dbName]);
            $databaseSize = $dbSize[0]->size ?? 'N/A';
            
            return [
                'status' => 'healthy',
                'response_time' => $responseTime,
                'connection' => 'active',
                'current_connections' => $currentConnections,
                'max_connections' => $maxConnections,
                'database_size' => $databaseSize,
                'database_name' => $dbName,
                'host' => env('DB_HOST', 'localhost')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check Redis connectivity
     */
    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            // Get Redis info
            $info = Redis::info();
            
            // Get memory usage
            $usedMemory = $info['used_memory_human'] ?? 'N/A';
            $usedMemoryPeak = $info['used_memory_peak_human'] ?? 'N/A';
            $memoryFragmentationRatio = $info['mem_fragmentation_ratio'] ?? 0;
            
            // Get connection stats
            $connectedClients = $info['connected_clients'] ?? 0;
            $blockedClients = $info['blocked_clients'] ?? 0;
            
            // Get keyspace stats
            $totalKeys = 0;
            $expiringKeys = 0;
            foreach ($info as $key => $value) {
                if (strpos($key, 'db') === 0 && is_string($value)) {
                    preg_match('/keys=(\d+)/', $value, $keys);
                    preg_match('/expires=(\d+)/', $value, $expires);
                    $totalKeys += isset($keys[1]) ? (int)$keys[1] : 0;
                    $expiringKeys += isset($expires[1]) ? (int)$expires[1] : 0;
                }
            }
            
            // Get performance stats
            $opsPerSec = $info['instantaneous_ops_per_sec'] ?? 0;
            $hitRate = 0;
            if (isset($info['keyspace_hits']) && isset($info['keyspace_misses'])) {
                $hits = (int)$info['keyspace_hits'];
                $misses = (int)$info['keyspace_misses'];
                $total = $hits + $misses;
                $hitRate = $total > 0 ? round(($hits / $total) * 100, 2) : 0;
            }
            
            // Get uptime
            $uptimeInSeconds = $info['uptime_in_seconds'] ?? 0;
            $uptime = $this->formatUptime($uptimeInSeconds);
            
            // Get evicted keys (memory pressure indicator)
            $evictedKeys = $info['evicted_keys'] ?? 0;
            
            // Determine status
            $status = 'healthy';
            if ($memoryFragmentationRatio > 1.5 || $evictedKeys > 1000) {
                $status = 'warning';
            }
            
            return [
                'status' => $status,
                'response_time' => $responseTime,
                'version' => $info['redis_version'] ?? 'Unknown',
                'uptime' => $uptime,
                'memory' => [
                    'used' => $usedMemory,
                    'peak' => $usedMemoryPeak,
                    'fragmentation_ratio' => $memoryFragmentationRatio
                ],
                'connections' => [
                    'connected_clients' => $connectedClients,
                    'blocked_clients' => $blockedClients
                ],
                'keyspace' => [
                    'total_keys' => $totalKeys,
                    'expiring_keys' => $expiringKeys
                ],
                'performance' => [
                    'ops_per_sec' => $opsPerSec,
                    'hit_rate' => $hitRate,
                    'evicted_keys' => $evictedKeys
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'degraded',
                'error' => 'Redis not available: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check disk space
     */
    private function checkDiskSpace(): array
    {
        $storagePath = storage_path();
        $freeSpace = disk_free_space($storagePath);
        $totalSpace = disk_total_space($storagePath);
        $usedSpace = $totalSpace - $freeSpace;
        $usedPercent = round(($usedSpace / $totalSpace) * 100, 2);
        
        $status = 'healthy';
        if ($usedPercent > 90) {
            $status = 'unhealthy';
        } elseif ($usedPercent > 80) {
            $status = 'warning';
        }
        
        // Get system information
        $systemInfo = php_uname('s') . ' ' . php_uname('r'); // OS name and release
        $hostname = php_uname('n'); // Hostname
        
        return [
            'status' => $status,
            'used_percent' => $usedPercent,
            'free' => $this->formatBytes($freeSpace),
            'total' => $this->formatBytes($totalSpace),
            'used' => $this->formatBytes($usedSpace),
            'system' => $systemInfo,
            'hostname' => $hostname,
            'mount_point' => $storagePath
        ];
    }
    
    /**
     * Check memory usage
     */
    private function checkMemory(): array
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        // Convert memory limit to bytes for percentage calculation
        $limitBytes = $this->convertToBytes($memoryLimit);
        $usedPercent = $limitBytes > 0 ? round(($memoryUsage / $limitBytes) * 100, 2) : 0;
        
        // Get system memory info if available (Linux)
        $systemMemory = $this->getSystemMemoryInfo();
        
        $status = 'healthy';
        if ($usedPercent > 90) {
            $status = 'warning';
        }
        
        return [
            'status' => $status,
            'limit' => $memoryLimit,
            'current' => $this->formatBytes($memoryUsage),
            'peak' => $this->formatBytes($memoryPeak),
            'used_percent' => $usedPercent,
            'system_info' => $systemMemory,
            'php_version' => PHP_VERSION
        ];
    }
    
    /**
     * Check environment configuration
     */
    private function checkEnvironment(): array
    {
        $required = [
            'APP_KEY',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_DATABASE',
            'REDIS_HOST',
            'RADIUS_SERVER_HOST',
        ];
        
        $missing = [];
        foreach ($required as $key) {
            if (empty(env($key))) {
                $missing[] = $key;
            }
        }
        
        $status = empty($missing) ? 'healthy' : 'unhealthy';
        
        return [
            'status' => $status,
            'missing_vars' => $missing,
            'total_required' => count($required),
            'configured' => count($required) - count($missing)
        ];
    }
    
    /**
     * Check queue health
     */
    private function checkQueues(): array
    {
        try {
            // Pending jobs
            $pendingJobs = DB::table('jobs')->count();
            
            // Failed jobs
            $failedJobs = DB::table('failed_jobs')->count();
            
            // Get failed jobs by queue
            $failedByQueue = DB::table('failed_jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->queue => $item->count];
                })
                ->toArray();
            
            // Get recent failed jobs
            $recentFailed = DB::table('failed_jobs')
                ->select('queue', 'exception', 'failed_at')
                ->orderBy('failed_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($job) {
                    return [
                        'queue' => $job->queue,
                        'error' => substr($job->exception, 0, 100) . '...',
                        'failed_at' => $job->failed_at
                    ];
                })
                ->toArray();
            
            // Get processed jobs count from logs (minimal 30s cache for performance)
            $processedJobs = \Cache::remember('queue_processed_jobs_count', 30, function() {
                try {
                    $logDir = storage_path('logs');
                    $command = "grep -h ' DONE' " . escapeshellarg($logDir) . "/*-queue.log 2>/dev/null | wc -l";
                    $count = (int) trim(shell_exec($command));
                    return $count > 0 ? $count : 0;
                } catch (\Exception $e) {
                    return 0;
                }
            });
            
            // Check if workers are running (via supervisor - matches both singular and plural)
            $workersRunning = false;
            $workerCount = 0;
            try {
                $supervisorStatus = shell_exec('supervisorctl -c /etc/supervisor/supervisord.conf status 2>/dev/null | grep -E "laravel-queue" | grep "RUNNING"');
                if (!empty($supervisorStatus)) {
                    $workersRunning = true;
                    $workerCount = substr_count($supervisorStatus, 'RUNNING');
                }
            } catch (\Exception $e) {
                // Ignore
            }
            
            // Determine status
            $status = 'healthy';
            if ($failedJobs > 50) {
                $status = 'critical';
            } elseif ($failedJobs > 10) {
                $status = 'warning';
            } elseif (!$workersRunning) {
                $status = 'warning';
            }
            
            return [
                'status' => $status,
                'pending_jobs' => $pendingJobs,
                'processed_jobs' => $processedJobs,
                'failed_jobs' => $failedJobs,
                'workers_running' => $workersRunning,
                'worker_count' => $workerCount,
                'failed_by_queue' => $failedByQueue,
                'recent_failures' => $recentFailed
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check log files
     */
    private function checkLogs(): array
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (file_exists($logPath)) {
            $logSize = filesize($logPath);
            
            // Count recent errors (last 100 lines)
            $recentErrors = $this->countRecentLogErrors($logPath);
            
            // Get last modified time
            $lastModified = filemtime($logPath);
            $lastModifiedTime = date('Y-m-d H:i:s', $lastModified);
            $minutesSinceUpdate = round((time() - $lastModified) / 60);
            
            // Analyze log levels in recent entries
            $logLevels = $this->analyzeLogLevels($logPath);
            
            // Determine status
            $status = 'healthy';
            if ($logSize > 100 * 1024 * 1024) {
                $status = 'warning'; // Log file too large
            }
            if ($recentErrors['critical'] > 0 || $recentErrors['error'] > 5) {
                $status = 'warning'; // Too many recent errors
            }
            
            return [
                'status' => $status,
                'size' => $this->formatBytes($logSize),
                'size_bytes' => $logSize,
                'path' => 'storage/logs/laravel.log',
                'last_modified' => $lastModifiedTime,
                'minutes_since_update' => $minutesSinceUpdate,
                'recent_errors' => $recentErrors,
                'log_levels' => $logLevels,
                'is_active' => $minutesSinceUpdate < 60 // Active if updated in last hour
            ];
        }
        
        return [
            'status' => 'healthy',
            'size' => '0B',
            'size_bytes' => 0,
            'path' => 'storage/logs/laravel.log',
            'recent_errors' => [
                'emergency' => 0,
                'alert' => 0,
                'critical' => 0,
                'error' => 0,
                'warning' => 0
            ]
        ];
    }
    
    /**
     * Determine overall system status
     */
    private function determineOverallStatus(array $checks): string
    {
        $unhealthyCount = 0;
        $warningCount = 0;
        
        foreach ($checks as $check) {
            if ($check['status'] === 'unhealthy') {
                $unhealthyCount++;
            } elseif ($check['status'] === 'warning' || $check['status'] === 'degraded') {
                $warningCount++;
            }
        }
        
        if ($unhealthyCount > 0) {
            return 'unhealthy';
        } elseif ($warningCount > 0) {
            return 'warning';
        }
        
        return 'healthy';
    }
    
    /**
     * Generate summary statistics
     */
    private function generateSummary(array $checks): array
    {
        $total = count($checks);
        $healthy = 0;
        $warning = 0;
        $unhealthy = 0;
        
        foreach ($checks as $check) {
            switch ($check['status']) {
                case 'healthy':
                    $healthy++;
                    break;
                case 'warning':
                case 'degraded':
                    $warning++;
                    break;
                case 'unhealthy':
                    $unhealthy++;
                    break;
            }
        }
        
        return [
            'total_checks' => $total,
            'healthy' => $healthy,
            'warning' => $warning,
            'unhealthy' => $unhealthy,
            'health_percentage' => round(($healthy / $total) * 100, 2)
        ];
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Convert memory limit string to bytes
     */
    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int) $value;
        
        switch($last) {
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
     * Get system memory information (Linux only)
     */
    private function getSystemMemoryInfo(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [
                'os' => PHP_OS_FAMILY,
                'available' => false
            ];
        }
        
        try {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
            preg_match('/MemFree:\s+(\d+)/', $meminfo, $free);
            preg_match('/Buffers:\s+(\d+)/', $meminfo, $buffers);
            preg_match('/Cached:\s+(\d+)/', $meminfo, $cached);
            
            $totalKB = isset($total[1]) ? (int)$total[1] : 0;
            $availableKB = isset($available[1]) ? (int)$available[1] : 0;
            $freeKB = isset($free[1]) ? (int)$free[1] : 0;
            $buffersKB = isset($buffers[1]) ? (int)$buffers[1] : 0;
            $cachedKB = isset($cached[1]) ? (int)$cached[1] : 0;
            
            $usedKB = $totalKB - $availableKB;
            $usedPercent = $totalKB > 0 ? round(($usedKB / $totalKB) * 100, 2) : 0;
            
            return [
                'os' => 'Linux',
                'available' => true,
                'total' => $this->formatBytes($totalKB * 1024),
                'used' => $this->formatBytes($usedKB * 1024),
                'free' => $this->formatBytes($freeKB * 1024),
                'buffers' => $this->formatBytes($buffersKB * 1024),
                'cached' => $this->formatBytes($cachedKB * 1024),
                'used_percent' => $usedPercent
            ];
        } catch (\Exception $e) {
            return [
                'os' => 'Linux',
                'available' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Format uptime in human readable format
     */
    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        $parts = [];
        if ($days > 0) $parts[] = $days . 'd';
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0) $parts[] = $minutes . 'm';
        
        return !empty($parts) ? implode(' ', $parts) : '0m';
    }
    
    /**
     * Count recent log errors
     */
    private function countRecentLogErrors(string $logPath): array
    {
        $counts = [
            'emergency' => 0,
            'alert' => 0,
            'critical' => 0,
            'error' => 0,
            'warning' => 0
        ];
        
        try {
            // Read last 200 lines
            $lines = $this->tail($logPath, 200);
            
            foreach ($lines as $line) {
                if (preg_match('/\.(EMERGENCY|emergency)/', $line)) {
                    $counts['emergency']++;
                } elseif (preg_match('/\.(ALERT|alert)/', $line)) {
                    $counts['alert']++;
                } elseif (preg_match('/\.(CRITICAL|critical)/', $line)) {
                    $counts['critical']++;
                } elseif (preg_match('/\.(ERROR|error)/', $line)) {
                    $counts['error']++;
                } elseif (preg_match('/\.(WARNING|warning)/', $line)) {
                    $counts['warning']++;
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }
        
        return $counts;
    }
    
    /**
     * Analyze log levels distribution
     */
    private function analyzeLogLevels(string $logPath): array
    {
        $levels = [
            'info' => 0,
            'debug' => 0,
            'warning' => 0,
            'error' => 0,
            'critical' => 0
        ];
        
        try {
            $lines = $this->tail($logPath, 100);
            
            foreach ($lines as $line) {
                if (preg_match('/\.(INFO|info)/', $line)) {
                    $levels['info']++;
                } elseif (preg_match('/\.(DEBUG|debug)/', $line)) {
                    $levels['debug']++;
                } elseif (preg_match('/\.(WARNING|warning)/', $line)) {
                    $levels['warning']++;
                } elseif (preg_match('/\.(ERROR|error)/', $line)) {
                    $levels['error']++;
                } elseif (preg_match('/\.(CRITICAL|critical|EMERGENCY|emergency|ALERT|alert)/', $line)) {
                    $levels['critical']++;
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }
        
        return $levels;
    }
    
    /**
     * Read last N lines from file
     */
    private function tail(string $filepath, int $lines = 100): array
    {
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return [];
        }
        
        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];
        
        while ($linecounter > 0) {
            $t = ' ';
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }
            $linecounter--;
            if ($beginning) {
                rewind($handle);
            }
            $text[$lines - $linecounter - 1] = fgets($handle);
            if ($beginning) break;
        }
        
        fclose($handle);
        return array_reverse($text);
    }
}
