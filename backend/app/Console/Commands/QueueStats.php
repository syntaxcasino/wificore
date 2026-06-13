<?php

namespace App\Console\Commands;

use App\Services\QueueMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QueueStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:stats
                            {--reset : Reset the processed jobs counter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show queue statistics including processed, pending, and failed jobs';

    /**
     * Execute the console command.
     */
    public function handle(QueueMetricsService $queueMetrics): int
    {
        $this->info('📊 Queue Statistics');
        $this->info('==================');
        $this->newLine();

        if ($this->option('reset')) {
            Cache::forget('queue_stats_processed_count');
            Cache::forget('queue_stats_start_time');
            $this->info('✅ Statistics reset');
            $this->newLine();
        }

        $metrics = $queueMetrics->getRealtimeMetrics();
        $pendingJobs = $metrics['pending_jobs'];
        $processingJobs = $metrics['processing_jobs'];
        $delayedJobs = $metrics['delayed_jobs'];
        $failedJobs = $metrics['failed_jobs'];

        $processedCount = $this->getProcessedJobsCount();
        $startTime = Cache::get('queue_stats_start_time', now());
        $duration = now()->diffForHumans($startTime, true);
        $totalJobs = $pendingJobs + $failedJobs + $processedCount;

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Jobs (all time)', $totalJobs],
                ['✅ Processed Successfully', $processedCount],
                ['⏳ Pending in Queue', $pendingJobs],
                ['🔄 Reserved / Processing', $processingJobs],
                ['🕒 Delayed', $delayedJobs],
                ['❌ Failed', $failedJobs],
            ]
        );

        $this->newLine();

        if ($totalJobs > 0) {
            $successRate = round(($processedCount / $totalJobs) * 100, 2);
            $failureRate = round(($failedJobs / $totalJobs) * 100, 2);

            $this->info('📈 Performance Metrics:');
            $this->line("   Success Rate: {$successRate}%");
            $this->line("   Failure Rate: {$failureRate}%");
            $this->line("   Tracking Since: {$startTime->format('Y-m-d H:i:s')} ({$duration})");
            $this->newLine();
        }

        if ($pendingJobs > 0 || $processingJobs > 0 || $delayedJobs > 0 || $failedJobs > 0) {
            $this->info('⏳ Queue Backlog by Queue:');
            $rows = [];
            foreach ($metrics['configured_queues'] as $queue) {
                $rows[] = [
                    $queue,
                    $metrics['pending_by_queue'][$queue] ?? 0,
                    $metrics['processing_by_queue'][$queue] ?? 0,
                    $metrics['delayed_by_queue'][$queue] ?? 0,
                    $metrics['failed_by_queue'][$queue] ?? 0,
                    $metrics['oldest_pending_age_by_queue'][$queue] ?? 'N/A',
                    $metrics['workers_by_queue'][$queue] ?? 0,
                    $metrics['configured_workers_by_queue'][$queue] ?? 0,
                ];
            }

            $this->table(
                ['Queue', 'Pending', 'Reserved', 'Delayed', 'Failed', 'Oldest Pending Age (s)', 'Workers', 'Configured'],
                $rows
            );
            $this->newLine();
        }

        $this->info('📅 Recent Activity (Last 24 Hours):');
        $this->table(
            ['Period', 'Processed', 'Failed'],
            $this->getRecentActivity()
        );
        $this->newLine();

        $workerRunning = ! empty($metrics['workers_by_queue']);
        $this->info('👷 Queue Worker Status:');
        if ($workerRunning) {
            $this->line('   Status: ✅ Running');
        } else {
            $this->error('   Status: ❌ Not Running');
            $this->line('   Start with: php artisan queue:work');
        }
        $this->newLine();

        if ($failedJobs > 10) {
            $this->warn('💡 Recommendation: You have many failed jobs. Run: php artisan queue:diagnose-failed');
        }
        if ($pendingJobs > 100) {
            $this->warn('💡 Recommendation: Queue is backing up. Consider adding more workers.');
        }
        if (! $workerRunning) {
            $this->error('💡 Recommendation: Start queue worker: php artisan queue:work');
        }

        return 0;
    }

    private function getProcessedJobsCount(): int
    {
        if (! Cache::has('queue_stats_start_time')) {
            Cache::put('queue_stats_start_time', now(), 30);
        }

        $count = Cache::get('queue_stats_processed_count', 0);

        if ($count === 0) {
            $count = $this->estimateProcessedFromLogs();
            if ($count > 0) {
                Cache::put('queue_stats_processed_count', $count, 30);
            }
        }

        return $count;
    }

    private function estimateProcessedFromLogs(): int
    {
        $logPath = storage_path('logs/laravel.log');

        if (! file_exists($logPath)) {
            return 0;
        }

        try {
            $command = "grep -c 'Processing:' " . escapeshellarg($logPath) . " 2>/dev/null || echo 0";
            return (int) trim(shell_exec($command));
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getRecentActivity(): array
    {
        $logPath = storage_path('logs/laravel.log');

        if (! file_exists($logPath)) {
            return [
                ['Last 24 hours', 0, 0],
                ['Last 7 days', 0, 0],
            ];
        }

        try {
            $yesterday = now()->subDay()->format('Y-m-d');
            $lastWeek = now()->subWeek()->format('Y-m-d');

            $processed24h = $this->countLogEntries($logPath, 'Processing:', $yesterday);
            $processedWeek = $this->countLogEntries($logPath, 'Processing:', $lastWeek);
            $failed24h = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
            $failedWeek = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subWeek())
                ->count();

            return [
                ['Last 24 hours', $processed24h, $failed24h],
                ['Last 7 days', $processedWeek, $failedWeek],
            ];
        } catch (\Exception $e) {
            return [
                ['Last 24 hours', 'N/A', 'N/A'],
                ['Last 7 days', 'N/A', 'N/A'],
            ];
        }
    }

    private function countLogEntries(string $logPath, string $pattern, string $afterDate): int
    {
        try {
            $command = "grep '{$pattern}' " . escapeshellarg($logPath) . " | grep -c '{$afterDate}' 2>/dev/null || echo 0";
            return (int) trim(shell_exec($command));
        } catch (\Exception $e) {
            return 0;
        }
    }
}
