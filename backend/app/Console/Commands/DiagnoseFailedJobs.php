<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseFailedJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:diagnose-failed 
                            {--queue= : Filter by specific queue}
                            {--limit=10 : Number of failed jobs to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose failed queue jobs and show detailed error information';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $queue = $this->option('queue');
        $limit = $this->option('limit');

        $this->info('🔍 Diagnosing Failed Queue Jobs...');
        $this->newLine();

        // Get failed jobs count by queue
        $failedByQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->get();

        if ($failedByQueue->isEmpty()) {
            $this->info('✅ No failed jobs found!');
            return 0;
        }

        $this->info('📊 Failed Jobs by Queue:');
        $this->table(
            ['Queue', 'Failed Count'],
            $failedByQueue->map(fn($item) => [$item->queue, $item->count])
        );
        $this->newLine();

        // Get recent failed jobs
        $query = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit($limit);

        if ($queue) {
            $query->where('queue', $queue);
        }

        $failedJobs = $query->get();

        $this->info("📋 Recent Failed Jobs (showing {$failedJobs->count()}):");
        $this->newLine();

        foreach ($failedJobs as $index => $job) {
            $jobNumber = $index + 1;
            $this->warn("Job #{$jobNumber}");
            $this->line("  Queue: {$job->queue}");
            $this->line("  Connection: {$job->connection}");
            $this->line("  Failed At: {$job->failed_at}");
            
            // Extract job class name from payload
            $payload = json_decode($job->payload, true);
            $jobClass = $payload['displayName'] ?? 'Unknown';
            $this->line("  Job Class: {$jobClass}");
            
            // Show first 200 characters of exception
            $exceptionPreview = substr($job->exception, 0, 200);
            $this->error("  Error: {$exceptionPreview}...");
            
            // Try to extract the actual error message
            if (preg_match('/: (.+?)\n/', $job->exception, $matches)) {
                $this->error("  Message: {$matches[1]}");
            }
            
            $this->newLine();
        }

        // Provide recommendations
        $this->info('💡 Recommendations:');
        
        foreach ($failedByQueue as $item) {
            if ($item->queue === 'dashboard') {
                $this->line("  • Dashboard queue: Check UpdateDashboardStatsJob for errors");
                $this->line("    Run: php artisan queue:retry --queue=dashboard");
            } elseif ($item->queue === 'default') {
                $this->line("  • Default queue: Check general job configurations");
                $this->line("    Run: php artisan queue:retry --queue=default");
            }
        }

        $this->newLine();
        $this->info('🔧 To retry all failed jobs: php artisan queue:retry all');
        $this->info('🗑️  To clear failed jobs: php artisan queue:flush');
        $this->info('📝 To view full exception: php artisan queue:failed');

        return 0;
    }
}
