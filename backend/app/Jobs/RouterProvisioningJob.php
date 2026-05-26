<?php

namespace App\Jobs;

use App\Events\RouterProvisioningProgress;
use App\Models\Router;
use App\Models\RouterTask;
use App\Services\RouterTaskExecutionService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RouterProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use TenantAwareJob;

    public string $routerId;
    public array $provisioningData;
    public ?string $routerTaskId = null;

    public int $tries = 5;
    public int $timeout = 120;
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(string $routerId, string $tenantId, array $provisioningData, ?string $routerTaskId = null)
    {
        $this->routerId = $routerId;
        $this->setTenantContext($tenantId);
        $this->provisioningData = $provisioningData;
        $this->routerTaskId = $routerTaskId;
        $this->onQueue('router-provisioning');
    }

    public function handle(RouterTaskExecutionService $taskExecutionService): void
    {
        $task = $this->routerTaskId ? RouterTask::find($this->routerTaskId) : null;
        if ($task) {
            $task->markRunning(5, 'Submitting router provisioning command to provisioning service');
        }

        $this->executeInTenantContext(function () use ($taskExecutionService, $task) {
            $router = Router::find($this->routerId);
            if (! $router) {
                if ($task) {
                    $task->markFailed('Router not found', 0, 'Router not found');
                }
                Log::error('RouterProvisioningJob: Router not found', [
                    'router_id' => $this->routerId,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }

            try {
                $router->update([
                    'status' => 'provisioning',
                    'provisioning_stage' => 'submitted',
                    'last_checked' => now(),
                ]);

                $this->broadcastProgress($router, 'submitted', 5, 'Submitting provisioning workflow to provisioning service...', [
                    'router_id' => $router->id,
                    'task_id' => $task?->id,
                ]);

                if ($task) {
                    $response = $taskExecutionService->submitTaskCommand($router, $this->tenantId, $task);
                    $task->forceFill([
                        'status' => RouterTask::STATUS_RUNNING,
                        'progress' => 15,
                        'message' => 'Provisioning command accepted by provisioning service',
                        'result_payload' => array_merge((array) $task->result_payload, [
                            'command_submission' => $response['data'] ?? $response,
                        ]),
                        'started_at' => $task->started_at ?? now(),
                        'error_message' => null,
                    ])->save();
                }

                Log::info('Router provisioning command accepted', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                    'task_id' => $task?->id,
                    'service_type' => $this->provisioningData['service_type'] ?? 'unknown',
                ]);
            } catch (\Exception $e) {
                Log::error('Router provisioning command submission failed', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $router->update([
                    'status' => 'failed',
                    'provisioning_stage' => 'failed',
                    'last_checked' => now(),
                ]);

                if ($task && ! in_array(($task->fresh()?->status), [RouterTask::STATUS_COMPLETED, RouterTask::STATUS_FAILED], true)) {
                    $task->markFailed($e->getMessage(), $task->progress, 'Provisioning command submission failed', [
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->broadcastProgress($router, 'failed', 0, 'Provisioning command submission failed: ' . $e->getMessage(), [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    private function broadcastProgress(Router $router, string $stage, float $progress, string $message, array $data = []): void
    {
        broadcast(new RouterProvisioningProgress(
            (string) $router->id,
            $stage,
            $progress,
            $message,
            $data
        ))->toOthers();

        usleep(50000);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Router provisioning job failed permanently', [
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
