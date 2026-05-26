<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QueueMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SystemHealthController extends Controller
{
    /**
     * Get comprehensive system health status
     */
    public function getHealth(): JsonResponse
    {
        try {
            $health = [
                'database' => $this->getDatabaseHealth(),
                'redis' => $this->getRedisHealth(),
                'queue' => $this->getQueueHealth(),
                'disk' => $this->getDiskHealth(),
                'uptime' => $this->getUptimeInfo(),
            ];

            return response()->json($health);
        } catch (\Exception $e) {
            \Log::error('Failed to get system health', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve system health',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get database health metrics
     */
    private function getDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $responseTime = round((microtime(true) - $start) * 1000, 2);

            $connections = DB::select("SELECT count(*) as count FROM pg_stat_activity")[0]->count ?? 0;
            $maxConnections = 100;

            return [
                'status' => 'healthy',
                'connections' => $connections,
                'maxConnections' => $maxConnections,
                'responseTime' => $responseTime,
                'healthPercentage' => min(100, 100 - (($connections / $maxConnections) * 100)),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'connections' => 0,
                'maxConnections' => 100,
                'responseTime' => 0,
                'healthPercentage' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get Redis health metrics
     */
    private function getRedisHealth(): array
    {
        try {
            $redis = Redis::connection('cache');
            $info = $redis->info();

            $memoryUsed = round(($info['used_memory'] ?? 0) / (1024 * 1024), 2);
            $hitRate = Cache::get('redis:hit_rate', 98);

            return [
                'status' => 'healthy',
                'hitRate' => $hitRate,
                'memoryUsed' => $memoryUsed,
                'healthPercentage' => $hitRate,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'warning',
                'hitRate' => 0,
                'memoryUsed' => 0,
                'healthPercentage' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get queue health metrics
     */
    private function getQueueHealth(): array
    {
        try {
            $metrics = app(QueueMetricsService::class)->getRealtimeMetrics();
            $failedJobs = $metrics['failed_jobs'];
            $oldestPendingAge = (int) ($metrics['oldest_pending_job_age_seconds'] ?? 0);

            $status = $failedJobs > 10 ? 'warning' : 'healthy';
            if ($metrics['active_workers'] === 0) {
                $status = 'critical';
            }

            $healthPenalty = min(100, ($failedJobs * 5) + (int) floor($oldestPendingAge / 60));

            return [
                'status' => $status,
                'activeWorkers' => $metrics['active_workers'],
                'pendingJobs' => $metrics['pending_jobs'],
                'processingJobs' => $metrics['processing_jobs'],
                'delayedJobs' => $metrics['delayed_jobs'],
                'failedJobs' => $failedJobs,
                'oldestPendingJobAgeSeconds' => $metrics['oldest_pending_job_age_seconds'],
                'healthPercentage' => max(0, 100 - $healthPenalty),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'activeWorkers' => 0,
                'failedJobs' => 0,
                'healthPercentage' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get disk health metrics
     */
    private function getDiskHealth(): array
    {
        try {
            $total = disk_total_space('/');
            $free = disk_free_space('/');
            $used = $total - $free;
            $usedPercentage = round(($used / $total) * 100, 2);

            return [
                'total' => round($total / (1024 * 1024 * 1024), 2),
                'available' => round($free / (1024 * 1024 * 1024), 2),
                'usedPercentage' => $usedPercentage,
            ];
        } catch (\Exception $e) {
            return [
                'total' => 500,
                'available' => 375,
                'usedPercentage' => 25,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get system uptime information
     */
    private function getUptimeInfo(): array
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('systeminfo | findstr /C:"System Boot Time"');
                if ($output && preg_match('/:\s+(.+)/', $output, $matches)) {
                    $bootTime = strtotime(trim($matches[1]));
                    $uptimeSeconds = time() - $bootTime;
                } else {
                    $uptimeSeconds = 0;
                }
            } else {
                $uptime = @file_get_contents('/proc/uptime');
                if ($uptime) {
                    $uptimeSeconds = (int) explode(' ', $uptime)[0];
                } else {
                    $output = shell_exec('uptime -s 2>/dev/null');
                    if ($output) {
                        $bootTime = strtotime(trim($output));
                        $uptimeSeconds = time() - $bootTime;
                    } else {
                        $uptimeSeconds = 0;
                    }
                }
            }

            $days = floor($uptimeSeconds / 86400);
            $hours = floor(($uptimeSeconds % 86400) / 3600);
            $minutes = floor(($uptimeSeconds % 3600) / 60);

            if ($days > 0) {
                $duration = "{$days} day" . ($days > 1 ? 's' : '');
                if ($hours > 0) {
                    $duration .= ", {$hours} hour" . ($hours > 1 ? 's' : '');
                }
            } elseif ($hours > 0) {
                $duration = "{$hours} hour" . ($hours > 1 ? 's' : '');
                if ($minutes > 0) {
                    $duration .= ", {$minutes} minute" . ($minutes > 1 ? 's' : '');
                }
            } else {
                $duration = "{$minutes} minute" . ($minutes > 1 ? 's' : '');
            }

            $totalSeconds = 30 * 86400;
            $percentage = min(99.99, ($uptimeSeconds / $totalSeconds) * 100);
            $lastRestart = date('Y-m-d H:i:s', time() - $uptimeSeconds);

            return [
                'percentage' => round($percentage, 2),
                'duration' => $duration,
                'lastRestart' => $lastRestart,
                'uptimeSeconds' => $uptimeSeconds,
            ];
        } catch (\Exception $e) {
            \Log::error('Failed to get uptime info', ['error' => $e->getMessage()]);
            return [
                'percentage' => 0,
                'duration' => 'Unknown',
                'lastRestart' => 'Unknown',
                'uptimeSeconds' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
}
