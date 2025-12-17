<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class QueueStatsController extends Controller
{
    /**
     * Get queue statistics
     */
    public function index(): JsonResponse
    {
        try {
            // Pending jobs
            $pendingJobs = DB::table('jobs')->count();
            $pendingByQueue = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->get()
                ->mapWithKeys(fn($item) => [$item->queue => $item->count])
                ->toArray();

            // Failed jobs
            $failedJobs = DB::table('failed_jobs')->count();
            $failedByQueue = DB::table('failed_jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->get()
                ->mapWithKeys(fn($item) => [$item->queue => $item->count])
                ->toArray();

            // Processed jobs (estimated)
            $processedCount = $this->getProcessedJobsCount();
            
            // Total jobs
            $totalJobs = $pendingJobs + $failedJobs + $processedCount;

            // Success rate
            $successRate = $totalJobs > 0 ? round(($processedCount / $totalJobs) * 100, 2) : 0;
            $failureRate = $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0;

            // Recent activity (last 24 hours)
            $recentActivity = $this->getRecentActivity();

            // Worker status
            $workerRunning = $this->checkWorkerRunning();

            // Queue health status
            $status = 'healthy';
            if ($failedJobs > 50) {
                $status = 'critical';
            } elseif ($failedJobs > 10) {
                $status = 'warning';
            } elseif (!$workerRunning) {
                $status = 'warning';
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'total_jobs' => $totalJobs,
                        'processed' => $processedCount,
                        'pending' => $pendingJobs,
                        'failed' => $failedJobs,
                        'success_rate' => $successRate,
                        'failure_rate' => $failureRate,
                    ],
                    'pending_by_queue' => $pendingByQueue,
                    'failed_by_queue' => $failedByQueue,
                    'recent_activity' => $recentActivity,
                    'worker_status' => [
                        'running' => $workerRunning,
                        'status' => $workerRunning ? 'active' : 'stopped',
                    ],
                    'health_status' => $status,
                    'tracking_since' => Cache::get('queue_stats_start_time', now())->toIso8601String(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch queue statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get processed jobs count
     */
    private function getProcessedJobsCount(): int
    {
        if (!Cache::has('queue_stats_start_time')) {
            Cache::forever('queue_stats_start_time', now());
        }

        // Get count from logs with minimal caching (30 seconds for performance)
        return $this->estimateProcessedFromLogs();
    }

    /**
     * Estimate processed jobs from logs
     * Minimal caching (30 seconds) for performance while maintaining near real-time data
     */
    private function estimateProcessedFromLogs(): int
    {
        // Check cache first (30 second cache for performance)
        $cacheKey = 'queue_processed_jobs_count';
        $cached = Cache::get($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }
        
        try {
            // Count from all queue log files using shell command for efficiency
            $logDir = storage_path('logs');
            
            // Use grep to count DONE entries efficiently
            $command = "grep -h ' DONE' " . escapeshellarg($logDir) . "/*-queue.log 2>/dev/null | wc -l";
            $count = (int) trim(shell_exec($command));
            
            // If shell command fails, fallback to PHP
            if ($count === 0) {
                $queueLogs = glob($logDir . '/*-queue.log');
                
                foreach ($queueLogs as $logFile) {
                    if (file_exists($logFile)) {
                        // Count "DONE" entries which indicate successful job completion
                        $content = file_get_contents($logFile);
                        $count += substr_count($content, ' DONE');
                    }
                }
            }
            
            // Cache for only 30 seconds to maintain near real-time stats
            Cache::put($cacheKey, $count, now()->addSeconds(30));
            
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get recent activity
     */
    private function getRecentActivity(): array
    {
        try {
            // Last 24 hours
            $failed24h = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();

            // Last 7 days
            $failedWeek = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subWeek())
                ->count();

            // Count processed jobs from queue logs (last 24 hours)
            $processed24h = $this->countRecentProcessedJobs(1);
            $processedWeek = $this->countRecentProcessedJobs(7);

            return [
                'last_24_hours' => [
                    'processed' => $processed24h,
                    'failed' => $failed24h,
                ],
                'last_7_days' => [
                    'processed' => $processedWeek,
                    'failed' => $failedWeek,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'last_24_hours' => ['processed' => 0, 'failed' => 0],
                'last_7_days' => ['processed' => 0, 'failed' => 0],
            ];
        }
    }

    /**
     * Count recently processed jobs from logs
     */
    private function countRecentProcessedJobs(int $days): int
    {
        // Check cache first (30 second cache for near real-time data)
        $cacheKey = "queue_processed_last_{$days}_days";
        $cached = Cache::get($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }
        
        try {
            $logDir = storage_path('logs');
            $count = 0;
            $cutoffDate = now()->subDays($days)->format('Y-m-d');
            
            // Use shell command for efficiency
            $command = "grep -h ' DONE' " . escapeshellarg($logDir) . "/*-queue.log 2>/dev/null | grep -c '" . $cutoffDate . "'";
            $shellCount = (int) trim(shell_exec($command));
            
            if ($shellCount > 0) {
                $count = $shellCount;
            } else {
                // Fallback to PHP parsing
                $queueLogs = glob($logDir . '/*-queue.log');
                
                foreach ($queueLogs as $logFile) {
                    if (file_exists($logFile)) {
                        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        foreach ($lines as $line) {
                            // Check if line contains DONE and is within date range
                            if (strpos($line, ' DONE') !== false) {
                                // Extract date from line (format: 2025-10-12 01:41:08)
                                if (preg_match('/(\d{4}-\d{2}-\d{2})/', $line, $matches)) {
                                    if ($matches[1] >= $cutoffDate) {
                                        $count++;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Cache for only 30 seconds to maintain near real-time stats
            Cache::put($cacheKey, $count, now()->addSeconds(30));
            
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if worker is running
     */
    private function checkWorkerRunning(): bool
    {
        try {
            // Check if supervisor is managing workers (matches both singular and plural)
            $supervisorStatus = shell_exec('supervisorctl -c /etc/supervisor/supervisord.conf status 2>/dev/null | grep -E "laravel-queue" | grep "RUNNING"');
            if (!empty($supervisorStatus)) {
                return true;
            }
            
            // Fallback to process check
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" 2>NUL | findstr "queue:work"');
            } else {
                $output = shell_exec('pgrep -f "queue:work" 2>/dev/null');
            }
            
            return !empty(trim($output));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Increment processed job counter
     */
    public function incrementProcessed(): JsonResponse
    {
        try {
            $current = Cache::get('queue_stats_processed_count', 0);
            Cache::put('queue_stats_processed_count', $current + 1, now()->addDays(30));

            // Also increment 24h and week counters
            Cache::increment('queue_processed_24h', 1);
            Cache::increment('queue_processed_week', 1);

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
