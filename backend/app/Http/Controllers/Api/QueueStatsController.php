<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QueueMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QueueStatsController extends Controller
{
    public function index(QueueMetricsService $queueMetrics): JsonResponse
    {
        try {
            $metrics = $queueMetrics->getRealtimeMetrics();
            $processedCount = $this->getProcessedJobsCount();
            $totalJobs = $metrics['pending_jobs'] + $metrics['failed_jobs'] + $processedCount;
            $workerRunning = ! empty($metrics['workers_by_queue']);
            $status = 'healthy';

            if ($metrics['failed_jobs'] > 50) {
                $status = 'critical';
            } elseif ($metrics['failed_jobs'] > 10 || ! $workerRunning) {
                $status = 'warning';
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'total_jobs' => $totalJobs,
                        'processed' => $processedCount,
                        'pending' => $metrics['pending_jobs'],
                        'processing' => $metrics['processing_jobs'],
                        'delayed' => $metrics['delayed_jobs'],
                        'failed' => $metrics['failed_jobs'],
                        'oldest_pending_job_age_seconds' => $metrics['oldest_pending_job_age_seconds'],
                        'success_rate' => $totalJobs > 0 ? round(($processedCount / $totalJobs) * 100, 2) : 0,
                        'failure_rate' => $totalJobs > 0 ? round(($metrics['failed_jobs'] / $totalJobs) * 100, 2) : 0,
                    ],
                    'configured_queues' => $metrics['configured_queues'],
                    'pending_by_queue' => $metrics['pending_by_queue'],
                    'processing_by_queue' => $metrics['processing_by_queue'],
                    'delayed_by_queue' => $metrics['delayed_by_queue'],
                    'failed_by_queue' => $metrics['failed_by_queue'],
                    'oldest_pending_age_by_queue' => $metrics['oldest_pending_age_by_queue'],
                    'worker_status' => [
                        'running' => $workerRunning,
                        'status' => $workerRunning ? 'active' : 'stopped',
                        'workers_by_queue' => $metrics['workers_by_queue'],
                        'configured_workers_by_queue' => $metrics['configured_workers_by_queue'],
                    ],
                    'recent_activity' => $this->getRecentActivity(),
                    'health_status' => $status,
                    'tracking_since' => $this->resolveTrackingStartTime()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch queue statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveTrackingStartTime(): Carbon
    {
        $cached = Cache::get('queue_stats_start_time');

        if ($cached instanceof Carbon) {
            return $cached;
        }

        if ($cached instanceof \DateTimeInterface) {
            return Carbon::instance($cached);
        }

        if (is_numeric($cached)) {
            return Carbon::createFromTimestamp((int) $cached);
        }

        if (is_string($cached) && trim($cached) !== '') {
            try {
                return Carbon::parse($cached);
            } catch (\Throwable) {
            }
        }

        $startTime = now();
        Cache::put('queue_stats_start_time', $startTime, now()->addSeconds(30));

        return $startTime;
    }

    private function getProcessedJobsCount(): int
    {
        if (! Cache::has('queue_stats_start_time')) {
            Cache::put('queue_stats_start_time', now(), now()->addSeconds(30));
        }

        return $this->estimateProcessedFromLogs();
    }

    private function estimateProcessedFromLogs(): int
    {
        $cacheKey = 'queue_processed_jobs_count';
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $logDir = storage_path('logs');
            $command = "grep -h ' DONE' " . escapeshellarg($logDir) . "/*-queue.log 2>/dev/null | wc -l";
            $count = (int) trim(shell_exec($command));

            if ($count == 0) {
                foreach (glob($logDir . '/*-queue.log') as $logFile) {
                    if (file_exists($logFile)) {
                        $count += substr_count((string) file_get_contents($logFile), ' DONE');
                    }
                }
            }

            Cache::put($cacheKey, $count, now()->addSeconds(30));
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getRecentActivity(): array
    {
        try {
            $failed24h = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
            $failedWeek = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subWeek())
                ->count();

            return [
                'last_24_hours' => [
                    'processed' => $this->countRecentProcessedJobs(1),
                    'failed' => $failed24h,
                ],
                'last_7_days' => [
                    'processed' => $this->countRecentProcessedJobs(7),
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

    private function countRecentProcessedJobs(int $days): int
    {
        $cacheKey = "queue_processed_last_{$days}_days";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $logDir = storage_path('logs');
            $count = 0;
            $cutoffDate = now()->subDays($days)->format('Y-m-d');
            $command = "grep -h ' DONE' " . escapeshellarg($logDir) . "/*-queue.log 2>/dev/null | grep -c '" . $cutoffDate . "'";
            $shellCount = (int) trim(shell_exec($command));

            if ($shellCount > 0) {
                $count = $shellCount;
            } else {
                foreach (glob($logDir . '/*-queue.log') as $logFile) {
                    if (! file_exists($logFile)) {
                        continue;
                    }

                    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    foreach ($lines as $line) {
                        if (strpos($line, ' DONE') === false) {
                            continue;
                        }

                        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $line, $matches) && $matches[1] >= $cutoffDate) {
                            $count++;
                        }
                    }
                }
            }

            Cache::put($cacheKey, $count, now()->addSeconds(30));
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
