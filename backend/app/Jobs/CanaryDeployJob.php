<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\CanaryDeployment;
use App\Services\Deployment\DeploymentSafetyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Canary Deployment Job
 *
 * Deploys configuration to a single router as part of canary deployment.
 */
class CanaryDeployJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public CanaryDeployment $deployment;
    public Router $router;
    public string $config;

    public function __construct(CanaryDeployment $deployment, Router $router, string $config)
    {
        $this->deployment = $deployment;
        $this->router = $router;
        $this->config = $config;
    }

    public function handle(DeploymentSafetyService $deploymentSafetyService): void
    {
        try {
            Log::info('Starting canary deployment for router', [
                'deployment_id' => $this->deployment->id,
                'router_id' => $this->router->id,
            ]);

            $result = $deploymentSafetyService->deployWithSafety($this->router, $this->config, [
                'allow_snapshot_exemption' => true,
            ]);

            if ($result->success) {
                Log::info('Canary deployment successful for router', [
                    'deployment_id' => $this->deployment->id,
                    'router_id' => $this->router->id,
                    'deployment_safety' => $result->toArray(),
                ]);
            } else {
                Log::error('Canary deployment failed for router', [
                    'deployment_id' => $this->deployment->id,
                    'router_id' => $this->router->id,
                    'deployment_safety' => $result->toArray(),
                ]);

                $this->fail($result->message ?? 'Failed to apply configuration safely');
            }

        } catch (\Exception $e) {
            Log::error('Exception in canary deployment job', [
                'deployment_id' => $this->deployment->id,
                'router_id' => $this->router->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }
}
