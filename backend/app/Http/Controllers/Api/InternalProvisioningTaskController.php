<?php

namespace App\Http\Controllers\Api;

use App\Events\RouterInterfacesDiscovered;
use App\Events\RouterProvisioningProgress;
use App\Http\Controllers\Controller;
use App\Models\ProvisioningRun;
use App\Models\Router;
use App\Models\RouterTask;
use App\Models\Tenant;
use App\Services\ProvisioningRunAuditService;
use App\Services\Deployment\ConfigDriftDetector;
use App\Jobs\RollbackRouterConfigJob;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InternalProvisioningTaskController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ProvisioningRunAuditService $auditService,
        private readonly ConfigDriftDetector $driftDetector,
    ) {
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
            && ($validated['status'] ?? null) === RouterTask::STATUS_RUNNING) {
            return response()->json([
                'success' => true,
                'task' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'progress' => $task->progress,
                ],
            ]);
        }

        $progress = (int) ($validated['progress'] ?? $task->progress);
        $message = $validated['message'] ?? $task->message;
        $result = $validated['result'] ?? [];
        $terminal = (bool) ($validated['terminal'] ?? true);
        $stage = $validated['stage'] ?? null;
        $callbackStatus = $validated['status'];
        $callbackError = $validated['error'] ?? null;

        [$callbackStatus, $message, $callbackError, $result, $progress] = $this->applyVerificationPolicy(
            $task,
            $callbackStatus,
            $terminal,
            $result,
            $message,
            $callbackError,
            $progress
        );

        if ($callbackStatus === RouterTask::STATUS_RUNNING) {
            $task->markRunning($progress, $message);
        } elseif ($callbackStatus === RouterTask::STATUS_COMPLETED && ! $terminal) {
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
        } elseif ($callbackStatus === RouterTask::STATUS_COMPLETED) {
            $task->markCompleted($result, $progress ?: 100, $message);
        } else {
            $task->markFailed($callbackError ?? 'Provisioning task failed', $progress, $message, $result);
        }

        $freshTask = $task->fresh() ?? $task;
        $this->syncRouterProvisioningState($freshTask, $callbackStatus, $progress, $message, $result, $stage, $terminal);
        $this->syncProvisioningRunAudit($freshTask, $callbackStatus, $progress, $message, $result, $stage, $terminal, $callbackError);

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

    private function applyVerificationPolicy(
        RouterTask $task,
        string $status,
        bool $terminal,
        array $result,
        ?string $message,
        ?string $error,
        int $progress,
    ): array {
        $isProvisioningTask = in_array($task->type, [
            RouterTask::TYPE_DEPLOY_SERVICE_CONFIG,
            RouterTask::TYPE_APPLY_SERVICE_CONFIGS,
        ], true);

        if (! $isProvisioningTask || $status !== RouterTask::STATUS_COMPLETED || ! $terminal) {
            return [$status, $message, $error, $result, $progress];
        }

        $verification = $this->evaluateVerificationBundle($result);
        if ($verification['valid']) {
            return [$status, $message, $error, $result, $progress];
        }

        $errorMessage = 'Post-provision verification failed: ' . implode(', ', $verification['missing']);
        $result['verification_status'] = 'failed';
        $result['verification_missing'] = $verification['missing'];

        return [
            RouterTask::STATUS_FAILED,
            $errorMessage,
            $errorMessage,
            $result,
            min($progress, 99),
        ];
    }

    private function evaluateVerificationBundle(array $result): array
    {
        $required = [
            'interface_bridge',
            'ip_pool',
            'ppp_profile',
            'pppoe_server',
            'ip_firewall',
            'queue',
            'wireguard',
        ];

        $resources = [];

        if (isset($result['verification']['resources']) && is_array($result['verification']['resources'])) {
            $resources = $result['verification']['resources'];
        } elseif (isset($result['verification_results']) && is_array($result['verification_results'])) {
            $resources = $result['verification_results'];
        }

        if ($resources === []) {
            return ['valid' => false, 'missing' => $required];
        }

        $missing = [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $resources) || $resources[$key] !== true) {
                $missing[] = $key;
            }
        }

        return [
            'valid' => empty($missing),
            'missing' => $missing,
        ];
    }

    private function syncProvisioningRunAudit(
        RouterTask $task,
        string $taskStatus,
        int $progress,
        ?string $message,
        array $result,
        ?string $stage,
        bool $terminal,
        ?string $error,
    ): void {
        $runId = $this->resolveRunIdFromTask($task);
        if (! $runId) {
            return;
        }

        $run = ProvisioningRun::find($runId);
        if (! $run) {
            return;
        }

        $stageName = $stage ?: match ($taskStatus) {
            RouterTask::STATUS_FAILED => 'failed',
            RouterTask::STATUS_COMPLETED => 'completed',
            default => 'running',
        };

        $this->auditService->logStep($run, [
            'stage' => $stageName,
            'action' => 'task_callback_' . $task->type,
            'status' => $this->mapStepStatus($taskStatus),
            'response_payload' => [
                'message' => $message,
                'result' => $result,
                'task_status' => $taskStatus,
                'terminal' => $terminal,
            ],
            'error_message' => $error,
            'is_terminal' => $terminal,
            'completed_at' => now(),
        ]);

        $runStatus = $this->mapRunStatus($taskStatus, $terminal);
        $this->auditService->updateRun(
            $run,
            $runStatus,
            $progress,
            $stageName,
            $error
        );

        $this->triggerRollbackForVerificationFailure($task, $run, $taskStatus, $terminal, $result, $error);
    }

    private function triggerRollbackForVerificationFailure(
        RouterTask $task,
        ProvisioningRun $run,
        string $taskStatus,
        bool $terminal,
        array $result,
        ?string $error
    ): void
    {
        if ($taskStatus !== RouterTask::STATUS_FAILED || ! $terminal) {
            return;
        }

        if (($result['verification_status'] ?? null) !== 'failed') {
            return;
        }

        $meta = is_array($run->metadata) ? $run->metadata : [];
        if (($meta['rollback_dispatched'] ?? false) === true) {
            return;
        }

        $tenant = Tenant::find($task->tenant_id);
        if (! $tenant || ! $tenant->schema_created || ! $tenant->schema_name) {
            $this->auditService->logStep($run, [
                'stage' => 'rollback',
                'action' => 'dispatch_rollback',
                'status' => 'failed',
                'error_message' => 'Tenant schema unavailable for rollback dispatch',
                'is_terminal' => true,
                'completed_at' => now(),
            ]);
            return;
        }

        $this->tenantContext->runInTenantContext($tenant, function () use ($task, $run, $meta, $error): void {
            $router = Router::find($task->router_id);
            if (! $router) {
                $this->auditService->logStep($run, [
                    'stage' => 'rollback',
                    'action' => 'dispatch_rollback',
                    'status' => 'failed',
                    'error_message' => 'Router not found for rollback dispatch',
                    'is_terminal' => true,
                    'completed_at' => now(),
                ]);
                return;
            }

            $snapshot = $this->driftDetector->getLatestSnapshot($router);
            if (! $snapshot) {
                $this->auditService->logStep($run, [
                    'stage' => 'rollback',
                    'action' => 'dispatch_rollback',
                    'status' => 'failed',
                    'error_message' => 'No config snapshot available for rollback',
                    'response_payload' => ['reason' => 'snapshot_missing'],
                    'is_terminal' => true,
                    'completed_at' => now(),
                ]);
                return;
            }

            dispatch((new RollbackRouterConfigJob($router, $snapshot->config_text, $run->id))->onQueue('router-provisioning'));

            $this->auditService->logStep($run, [
                'stage' => 'rollback',
                'action' => 'dispatch_rollback',
                'status' => 'completed',
                'response_payload' => [
                    'snapshot_id' => $snapshot->id,
                    'router_id' => $router->id,
                    'source_error' => $error,
                ],
                'is_terminal' => false,
                'completed_at' => now(),
            ]);

            $meta['rollback_dispatched'] = true;
            $meta['rollback_dispatched_at'] = now()->toIso8601String();
            $this->auditService->updateRun($run, ProvisioningRun::STATUS_FAILED, (int) $run->progress, 'rollback_dispatched', null, $meta);
        });
    }

    private function resolveRunIdFromTask(RouterTask $task): ?string
    {
        $payload = is_array($task->result_payload) ? $task->result_payload : [];
        $runId = $payload['provisioning_run_id'] ?? null;
        return is_string($runId) && $runId !== '' ? $runId : null;
    }

    private function mapRunStatus(string $taskStatus, bool $terminal): string
    {
        if ($taskStatus === RouterTask::STATUS_FAILED) {
            return ProvisioningRun::STATUS_FAILED;
        }

        if ($taskStatus === RouterTask::STATUS_COMPLETED && $terminal) {
            return ProvisioningRun::STATUS_COMPLETED;
        }

        return ProvisioningRun::STATUS_RUNNING;
    }

    private function mapStepStatus(string $taskStatus): string
    {
        return match ($taskStatus) {
            RouterTask::STATUS_FAILED => 'failed',
            RouterTask::STATUS_COMPLETED => 'completed',
            default => 'running',
        };
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
                'architecture_name' => $result['architecture_name'] ?? $result['architecture'] ?? $router->architecture_name,
                'board_name' => $result['board_name'] ?? $result['model'] ?? $router->board_name,
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
                'architecture_name' => $result['architecture_name'] ?? $result['architecture'] ?? $router->architecture_name,
                'board_name' => $result['board_name'] ?? $result['model'] ?? $router->board_name,
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
                'architecture_name' => $result['architecture_name'] ?? $result['architecture'] ?? $router->architecture_name,
                'board_name' => $result['board_name'] ?? $result['model'] ?? $router->board_name,
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
