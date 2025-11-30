<?php

/**
 * System Health Check Script
 * 
 * Comprehensive health check for the entire WiFi Hotspot Management System
 * Can be run via CLI or accessed via API endpoint
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use App\Models\Router;

class SystemHealthCheck
{
    private $results = [];
    private $overallStatus = 'healthy';
    
    public function run(): array
    {
        $startTime = microtime(true);
        
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║         SYSTEM HEALTH CHECK                                    ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        // Run all health checks
        $this->checkDatabase();
        $this->checkRedis();
        $this->checkDiskSpace();
        $this->checkMemory();
        $this->checkEnvironment();
        $this->checkQueues();
        $this->checkLogs();
        $this->checkRouters();
        
        $duration = round(microtime(true) - $startTime, 2);
        
        // Display results
        $this->displayResults($duration);
        
        return [
            'status' => $this->overallStatus,
            'timestamp' => now()->toIso8601String(),
            'duration' => $duration,
            'checks' => $this->results
        ];
    }
    
    private function checkDatabase(): void
    {
        echo "🔍 Checking Database...\n";
        
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            // Test query
            $userCount = DB::table('users')->count();
            $routerCount = DB::table('routers')->count();
            
            $this->results['database'] = [
                'status' => 'healthy',
                'response_time' => $responseTime . 'ms',
                'connection' => 'active',
                'users' => $userCount,
                'routers' => $routerCount
            ];
            
            echo "   ✅ Database: Healthy ({$responseTime}ms)\n";
            echo "   📊 Users: $userCount, Routers: $routerCount\n\n";
            
        } catch (\Exception $e) {
            $this->results['database'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
            $this->overallStatus = 'unhealthy';
            echo "   ❌ Database: Failed - " . $e->getMessage() . "\n\n";
        }
    }
    
    private function checkRedis(): void
    {
        echo "🔍 Checking Redis...\n";
        
        try {
            $start = microtime(true);
            Redis::ping();
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            $info = Redis::info();
            $memory = $info['used_memory_human'] ?? 'N/A';
            
            $this->results['redis'] = [
                'status' => 'healthy',
                'response_time' => $responseTime . 'ms',
                'memory_used' => $memory
            ];
            
            echo "   ✅ Redis: Healthy ({$responseTime}ms)\n";
            echo "   💾 Memory Used: $memory\n\n";
            
        } catch (\Exception $e) {
            $this->results['redis'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
            $this->overallStatus = 'degraded';
            echo "   ⚠️  Redis: Failed - " . $e->getMessage() . "\n\n";
        }
    }
    
    private function checkDiskSpace(): void
    {
        echo "🔍 Checking Disk Space...\n";
        
        $storagePath = storage_path();
        $freeSpace = disk_free_space($storagePath);
        $totalSpace = disk_total_space($storagePath);
        $usedSpace = $totalSpace - $freeSpace;
        $usedPercent = round(($usedSpace / $totalSpace) * 100, 2);
        
        $status = 'healthy';
        if ($usedPercent > 90) {
            $status = 'unhealthy';
            $this->overallStatus = 'degraded';
        } elseif ($usedPercent > 80) {
            $status = 'warning';
        }
        
        $this->results['disk_space'] = [
            'status' => $status,
            'used_percent' => $usedPercent,
            'free' => $this->formatBytes($freeSpace),
            'total' => $this->formatBytes($totalSpace)
        ];
        
        $icon = $status === 'healthy' ? '✅' : ($status === 'warning' ? '⚠️' : '❌');
        echo "   $icon Disk Space: {$usedPercent}% used\n";
        echo "   💾 Free: " . $this->formatBytes($freeSpace) . " / " . $this->formatBytes($totalSpace) . "\n\n";
    }
    
    private function checkMemory(): void
    {
        echo "🔍 Checking Memory...\n";
        
        $memoryLimit = ini_get('memory_limit');
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        $this->results['memory'] = [
            'status' => 'healthy',
            'limit' => $memoryLimit,
            'current' => $this->formatBytes($memoryUsage),
            'peak' => $this->formatBytes($memoryPeak)
        ];
        
        echo "   ✅ Memory: Healthy\n";
        echo "   💾 Current: " . $this->formatBytes($memoryUsage) . " / Peak: " . $this->formatBytes($memoryPeak) . "\n\n";
    }
    
    private function checkEnvironment(): void
    {
        echo "🔍 Checking Environment...\n";
        
        $required = [
            'APP_KEY' => env('APP_KEY'),
            'DB_CONNECTION' => env('DB_CONNECTION'),
            'REDIS_HOST' => env('REDIS_HOST'),
            'RADIUS_SERVER_HOST' => env('RADIUS_SERVER_HOST'),
        ];
        
        $missing = [];
        foreach ($required as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }
        
        $status = empty($missing) ? 'healthy' : 'unhealthy';
        if ($status === 'unhealthy') {
            $this->overallStatus = 'unhealthy';
        }
        
        $this->results['environment'] = [
            'status' => $status,
            'missing_vars' => $missing
        ];
        
        $icon = $status === 'healthy' ? '✅' : '❌';
        echo "   $icon Environment: $status\n";
        if (!empty($missing)) {
            echo "   ⚠️  Missing: " . implode(', ', $missing) . "\n";
        }
        echo "\n";
    }
    
    private function checkQueues(): void
    {
        echo "🔍 Checking Queues...\n";
        
        try {
            $failedJobs = DB::table('failed_jobs')->count();
            
            $status = $failedJobs > 10 ? 'warning' : 'healthy';
            
            $this->results['queues'] = [
                'status' => $status,
                'failed_jobs' => $failedJobs
            ];
            
            $icon = $status === 'healthy' ? '✅' : '⚠️';
            echo "   $icon Queues: $status\n";
            echo "   📊 Failed Jobs: $failedJobs\n\n";
            
        } catch (\Exception $e) {
            $this->results['queues'] = [
                'status' => 'unknown',
                'error' => $e->getMessage()
            ];
            echo "   ⚠️  Queues: Could not check - " . $e->getMessage() . "\n\n";
        }
    }
    
    private function checkLogs(): void
    {
        echo "🔍 Checking Logs...\n";
        
        $logPath = storage_path('logs/laravel.log');
        
        if (file_exists($logPath)) {
            $logSize = filesize($logPath);
            $status = $logSize > 100 * 1024 * 1024 ? 'warning' : 'healthy'; // 100MB threshold
            
            $this->results['logs'] = [
                'status' => $status,
                'size' => $this->formatBytes($logSize),
                'path' => $logPath
            ];
            
            $icon = $status === 'healthy' ? '✅' : '⚠️';
            echo "   $icon Logs: $status\n";
            echo "   📄 Size: " . $this->formatBytes($logSize) . "\n\n";
        } else {
            $this->results['logs'] = [
                'status' => 'healthy',
                'size' => '0B'
            ];
            echo "   ✅ Logs: No log file (fresh install)\n\n";
        }
    }
    
    private function checkRouters(): void
    {
        echo "🔍 Checking Routers...\n";
        
        try {
            $totalRouters = Router::count();
            $onlineRouters = Router::where('status', 'online')->count();
            $offlineRouters = $totalRouters - $onlineRouters;
            
            $status = $offlineRouters > 0 ? 'warning' : 'healthy';
            
            $this->results['routers'] = [
                'status' => $status,
                'total' => $totalRouters,
                'online' => $onlineRouters,
                'offline' => $offlineRouters
            ];
            
            $icon = $status === 'healthy' ? '✅' : '⚠️';
            echo "   $icon Routers: $status\n";
            echo "   📊 Total: $totalRouters | Online: $onlineRouters | Offline: $offlineRouters\n\n";
            
        } catch (\Exception $e) {
            $this->results['routers'] = [
                'status' => 'unknown',
                'error' => $e->getMessage()
            ];
            echo "   ⚠️  Routers: Could not check - " . $e->getMessage() . "\n\n";
        }
    }
    
    private function displayResults(float $duration): void
    {
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║         HEALTH CHECK SUMMARY                                   ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        $statusIcon = [
            'healthy' => '✅',
            'degraded' => '⚠️',
            'unhealthy' => '❌'
        ];
        
        echo "Overall Status: " . $statusIcon[$this->overallStatus] . " " . strtoupper($this->overallStatus) . "\n";
        echo "Duration: {$duration}s\n";
        echo "Timestamp: " . now()->toDateTimeString() . "\n\n";
        
        $healthyCount = 0;
        $totalChecks = count($this->results);
        
        foreach ($this->results as $check => $result) {
            if ($result['status'] === 'healthy') {
                $healthyCount++;
            }
        }
        
        $healthPercent = round(($healthyCount / $totalChecks) * 100);
        echo "Health Score: $healthyCount/$totalChecks ($healthPercent%)\n\n";
        
        if ($this->overallStatus === 'healthy') {
            echo "🎉 System is healthy and operational!\n";
        } elseif ($this->overallStatus === 'degraded') {
            echo "⚠️  System is operational but has some issues.\n";
        } else {
            echo "❌ System has critical issues that need attention!\n";
        }
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Run health check
$healthCheck = new SystemHealthCheck();
$result = $healthCheck->run();

// Return JSON if requested
if (isset($argv[1]) && $argv[1] === '--json') {
    echo "\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n";
}
