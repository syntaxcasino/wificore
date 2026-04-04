<?php

namespace App\Jobs;

use App\Models\CanaryDeployment;
use App\Services\Deployment\CanaryDeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Canary Health Check Job
 * 
 * Periodic health check for canary deployments with auto-promote/rollback.
 */
class CanaryHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public CanaryDeployment $deployment;

    public function __construct(CanaryDeployment $deployment)
    {
        $this->deployment = $deployment;
    }

    public function handle(CanaryDeploymentService $service): void
    {
        // Skip if deployment is no longer running
        if (!$this->deployment->isCanaryRunning()) {
            Log::info('Canary deployment no longer running, skipping health check', [
                'deployment_id' => $this->deployment->id,
                'status' => $this->deployment->status,
            ]);
            return;
        }

        try {
            $action = $service->autoEvaluate($this->deployment);

            Log::info('Canary health check completed', [
                'deployment_id' => $this->deployment->id,
                'action' => $action,
                'health_score' => $this->deployment->health_score,
            ]);

            // Schedule next check if still monitoring
            if ($action === 'continue_monitoring') {
                dispatch(new self($this->deployment))
                    ->delay(now()->addSeconds($this->deployment->health_check_interval));
            }

        } catch (\Exception $e) {
            Log::error('Health check failed', [
                'deployment_id' => $this->deployment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
