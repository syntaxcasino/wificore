<?php

namespace App\Jobs;

use App\Models\ProvisioningRun;
use App\Models\Router;
use App\Services\ProvisioningRunAuditService;
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
    public ?string $provisioningRunId;

    public function __construct(Router $router, string $config, ?string $provisioningRunId = null)
    {
        $this->router = $router;
        $this->config = $config;
        $this->provisioningRunId = $provisioningRunId;
    }

    public function handle(): void
    {
        try {
            Log::info('Starting configuration rollback', [
                'router_id' => $this->router->id,
                'router_name' => $this->router->name,
                'provisioning_run_id' => $this->provisioningRunId,
            ]);

            $driver = app(\App\Services\RouterDriver\DriverRegistry::class)
                ->getDriverForRouter($this->router);

            $success = $driver->restoreConfig($this->router, $this->config);

            if ($success) {
                Log::info('Configuration rollback successful', [
                    'router_id' => $this->router->id,
                    'provisioning_run_id' => $this->provisioningRunId,
                ]);
                $this->logRollbackOutcome(true, null);
            } else {
                Log::error('Configuration rollback failed', [
                    'router_id' => $this->router->id,
                    'provisioning_run_id' => $this->provisioningRunId,
                ]);
                $this->logRollbackOutcome(false, 'Failed to restore configuration');
                $this->fail('Failed to restore configuration');
            }
        } catch (\Exception $e) {
            Log::error('Exception during configuration rollback', [
                'router_id' => $this->router->id,
                'error' => $e->getMessage(),
                'provisioning_run_id' => $this->provisioningRunId,
            ]);
            $this->logRollbackOutcome(false, $e->getMessage());
            $this->fail($e);
        }
    }

    private function logRollbackOutcome(bool $success, ?string $errorMessage): void
    {
        if (! $this->provisioningRunId) {
            return;
        }

        $run = ProvisioningRun::find($this->provisioningRunId);
        if (! $run) {
            return;
        }

        $audit = app(ProvisioningRunAuditService::class);
        $audit->logStep($run, [
            'stage' => 'rollback',
            'action' => 'execute_rollback',
            'status' => $success ? 'completed' : 'failed',
            'response_payload' => [
                'router_id' => $this->router->id,
                'result' => $success ? 'rolled_back' : 'rollback_failed',
            ],
            'error_message' => $errorMessage,
            'is_terminal' => true,
            'completed_at' => now(),
        ]);

        if ($success) {
            $audit->updateRun(
                $run,
                ProvisioningRun::STATUS_ROLLED_BACK,
                100,
                'rolled_back',
                null
            );
            return;
        }

        $audit->updateRun(
            $run,
            ProvisioningRun::STATUS_FAILED,
            (int) $run->progress,
            'rollback_failed',
            $errorMessage
        );
    }
}
