<?php

namespace Tests\Unit\Services;

use App\Models\RouterTask;
use App\Services\ProvisioningServiceClient;
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
}
