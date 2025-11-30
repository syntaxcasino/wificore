<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\CollectSystemMetricsJob;
use App\Services\MetricsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TestMetricsCollection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test metrics collection and display current metrics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('========================================');
        $this->info('Testing Metrics Collection System');
        $this->info('========================================');
        $this->newLine();

        // Step 1: Check database tables
        $this->info('[1/5] Checking Database Tables...');
        $this->checkDatabaseTables();
        $this->newLine();

        // Step 2: Check cache
        $this->info('[2/5] Checking Cache Keys...');
        $this->checkCacheKeys();
        $this->newLine();

        // Step 3: Collect metrics
        $this->info('[3/5] Collecting Metrics...');
        $this->collectMetrics();
        $this->newLine();

        // Step 4: Store metrics
        $this->info('[4/5] Storing Performance Metrics...');
        $this->storePerformanceMetrics();
        $this->newLine();

        // Step 5: Display results
        $this->info('[5/5] Displaying Current Metrics...');
        $this->displayMetrics();
        $this->newLine();

        $this->info('========================================');
        $this->info('Test Complete!');
        $this->info('========================================');

        return Command::SUCCESS;
    }

    private function checkDatabaseTables()
    {
        $tables = [
            'performance_metrics',
            'queue_metrics',
            'system_health_metrics'
        ];

        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    $this->line("  ✓ Table '$table' has $count rows", 'info');
                } else {
                    $this->line("  ✗ Table '$table' is EMPTY", 'error');
                }
            } catch (\Exception $e) {
                $this->line("  ✗ Table '$table' check failed: " . $e->getMessage(), 'error');
            }
        }
    }

    private function checkCacheKeys()
    {
        $cacheKeys = [
            'metrics:queue:latest' => 'Queue Metrics',
            'metrics:health:latest' => 'Health Metrics',
            'metrics:performance:latest' => 'Performance Metrics',
            'metrics:tps' => 'TPS (Current)',
            'metrics:tps:history' => 'TPS History'
        ];

        foreach ($cacheKeys as $key => $label) {
            if (Cache::has($key)) {
                $this->line("  ✓ $label ($key) exists", 'info');
            } else {
                $this->line("  ✗ $label ($key) is MISSING", 'error');
            }
        }
    }

    private function collectMetrics()
    {
        try {
            $this->line('  Dispatching CollectSystemMetricsJob...', 'comment');
            
            // Dispatch the job
            $job = new CollectSystemMetricsJob();
            $job->handle();
            
            $this->line('  ✓ Metrics collected successfully', 'info');
        } catch (\Exception $e) {
            $this->line('  ✗ Failed to collect metrics: ' . $e->getMessage(), 'error');
            $this->line('  Stack trace: ' . $e->getTraceAsString(), 'error');
        }
    }

    private function storePerformanceMetrics()
    {
        try {
            MetricsService::storeMetrics();
            $this->line('  ✓ Performance metrics stored successfully', 'info');
        } catch (\Exception $e) {
            $this->line('  ✗ Failed to store metrics: ' . $e->getMessage(), 'error');
        }
    }

    private function displayMetrics()
    {
        // Display Performance Metrics
        $this->line('  Performance Metrics:', 'comment');
        try {
            $perfMetrics = MetricsService::getPerformanceMetrics();
            $this->line('    TPS Current: ' . $perfMetrics['tps']['current']);
            $this->line('    TPS Average: ' . $perfMetrics['tps']['average']);
            $this->line('    OPS Current: ' . $perfMetrics['ops']['current']);
            $this->line('    DB Connections: ' . $perfMetrics['database']['active_connections']);
        } catch (\Exception $e) {
            $this->line('    Error: ' . $e->getMessage(), 'error');
        }

        $this->newLine();

        // Display Queue Metrics
        $this->line('  Queue Metrics:', 'comment');
        $queueMetrics = Cache::get('metrics:queue:latest');
        if ($queueMetrics) {
            $this->line('    Pending Jobs: ' . ($queueMetrics['pending_jobs'] ?? 0));
            $this->line('    Processing Jobs: ' . ($queueMetrics['processing_jobs'] ?? 0));
            $this->line('    Failed Jobs: ' . ($queueMetrics['failed_jobs'] ?? 0));
            $this->line('    Active Workers: ' . ($queueMetrics['active_workers'] ?? 0));
        } else {
            $this->line('    No queue metrics in cache', 'error');
        }

        $this->newLine();

        // Display Health Metrics
        $this->line('  System Health Metrics:', 'comment');
        $healthMetrics = Cache::get('metrics:health:latest');
        if ($healthMetrics) {
            $this->line('    DB Connections: ' . ($healthMetrics['db_connections'] ?? 0));
            $this->line('    Redis Hit Rate: ' . ($healthMetrics['redis_hit_rate'] ?? 0) . '%');
            $this->line('    Disk Used: ' . ($healthMetrics['disk_used_percentage'] ?? 0) . '%');
            $this->line('    Uptime: ' . ($healthMetrics['uptime_percentage'] ?? 0) . '%');
        } else {
            $this->line('    No health metrics in cache', 'error');
        }

        $this->newLine();

        // Display Database Counts
        $this->line('  Database Row Counts:', 'comment');
        try {
            $perfCount = DB::table('performance_metrics')->count();
            $queueCount = DB::table('queue_metrics')->count();
            $healthCount = DB::table('system_health_metrics')->count();
            
            $this->line('    performance_metrics: ' . $perfCount . ' rows');
            $this->line('    queue_metrics: ' . $queueCount . ' rows');
            $this->line('    system_health_metrics: ' . $healthCount . ' rows');
        } catch (\Exception $e) {
            $this->line('    Error: ' . $e->getMessage(), 'error');
        }
    }
}
