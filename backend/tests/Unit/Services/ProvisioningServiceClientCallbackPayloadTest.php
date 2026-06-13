<?php

namespace Tests\Unit\Services;

use App\Models\RouterTask;
use App\Services\ProvisioningServiceClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProvisioningServiceClientCallbackPayloadTest extends TestCase
{
    private function invokeBuildTaskCallbackPayload(ProvisioningServiceClient $client, ?RouterTask $task, bool $terminal = true, ?string $stage = null): ?array
    {
        $invoker = \Closure::bind(function (?RouterTask $task, bool $terminal = true, ?string $stage = null): ?array {
            return $this->buildTaskCallbackPayload($task, $terminal, $stage);
        }, $client, $client);

        return $invoker($task, $terminal, $stage);
    }

    #[Test]
    public function it_includes_task_identity_in_callback_payload(): void
    {
        config()->set('app.url', 'https://example.test');
        config()->set('services.provisioning.api_key', 'test-api-key');

        $client = new ProvisioningServiceClient();

        $task = new RouterTask();
        $task->id = 'task-123';
        $task->tenant_id = 'tenant-abc';
        $task->router_id = 'router-xyz';

        $payload = $this->invokeBuildTaskCallbackPayload($client, $task, false, 'deploying_config');

        $this->assertIsArray($payload);
        $this->assertSame('https://example.test/api/internal/provisioning/router-tasks/task-123/status', $payload['url']);
        $this->assertSame('test-api-key', $payload['api_key']);
        $this->assertFalse($payload['terminal']);
        $this->assertSame('deploying_config', $payload['stage']);
        $this->assertSame('tenant-abc', $payload['tenant_id']);
        $this->assertSame('router-xyz', $payload['router_id']);
    }

    #[Test]
    public function it_returns_null_callback_payload_without_task(): void
    {
        config()->set('app.url', 'https://example.test');
        config()->set('services.provisioning.api_key', 'test-api-key');

        $client = new ProvisioningServiceClient();

        $payload = $this->invokeBuildTaskCallbackPayload($client, null);

        $this->assertNull($payload);
    }

    #[Test]
    public function it_reuses_completed_workflow_without_posting_a_duplicate_command(): void
    {
        config()->set('app.url', 'https://example.test');
        config()->set('services.provisioning.api_key', 'test-api-key');
        config()->set('services.provisioning.url', 'http://wificore-provisioning:8080');

        $client = new ProvisioningServiceClient();

        $router = new \App\Models\Router();
        $router->id = 'router-abc';
        $router->ip_address = '10.0.0.1';
        $router->username = 'admin';
        $router->password = Crypt::encryptString('secret');
        $router->port = 8728;
        $router->os_version = '7.18.0';
        $router->interface_list = ['ether1'];

        $task = new RouterTask();
        $task->id = 'task-123';
        $task->tenant_id = 'tenant-abc';
        $task->router_id = 'router-abc';
        $task->type = RouterTask::TYPE_APPLY_SERVICE_CONFIGS;
        $task->request_payload = ['script' => '/interface bridge add name=br-lan'];

        Http::fake([
            'http://wificore-provisioning:8080/api/v1/workflows/task-123' => Http::response([
                'success' => true,
                'data' => [
                    'router_id' => 'router-abc',
                    'idempotency_key' => 'task-123',
                    'status' => 'completed',
                    'completed_at' => '2026-06-01T00:00:00Z',
                    'result' => ['accepted' => true],
                ],
            ], 200),
            'http://wificore-provisioning:8080/*' => Http::response(['success' => false, 'error' => 'unexpected post'], 500),
        ]);

        $response = $client->submitTaskCommand(
            $router,
            'tenant-abc',
            RouterTask::TYPE_APPLY_SERVICE_CONFIGS,
            ['script' => '/interface bridge add name=br-lan'],
            $task,
        );

        $this->assertTrue($response['success']);
        $this->assertSame('duplicate_completed', $response['data']['status']);
        $this->assertSame('task-123', $response['data']['idempotency_key']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_reuses_active_workflow_for_deploy_script_without_posting_a_duplicate_command(): void
    {
        config()->set('app.url', 'https://example.test');
        config()->set('services.provisioning.api_key', 'test-api-key');
        config()->set('services.provisioning.url', 'http://wificore-provisioning:8080');

        $client = new ProvisioningServiceClient();

        $router = new \App\Models\Router();
        $router->id = 'router-def';
        $router->ip_address = '10.0.0.2';
        $router->username = 'admin';
        $router->password = Crypt::encryptString('secret');
        $router->port = 8728;
        $router->os_version = '7.18.0';
        $router->interface_list = ['ether1'];

        $task = new RouterTask();
        $task->id = 'task-456';
        $task->tenant_id = 'tenant-abc';
        $task->router_id = 'router-def';
        $task->type = RouterTask::TYPE_DEPLOY_SERVICE_CONFIG;
        $task->request_payload = ['service_type' => 'pppoe'];

        Http::fake([
            'http://wificore-provisioning:8080/api/v1/workflows/task-456' => Http::response([], 404),
            'http://wificore-provisioning:8080/api/v1/routers/router-def/workflows/active' => Http::response([
                'success' => true,
                'data' => [
                    'router_id' => 'router-def',
                    'idempotency_key' => 'task-456',
                    'status' => 'running',
                    'started_at' => '2026-06-01T00:00:00Z',
                ],
            ], 200),
            'http://wificore-provisioning:8080/*' => Http::response(['success' => false, 'error' => 'unexpected post'], 500),
        ]);

        $response = $client->deployScript($router, '/interface bridge add name=br-lan', 'tenant-abc', $task);

        $this->assertTrue($response['success']);
        $this->assertSame('duplicate_active', $response['data']['status']);
        $this->assertSame('task-456', $response['data']['idempotency_key']);
        Http::assertSentCount(2);
    }

}
