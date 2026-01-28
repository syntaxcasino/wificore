<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

/**
 * Client for communicating with the provisioning service
 * 
 * This service acts as a proxy between the backend and routers,
 * implementing network segmentation for security.
 */
class ProvisioningServiceClient
{
    protected string $baseUrl;
    protected int $timeout;
    protected ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = env('PROVISIONING_SERVICE_URL', 'http://wificore-provisioning:8080');
        $this->timeout = env('PROVISIONING_SERVICE_TIMEOUT', 30);
        $this->apiKey = env('PROVISIONING_SERVICE_API_KEY');
    }

    /**
     * Get HTTP client with authentication headers
     */
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

    /**
     * Provision a router with configuration
     *
     * @param Router $router
     * @param array $commands Commands to execute
     * @param string $tenantId Tenant ID for isolation
     * @return array Response from provisioning service
     * @throws \Exception
     */
    public function provision(Router $router, array $commands, string $tenantId): array
    {
        try {
            Log::info('Provisioning router via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'command_count' => count($commands)
            ]);

            $response = $this->getHttpClient()
                ->post($this->baseUrl . '/api/v1/provision', [
                    'router_id' => $router->id,
                    'tenant_id' => $tenantId,
                    'configuration' => [
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'username' => $router->username,
                        'password' => Crypt::decryptString($router->password),
                        'commands' => $commands
                    ]
                ]);

            if ($response->failed()) {
                throw new \Exception('Provisioning service request failed: ' . $response->body());
            }

            $data = $response->json();

            if (!$data['success']) {
                throw new \Exception($data['error'] ?? 'Unknown provisioning error');
            }

            Log::info('Router provisioned successfully', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Provisioning service error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Fetch live data from a router
     *
     * @param Router $router
     * @param string $context Context: 'live', 'provisioning', 'details'
     * @param string $tenantId Tenant ID for isolation
     * @return array Live data from router
     * @throws \Exception
     */
    public function fetchLiveData(Router $router, string $context, string $tenantId): array
    {
        try {
            Log::debug('Fetching live data via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'context' => $context
            ]);

            $response = $this->getHttpClient()
                ->post($this->baseUrl . '/api/v1/live-data', [
                    'router_id' => $router->id,
                    'tenant_id' => $tenantId,
                    'context' => $context
                ]);

            if ($response->failed()) {
                throw new \Exception('Live data fetch failed: ' . $response->body());
            }

            $data = $response->json();

            if (!$data['success']) {
                throw new \Exception($data['error'] ?? 'Unknown error fetching live data');
            }

            return $data['data'] ?? [];

        } catch (\Exception $e) {
            Log::error('Live data fetch error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'context' => $context,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Execute commands on a router
     *
     * @param Router $router
     * @param array $commands Commands to execute
     * @param string $tenantId Tenant ID for isolation
     * @return array Command results
     * @throws \Exception
     */
    public function executeCommands(Router $router, array $commands, string $tenantId): array
    {
        try {
            Log::info('Executing commands via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'command_count' => count($commands)
            ]);

            $response = $this->getHttpClient()
                ->post($this->baseUrl . '/api/v1/execute', [
                    'router_id' => $router->id,
                    'tenant_id' => $tenantId,
                    'commands' => $commands
                ]);

            if ($response->failed()) {
                throw new \Exception('Command execution failed: ' . $response->body());
            }

            $data = $response->json();

            if (!$data['success']) {
                throw new \Exception($data['error'] ?? 'Unknown command execution error');
            }

            return $data['results'] ?? [];

        } catch (\Exception $e) {
            Log::error('Command execution error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Verify router connectivity
     *
     * @param Router $router
     * @param string $tenantId Tenant ID for isolation
     * @return array Connectivity status
     * @throws \Exception
     */
    public function verifyConnectivity(Router $router, string $tenantId): array
    {
        try {
            Log::debug('Verifying connectivity via provisioning service', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId
            ]);

            $response = $this->getHttpClient()
                ->post($this->baseUrl . '/api/v1/verify', [
                    'router_id' => $router->id,
                    'tenant_id' => $tenantId
                ]);

            if ($response->failed()) {
                throw new \Exception('Connectivity verification failed: ' . $response->body());
            }

            $data = $response->json();

            if (!$data['success']) {
                throw new \Exception($data['error'] ?? 'Unknown connectivity error');
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('Connectivity verification error', [
                'router_id' => $router->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check provisioning service health
     *
     * @return array Health status
     * @throws \Exception
     */
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
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
