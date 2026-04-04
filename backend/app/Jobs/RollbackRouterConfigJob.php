<?php

namespace App\Jobs;

use App\Models\Router;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rollback Router Configuration Job
 * 
 * Restores router to previous configuration snapshot.
 */
class RollbackRouterConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Router $router;
    public string $config;

    public function __construct(Router $router, string $config)
    {
        $this->router = $router;
        $this->config = $config;
    }

    public function handle(): void
    {
        try {
            Log::info('Starting configuration rollback', [
                'router_id' => $this->router->id,
                'router_name' => $this->router->name,
            ]);

            // Apply the backup configuration
            $driver = app(\App\Services\RouterDriver\DriverRegistry::class)
                ->getDriverForRouter($this->router);
            
            $success = $driver->restoreConfig($this->router, $this->config);

            if ($success) {
                Log::info('Configuration rollback successful', [
                    'router_id' => $this->router->id,
                ]);
            } else {
                Log::error('Configuration rollback failed', [
                    'router_id' => $this->router->id,
                ]);
                $this->fail('Failed to restore configuration');
            }

        } catch (\Exception $e) {
            Log::error('Exception during configuration rollback', [
                'router_id' => $this->router->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }
}
