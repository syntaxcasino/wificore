<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\UpdateDashboardStatsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class TestDashboardJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:dashboard-job
                            {--sync : Run synchronously instead of queuing}
                            {--clear-cache : Clear dashboard cache before running}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the UpdateDashboardStatsJob to ensure it runs without errors';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧪 Testing UpdateDashboardStatsJob...');
        $this->newLine();

        // Check prerequisites
        $this->info('📋 Checking Prerequisites:');
        
        // 1. Check database connection
        try {
            DB::connection()->getPdo();
            $this->info('  ✅ Database connection: OK');
        } catch (\Exception $e) {
            $this->error('  ❌ Database connection: FAILED');
            $this->error('     ' . $e->getMessage());
            return 1;
        }

        // 2. Check required tables
        $requiredTables = ['routers', 'users', 'payments', 'user_sessions', 'jobs', 'failed_jobs'];
        foreach ($requiredTables as $table) {
            try {
                DB::table($table)->limit(1)->get();
                $this->info("  ✅ Table '{$table}': EXISTS");
            } catch (\Exception $e) {
                $this->error("  ❌ Table '{$table}': MISSING");
                $this->error('     Run: php artisan migrate');
                return 1;
            }
        }

        // 3. Check Redis (optional)
        try {
            Cache::store('redis')->get('test');
            $this->info('  ✅ Redis connection: OK');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  Redis connection: NOT AVAILABLE (using file cache)');
        }

        $this->newLine();

        // Clear cache if requested
        if ($this->option('clear-cache')) {
            Cache::forget('dashboard_stats');
            $this->info('🗑️  Cleared dashboard cache');
            $this->newLine();
        }

        // Run the job
        $this->info('🚀 Running UpdateDashboardStatsJob...');
        $startTime = microtime(true);

        try {
            if ($this->option('sync')) {
                // Run synchronously
                $this->info('   Mode: Synchronous (direct execution)');
                $job = new UpdateDashboardStatsJob();
                $job->handle();
                $this->info('  ✅ Job executed successfully!');
            } else {
                // Queue the job
                $this->info('   Mode: Queued (will be processed by worker)');
                UpdateDashboardStatsJob::dispatch()->onQueue('dashboard');
                $this->info('  ✅ Job queued successfully!');
                $this->warn('     Make sure queue worker is running: php artisan queue:work');
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("   Duration: {$duration}ms");
        } catch (\Exception $e) {
            $this->error('  ❌ Job FAILED!');
            $this->error('     Error: ' . $e->getMessage());
            $this->error('     File: ' . $e->getFile() . ':' . $e->getLine());
            $this->newLine();
            $this->error('Stack Trace:');
            $this->line($e->getTraceAsString());
            return 1;
        }

        $this->newLine();

        // Check results
        $this->info('📊 Checking Results:');
        $stats = Cache::get('dashboard_stats');

        if ($stats) {
            $this->info('  ✅ Dashboard stats cached successfully');
            $this->newLine();
            $this->info('  Sample Data:');
            $this->line('    Total Routers: ' . ($stats['total_routers'] ?? 'N/A'));
            $this->line('    Online Routers: ' . ($stats['online_routers'] ?? 'N/A'));
            $this->line('    Active Sessions: ' . ($stats['active_sessions'] ?? 'N/A'));
            $this->line('    Total Revenue: ' . ($stats['total_revenue'] ?? 'N/A'));
            
            if (isset($stats['payment_details'])) {
                $this->info('    ✅ Payment details: PRESENT');
            }
            if (isset($stats['sms_expenses'])) {
                $this->info('    ✅ SMS expenses: PRESENT');
            }
            if (isset($stats['business_analytics'])) {
                $this->info('    ✅ Business analytics: PRESENT');
                $this->line('       Access Points: ' . count($stats['business_analytics']['accessPoints'] ?? []));
            }
        } else {
            $this->warn('  ⚠️  Dashboard stats not found in cache');
            if (!$this->option('sync')) {
                $this->info('     This is normal for queued jobs - check after worker processes it');
            }
        }

        $this->newLine();
        $this->info('✨ Test completed!');
        
        if (!$this->option('sync')) {
            $this->newLine();
            $this->info('💡 Next Steps:');
            $this->line('   1. Start queue worker: php artisan queue:work');
            $this->line('   2. Monitor logs: tail -f storage/logs/laravel.log');
            $this->line('   3. Check failed jobs: php artisan queue:diagnose-failed');
        }

        return 0;
    }
}
