<?php

namespace App\Services;

use App\Events\ProvisioningFailed;
use App\Events\RouterConnected;
use App\Events\RouterProvisioningProgress;
use App\Models\Router;
use App\Models\RouterConfig;
use App\Models\RouterService;
use App\Models\Tenant;
use App\Services\MikroTik\ConfigurationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MikroTik Provisioning Service
 * 
 * Handles router provisioning, connectivity verification, and live data fetching.
 * Uses the new clean architecture (MikroTik/* services) for configuration generation.
 * 
 * Network Segmentation: Supports routing operations through provisioning service
 * for enhanced security. Enable with USE_PROVISIONING_SERVICE=true
 */
class MikrotikProvisioningService extends TenantAwareService
{
    protected ConfigurationService $configService;
    protected ?ProvisioningServiceClient $provisioningClient = null;
    protected bool $useProvisioningService = false;
    
    public function __construct()
    {
        $this->configService = new ConfigurationService();
        
        $this->useProvisioningService = true;
        $this->provisioningClient = app(ProvisioningServiceClient::class);
        Log::debug('MikrotikProvisioningService: Router operations delegated to provisioning service');
    }
    
    /**
     * Check if router should use provisioning service
     * Allows gradual rollout by router ID
     */
    protected function shouldUseProvisioningService(Router $router): bool
    {
        return true;
    }

    /**
     * Generate service configuration using new clean architecture
     */
    public function generateConfigs(Router $router, array $data): array
    {
        try {
            // Reduced: Removed routine delegation log
            return $this->configService->generateServiceConfig($router, $data);
            
        } catch (\Exception $e) {
            Log::error('Failed to generate service configuration', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to generate service configuration: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Apply saved configuration to router with enhanced reliability and error handling
     * 
     * @param Router $router The router to configure
     * @param string|null $script Optional script content to apply (bypasses database lookup)
     * @return array Result of the operation
     * @throws \Exception On any critical error during configuration
     */
    public function applyConfigs(Router $router, ?string $script = null, bool $broadcast = true): array
    {
        $startTime = microtime(true);
        $routerName = $router->name ?? 'Unknown';

        if ($broadcast) {
            RouterProvisioningProgress::dispatch(
                $router->id,
                'init',
                0,
                'Starting provisioning process',
                ['router_name' => $routerName, 'method' => 'PROVISIONING_SERVICE']
            );
        }

        if ($script !== null) {
            $serviceScript = trim($script);
        } else {
            $routerConfig = RouterConfig::where('router_id', $router->id)
                ->where('config_type', 'service')
                ->first();

            if (!$routerConfig || empty(trim($routerConfig->config_content))) {
                $errorMsg = 'No valid service configuration found. Please generate the configuration first.';
                if ($broadcast) {
                    ProvisioningFailed::dispatch($router->id, 'config_missing', $errorMsg, ['router_id' => $router->id]);
                }
                throw new \Exception($errorMsg, 400);
            }

            $serviceScript = trim($routerConfig->config_content);
        }

        if ($serviceScript === '') {
            throw new \Exception('Service script is empty', 400);
        }

        if (!str_contains($serviceScript, '/snmp set enabled=yes')) {
            $serviceScript .= "\n\n# Enable SNMP for monitoring\n" . $this->getSnmpConfigScript($router);
        }

        $scriptName = 'svc_deploy_' . $router->id . '_' . md5($serviceScript);

        try {
            if ($broadcast) {
                RouterProvisioningProgress::dispatch(
                    $router->id,
                    'submitting',
                    35,
                    'Submitting configuration to provisioning service',
                    ['script_name' => $scriptName]
                );
            }

            $response = $this->provisioningClient->deployScript($router, $serviceScript, (string) $router->tenant_id);
            $payload = $response['data'] ?? [];

            $result = [
                'success' => true,
                'message' => $response['message'] ?? 'Configuration applied successfully via provisioning service',
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'script_name' => $scriptName,
                'router_id' => $router->id,
                'method' => 'PROVISIONING_SERVICE',
                'executed_at' => $payload['executed_at'] ?? null,
                'executed_commands' => $payload['executed_commands'] ?? null,
                'command_results' => $payload['command_results'] ?? [],
            ];

            if ($broadcast) {
                RouterProvisioningProgress::dispatch($router->id, 'completed', 100, 'Service deployment completed successfully', $result);
            }

            return $result;
        } catch (\Exception $e) {
            $errorMsg = 'Failed to execute service provisioning: ' . $e->getMessage();
            Log::error('Service provisioning execution failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'content_preview' => substr($serviceScript, 0, 200),
            ]);

            if ($broadcast) {
                ProvisioningFailed::dispatch($router->id, 'script_execution_failed', $errorMsg, ['error' => $e->getMessage()]);
            }

            throw new \Exception($errorMsg, 500, $e);
        }
    }
}
