<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class FixFailedQueues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:fix
                            {--clear : Clear all failed jobs instead of retrying}
                            {--queue= : Only fix specific queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix failed queue jobs by retrying or clearing them';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $clear = $this->option('clear');
        $queue = $this->option('queue');

        $this->info('🔧 Fixing Failed Queue Jobs...');
        $this->newLine();

        // Get count of failed jobs
        $query = DB::table('failed_jobs');
        if ($queue) {
            $query->where('queue', $queue);
        }
        $failedCount = $query->count();

        if ($failedCount === 0) {
            $this->info('✅ No failed jobs to fix!');
            return 0;
        }

        $this->warn("Found {$failedCount} failed job(s)" . ($queue ? " in queue '{$queue}'" : ''));
        $this->newLine();

        if ($clear) {
            if ($this->confirm('Are you sure you want to DELETE all failed jobs?', false)) {
                if ($queue) {
                    DB::table('failed_jobs')->where('queue', $queue)->delete();
                    $this->info("✅ Cleared all failed jobs from queue '{$queue}'");
                } else {
                    Artisan::call('queue:flush');
                    $this->info('✅ Cleared all failed jobs');
                }
            } else {
                $this->info('❌ Operation cancelled');
            }
        } else {
            if ($this->confirm('Do you want to RETRY all failed jobs?', true)) {
                if ($queue) {
                    // Retry specific queue
                    $failedJobs = DB::table('failed_jobs')
                        ->where('queue', $queue)
                        ->pluck('uuid');
                    
                    foreach ($failedJobs as $uuid) {
                        Artisan::call('queue:retry', ['id' => [$uuid]]);
                    }
                    
                    $this->info("✅ Retrying {$failedCount} job(s) from queue '{$queue}'");
                } else {
                    Artisan::call('queue:retry', ['id' => ['all']]);
                    $this->info("✅ Retrying all {$failedCount} failed job(s)");
                }
                
                $this->newLine();
                $this->info('💡 Jobs have been pushed back to their respective queues');
                $this->info('   Make sure queue workers are running: php artisan queue:work');
            } else {
                $this->info('❌ Operation cancelled');
            }
        }

        return 0;
    }
}
