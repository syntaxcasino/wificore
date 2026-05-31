<?php

namespace App\Services;

use App\Contracts\ProvisioningCommandBus;
use App\Models\Router;
use App\Models\RouterConfig;
use App\Models\RouterTask;
use App\Services\MikroTik\RouterOsCapabilityRegistry;
use App\Services\MikroTik\RouterOsV7ProvisioningValidator;

class RouterTaskExecutionService
{
    public function __construct(
        protected ProvisioningCommandBus $provisioningClient,
        protected RouterOsCapabilityRegistry $capabilityRegistry,
        protected RouterOsV7ProvisioningValidator $routerOsValidator,
    ) {
    }

    public function callbacksEnabled(?RouterTask $task = null, bool $terminal = true, ?string $stage = null): bool
    {
        return $this->provisioningClient->callbacksEnabled($task, $terminal, $stage);
    }

    public function buildServiceScript(Router $router, ?string $script = null): string
    {
        if ($script !== null) {
            $serviceScript = trim($script);
        } else {
            $routerConfig = RouterConfig::where('router_id', $router->id)
                ->where('config_type', 'service')
                ->first();

            $serviceScript = trim((string) ($routerConfig?->config_content ?? ''));
        }

        if ($serviceScript === '') {
            throw new \RuntimeException('No valid service configuration found. Please generate the configuration first.');
        }

        $this->validateRouterOsCompatibility($router, $serviceScript);

        return $serviceScript;
    }

    public function submitTaskCommand(Router $router, string $tenantId, RouterTask $task, ?string $script = null): array
    {
        $payload = match ($task->type) {
            RouterTask::TYPE_DEPLOY_SERVICE_CONFIG => [
                'script' => $this->buildServiceScript($router, $script),
                'service_type' => $task->request_payload['service_type'] ?? null,
            ],
            RouterTask::TYPE_APPLY_SERVICE_CONFIGS => array_filter([
                'script' => $task->request_payload['script'] ?? $script,
                'commands' => $task->request_payload['commands'] ?? null,
            ], static fn ($value) => $value !== null && $value !== [] && $value !== ''),
            RouterTask::TYPE_VERIFY_CONNECTIVITY => [],
            RouterTask::TYPE_DISCOVER_INTERFACES => [
                'context' => 'provisioning',
                'filter_configurable' => true,
            ],
            default => throw new \InvalidArgumentException('Unsupported router task type: ' . $task->type),
        };

        return $this->provisioningClient->submitTaskCommand($router, $tenantId, $task->type, $payload, $task);
    }

    public function applyServiceConfigs(
        Router $router,
        string $tenantId,
        ?string $script = null,
        ?RouterTask $task = null,
        bool $terminalCallback = true,
        ?string $callbackStage = null,
    ): array {
        $serviceScript = $this->buildServiceScript($router, $script);
        $response = $this->provisioningClient->deployScript($router, $serviceScript, $tenantId, $task, $terminalCallback, $callbackStage);
        $payload = $response['data'] ?? [];

        return [
            'router_id' => $router->id,
            'method' => 'provisioning_service',
            'executed_at' => $payload['executed_at'] ?? null,
            'executed_commands' => $payload['executed_commands'] ?? null,
            'command_results' => $payload['command_results'] ?? [],
            'message' => $response['message'] ?? null,
        ];
    }

    public function provisionServiceWorkflow(
        Router $router,
        string $tenantId,
        ?string $script = null,
        ?RouterTask $task = null,
    ): array {
        $serviceScript = $this->buildServiceScript($router, $script);
        $response = $this->provisioningClient->provisionServiceWorkflow($router, $serviceScript, $tenantId, $task);
        $payload = $response['data'] ?? [];

        return [
            'router_id' => $router->id,
            'method' => 'provisioning_service',
            'executed_at' => $payload['executed_at'] ?? null,
            'executed_commands' => $payload['executed_commands'] ?? null,
            'command_results' => $payload['command_results'] ?? [],
            'status' => $payload['status'] ?? null,
            'model' => $payload['model'] ?? null,
            'os_version' => $payload['os_version'] ?? null,
            'identity' => $payload['identity'] ?? null,
            'interfaces' => $payload['interfaces'] ?? [],
            'last_seen' => $payload['last_seen'] ?? null,
            'message' => $response['message'] ?? null,
        ];
    }

    public function verifyConnectivity(
        Router $router,
        string $tenantId,
        ?RouterTask $task = null,
        bool $terminalCallback = true,
        ?string $callbackStage = null,
    ): array {
        return $this->provisioningClient->verifyConnectivity($router, $tenantId, $task, $terminalCallback, $callbackStage);
    }

    public function discoverInterfaces(Router $router, string $tenantId, ?RouterTask $task = null): array
    {
        return $this->provisioningClient->fetchLiveData($router, 'provisioning', $tenantId, $task);
    }

    private function validateRouterOsCompatibility(Router $router, string $script): void
    {
        $versionProfile = $this->capabilityRegistry->resolveProfile($router->os_version);
        if (! ($versionProfile['supported'] ?? false)) {
            throw new \RuntimeException($versionProfile['error'] ?? 'Unsupported RouterOS version for provisioning.');
        }

        $validation = $this->routerOsValidator->validateScript($router, $script);
        if (! ($validation['valid'] ?? false)) {
            $message = implode(' | ', array_slice($validation['errors'] ?? ['RouterOS validation failed'], 0, 6));
            throw new \RuntimeException('RouterOS validation failed: ' . $message);
        }
    }
}
