<?php

namespace Tests\Unit\Services;

use App\Models\ConfigSnapshot;
use App\Models\Router;
use App\Services\Deployment\ConfigDriftDetector;
use App\Services\Deployment\DeploymentSafetyService;
use App\Services\RouterDriver\DriverRegistry;
use App\Services\RouterDriver\RouterDriverInterface;
use App\Services\RouterDriver\VerificationResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeploymentSafetyServiceTest extends TestCase
{
    private function makeRouter(string $id = 'router-safety-1'): Router
    {
        $router = new Router();
        $router->id = $id;
        $router->name = 'Safety Router';
        $router->vendor = 'mikrotik';
        $router->model = 'hAP ac2';

        return $router;
    }

    #[Test]
    public function it_takes_a_snapshot_before_deployment_and_verifies_successfully(): void
    {
        $router = $this->makeRouter();

        $driver = $this->createMock(RouterDriverInterface::class);
        $driver->expects($this->once())->method('applyConfig')->willReturn(true);
        $driver->expects($this->once())->method('verifyConfig')->willReturn(
            new VerificationResult(true, ['apply' => true], null, ['ok' => true])
        );

        $registry = $this->createMock(DriverRegistry::class);
        $registry->method('getDriverForRouter')->willReturn($driver);

        $snapshot = new ConfigSnapshot();
        $snapshot->id = 'snapshot-safety-1';
        $snapshot->config_text = '/interface bridge add name=br-lan';

        $driftDetector = $this->createMock(ConfigDriftDetector::class);
        $driftDetector->expects($this->once())->method('snapshotConfiguration')->with($router)->willReturn($snapshot);

        $service = new DeploymentSafetyService($registry, $driftDetector);
        $result = $service->deployWithSafety($router, '/interface bridge add name=br-lan');

        $this->assertTrue($result->success);
        $this->assertTrue($result->snapshotTaken);
        $this->assertTrue($result->verificationPassed);
        $this->assertSame('snapshot-safety-1', $result->snapshotId);
    }

    #[Test]
    public function it_rolls_back_when_post_deploy_verification_fails(): void
    {
        $router = $this->makeRouter('router-safety-2');

        $driver = $this->createMock(RouterDriverInterface::class);
        $driver->expects($this->once())->method('applyConfig')->willReturn(true);
        $driver->expects($this->once())->method('verifyConfig')->willReturn(
            new VerificationResult(false, ['apply' => false], 'missing interface', ['failed' => true])
        );
        $driver->expects($this->once())->method('restoreConfig')->with($router, '/export compact')->willReturn(true);

        $registry = $this->createMock(DriverRegistry::class);
        $registry->method('getDriverForRouter')->willReturn($driver);

        $snapshot = new ConfigSnapshot();
        $snapshot->id = 'snapshot-safety-2';
        $snapshot->config_text = '/export compact';

        $driftDetector = $this->createMock(ConfigDriftDetector::class);
        $driftDetector->expects($this->once())->method('snapshotConfiguration')->willReturn($snapshot);

        $service = new DeploymentSafetyService($registry, $driftDetector);
        $result = $service->deployWithSafety($router, '/interface bridge add name=br-lan');

        $this->assertFalse($result->success);
        $this->assertTrue($result->rolledBack);
        $this->assertFalse($result->verificationPassed);
        $this->assertSame('snapshot-safety-2', $result->snapshotId);
    }
}
