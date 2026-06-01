<?php

namespace Tests\Unit\Jobs;

use App\Jobs\DeployRouterConfigJob;
use App\Models\Router;
use App\Services\Deployment\DeploymentSafetyResult;
use App\Services\Deployment\DeploymentSafetyService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeployRouterConfigJobTest extends TestCase
{
    private function makeRouter(string $id = 'router-deploy-1'): Router
    {
        $router = new Router();
        $router->id = $id;
        $router->name = 'Deploy Router';
        $router->vendor = 'mikrotik';
        $router->model = 'hAP ac2';

        return $router;
    }

    #[Test]
    public function it_uses_the_deployment_safety_service_and_completes_on_success(): void
    {
        $router = $this->makeRouter();

        $service = $this->createMock(DeploymentSafetyService::class);
        $service->expects($this->once())
            ->method('deployWithSafety')
            ->with(
                $router,
                '/interface bridge add name=br-lan',
                $this->callback(static fn (array $options): bool => ($options['allow_snapshot_exemption'] ?? null) === false)
            )
            ->willReturn(new DeploymentSafetyResult(true, 1, true, true, false, 'Configuration applied and verified', ['valid' => true], [], null));

        $job = new DeployRouterConfigJob($router, '/interface bridge add name=br-lan', 'v1');
        $job->handle($service);

        $this->assertTrue(true);
    }
}
