<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Api\InternalProvisioningTaskController;
use App\Jobs\RollbackRouterConfigJob;
use App\Models\ConfigSnapshot;
use App\Models\ProvisioningRun;
use App\Models\Router;
use App\Models\RouterTask;
use App\Models\Tenant;
use App\Services\Deployment\ConfigDriftDetector;
use App\Services\ProvisioningRunAuditService;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InternalProvisioningTaskControllerRollbackTest extends TestCase
{
    private function makeController(
        TenantContext $tenantContext,
        ProvisioningRunAuditService $auditService,
        ConfigDriftDetector $driftDetector,
        ?Tenant $tenant = null,
        ?Router $router = null,
    ): InternalProvisioningTaskController {
        return new class($tenantContext, $auditService, $driftDetector, $tenant, $router) extends InternalProvisioningTaskController {
            public function __construct(
                TenantContext $tenantContext,
                ProvisioningRunAuditService $auditService,
                ConfigDriftDetector $driftDetector,
                private readonly ?Tenant $tenant,
                private readonly ?Router $router,
            ) {
                parent::__construct($tenantContext, $auditService, $driftDetector);
            }

            protected function findRollbackTenant(string $tenantId): ?Tenant
            {
                return $this->tenant?->id === $tenantId ? $this->tenant : null;
            }

            protected function findRollbackRouter(string $routerId): ?Router
            {
                return $this->router?->id === $routerId ? $this->router : null;
            }
        };
    }

    private function invokeShouldAttemptRollbackForFailedProvisioningTask(
        InternalProvisioningTaskController $controller,
        RouterTask $task,
        string $taskStatus,
        bool $terminal,
        array $result,
        ?string $error,
    ): bool {
        $invoker = \Closure::bind(function (
            RouterTask $task,
            string $taskStatus,
            bool $terminal,
            array $result,
            ?string $error,
        ): bool {
            return $this->shouldAttemptRollbackForFailedProvisioningTask($task, $taskStatus, $terminal, $result, $error);
        }, $controller, InternalProvisioningTaskController::class);

        return $invoker($task, $taskStatus, $terminal, $result, $error);
    }

    private function invokeTriggerRollbackForFailedProvisioningTask(
        InternalProvisioningTaskController $controller,
        RouterTask $task,
        ProvisioningRun $run,
        string $taskStatus,
        bool $terminal,
        array $result,
        ?string $error,
    ): void {
        $invoker = \Closure::bind(function (
            RouterTask $task,
            ProvisioningRun $run,
            string $taskStatus,
            bool $terminal,
            array $result,
            ?string $error,
        ): void {
            $this->triggerRollbackForFailedProvisioningTask($task, $run, $taskStatus, $terminal, $result, $error);
        }, $controller, InternalProvisioningTaskController::class);

        $invoker($task, $run, $taskStatus, $terminal, $result, $error);
    }

    #[Test]
    public function it_only_attempts_rollback_for_failed_deploy_and_apply_callbacks(): void
    {
        $controller = $this->makeController(
            $this->createMock(TenantContext::class),
            $this->createMock(ProvisioningRunAuditService::class),
            $this->createMock(ConfigDriftDetector::class),
        );

        $deployTask = new RouterTask();
        $deployTask->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;

        $applyTask = new RouterTask();
        $applyTask->type = RouterTask::TYPE_APPLY_SERVICE_CONFIGS;

        $otherTask = new RouterTask();
        $otherTask->type = RouterTask::TYPE_VERIFY_CONNECTIVITY;

        $this->assertTrue($this->invokeShouldAttemptRollbackForFailedProvisioningTask(
            $controller,
            $deployTask,
            RouterTask::STATUS_FAILED,
            true,
            ['error' => 'boom'],
            'boom',
        ));

        $this->assertTrue($this->invokeShouldAttemptRollbackForFailedProvisioningTask(
            $controller,
            $applyTask,
            RouterTask::STATUS_FAILED,
            true,
            ['trap_message' => 'duplicate rule'],
            null,
        ));

        $this->assertFalse($this->invokeShouldAttemptRollbackForFailedProvisioningTask(
            $controller,
            $otherTask,
            RouterTask::STATUS_FAILED,
            true,
            ['error' => 'boom'],
            'boom',
        ));

        $this->assertFalse($this->invokeShouldAttemptRollbackForFailedProvisioningTask(
            $controller,
            $deployTask,
            RouterTask::STATUS_COMPLETED,
            true,
            ['error' => 'boom'],
            'boom',
        ));

        $this->assertFalse($this->invokeShouldAttemptRollbackForFailedProvisioningTask(
            $controller,
            $deployTask,
            RouterTask::STATUS_FAILED,
            false,
            ['error' => 'boom'],
            'boom',
        ));
    }

    #[Test]
    public function it_dispatches_a_rollback_job_when_failed_deploy_callback_has_a_snapshot(): void
    {
        Queue::fake([RollbackRouterConfigJob::class]);

        $tenant = new Tenant();
        $tenant->id = 'tenant-rollback-1';
        $tenant->schema_created = true;
        $tenant->schema_name = 'ts_rollback_test';

        $router = new Router();
        $router->id = 'router-rollback-1';
        $router->name = 'Core-01';

        $task = new RouterTask();
        $task->id = 'task-rollback-1';
        $task->tenant_id = $tenant->id;
        $task->router_id = $router->id;
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;

        $run = new ProvisioningRun();
        $run->id = 'run-rollback-1';
        $run->progress = 40;
        $run->metadata = [];
        $run->exists = true;

        $snapshot = new ConfigSnapshot();
        $snapshot->id = 'snapshot-1';
        $snapshot->config_text = '/interface bridge add name=br-lan';

        $auditService = $this->createMock(ProvisioningRunAuditService::class);
        $auditService->expects($this->once())
            ->method('logStep')
            ->with(
                $run,
                $this->callback(function (array $step): bool {
                    return ($step['stage'] ?? null) === 'rollback'
                        && ($step['action'] ?? null) === 'dispatch_rollback'
                        && ($step['status'] ?? null) === 'completed';
                })
            );
        $auditService->expects($this->once())
            ->method('updateRun')
            ->with(
                $run,
                ProvisioningRun::STATUS_FAILED,
                40,
                'rollback_dispatched',
                null,
                $this->callback(function (array $meta): bool {
                    return ($meta['rollback_dispatched'] ?? false) === true
                        && isset($meta['rollback_dispatched_at']);
                })
            );

        $driftDetector = $this->createMock(ConfigDriftDetector::class);
        $driftDetector->expects($this->once())
            ->method('getLatestSnapshot')
            ->with($router)
            ->willReturn($snapshot);

        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->expects($this->once())
            ->method('runInTenantContext')
            ->with($tenant, $this->isType('callable'))
            ->willReturnCallback(function ($tenant, callable $callback) {
                return $callback();
            });

        $controller = $this->makeController($tenantContext, $auditService, $driftDetector, $tenant, $router);

        $this->invokeTriggerRollbackForFailedProvisioningTask(
            $controller,
            $task,
            $run,
            RouterTask::STATUS_FAILED,
            true,
            ['verification_status' => 'failed'],
            'deploy failed',
        );

        Queue::assertPushedOn('router-provisioning', RollbackRouterConfigJob::class, function (RollbackRouterConfigJob $job) use ($router, $run): bool {
            return $job->router->id === $router->id
                && $job->config === '/interface bridge add name=br-lan'
                && $job->provisioningRunId === $run->id;
        });
    }
    
    #[Test]
    public function it_logs_a_failure_when_tenant_schema_is_unavailable_for_rollback_dispatch(): void
    {
        Queue::fake([RollbackRouterConfigJob::class]);

        $tenant = new Tenant();
        $tenant->id = 'tenant-rollback-2';
        $tenant->schema_created = false;
        $tenant->schema_name = 'ts_rollback_test';

        $task = new RouterTask();
        $task->tenant_id = $tenant->id;
        $task->router_id = 'router-rollback-2';
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;

        $run = new ProvisioningRun();
        $run->id = 'run-rollback-2';
        $run->progress = 55;
        $run->metadata = [];
        $run->exists = true;

        $auditService = $this->createMock(ProvisioningRunAuditService::class);
        $auditService->expects($this->once())
            ->method('logStep')
            ->with(
                $run,
                $this->callback(function (array $step): bool {
                    return ($step['stage'] ?? null) === 'rollback'
                        && ($step['action'] ?? null) === 'dispatch_rollback'
                        && ($step['status'] ?? null) === 'failed'
                        && ($step['error_message'] ?? null) === 'Tenant schema unavailable for rollback dispatch';
                })
            );
        $auditService->expects($this->never())->method('updateRun');

        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->expects($this->never())->method('runInTenantContext');

        $controller = $this->makeController(
            $tenantContext,
            $auditService,
            $this->createMock(ConfigDriftDetector::class),
            $tenant,
            null,
        );

        $this->invokeTriggerRollbackForFailedProvisioningTask(
            $controller,
            $task,
            $run,
            RouterTask::STATUS_FAILED,
            true,
            ['verification_status' => 'failed'],
            'deploy failed',
        );

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_logs_a_failure_when_no_snapshot_is_available_for_rollback_dispatch(): void
    {
        Queue::fake([RollbackRouterConfigJob::class]);

        $tenant = new Tenant();
        $tenant->id = 'tenant-rollback-3';
        $tenant->schema_created = true;
        $tenant->schema_name = 'ts_rollback_test';

        $router = new Router();
        $router->id = 'router-rollback-3';
        $router->name = 'Core-03';

        $task = new RouterTask();
        $task->tenant_id = $tenant->id;
        $task->router_id = $router->id;
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;

        $run = new ProvisioningRun();
        $run->id = 'run-rollback-3';
        $run->progress = 60;
        $run->metadata = [];
        $run->exists = true;

        $auditService = $this->createMock(ProvisioningRunAuditService::class);
        $auditService->expects($this->once())
            ->method('logStep')
            ->with(
                $run,
                $this->callback(function (array $step): bool {
                    return ($step['stage'] ?? null) === 'rollback'
                        && ($step['action'] ?? null) === 'dispatch_rollback'
                        && ($step['status'] ?? null) === 'failed'
                        && ($step['error_message'] ?? null) === 'No config snapshot available for rollback';
                })
            );
        $auditService->expects($this->never())->method('updateRun');

        $driftDetector = $this->createMock(ConfigDriftDetector::class);
        $driftDetector->expects($this->once())
            ->method('getLatestSnapshot')
            ->with($router)
            ->willReturn(null);

        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->expects($this->once())
            ->method('runInTenantContext')
            ->with($tenant, $this->isType('callable'))
            ->willReturnCallback(function ($tenant, callable $callback) {
                return $callback();
            });

        $controller = $this->makeController($tenantContext, $auditService, $driftDetector, $tenant, $router);

        $this->invokeTriggerRollbackForFailedProvisioningTask(
            $controller,
            $task,
            $run,
            RouterTask::STATUS_FAILED,
            true,
            ['verification_status' => 'failed'],
            'deploy failed',
        );

        Queue::assertNothingPushed();
    }
}