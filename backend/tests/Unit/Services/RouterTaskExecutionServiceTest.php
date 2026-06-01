<?php

namespace Tests\Unit\Services;

use App\Contracts\ProvisioningCommandBus;
use App\Models\Router;
use App\Models\RouterTask;
use App\Services\MikroTik\RouterOsCapabilityRegistry;
use App\Services\MikroTik\RouterOsV7ProvisioningValidator;
use App\Services\RouterTaskExecutionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterTaskExecutionServiceTest extends TestCase
{
    private function makeService(ProvisioningCommandBus $bus): RouterTaskExecutionService
    {
        return new RouterTaskExecutionService(
            $bus,
            new RouterOsCapabilityRegistry(),
            new RouterOsV7ProvisioningValidator(new RouterOsCapabilityRegistry()),
            new \App\Services\RouterProvisioningPreflightService(),
        );
    }

    #[Test]
    public function it_blocks_submit_when_routeros_version_is_missing(): void
    {
        $bus = $this->createMock(ProvisioningCommandBus::class);
        $bus->expects($this->never())->method('submitTaskCommand');

        $service = $this->makeService($bus);

        $router = new Router();
        $router->id = 'router-1';
        $router->os_version = null;
        $router->interface_list = ['ether1', 'ether2'];

        $task = new RouterTask();
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;
        $task->request_payload = ['service_type' => 'pppoe'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RouterOS version is missing');

        $service->submitTaskCommand(
            $router,
            'tenant-1',
            $task,
            '/interface/bridge/add name=br-lan'
        );
    }

    #[Test]
    public function it_blocks_submit_when_script_fails_routeros_validation(): void
    {
        $bus = $this->createMock(ProvisioningCommandBus::class);
        $bus->expects($this->never())->method('submitTaskCommand');

        $service = $this->makeService($bus);

        $router = new Router();
        $router->id = 'router-2';
        $router->os_version = '7.15.3';
        $router->interface_list = ['ether1', 'ether2'];

        $task = new RouterTask();
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;
        $task->request_payload = ['service_type' => 'hotspot'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RouterOS validation failed');

        $service->submitTaskCommand(
            $router,
            'tenant-1',
            $task,
            '/system reset-configuration'
        );
    }

    #[Test]
    public function it_submits_task_when_script_is_valid(): void
    {
        $bus = $this->createMock(ProvisioningCommandBus::class);
        $bus->expects($this->once())
            ->method('submitTaskCommand')
            ->with(
                $this->isInstanceOf(Router::class),
                'tenant-1',
                RouterTask::TYPE_DEPLOY_SERVICE_CONFIG,
                $this->callback(function (array $payload): bool {
                    return isset($payload['script'])
                        && str_contains($payload['script'], '/interface/bridge/add')
                        && ($payload['service_type'] ?? null) === 'pppoe';
                }),
                $this->isInstanceOf(RouterTask::class)
            )
            ->willReturn(['success' => true, 'data' => ['accepted' => true]]);

        $service = $this->makeService($bus);

        $router = new Router();
        $router->id = 'router-3';
        $router->os_version = '7.18.0';
        $router->interface_list = ['ether1', 'ether2'];

        $task = new RouterTask();
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;
        $task->request_payload = ['service_type' => 'pppoe'];

        $response = $service->submitTaskCommand(
            $router,
            'tenant-1',
            $task,
            '/interface/bridge/add name=br-lan'
        );

        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['accepted']);
    }

    #[Test]
    public function it_blocks_submit_when_interface_preflight_fails(): void
    {
        $bus = $this->createMock(ProvisioningCommandBus::class);
        $bus->expects($this->never())->method('submitTaskCommand');

        $service = $this->makeService($bus);

        $router = new Router();
        $router->id = 'router-4';
        $router->os_version = '7.18.0';
        $router->interface_list = ['ether1', 'ether2'];

        $task = new RouterTask();
        $task->type = RouterTask::TYPE_APPLY_SERVICE_CONFIGS;
        $task->request_payload = [
            'enable_hotspot' => true,
            'hotspot_interfaces' => ['ether9'],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Router interface preflight failed');

        $service->submitTaskCommand(
            $router,
            'tenant-1',
            $task,
            '/interface/bridge/add name=br-lan'
        );
    }

}
