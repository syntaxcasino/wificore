<?php

namespace App\Http\Controllers\Api;

use App\Events\RouterInterfacesDiscovered;
use App\Events\RouterProvisioningProgress;
use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterTask;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InternalProvisioningTaskController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function updateStatus(Request $request, string $taskId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', [
                RouterTask::STATUS_RUNNING,
                RouterTask::STATUS_COMPLETED,
                RouterTask::STATUS_FAILED,
            ]),
            'progress' => 'nullable|integer|min:0|max:100',
            'message' => 'nullable|string|max:1000',
            'result' => 'nullable|array',
            'error' => 'nullable|string|max:2000',
            'terminal' => 'nullable|boolean',
            'stage' => 'nullable|string|max:255',
        ]);

        $task = RouterTask::findOrFail($taskId);

        if (in_array($task->status, [RouterTask::STATUS_COMPLETED, RouterTask::STATUS_FAILED], true)
            && $validated['status'] === RouterTask::STATUS_RUNNING) {
            return response()->json([
                'success' => true,
                'task' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'progress' => $task->progress,
                ],
            ]);
        }

        $progress = $validated['progress'] ?? $task->progress;
        $message = $validated['message'] ?? $task->message;
        $result = $validated['result'] ?? [];
        $terminal = $validated['terminal'] ?? true;
        $stage = $validated['stage'] ?? null;

        if ($validated['status'] === RouterTask::STATUS_RUNNING) {
            $task->markRunning($progress, $message);
        } elseif ($validated['status'] === RouterTask::STATUS_COMPLETED && ! $terminal) {
            $existingResult = is_array($task->result_payload) ? $task->result_payload : [];
            $stageResults = is_array($existingResult['stage_results'] ?? null)
                ? $existingResult['stage_results']
                : [];

            if ($stage) {
                $stageResults[$stage] = $result;
            }

            $task->forceFill([
                'status' => RouterTask::STATUS_RUNNING,
                'progress' => min($progress ?: 95, 99),
                'message' => $message,
                'result_payload' => array_merge($existingResult, [
                    'stage' => $stage,
                    'stage_result' => $result,
                    'stage_results' => $stageResults,
                ]),
                'started_at' => $task->started_at ?? now(),
                'error_message' => null,
            ])->save();
        } elseif ($validated['status'] === RouterTask::STATUS_COMPLETED) {
            $task->markCompleted($result, $progress ?: 100, $message);
        } else {
            $task->markFailed($validated['error'] ?? 'Provisioning task failed', $progress, $message, $result);
        }

        $this->syncRouterProvisioningState($task->fresh() ?? $task, $validated['status'], (int) $progress, $message, $result, $stage, $terminal);

        return response()->json([
            'success' => true,
            'task' => [
                'id' => $task->id,
                'status' => $task->status,
                'progress' => $task->progress,
                'message' => $task->message,
            ],
        ]);
    }

    private function syncRouterProvisioningState(
        RouterTask $task,
        string $status,
        int $progress,
        ?string $message,
        array $result,
        ?string $stage,
        bool $terminal,
    ): void {
        if (! in_array($task->type, [
            RouterTask::TYPE_DEPLOY_SERVICE_CONFIG,
            RouterTask::TYPE_APPLY_SERVICE_CONFIGS,
            RouterTask::TYPE_VERIFY_CONNECTIVITY,
            RouterTask::TYPE_DISCOVER_INTERFACES,
        ], true) || ! $task->tenant_id || ! $task->router_id) {
            return;
        }

        $tenant = Tenant::find($task->tenant_id);
        if (! $tenant || ! $tenant->schema_created || ! $tenant->schema_name) {
            Log::warning('Provisioning callback skipped router sync because tenant schema is unavailable', [
                'task_id' => $task->id,
                'tenant_id' => $task->tenant_id,
                'router_id' => $task->router_id,
            ]);
            return;
        }

        DB::transaction(function () use ($tenant, $task, $status, $progress, $message, $result, $stage, $terminal) {
            $this->tenantContext->runInTenantContext($tenant, function () use ($task, $status, $progress, $message, $result, $stage, $terminal) {
                $router = Router::find($task->router_id);
                if (! $router) {
                    Log::warning('Provisioning callback skipped router sync because router was not found', [
                        'task_id' => $task->id,
                        'tenant_id' => $task->tenant_id,
                        'router_id' => $task->router_id,
                    ]);
                    return;
                }

                $stageName = $stage ?: match ($status) {
                    RouterTask::STATUS_FAILED => 'failed',
                    RouterTask::STATUS_COMPLETED => 'completed',
                    default => 'submitted',
                };

                switch ($task->type) {
                    case RouterTask::TYPE_DEPLOY_SERVICE_CONFIG:
                        $this->syncDeployServiceTask($router, $task, $status, $progress, $message, $result, $stageName, $terminal);
                        break;
                    case RouterTask::TYPE_APPLY_SERVICE_CONFIGS:
                        $this->syncApplyConfigTask($router, $status, $result, $stageName, $terminal);
                        break;
                    case RouterTask::TYPE_VERIFY_CONNECTIVITY:
                        $this->syncVerifyConnectivityTask($router, $status, $result, $stageName, $terminal);
                        break;
                    case RouterTask::TYPE_DISCOVER_INTERFACES:
                        $this->syncDiscoverInterfacesTask($router, $status, $result, $stageName, $terminal, $task);
                        break;
                }
            });
        });
    }

    private function syncDeployServiceTask(Router $router, RouterTask $task, string $status, int $progress, ?string $message, array $result, string $stageName, bool $terminal): void
    {
        if ($status === RouterTask::STATUS_FAILED) {
            $router->update([
                'status' => 'failed',
                'provisioning_stage' => 'failed',
                'last_checked' => now(),
            ]);
        } elseif ($status === RouterTask::STATUS_COMPLETED && $terminal) {
            $router->update([
                'status' => 'online',
                'provisioning_stage' => 'completed',
                'model' => $result['model'] ?? $router->model,
                'os_version' => $result['os_version'] ?? $router->os_version,
                'last_seen' => $result['last_seen'] ?? now(),
                'last_checked' => now(),
            ]);
        } else {
            [$routerStatus, $routerStage] = $this->mapProvisioningStageToRouterState($stageName);
            $router->update([
                'status' => $routerStatus,
                'provisioning_stage' => $routerStage,
                'last_checked' => now(),
            ]);
        }

        broadcast(new RouterProvisioningProgress(
            (string) $router->id,
            $stageName,
            (float) $progress,
            $message ?? 'Provisioning update received',
            array_merge($result, [
                'task_id' => $task->id,
                'task_status' => $task->status,
                'terminal' => $terminal,
            ])
        ));
    }

    private function syncApplyConfigTask(Router $router, string $status, array $result, string $stageName, bool $terminal): void
    {
        if ($status === RouterTask::STATUS_FAILED) {
            $router->update([
                'status' => 'failed',
                'provisioning_stage' => 'failed',
                'last_checked' => now(),
            ]);
            return;
        }

        if ($status === RouterTask::STATUS_COMPLETED && $terminal) {
            $router->update([
                'status' => 'online',
                'provisioning_stage' => 'config_applied',
                'last_seen' => $result['last_seen'] ?? now(),
                'last_checked' => now(),
            ]);
            return;
        }

        [$routerStatus, $routerStage] = $this->mapProvisioningStageToRouterState($stageName);
        $router->update([
            'status' => $routerStatus,
            'provisioning_stage' => $routerStage,
            'last_checked' => now(),
        ]);
    }

    private function syncVerifyConnectivityTask(Router $router, string $status, array $result, string $stageName, bool $terminal): void
    {
        if ($status === RouterTask::STATUS_FAILED) {
            $router->update([
                'status' => 'connection_failed',
                'provisioning_stage' => 'verify_connectivity_failed',
                'last_checked' => now(),
            ]);
            return;
        }

        if ($status === RouterTask::STATUS_COMPLETED && $terminal) {
            $router->update([
                'status' => 'online',
                'provisioning_stage' => 'connectivity_verified',
                'model' => $result['model'] ?? $router->model,
                'os_version' => $result['os_version'] ?? $router->os_version,
                'last_seen' => $result['last_seen'] ?? now(),
                'last_checked' => now(),
            ]);
            return;
        }

        [$routerStatus, $routerStage] = $this->mapProvisioningStageToRouterState($stageName, 'verifying');
        $router->update([
            'status' => $routerStatus,
            'provisioning_stage' => $routerStage,
            'last_checked' => now(),
        ]);
    }

    private function syncDiscoverInterfacesTask(Router $router, string $status, array $result, string $stageName, bool $terminal, RouterTask $task): void
    {
        if ($status === RouterTask::STATUS_FAILED) {
            $router->update([
                'last_checked' => now(),
                'provisioning_stage' => 'discover_interfaces_failed',
            ]);
            return;
        }

        if ($status === RouterTask::STATUS_COMPLETED && $terminal) {
            $router->update([
                'model' => $result['model'] ?? $result['board_name'] ?? $router->model,
                'os_version' => $result['os_version'] ?? $result['version'] ?? $router->os_version,
                'last_seen' => $result['last_seen'] ?? now(),
                'last_checked' => now(),
                'provisioning_stage' => 'interfaces_discovered',
            ]);

            $interfaces = is_array($result['interfaces'] ?? null) ? $result['interfaces'] : [];
            broadcast(new RouterInterfacesDiscovered(
                (string) $task->tenant_id,
                (string) $router->id,
                $interfaces,
                [
                    'model' => $result['model'] ?? $result['board_name'] ?? null,
                    'version' => $result['os_version'] ?? $result['version'] ?? null,
                    'uptime' => $result['uptime'] ?? null,
                    'interface_count' => count($interfaces),
                ]
            ));
            return;
        }

        [$routerStatus, $routerStage] = $this->mapProvisioningStageToRouterState($stageName, $router->status);
        $router->update([
            'status' => $routerStatus,
            'provisioning_stage' => $routerStage,
            'last_checked' => now(),
        ]);
    }

    private function mapProvisioningStageToRouterState(string $stage, string $defaultStatus = 'provisioning'): array
    {
        return match ($stage) {
            'submitted' => [$defaultStatus, 'submitted'],
            'precheck_connectivity' => ['provisioning', 'ping_verification'],
            'deploying_config' => ['deploying', 'deploying_config'],
            'verifying_deployment' => ['verifying', 'verifying_deployment'],
            'verifying_connectivity' => ['verifying', 'verify_connectivity'],
            'discovering_interfaces' => [$defaultStatus, 'discovering_interfaces'],
            default => [$defaultStatus, $stage],
        };
    }
}
