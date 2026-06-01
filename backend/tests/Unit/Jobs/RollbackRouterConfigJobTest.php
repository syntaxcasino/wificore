<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RollbackRouterConfigJob;
use App\Models\ProvisioningRun;
use App\Services\ProvisioningRunAuditService;
use App\Services\RouterDriver\CommandResult;
use App\Services\RouterDriver\RouterDriverInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RollbackRouterConfigJobTest extends TestCase
{
    private function makeRouter(string $id): object
    {
        $router = new \App\Models\Router();
        $router->id = $id;
        $router->name = 'Test Router';
        $router->vendor = 'mikrotik';
        $router->model = 'hAP ac2';

        return $router;
    }

    private function invokeExecuteCompensatingPlan(
        RollbackRouterConfigJob $job,
        RouterDriverInterface $driver,
        ProvisioningRun $run,
        array $actions,
        ProvisioningRunAuditService $audit,
    ): bool {
        $invoker = \Closure::bind(function (
            mixed $driver,
            ProvisioningRun $run,
            array $actions,
            ProvisioningRunAuditService $audit,
        ): bool {
            return $this->executeCompensatingPlan($driver, $run, $actions, $audit);
        }, $job, $job);

        return $invoker($driver, $run, $actions, $audit);
    }

    #[Test]
    public function it_executes_compensating_actions_and_marks_the_run_rolled_back(): void
    {
        $router = $this->makeRouter('router-rollback-job-1');
        $run = new ProvisioningRun();
        $run->id = 'run-rollback-job-1';
        $run->router_id = $router->id;
        $run->progress = 80;
        $run->status = ProvisioningRun::STATUS_FAILED;
        $run->current_stage = 'rollback_pending';

        $driver = $this->createMock(RouterDriverInterface::class);
        $driver->expects($this->exactly(2))
            ->method('executeCommand')
            ->willReturnOnConsecutiveCalls(
                new CommandResult(true, 'removed pool'),
                new CommandResult(true, 'removed profile'),
            );

        $audit = $this->createMock(ProvisioningRunAuditService::class);
        $audit->expects($this->exactly(4))
            ->method('logStep')
            ->willReturn(new \App\Models\ProvisioningStep());
        $audit->expects($this->once())
            ->method('updateRun')
            ->with(
                $run,
                ProvisioningRun::STATUS_ROLLED_BACK,
                100,
                'rolled_back',
                null,
                $this->callback(static fn (array $metadata): bool => ($metadata['rollback_strategy'] ?? null) === 'compensating_actions')
            )
            ->willReturn($run);

        $job = new RollbackRouterConfigJob($router, '/export compact', $run->id);
        $result = $this->invokeExecuteCompensatingPlan($job, $driver, $run, [
            [
                'source_step_id' => 'step-2',
                'source_sequence' => 2,
                'command' => '/ppp profile remove numbers=*2',
                'resource_id' => '*2',
            ],
            [
                'source_step_id' => 'step-1',
                'source_sequence' => 1,
                'command' => '/ip pool remove numbers=*1',
                'resource_id' => '*1',
            ],
        ], $audit);

        $this->assertTrue($result);
    }
}
