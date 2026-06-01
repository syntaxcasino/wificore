<?php

namespace Tests\Unit\Services;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Services\ProvisioningRunAuditService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProvisioningRunAuditServiceTest extends TestCase
{
    private function invokeNormalizeCommandResult(array $result, int $index): array
    {
        $service = new ProvisioningRunAuditService();
        $method = new \ReflectionMethod($service, 'normalizeCommandResult');
        $method->setAccessible(true);

        return $method->invoke($service, $result, $index);
    }

    #[Test]
    public function it_normalizes_success_and_trap_command_results(): void
    {
        $success = $this->invokeNormalizeCommandResult([
            'command' => '/ppp profile add name=test',
            'status' => 'done',
            'message' => 'ok',
        ], 0);

        $trap = $this->invokeNormalizeCommandResult([
            'command' => '/ip firewall add',
            'trap_message' => 'duplicate rule',
            'status' => 'trap',
        ], 1);

        $this->assertSame(ProvisioningStep::STATUS_COMPLETED, $success['status']);
        $this->assertSame('/ppp profile add name=test', $success['command']);
        $this->assertSame(ProvisioningStep::STATUS_FAILED, $trap['status']);
        $this->assertSame('duplicate rule', $trap['trap_message']);
        $this->assertSame('command_result', $trap['stage']);
    }

    #[Test]
    public function it_preserves_trap_message_from_failed_command_status(): void
    {
        $result = $this->invokeNormalizeCommandResult([
            'command' => '/ip firewall filter add',
            'status' => 'trap',
            'message' => 'duplicate rule',
        ], 2);

        $this->assertSame(ProvisioningStep::STATUS_FAILED, $result['status']);
        $this->assertSame('duplicate rule', $result['trap_message']);
        $this->assertSame('duplicate rule', $result['error_message']);
    }

    #[Test]
    public function it_builds_a_conservative_compensating_rollback_plan_from_step_records(): void
    {
        $steps = [
            [
                'id' => 'step-2',
                'sequence' => 2,
                'stage' => 'provisioning',
                'action' => 'add_profile',
                'status' => ProvisioningStep::STATUS_COMPLETED,
                'command' => '/ppp profile add name=ppp-a',
                'response_payload' => ['ret' => '*2'],
            ],
            [
                'id' => 'step-1',
                'sequence' => 1,
                'stage' => 'provisioning',
                'action' => 'add_pool',
                'status' => ProvisioningStep::STATUS_COMPLETED,
                'command' => '/ip pool add name=pool-a ranges=10.10.0.2-10.10.0.254',
                'response_payload' => ['.id' => '*1'],
            ],
        ];

        $plan = (new ProvisioningRunAuditService())->buildRollbackPlanFromSteps($steps);

        $this->assertTrue($plan['complete']);
        $this->assertSame('compensating_actions', $plan['strategy']);
        $this->assertCount(2, $plan['actions']);
        $this->assertSame('/ppp profile remove numbers=*2', $plan['actions'][0]['command']);
        $this->assertSame('/ip pool remove numbers=*1', $plan['actions'][1]['command']);
    }

    #[Test]
    public function it_marks_the_rollback_plan_incomplete_when_a_step_cannot_be_reversed_safely(): void
    {
        $steps = [
            [
                'id' => 'step-3',
                'sequence' => 1,
                'stage' => 'provisioning',
                'action' => 'set_bridge',
                'status' => ProvisioningStep::STATUS_COMPLETED,
                'command' => '/interface bridge set [find name=br-lan] comment="updated"',
                'response_payload' => ['.id' => '*3'],
            ],
        ];

        $plan = (new ProvisioningRunAuditService())->buildRollbackPlanFromSteps($steps);

        $this->assertFalse($plan['complete']);
        $this->assertNotEmpty($plan['incomplete_steps']);
        $this->assertSame('non_reversible_mutation', $plan['incomplete_steps'][0]['reason']);
    }
}
