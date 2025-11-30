<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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
    public function handle()
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

        // Pending jobs (currently in queue)
        $pendingJobs = DB::table('jobs')->count();
        $pendingByQueue = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->get();

        // Failed jobs
        $failedJobs = DB::table('failed_jobs')->count();
        $failedByQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->get();

        // Processed jobs (estimated from cache or log analysis)
        $processedCount = $this->getProcessedJobsCount();
        $startTime = Cache::get('queue_stats_start_time', now());
        $duration = now()->diffForHumans($startTime, true);

        // Total jobs
        $totalJobs = $pendingJobs + $failedJobs + $processedCount;

        // Display summary
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Jobs (all time)', $totalJobs],
                ['✅ Processed Successfully', $processedCount],
                ['⏳ Pending in Queue', $pendingJobs],
                ['❌ Failed', $failedJobs],
            ]
        );

        $this->newLine();

        // Success rate
        if ($totalJobs > 0) {
            $successRate = round(($processedCount / $totalJobs) * 100, 2);
            $failureRate = round(($failedJobs / $totalJobs) * 100, 2);
            
            $this->info("📈 Performance Metrics:");
            $this->line("   Success Rate: {$successRate}%");
            $this->line("   Failure Rate: {$failureRate}%");
            $this->line("   Tracking Since: {$startTime->format('Y-m-d H:i:s')} ({$duration})");
            $this->newLine();
        }

        // Pending jobs by queue
        if ($pendingJobs > 0) {
            $this->info('⏳ Pending Jobs by Queue:');
            $this->table(
                ['Queue', 'Count'],
                $pendingByQueue->map(fn($item) => [$item->queue, $item->count])
            );
            $this->newLine();
        }

        // Failed jobs by queue
        if ($failedJobs > 0) {
            $this->warn('❌ Failed Jobs by Queue:');
            $this->table(
                ['Queue', 'Count'],
                $failedByQueue->map(fn($item) => [$item->queue, $item->count])
            );
            $this->newLine();
        }

        // Recent activity (last 24 hours from Laravel log)
        $this->info('📅 Recent Activity (Last 24 Hours):');
        $recentStats = $this->getRecentActivity();
        $this->table(
            ['Period', 'Processed', 'Failed'],
            $recentStats
        );
        $this->newLine();

        // Worker status
        $this->info('👷 Queue Worker Status:');
        $workerRunning = $this->checkWorkerRunning();
        if ($workerRunning) {
            $this->line('   Status: ✅ Running');
        } else {
            $this->error('   Status: ❌ Not Running');
            $this->line('   Start with: php artisan queue:work');
        }
        $this->newLine();

        // Recommendations
        if ($failedJobs > 10) {
            $this->warn('💡 Recommendation: You have many failed jobs. Run: php artisan queue:diagnose-failed');
        }
        if ($pendingJobs > 100) {
            $this->warn('💡 Recommendation: Queue is backing up. Consider adding more workers.');
        }
        if (!$workerRunning) {
            $this->error('💡 Recommendation: Start queue worker: php artisan queue:work');
        }

        return 0;
    }

    /**
     * Get processed jobs count from cache
     */
    private function getProcessedJobsCount(): int
    {
        // Initialize counter if not exists
        if (!Cache::has('queue_stats_start_time')) {
            Cache::forever('queue_stats_start_time', now());
        }

        // Try to get from cache
        $count = Cache::get('queue_stats_processed_count', 0);

        // If zero, try to estimate from log file
        if ($count === 0) {
            $count = $this->estimateProcessedFromLogs();
            if ($count > 0) {
                Cache::put('queue_stats_processed_count', $count, now()->addDays(30));
            }
        }

        return $count;
    }

    /**
     * Estimate processed jobs from log file
     */
    private function estimateProcessedFromLogs(): int
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!file_exists($logPath)) {
            return 0;
        }

        try {
            // Count "Processing:" entries in log (indicates job started)
            $command = "grep -c 'Processing:' " . escapeshellarg($logPath) . " 2>/dev/null || echo 0";
            $count = (int) trim(shell_exec($command));
            
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get recent activity from logs
     */
    private function getRecentActivity(): array
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!file_exists($logPath)) {
            return [
                ['Last 24 hours', 0, 0],
                ['Last 7 days', 0, 0],
            ];
        }

        try {
            // Get log entries from last 24 hours
            $yesterday = now()->subDay()->format('Y-m-d');
            $lastWeek = now()->subWeek()->format('Y-m-d');
            
            // Count processed jobs (simplified - counts log entries)
            $processed24h = $this->countLogEntries($logPath, 'Processing:', $yesterday);
            $processedWeek = $this->countLogEntries($logPath, 'Processing:', $lastWeek);
            
            // Count failed jobs
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

    /**
     * Count log entries matching pattern after date
     */
    private function countLogEntries(string $logPath, string $pattern, string $afterDate): int
    {
        try {
            $command = "grep '{$pattern}' " . escapeshellarg($logPath) . 
                      " | grep -c '{$afterDate}' 2>/dev/null || echo 0";
            return (int) trim(shell_exec($command));
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if queue worker is running
     */
    private function checkWorkerRunning(): bool
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" 2>NUL | findstr "queue:work"');
            } else {
                $output = shell_exec('ps aux | grep "queue:work" | grep -v grep');
            }
            
            return !empty($output);
        } catch (\Exception $e) {
            return false;
        }
    }
}
