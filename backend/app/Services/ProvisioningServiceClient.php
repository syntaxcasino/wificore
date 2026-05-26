<?php

namespace App\Services;

use App\Contracts\ProvisioningCommandBus;
use App\Models\Router;
use App\Models\RouterTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

/**
 * Client for communicating with the provisioning service
 */
class ProvisioningServiceClient implements ProvisioningCommandBus
{
    protected string $baseUrl;
    protected int $timeout;
    protected ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.provisioning.url', env('PROVISIONING_SERVICE_URL', 'http://wificore-provisioning:8080'));
        $this->timeout = env('PROVISIONING_SERVICE_TIMEOUT', 30);
        $this->apiKey = (string) config('services.provisioning.api_key', env('PROVISIONING_SERVICE_API_KEY', ''));
    }

    protected function getHttpClient()
    {
        $client = Http::timeout($this->timeout);

        if ($this->apiKey) {
            $client = $client->withHeaders([
                'X-API-Key' => $this->apiKey,
            ]);
        }

        return $client;
    }

    protected function requestJson(string $path, array $payload, string $errorPrefix, bool $throwOnBusy = true): array
    {
        $response = $this->getHttpClient()->post($this->baseUrl . $path, $payload);

        if ($response->failed()) {
            $message = trim((string) $response->body());
            if ($message === '') {
                $message = 'HTTP ' . $response->status() . ' with empty response body';
            }
            throw new \Exception($errorPrefix . ': ' . $message);
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new \Exception($errorPrefix . ': invalid JSON response');
        }
        if (($data['success'] ?? false) !== true) {
            // Check if this is a "router busy" error - for monitoring commands, we can skip gracefully
            $error = $data['error'] ?? '';
            $status = $data['data']['status'] ?? '';
            if (!$throwOnBusy && $status === 'router_busy') {
                Log::warning('Provisioning service busy, skipping command', [
                    'command_id' => $payload['command_id'] ?? 'unknown',
                    'error' => $error,
                    'active_idempotency_key' => $data['data']['active_idempotency_key'] ?? null,
                ]);
                // Return a synthetic success response with busy status
                return [
                    'success' => true,
                    'message' => 'Skipped: provisioning service busy',
                    'data' => array_merge($data['data'] ?? [], ['skipped' => true]),
                ];
            }
            throw new \Exception($errorPrefix . ': ' . $error);
        }

        return $data;
    }

    protected function buildRouterConnectionPayload(Router $router): array
    {
        return [
            'ip_address' => $router->ip_address,
            'vpn_ip' => $router->vpn_ip ? explode('/', $router->vpn_ip)[0] : null,
            'username' => $router->username,
            'password' => Crypt::decryptString($router->password),
            'ssh_port' => (int) ($router->port ?: 22),
        ];
    }

    protected function normalizeConnectionPayload(array $connection): array
    {
        return [
            'ip_address' => (string) ($connection['ip_address'] ?? ''),
            'vpn_ip' => isset($connection['vpn_ip']) && $connection['vpn_ip'] !== ''
                ? explode('/', (string) $connection['vpn_ip'])[0]
                : null,
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'ssh_port' => (int) ($connection['ssh_port'] ?? 22),
        ];
    }

    protected function buildIdempotencyKey(Router $router, string $operation, ?RouterTask $task = null): string
    {
        if ($task?->id) {
            return (string) $task->id;
        }

        return implode(':', [$operation, (string) $router->id]);
    }

    public function callbacksEnabled(?RouterTask $task = null, bool $terminal = true, ?string $stage = null): bool
    {
        return $this->buildTaskCallbackPayload($task, $terminal, $stage) !== null;
    }

    protected function buildTaskCallbackPayload(?RouterTask $task, bool $terminal = true, ?string $stage = null): ?array
    {
        if (!$task) {
            return null;
        }

        $appUrl = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        if ($appUrl === '' || $this->apiKey === '') {
            return null;
        }

        return [
            'url' => $appUrl . '/api/internal/provisioning/router-tasks/' . $task->id . '/status',
            'api_key' => $this->apiKey,
            'terminal' => $terminal,
            'stage' => $stage,
        ];
    }

    protected function buildRouterServiceCallbackPayload(string $serviceId, bool $terminal = true, ?string $stage = null): ?array
    {
        $appUrl = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        if ($appUrl === '' || $this->apiKey === '') {
            return null;
        }

        return [
            'url' => $appUrl . '/api/internal/provisioning/router-services/' . $serviceId . '/status',
            'api_key' => $this->apiKey,
            'terminal' => $terminal,
            'stage' => $stage,
        ];
    }

    protected function buildMonitoringCallbackPayload(string $tenantId, string $path, bool $terminal = true, ?string $stage = null): ?array
    {
        $appUrl = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        if ($appUrl === '' || $this->apiKey === '') {
            return null;
        }

        return [
            'url' => $appUrl . '/api/internal/provisioning/monitoring/tenants/' . $tenantId . '/' . ltrim($path, '/'),
            'api_key' => $this->apiKey,
            'terminal' => $terminal,
            'stage' => $stage,
        ];
    }

    public function submitRouterServiceDeploymentCommand(Router $router, string $tenantId, string $serviceId, string $script, ?string $requestedBy = null): array
    {
        try {
            Log::debug('Submitting router service deployment command', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'service_id' => $serviceId,
            ]);

            return $this->requestJson('/api/v1/commands', [
                'command_id' => 'router-service:' . $serviceId,
                'router_id' => (string) $router->id,
                'tenant_id' => $tenantId,
                'type' => RouterTask::TYPE_APPLY_SERVICE_CONFIGS,
                'requested_by' => $requestedBy,
                'requested_at' => now()->toIso8601String(),
                'payload' => [
                    'script' => $script,
                    'connection' => $this->buildRouterConnectionPayload($router),
                ],
                'callback' => $this->buildRouterServiceCallbackPayload($serviceId, true),
            ], 'Router service deployment command submission failed');
        } catch (\Exception $e) {
            Log::error('Router service deployment command submission error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'service_id' => $serviceId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function submitVpnConnectivityWaitCommand(string $tenantId, array $payload): array
    {
        try {
            Log::debug('Submitting VPN connectivity wait command', [
                'tenant_id' => $tenantId,
                'router_count' => count($payload['monitoring']['routers'] ?? []),
            ]);

            $routerId = (string) (($payload['monitoring']['routers'][0]['router_id'] ?? 'tenant-vpn-verification'));

            return $this->requestJson('/api/v1/commands', [
                'command_id' => 'vpn-connectivity:' . $tenantId . ':' . now()->format('YmdHis'),
                'tenant_id' => $tenantId,
                'router_id' => $routerId,
                'type' => 'wait_vpn_connectivity',
                'requested_at' => now()->toIso8601String(),
                'payload' => $payload,
                'callback' => $this->buildMonitoringCallbackPayload($tenantId, 'vpn-verification', true),
            ], 'VPN connectivity wait command submission failed');
        } catch (\Exception $e) {
            Log::error('VPN connectivity wait command submission error', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function submitLiveDataRefreshCommand(string $tenantId, array $payload): array
    {
        try {
            Log::debug('Submitting live data refresh command', [
                'tenant_id' => $tenantId,
                'router_count' => count($payload['monitoring']['routers'] ?? []),
            ]);

            return $this->requestJson('/api/v1/commands', [
                'command_id' => 'live-data:' . $tenantId . ':' . now()->format('YmdHis'),
                'tenant_id' => $tenantId,
                'router_id' => 'tenant-live-data',
                'type' => 'refresh_live_data',
                'requested_at' => now()->toIso8601String(),
                'payload' => $payload,
                'callback' => $this->buildMonitoringCallbackPayload($tenantId, 'live-data', true),
            ], 'Live data refresh command submission failed', false);
        } catch (\Exception $e) {
            Log::error('Live data refresh command submission error', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function submitRouterStatusRefreshCommand(string $tenantId, array $payload): array
    {
        try {
            Log::debug('Submitting router status refresh command', [
                'tenant_id' => $tenantId,
                'router_count' => count($payload['monitoring']['routers'] ?? []),
            ]);

            return $this->requestJson('/api/v1/commands', [
                'command_id' => 'router-status:' . $tenantId . ':' . now()->format('YmdHis'),
                'tenant_id' => $tenantId,
                'router_id' => 'tenant-router-status',
                'type' => 'refresh_router_status',
                'requested_at' => now()->toIso8601String(),
                'payload' => $payload,
                'callback' => $this->buildMonitoringCallbackPayload($tenantId, 'router-status', true),
            ], 'Router status refresh command submission failed', false);
        } catch (\Exception $e) {
            Log::error('Router status refresh command submission error', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function submitVpnStatusRefreshCommand(string $tenantId, array $payload): array
    {
        try {
            Log::debug('Submitting VPN status refresh command', [
                'tenant_id' => $tenantId,
                'tunnel_count' => count($payload['monitoring']['tunnels'] ?? []),
                'peer_mapping_count' => count($payload['monitoring']['peer_mappings'] ?? []),
            ]);

            return $this->requestJson('/api/v1/commands', [
                'command_id' => 'vpn-status:' . $tenantId . ':' . now()->format('YmdHis'),
                'tenant_id' => $tenantId,
                'router_id' => 'tenant-monitoring',
                'type' => 'refresh_vpn_status',
                'requested_at' => now()->toIso8601String(),
                'payload' => $payload,
                'callback' => $this->buildMonitoringCallbackPayload($tenantId, 'vpn-status', true),
            ], 'VPN status refresh command submission failed', false);
        } catch (\Exception $e) {
            Log::error('VPN status refresh command submission error', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function submitRouterMetricsCommand(string $tenantId, array $payload): array
    {
        try {
            Log::debug('Submitting router metrics computation command', [
                'tenant_id' => $tenantId,
                'router_count' => count($payload['monitoring']['routers'] ?? []),
                'time_range_count' => count($payload['metrics']['time_ranges'] ?? []),
            ]);

            return $this->requestJson('/api/v1/commands', [
                'command_id' => 'router-metrics:' . $tenantId . ':' . now()->format('YmdHis'),
                'tenant_id' => $tenantId,
                'router_id' => 'tenant-router-metrics',
                'type' => 'compute_router_metrics',
                'requested_at' => now()->toIso8601String(),
                'payload' => $payload,
                'callback' => $this->buildMonitoringCallbackPayload($tenantId, 'router-metrics', true),
            ], 'Router metrics computation command submission failed', false);
        } catch (\Exception $e) {
            Log::error('Router metrics computation command submission error', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function submitTaskCommand(Router $router, string $tenantId, string $type, array $payload = [], ?RouterTask $task = null): array
    {
        try {
            Log::debug('Submitting provisioning command', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'type' => $type,
                'task_id' => $task?->id,
            ]);

            $commandPayload = array_merge($payload, [
                'connection' => $this->buildRouterConnectionPayload($router),
            ]);

            return $this->requestJson('/api/v1/commands', [
                'command_id' => (string) ($task?->id ?: $this->buildIdempotencyKey($router, $type, $task)),
                'task_id' => $task?->id,
                'router_id' => (string) $router->id,
                'tenant_id' => $tenantId,
                'type' => $type,
                'requested_by' => (string) ($task?->user_id ?? ''),
                'requested_at' => now()->toIso8601String(),
                'payload' => $commandPayload,
                'callback' => $this->buildTaskCallbackPayload($task, true),
            ], 'Provisioning command submission failed');
        } catch (\Exception $e) {
            Log::error('Provisioning command submission error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'type' => $type,
                'task_id' => $task?->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function provision(Router $router, array $commands, string $tenantId): array
    {
        try {
            Log::debug('Provisioning router via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'command_count' => count($commands)
            ]);

            return $this->requestJson('/api/v1/provision', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'configuration' => [
                    'ip_address' => $router->ip_address,
                    'vpn_ip' => $router->vpn_ip,
                    'username' => $router->username,
                    'password' => Crypt::decryptString($router->password),
                    'ssh_port' => (int) ($router->port ?: 22),
                    'commands' => $commands,
                ],
            ], 'Provisioning service request failed');
        } catch (\Exception $e) {
            Log::error('Provisioning service error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function fetchLiveData(Router $router, string $context, string $tenantId, ?RouterTask $task = null): array
    {
        try {
            Log::debug('Fetching live data via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'context' => $context,
            ]);

            $data = $this->requestJson('/api/v1/live-data', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'context' => $context,
                'connection' => $this->buildRouterConnectionPayload($router),
                'filter_configurable' => $context === 'provisioning',
                'callback' => $this->buildTaskCallbackPayload($task),
                'idempotency_key' => $this->buildIdempotencyKey($router, 'provision-service', $task),
            ], 'Live data fetch failed');

            return $data['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Live data fetch error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deployScript(Router $router, string $script, string $tenantId, ?RouterTask $task = null, bool $terminalCallback = true, ?string $callbackStage = null): array
    {
        try {
            Log::debug('Deploying script via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'script_length' => strlen($script),
            ]);

            return $this->requestJson('/api/v1/deploy-script', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'script' => $script,
                'connection' => $this->buildRouterConnectionPayload($router),
                'callback' => $this->buildTaskCallbackPayload($task, $terminalCallback, $callbackStage),
            ], 'Script deployment failed');
        } catch (\Exception $e) {
            Log::error('Script deployment error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function executeCommands(Router $router, array $commands, string $tenantId, ?RouterTask $task = null): array
    {
        try {
            Log::debug('Executing commands via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'command_count' => count($commands),
            ]);

            $data = $this->requestJson('/api/v1/execute', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'commands' => $commands,
                'connection' => $this->buildRouterConnectionPayload($router),
                'callback' => $this->buildTaskCallbackPayload($task),
            ], 'Command execution failed');

            return $data['results'] ?? [];
        } catch (\Exception $e) {
            Log::error('Command execution error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function executeCommandsWithConnection(string $routerId, array $connection, array $commands, string $tenantId, ?RouterTask $task = null): array
    {
        try {
            Log::debug('Executing commands via provisioning service using raw connection payload', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'command_count' => count($commands),
            ]);

            $data = $this->requestJson('/api/v1/execute', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'commands' => array_values($commands),
                'connection' => $this->normalizeConnectionPayload($connection),
                'callback' => $this->buildTaskCallbackPayload($task),
            ], 'Command execution failed');

            return $data['results'] ?? [];
        } catch (\Exception $e) {
            Log::error('Command execution error', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function fetchLiveDataWithConnection(string $routerId, array $connection, string $context, string $tenantId, bool $filterConfigurable = false, ?RouterTask $task = null): array
    {
        try {
            Log::debug('Fetching live data via provisioning service using raw connection payload', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'context' => $context,
            ]);

            $data = $this->requestJson('/api/v1/live-data', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'context' => $context,
                'connection' => $this->normalizeConnectionPayload($connection),
                'filter_configurable' => $filterConfigurable,
                'callback' => $this->buildTaskCallbackPayload($task),
            ], 'Live data fetch failed');

            return $data['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Live data fetch error', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deployScriptWithConnection(string $routerId, array $connection, string $script, string $tenantId, ?RouterTask $task = null, bool $terminalCallback = true, ?string $callbackStage = null): array
    {
        try {
            Log::debug('Deploying script via provisioning service using raw connection payload', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'script_length' => strlen($script),
            ]);

            return $this->requestJson('/api/v1/deploy-script', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'script' => $script,
                'connection' => $this->normalizeConnectionPayload($connection),
                'callback' => $this->buildTaskCallbackPayload($task, $terminalCallback, $callbackStage),
            ], 'Script deployment failed');
        } catch (\Exception $e) {
            Log::error('Script deployment error', [
                'router_id' => $routerId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function provisionServiceWorkflow(Router $router, string $script, string $tenantId, ?RouterTask $task = null): array
    {
        try {
            Log::debug('Submitting full service provisioning workflow to provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'script_length' => strlen($script),
            ]);

            return $this->requestJson('/api/v1/provision-service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'script' => $script,
                'connection' => $this->buildRouterConnectionPayload($router),
                'callback' => $this->buildTaskCallbackPayload($task),
            ], 'Service provisioning workflow failed');
        } catch (\Exception $e) {
            Log::error('Service provisioning workflow error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function verifyConnectivity(
        Router $router,
        string $tenantId,
        ?RouterTask $task = null,
        bool $terminalCallback = true,
        ?string $callbackStage = null,
    ): array {
        try {
            Log::debug('Verifying connectivity via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
            ]);

            return $this->requestJson('/api/v1/verify', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'connection' => $this->buildRouterConnectionPayload($router),
                'callback' => $this->buildTaskCallbackPayload($task, $terminalCallback, $callbackStage),
            ], 'Connectivity verification failed');
        } catch (\Exception $e) {
            Log::error('Connectivity verification error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function checkHealth(): array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/health');

            if ($response->failed()) {
                throw new \Exception('Health check failed');
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Provisioning service health check failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
