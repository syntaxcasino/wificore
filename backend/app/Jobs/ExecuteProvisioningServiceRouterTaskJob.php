<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\RouterTask;
use App\Services\RouterTaskExecutionService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteProvisioningServiceRouterTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [15, 30, 60];

    public function __construct(
        public string $taskId,
        string $tenantId,
        public string $routerId,
    ) {
        $this->tenantId = $tenantId;
        $this->onQueue('router-provisioning');
    }

    public function handle(RouterTaskExecutionService $executionService): void
    {
        $task = RouterTask::findOrFail($this->taskId);
        $task->markRunning(5, 'Submitting router task command to provisioning service');

        $this->executeInTenantContext(function () use ($executionService, $task) {
            $router = Router::find($this->routerId);
            if (! $router) {
                $task->markFailed('Router not found', 0, 'Router not found');
                return;
            }

            $this->prepareRouterState($task, $router);
            $response = $executionService->submitTaskCommand($router, $this->tenantId, $task);

            $task->forceFill([
                'status' => RouterTask::STATUS_RUNNING,
                'progress' => 15,
                'message' => $this->acceptedMessage($task),
                'result_payload' => array_merge((array) $task->result_payload, [
                    'command_submission' => $response['data'] ?? $response,
                ]),
                'started_at' => $task->started_at ?? now(),
                'error_message' => null,
            ])->save();
        });
    }

    protected function prepareRouterState(RouterTask $task, Router $router): void
    {
        switch ($task->type) {
            case RouterTask::TYPE_APPLY_SERVICE_CONFIGS:
                $router->update([
                    'status' => 'deploying',
                    'provisioning_stage' => 'submitting_to_provisioning_service',
                    'last_checked' => now(),
                ]);
                break;

            case RouterTask::TYPE_VERIFY_CONNECTIVITY:
                $router->update([
                    'status' => 'verifying',
                    'provisioning_stage' => 'verify_connectivity',
                    'last_checked' => now(),
                ]);
                break;

            case RouterTask::TYPE_DISCOVER_INTERFACES:
                $router->update([
                    'status' => $router->status === 'online' ? 'online' : 'provisioning',
                    'provisioning_stage' => 'discovering_interfaces',
                    'last_checked' => now(),
                ]);
                break;
        }
    }

    protected function acceptedMessage(RouterTask $task): string
    {
        return match ($task->type) {
            RouterTask::TYPE_APPLY_SERVICE_CONFIGS => 'Configuration apply command accepted by provisioning service',
            RouterTask::TYPE_VERIFY_CONNECTIVITY => 'Connectivity verification command accepted by provisioning service',
            RouterTask::TYPE_DISCOVER_INTERFACES => 'Interface discovery command accepted by provisioning service',
            default => 'Router task command accepted by provisioning service',
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExecuteProvisioningServiceRouterTaskJob failed', [
            'task_id' => $this->taskId,
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);

        if ($task = RouterTask::find($this->taskId)) {
            if (in_array($task->status, [RouterTask::STATUS_COMPLETED, RouterTask::STATUS_FAILED], true)) {
                return;
            }

            $task->markFailed($exception->getMessage(), $task->progress, 'Router task command submission failed');
        }
    }
}
