<?php

namespace App\Console\Commands;

use App\Services\CacheService;
use Illuminate\Console\Command;

class CacheWarmUp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:warmup
                            {--force : Force cache warmup even if cache exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm up application cache with frequently accessed data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cache warmup...');

        try {
            if ($this->option('force')) {
                $this->warn('Force flag detected - clearing existing cache first');
                CacheService::clearAll();
            }

            $warmed = CacheService::warmUp();

            $this->info('Cache warmup completed successfully!');
            $this->table(
                ['Item', 'Status'],
                array_map(fn($item) => [$item, '✓ Cached'], $warmed)
            );

            // Show cache stats
            $stats = CacheService::getStats();
            $this->newLine();
            $this->info('Cache Statistics:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Keys', $stats['keys']],
                    ['Memory Used', $stats['memory_used']],
                    ['Hit Rate', $stats['hit_rate'] . '%'],
                    ['Ops/sec', $stats['ops_per_sec']],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to warm up cache: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
