<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CanaryDeployJob;
use App\Models\CanaryDeployment;
use App\Models\Router;
use App\Services\Deployment\DeploymentSafetyResult;
use App\Services\Deployment\DeploymentSafetyService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanaryDeployJobTest extends TestCase
{
    private function makeRouter(string $id = 'router-canary-1'): Router
    {
        $router = new Router();
        $router->id = $id;
        $router->name = 'Canary Router';
        $router->vendor = 'mikrotik';
        $router->model = 'hAP ac2';

        return $router;
    }

    #[Test]
    public function it_uses_the_deployment_safety_service_with_snapshot_exemption_for_canary_rollouts(): void
    {
        $deployment = new CanaryDeployment();
        $deployment->id = 99;
        $deployment->status = 'canary_running';

        $router = $this->makeRouter();

        $service = $this->createMock(DeploymentSafetyService::class);
        $service->expects($this->once())
            ->method('deployWithSafety')
            ->with(
                $router,
                '/interface bridge add name=br-lan',
                $this->callback(static fn (array $options): bool => ($options['allow_snapshot_exemption'] ?? null) === true)
            )
            ->willReturn(new DeploymentSafetyResult(true, null, false, true, false, 'Configuration applied and verified'));

        $job = new CanaryDeployJob($deployment, $router, '/interface bridge add name=br-lan');
        $job->handle($service);

        $this->assertTrue(true);
    }
}
