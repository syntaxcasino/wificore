<?php

namespace App\Console\Commands;

use App\Jobs\ComputeRouterMetricsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScheduleRouterMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:schedule-router';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch router metrics computation job for all tenants';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            // Dispatch the job that will process all tenants
            ComputeRouterMetricsJob::dispatch();

            $this->info('Router metrics computation job dispatched successfully.');
            Log::info('Router metrics computation job dispatched via command');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to dispatch metrics job: ' . $e->getMessage());
            Log::error('Failed to dispatch metrics job', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }
}
