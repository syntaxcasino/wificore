<?php

namespace App\Console\Commands;

use App\Services\CacheService;
use Illuminate\Console\Command;

class CacheStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display cache statistics and health information';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching cache statistics...');
        $this->newLine();

        try {
            $health = CacheService::getHealth();
            $stats = $health['stats'];

            // Display health status
            $statusColor = $health['status'] === 'healthy' ? 'info' : 'warn';
            $this->{$statusColor}('Status: ' . strtoupper($health['status']));
            $this->line($health['message']);
            $this->newLine();

            // Display detailed stats
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Keys', $stats['keys']],
                    ['Memory Used', $stats['memory_used']],
                    ['Memory Peak', $stats['memory_peak']],
                    ['Hit Rate', $stats['hit_rate'] . '%'],
                    ['Operations/sec', $stats['ops_per_sec']],
                    ['Connected Clients', $stats['connected_clients']],
                    ['Uptime', gmdate('H:i:s', $stats['uptime'])],
                ]
            );

            // Recommendations
            $this->newLine();
            if ($stats['keys'] === 0) {
                $this->warn('⚠ Cache is empty. Consider running: php artisan cache:warmup');
            } elseif ($stats['hit_rate'] < 50) {
                $this->warn('⚠ Low hit rate detected. Cache may need optimization.');
            } else {
                $this->info('✓ Cache is performing well!');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to fetch cache stats: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
