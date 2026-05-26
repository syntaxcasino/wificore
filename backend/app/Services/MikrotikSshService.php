<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

class MikrotikSshService
{
    protected ?Router $router = null;

    public function __construct(?Router $router = null)
    {
        $this->router = $router;
    }

    public function executeScript(Router|string $routerOrPath, ?string $script = null): array|string
    {
        if ($routerOrPath instanceof Router) {
            $router = $routerOrPath;
            $scriptContent = (string) $script;
            $tenantId = (string) ($router->tenant_id ?? '');
            if ($tenantId === '') {
                throw new \RuntimeException('Router tenant context is not available for script deployment.');
            }

            return app(ProvisioningServiceClient::class)->deployScript($router, $scriptContent, $tenantId);
        }

        $localRscPath = $routerOrPath;
        if (!$this->router) {
            throw new \RuntimeException('Router context is required before executing a script file.');
        }
        if (!is_readable($localRscPath)) {
            throw new \InvalidArgumentException("File not found: {$localRscPath}");
        }

        $response = app(ProvisioningServiceClient::class)->deployScript(
            $this->router,
            file_get_contents($localRscPath) ?: '',
            $this->resolveTenantId($this->router),
        );

        return (string) ($response['message'] ?? 'Script deployed successfully');
    }

    public function executeCommand(Router $router, string $command): array
    {
        $results = app(ProvisioningServiceClient::class)->executeCommands(
            $router,
            [$command],
            $this->resolveTenantId($router),
        );

        $firstResult = $results[0] ?? [];
        $output = (string) ($firstResult['output'] ?? $firstResult['message'] ?? $firstResult['result'] ?? '');

        return [
            'success' => true,
            'command' => $command,
            'output' => $output,
            'results' => $results,
        ];
    }

    public function fetchInterfaces(Router $router, bool $filterConfigurable = false): array
    {
        return app(ProvisioningServiceClient::class)->fetchLiveData(
            $router,
            $filterConfigurable ? 'provisioning' : 'details',
            $this->resolveTenantId($router),
        );
    }

    public function fetchLiveData(Router $router, bool $includeInterfaces = false): array
    {
        return app(ProvisioningServiceClient::class)->fetchLiveData(
            $router,
            $includeInterfaces ? 'details' : 'live',
            $this->resolveTenantId($router),
        );
    }

    public function testConnection(Router $router): array
    {
        try {
            app(ProvisioningServiceClient::class)->verifyConnectivity($router, $this->resolveTenantId($router));
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getSystemInfo(Router $router): array
    {
        return $this->fetchLiveData($router, false);
    }

    public function getInterfaces(Router $router): array
    {
        $data = $this->fetchInterfaces($router, false);
        return $data['interfaces'] ?? [];
    }

    protected function resolveTenantId(Router $router): string
    {
        $tenantId = (string) ($router->tenant_id ?? '');
        if ($tenantId === '') {
            throw new \RuntimeException('Router tenant context is not available for provisioning service execution.');
        }

        return $tenantId;
    }
}
