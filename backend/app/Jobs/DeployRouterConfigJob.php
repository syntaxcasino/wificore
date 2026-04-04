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
 * Deploy Router Configuration Job
 * 
 * Deploys configuration to a single router.
 */
class DeployRouterConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Router $router;
    public string $config;
    public string $configVersion;

    public function __construct(Router $router, string $config, string $configVersion)
    {
        $this->router = $router;
        $this->config = $config;
        $this->configVersion = $configVersion;
    }

    public function handle(): void
    {
        try {
            Log::info('Starting router configuration deployment', [
                'router_id' => $this->router->id,
                'config_version' => $this->configVersion,
            ]);

            $driver = app(\App\Services\RouterDriver\DriverRegistry::class)
                ->getDriverForRouter($this->router);
            
            $success = $driver->applyConfig($this->router, $this->config);

            if ($success) {
                Log::info('Router configuration deployed successfully', [
                    'router_id' => $this->router->id,
                    'config_version' => $this->configVersion,
                ]);
            } else {
                Log::error('Router configuration deployment failed', [
                    'router_id' => $this->router->id,
                    'config_version' => $this->configVersion,
                ]);
                $this->fail('Failed to apply configuration');
            }

        } catch (\Exception $e) {
            Log::error('Exception during router configuration deployment', [
                'router_id' => $this->router->id,
                'config_version' => $this->configVersion,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }
}
