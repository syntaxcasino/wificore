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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class InternalProvisioningTaskController extends Controller
{
    private const CANONICAL_STAGE_ORDER = [
        'submitted',
        'precheck_connectivity',
        'deploying_config',
        'verifying_deployment',
        'verifying_connectivity',
        'discovering_interfaces',
    ];

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
            'tenant_id' => 'nullable|string',
            'router_id' => 'nullable|string',
            'callback_at' => 'nullable|date',
        ]);

        $task = RouterTask::findOrFail($taskId);

        $identityCheck = $this->validateCallbackIdentity($task, $validated);
        if ($identityCheck !== null) {
            $this->recordCallbackGuardOutcome($task, 'identity_validation_failed', 'failed', [
                'incoming_tenant_id' => $validated['tenant_id'] ?? null,
                'incoming_router_id' => $validated['router_id'] ?? null,
            ], $identityCheck->getStatusCode(), true);
            return $identityCheck;
        }

        $freshnessCheck = $this->validateCallbackFreshness($task, $validated);
        if ($freshnessCheck !== null) {
            $this->recordCallbackGuardOutcome($task, 'freshness_validation_failed', 'failed', [
                'callback_at' => $validated['callback_at'] ?? null,
            ], $freshnessCheck->getStatusCode(), true);
            return $freshnessCheck;
        }

        if ($this->shouldIgnoreTerminalStatusMutation($task, (string) ($validated['status'] ?? ''))) {
            Log::warning('Ignoring callback status mutation for terminal task', [
                'task_id' => $task->id,
                'stored_status' => $task->status,
                'incoming_status' => $validated['status'] ?? null,
            ]);

            $this->recordCallbackGuardOutcome($task, 'terminal_status_mutation_ignored', 'skipped', [
                'stored_status' => $task->status,
                'incoming_status' => $validated['status'] ?? null,
            ], 200, false);

            return response()->json([
                'success' => true,
                'ignored' => true,
                'task' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'progress' => $task->progress,
                    'message' => $task->message,
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

        if ($this->shouldIgnoreRegressiveStageUpdate($task, $stage, $terminal)) {
            Log::warning('Ignoring regressive provisioning stage callback', [
                'task_id' => $task->id,
                'status' => $task->status,
                'incoming_stage' => $stage,
                'stored_stage' => is_array($task->result_payload) ? ($task->result_payload['stage'] ?? null) : null,
            ]);

            $this->recordCallbackGuardOutcome($task, 'regressive_stage_ignored', 'skipped', [
                'incoming_stage' => $stage,
                'stored_stage' => is_array($task->result_payload) ? ($task->result_payload['stage'] ?? null) : null,
            ], 200, false);
            return response()->json([
                'success' => true,
                'ignored' => true,
                'task' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'progress' => $task->progress,
                    'message' => $task->message,
                ],
            ]);
        }

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
            if ($stage) {
                $existingResult = is_array($task->result_payload) ? $task->result_payload : [];
                $task->forceFill([
                    'result_payload' => array_merge($existingResult, [
                        'stage' => $stage,
                    ]),
                ])->save();
            }
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

    private function validateCallbackIdentity(RouterTask $task, array $validated): ?\Illuminate\Http\JsonResponse
    {
        $incomingTenantId = isset($validated['tenant_id']) ? (string) $validated['tenant_id'] : null;
        $incomingRouterId = isset($validated['router_id']) ? (string) $validated['router_id'] : null;

        $requireIdentity = (bool) config('services.provisioning.require_callback_identity', false);
        $warnOnMissingIdentity = (bool) config('services.provisioning.warn_on_missing_callback_identity', true);

        if ($incomingTenantId === null || $incomingTenantId === '' || $incomingRouterId === null || $incomingRouterId === '') {
            if ($warnOnMissingIdentity) {
                Log::warning('Provisioning callback missing identity fields', [
                    'task_id' => $task->id,
                    'incoming_tenant_id' => $incomingTenantId,
                    'incoming_router_id' => $incomingRouterId,
                    'require_identity' => $requireIdentity,
                ]);
            }

            if ($requireIdentity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Provisioning callback identity required',
                ], 403);
            }

            return null;
        }

        if ($incomingTenantId !== (string) $task->tenant_id) {
            Log::critical('Provisioning callback tenant mismatch', [
                'task_id' => $task->id,
                'task_tenant_id' => (string) $task->tenant_id,
                'incoming_tenant_id' => $incomingTenantId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Provisioning callback identity mismatch',
            ], 403);
        }

        if ($incomingRouterId !== (string) $task->router_id) {
            Log::critical('Provisioning callback router mismatch', [
                'task_id' => $task->id,
                'task_router_id' => (string) $task->router_id,
                'incoming_router_id' => $incomingRouterId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Provisioning callback identity mismatch',
            ], 403);
        }

        return null;
    }

    private function validateCallbackFreshness(RouterTask $task, array $validated): ?\Illuminate\Http\JsonResponse
    {
        if (!isset($validated['callback_at']) || !is_string($validated['callback_at']) || trim($validated['callback_at']) === '') {
            return null;
        }

        $callbackAt = Carbon::parse($validated['callback_at']);
        $now = now();
        $skewSeconds = (int) abs($callbackAt->diffInSeconds($now, false));

        $maxSkew = max(0, (int) config('services.provisioning.max_callback_skew_seconds', 900));
        $warnOnStale = (bool) config('services.provisioning.warn_on_stale_callbacks', true);
        $rejectStale = (bool) config('services.provisioning.reject_stale_callbacks', false);

        if ($skewSeconds <= $maxSkew) {
            return null;
        }

        if ($warnOnStale) {
            Log::warning('Provisioning callback outside freshness window', [
                'task_id' => $task->id,
                'callback_at' => $callbackAt->toIso8601String(),
                'now' => $now->toIso8601String(),
                'skew_seconds' => $skewSeconds,
                'max_skew_seconds' => $maxSkew,
                'reject_stale' => $rejectStale,
            ]);
        }

        if ($rejectStale) {
            return response()->json([
                'success' => false,
                'message' => 'Provisioning callback is stale',
            ], 409);
        }

        return null;
    }

    private function recordCallbackGuardOutcome(
        RouterTask $task,
        string $action,
        string $status,
        array $payload,
        int $httpCode,
        bool $isTerminal,
    ): void {
        $runId = $this->resolveRunIdFromTask($task);
        if (! $runId) {
            return;
        }

        $run = ProvisioningRun::find($runId);
        if (! $run) {
            return;
        }

        $this->auditService->logStep($run, [
            'stage' => 'callback_guard',
            'action' => $action,
            'status' => $status,
            'response_payload' => array_merge($payload, [
                'task_status' => $task->status,
                'task_type' => $task->type,
                'http_status' => $httpCode,
            ]),
            'is_terminal' => $isTerminal,
            'completed_at' => now(),
        ]);

        $this->incrementCallbackGuardCounter($action);
    }

    private function incrementCallbackGuardCounter(string $action): void
    {
        try {
            Cache::increment('metrics:provisioning:callback_guard:' . $action);
            Cache::put('metrics:provisioning:callback_guard:last_updated_at', now()->toIso8601String(), now()->addDay());

            $this->incrementCallbackGuardTrendBucket($action);
        } catch (\Throwable $e) {
            Log::warning('Failed to increment provisioning callback guard counter', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function incrementCallbackGuardTrendBucket(string $action): void
    {
        $bucketKey = 'metrics:provisioning:callback_guard:trend:' . $action;
        $currentBucket = now()->format('Y-m-d\TH:i');
        $cutoff = now()->subMinutes(60)->format('Y-m-d\TH:i');

        $buckets = Cache::get($bucketKey, []);
        if (! is_array($buckets)) {
            $buckets = [];
        }

        $buckets[$currentBucket] = (int) ($buckets[$currentBucket] ?? 0) + 1;

        foreach (array_keys($buckets) as $bucket) {
            if (! is_string($bucket) || $bucket < $cutoff) {
                unset($buckets[$bucket]);
            }
        }

        ksort($buckets);
        Cache::put($bucketKey, $buckets, now()->addDay());
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

    private function shouldIgnoreTerminalStatusMutation(RouterTask $task, string $incomingStatus): bool
    {
        if (!in_array($task->status, [RouterTask::STATUS_COMPLETED, RouterTask::STATUS_FAILED], true)) {
            return false;
        }

        if ($incomingStatus === '') {
            return false;
        }

        // Idempotent repeats are allowed; only conflicting mutations are ignored.
        return $incomingStatus !== $task->status;
    }

    private function shouldIgnoreRegressiveStageUpdate(
        RouterTask $task,
        ?string $incomingStage,
        bool $terminal
    ): bool {
        if ($terminal || !is_string($incomingStage) || $incomingStage === '') {
            return false;
        }

        $order = array_flip(self::CANONICAL_STAGE_ORDER);
        if (!isset($order[$incomingStage])) {
            return false;
        }

        $payload = is_array($task->result_payload) ? $task->result_payload : [];
        $storedStage = $payload['stage'] ?? null;
        if (!is_string($storedStage) || !isset($order[$storedStage])) {
            return false;
        }

        return $order[$incomingStage] < $order[$storedStage];
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
