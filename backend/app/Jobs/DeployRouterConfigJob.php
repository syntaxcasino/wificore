<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\Deployment\DeploymentSafetyService;
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
    public bool $allowSnapshotExemption;

    public function __construct(Router $router, string $config, string $configVersion, bool $allowSnapshotExemption = false)
    {
        $this->router = $router;
        $this->config = $config;
        $this->configVersion = $configVersion;
        $this->allowSnapshotExemption = $allowSnapshotExemption;
    }

    public function handle(DeploymentSafetyService $deploymentSafetyService): void
    {
        try {
            Log::info('Starting router configuration deployment', [
                'router_id' => $this->router->id,
                'config_version' => $this->configVersion,
            ]);

            $result = $deploymentSafetyService->deployWithSafety($this->router, $this->config, [
                'allow_snapshot_exemption' => $this->allowSnapshotExemption,
            ]);

            if ($result->success) {
                Log::info('Router configuration deployed successfully', [
                    'router_id' => $this->router->id,
                    'config_version' => $this->configVersion,
                    'deployment_safety' => $result->toArray(),
                ]);
            } else {
                Log::error('Router configuration deployment failed', [
                    'router_id' => $this->router->id,
                    'config_version' => $this->configVersion,
                    'deployment_safety' => $result->toArray(),
                ]);

                $this->fail($result->message ?? 'Failed to apply configuration safely');
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
