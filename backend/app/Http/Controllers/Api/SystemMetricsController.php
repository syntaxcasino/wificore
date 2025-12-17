<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class SystemMetricsController extends Controller
{
    /**
     * Get comprehensive system metrics
     */
    public function getMetrics(): JsonResponse
    {
        try {
            $metrics = MetricsService::getPerformanceMetrics();
            
            // Add system load information
            $metrics['system'] = [
                'cpu' => $this->getCpuUsage(),
                'memory' => $this->getMemoryUsage(),
            ];
            
            // Add response time metrics
            $metrics['responseTime'] = [
                'average' => Cache::get('metrics:response_time:avg', 23),
                'p95' => Cache::get('metrics:response_time:p95', 45),
                'p99' => Cache::get('metrics:response_time:p99', 78),
            ];
            
            return response()->json($metrics);
        } catch (\Exception $e) {
            \Log::error('Failed to get system metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to retrieve metrics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get queue statistics
     */
    /**
     * Get historical queue metrics
     */
    public function getHistoricalQueueMetrics(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date', now()->subHours(24));
            $endDate = $request->input('end_date', now());
            $interval = $request->input('interval', '1 hour'); // 1 hour, 1 day, etc.
            
            $metrics = \App\Models\QueueMetric::whereBetween('recorded_at', [$startDate, $endDate])
                ->orderBy('recorded_at', 'asc')
                ->get();
            
            return response()->json([
                'data' => $metrics,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'count' => $metrics->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve historical metrics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get queue statistics (real-time from cache)
     */
    public function getQueueStats(): JsonResponse
    {
        try {
            // Try to get from cache first (updated by background job every minute)
            $cachedMetrics = Cache::get('metrics:queue:latest');
            
            if ($cachedMetrics) {
                \Log::info('Queue stats served from cache', [
                    'workers' => $cachedMetrics['active_workers'] ?? 0
                ]);
                
                return response()->json([
                    'pending' => $cachedMetrics['pending_jobs'] ?? 0,
                    'processing' => $cachedMetrics['processing_jobs'] ?? 0,
                    'failed' => $cachedMetrics['failed_jobs'] ?? 0,
                    'completed' => $cachedMetrics['completed_jobs'] ?? 0,
                    'workers' => $cachedMetrics['active_workers'] ?? 0,
                    'workersByQueue' => $cachedMetrics['workers_by_queue'] ?? (object)[],
                    'pendingByQueue' => $cachedMetrics['pending_by_queue'] ?? (object)[],
                    'failedByQueue' => $cachedMetrics['failed_by_queue'] ?? (object)[],
                    'source' => 'cache'
                ]);
            }
            
            // Fallback: collect metrics directly (if cache is empty)
            \Log::warning('Cache miss - collecting queue stats directly');
            
            $pendingByQueue = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->whereNull('reserved_at')
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray();
            
            $failedByQueue = DB::table('failed_jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray();
            
            $processingJobs = DB::table('jobs')
                ->whereNotNull('reserved_at')
                ->count();
            
            $workersByQueue = $this->getWorkersByQueue();
            $activeWorkers = array_sum($workersByQueue);
            $completedLastHour = Cache::get('queue:completed:last_hour', 0);
            
            $stats = [
                'pending' => DB::table('jobs')->count(),
                'processing' => $processingJobs,
                'failed' => DB::table('failed_jobs')->count(),
                'completed' => $completedLastHour,
                'workers' => $activeWorkers,
                'workersByQueue' => empty($workersByQueue) ? (object)[] : $workersByQueue,
                'pendingByQueue' => empty($pendingByQueue) ? (object)[] : $pendingByQueue,
                'failedByQueue' => empty($failedByQueue) ? (object)[] : $failedByQueue,
                'source' => 'direct'
            ];
            
            return response()->json($stats);
        } catch (\Exception $e) {
            \Log::error('Failed to get queue stats', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to retrieve queue statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Retry all failed jobs
     */
    public function retryFailedJobs(): JsonResponse
    {
        try {
            // Get count before retry
            $failedCount = DB::table('failed_jobs')->count();
            
            if ($failedCount === 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'No failed jobs to retry',
                    'retried' => 0
                ]);
            }
            
            // Retry all failed jobs
            Artisan::call('queue:retry', ['id' => 'all']);
            
            return response()->json([
                'success' => true,
                'message' => "Retried {$failedCount} failed jobs",
                'retried' => $failedCount
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to retry jobs', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to retry jobs',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get CPU usage percentage
     */
    private function getCpuUsage(): int
    {
        return Cache::remember('system:cpu_usage', 10, function () {
            try {
                if (PHP_OS_FAMILY === 'Windows') {
                    // Windows: Use wmic to get CPU usage
                    $output = shell_exec('wmic cpu get loadpercentage');
                    if ($output && preg_match('/(\d+)/', $output, $matches)) {
                        return (int) $matches[1];
                    }
                } else {
                    // Linux: Use top command
                    $output = shell_exec("top -bn1 | grep 'Cpu(s)' | sed 's/.*, *\([0-9.]*\)%* id.*/\\1/' | awk '{print 100 - $1}'");
                    if ($output) {
                        return (int) round(floatval(trim($output)));
                    }
                    
                    // Fallback: Use sys_getloadavg()
                    $load = sys_getloadavg();
                    if ($load && isset($load[0])) {
                        // Convert load average to percentage (assuming 4 cores)
                        $cores = $this->getCpuCores();
                        return min(100, (int) round(($load[0] / $cores) * 100));
                    }
                }
                
                return 0;
            } catch (\Exception $e) {
                \Log::warning('Failed to get CPU usage', ['error' => $e->getMessage()]);
                return 0;
            }
        });
    }
    
    /**
     * Get number of CPU cores
     */
    private function getCpuCores(): int
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('wmic cpu get NumberOfCores');
                if ($output && preg_match('/(\d+)/', $output, $matches)) {
                    return (int) $matches[1];
                }
            } else {
                $output = shell_exec('nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo');
                if ($output) {
                    return (int) trim($output);
                }
            }
            return 4; // Default fallback
        } catch (\Exception $e) {
            return 4; // Default fallback
        }
    }
    
    /**
     * Get memory usage percentage
     */
    private function getMemoryUsage(): int
    {
        $memoryUsed = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();
        
        if ($memoryLimit === -1) {
            return 0; // No limit
        }
        
        return (int) (($memoryUsed / $memoryLimit) * 100);
    }
    
    /**
     * Get PHP memory limit in bytes
     */
    private function getMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit === '-1') {
            return -1;
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }
    
    /**
     * Get worker counts by queue from supervisor
     */
    protected function getWorkersByQueue(): array
    {
        try {
            // Try multiple methods to get supervisor status
            $output = [];
            $return_var = 0;
            
            // Method 1: Direct supervisorctl with correct config path
            $command = 'supervisorctl -c /etc/supervisor/supervisord.conf status 2>&1';
            exec($command, $output, $return_var);
            
            // Method 2: If direct command fails, try with full path
            if ($return_var !== 0 || empty($output)) {
                $output = [];
                $command = '/usr/bin/supervisorctl -c /etc/supervisor/supervisord.conf status 2>&1';
                exec($command, $output, $return_var);
            }
            
            // Method 3: Try via docker exec if we're on the host
            if ($return_var !== 0 || empty($output)) {
                $output = [];
                $command = 'docker exec traidnet-backend supervisorctl -c /etc/supervisor/supervisord.conf status 2>&1';
                exec($command, $output, $return_var);
            }
            
            if (empty($output)) {
                \Log::warning('supervisorctl command returned no output', [
                    'return_code' => $return_var,
                    'tried_commands' => ['supervisorctl -c /etc/supervisor/supervisord.conf', '/usr/bin/supervisorctl -c /etc/supervisor/supervisord.conf', 'docker exec']
                ]);
                return [];
            }
            
            $workersByQueue = [];
            $totalWorkers = 0;
            
            foreach ($output as $line) {
                if (empty($line)) continue;
                
                // Log the line for debugging
                \Log::debug('Parsing supervisor line', ['line' => $line]);
                
                // Parse different formats:
                // Format 1: laravel-queues:laravel-queue-dashboard_00   RUNNING   pid 123, uptime 1:23:45
                // Format 2: laravel-queue-monitoring:laravel-queue-monitoring_00   RUNNING   pid 123
                
                if (!preg_match('/RUNNING/', $line)) {
                    continue; // Skip non-running processes
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
            
            \Log::info('Parsed supervisor workers', [
                'total_workers' => $totalWorkers,
                'by_queue' => $workersByQueue,
                'output_lines' => count($output)
            ]);
            
            return $workersByQueue;
        } catch (\Exception $e) {
            \Log::error('Failed to get workers by queue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    
    /**
     * Get number of active queue workers
     */
    private function getActiveWorkers(): int
    {
        try {
            $workersByQueue = $this->getWorkersByQueue();
            return array_sum($workersByQueue);
        } catch (\Exception $e) {
            \Log::error('Failed to get active workers count', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}
