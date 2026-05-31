<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Api\InternalProvisioningTaskController;
use App\Models\RouterTask;
use App\Services\Deployment\ConfigDriftDetector;
use App\Services\ProvisioningRunAuditService;
use App\Services\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InternalProvisioningTaskControllerVerificationTest extends TestCase
{
    private function makeController(): InternalProvisioningTaskController
    {
        return new InternalProvisioningTaskController(
            $this->createMock(TenantContext::class),
            $this->createMock(ProvisioningRunAuditService::class),
            $this->createMock(ConfigDriftDetector::class),
        );
    }

    private function invokeApplyVerificationPolicy(
        InternalProvisioningTaskController $controller,
        RouterTask $task,
        string $status,
        bool $terminal,
        array $result,
        ?string $message,
        ?string $error,
        int $progress,
    ): array {
        $invoker = \Closure::bind(function (
            RouterTask $task,
            string $status,
            bool $terminal,
            array $result,
            ?string $message,
            ?string $error,
            int $progress,
        ): array {
            return $this->applyVerificationPolicy($task, $status, $terminal, $result, $message, $error, $progress);
        }, $controller, $controller);

        return $invoker($task, $status, $terminal, $result, $message, $error, $progress);
    }

    #[Test]
    public function it_downgrades_terminal_completed_status_when_verification_bundle_is_missing(): void
    {
        $controller = $this->makeController();
        $task = new RouterTask();
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;

        $result = $this->invokeApplyVerificationPolicy(
            $controller,
            $task,
            RouterTask::STATUS_COMPLETED,
            true,
            [],
            'ok',
            null,
            100,
        );

        $this->assertSame(RouterTask::STATUS_FAILED, $result[0]);
        $this->assertStringContainsString('Post-provision verification failed', (string) $result[1]);
        $this->assertSame($result[1], $result[2]);
        $this->assertSame('failed', $result[3]['verification_status']);
        $this->assertNotEmpty($result[3]['verification_missing']);
        $this->assertLessThanOrEqual(99, $result[4]);
    }

    #[Test]
    public function it_keeps_terminal_completed_status_when_verification_bundle_passes(): void
    {
        $controller = $this->makeController();
        $task = new RouterTask();
        $task->type = RouterTask::TYPE_APPLY_SERVICE_CONFIGS;

        $verification = [
            'verification' => [
                'resources' => [
                    'interface_bridge' => true,
                    'ip_pool' => true,
                    'ppp_profile' => true,
                    'pppoe_server' => true,
                    'ip_firewall' => true,
                    'queue' => true,
                    'wireguard' => true,
                ],
            ],
        ];

        $result = $this->invokeApplyVerificationPolicy(
            $controller,
            $task,
            RouterTask::STATUS_COMPLETED,
            true,
            $verification,
            'ok',
            null,
            100,
        );

        $this->assertSame(RouterTask::STATUS_COMPLETED, $result[0]);
        $this->assertSame('ok', $result[1]);
        $this->assertNull($result[2]);
        $this->assertSame($verification, $result[3]);
        $this->assertSame(100, $result[4]);
    }

    #[Test]
    public function it_skips_verification_gate_for_non_terminal_or_non_provisioning_callbacks(): void
    {
        $controller = $this->makeController();
        $task = new RouterTask();
        $task->type = RouterTask::TYPE_VERIFY_CONNECTIVITY;

        $result = $this->invokeApplyVerificationPolicy(
            $controller,
            $task,
            RouterTask::STATUS_COMPLETED,
            false,
            [],
            'stage complete',
            null,
            77,
        );

        $this->assertSame(RouterTask::STATUS_COMPLETED, $result[0]);
        $this->assertSame('stage complete', $result[1]);
        $this->assertNull($result[2]);
        $this->assertSame([], $result[3]);
        $this->assertSame(77, $result[4]);
    }
}
