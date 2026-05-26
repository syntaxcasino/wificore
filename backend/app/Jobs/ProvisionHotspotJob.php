<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\RouterService;
use App\Events\HotspotProvisionRequested;
use App\Events\HotspotProvisioned;
use App\Services\ProvisioningServiceClient;
use App\Services\RouterServiceManager;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job to provision Hotspot on a MikroTik router.
 * 
 * All MikroTik operations MUST run in queued jobs.
 * This job generates the configuration script and executes it via the Go provisioning service.
 */
class ProvisionHotspotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public string $serviceId;
    public string $routerId;
    public $tries = 3;
    public $timeout = 120;
    public $backoff = [30, 60, 120];

    public function __construct(string $serviceId, string $routerId, string $tenantId)
    {
        $this->serviceId = $serviceId;
        $this->routerId = $routerId;
        $this->setTenantContext($tenantId);
        $this->onQueue('router-provisioning');
    }

    public function handle(RouterServiceManager $serviceManager, ProvisioningServiceClient $provisioningClient): void
    {
        $this->executeInTenantContext(function () use ($serviceManager, $provisioningClient) {
            $startTime = microtime(true);
            
            $service = RouterService::find($this->serviceId);
            $router = Router::find($this->routerId);
            
            if (!$service || !$router) {
                Log::error('ProvisionHotspotJob: Service or router not found', [
                    'service_id' => $this->serviceId,
                    'router_id' => $this->routerId,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }
            
            // Update deployment status
            $service->update(['deployment_status' => RouterService::DEPLOYMENT_IN_PROGRESS]);
            
            try {
                // Generate configuration script
                $script = $serviceManager->generateConfigurationScript($service);
                
                if (empty($script)) {
                    throw new \Exception('Generated configuration script is empty');
                }
                
                Log::info('ProvisionHotspotJob: Executing script', [
                    'service_id' => $this->serviceId,
                    'router_id' => $this->routerId,
                    'script_length' => strlen($script),
                    'tenant_id' => $this->tenantId,
                ]);
                
                // Execute the script via the Go provisioning service
                $provisioningClient->deployScript($router, $script, $this->tenantId);

                // Verify deployment using Go-backed command execution
                $verification = $this->verifyDeployment($provisioningClient, $router, $service);
                
                if (!$verification['success']) {
                    throw new \Exception($verification['error'] ?? 'Deployment verification failed');
                }
                
                // Update service status
                $service->update([
                    'deployment_status' => RouterService::DEPLOYMENT_DEPLOYED,
                    'status' => RouterService::STATUS_ACTIVE,
                    'last_deployed_at' => now(),
                ]);
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                // Broadcast success event
                broadcast(new HotspotProvisioned(
                    $this->serviceId,
                    $this->routerId,
                    $this->tenantId,
                    true,
                    null,
                    [
                        'duration_ms' => $duration,
                        'verification' => $verification,
                    ]
                ))->toOthers();
                
                Log::info('ProvisionHotspotJob: Hotspot deployed successfully', [
                    'service_id' => $this->serviceId,
                    'router_id' => $this->routerId,
                    'duration_ms' => $duration,
                    'tenant_id' => $this->tenantId,
                ]);
                
            } catch (\Exception $e) {
                $service->update([
                    'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                    'last_error' => $e->getMessage(),
                ]);
                
                // Broadcast failure event
                broadcast(new HotspotProvisioned(
                    $this->serviceId,
                    $this->routerId,
                    $this->tenantId,
                    false,
                    $e->getMessage(),
                    []
                ))->toOthers();
                
                Log::error('ProvisionHotspotJob: Deployment failed', [
                    'service_id' => $this->serviceId,
                    'router_id' => $this->routerId,
                    'error' => $e->getMessage(),
                    'tenant_id' => $this->tenantId,
                ]);
                
                throw $e;
            }
        });
    }

    private function verifyDeployment(ProvisioningServiceClient $provisioningClient, Router $router, RouterService $service): array
    {
        try {
            $routerId = $service->router_id;
            
            $results = $provisioningClient->executeCommands($router, [
                '/ip hotspot print count-only where name="hs-server-' . $routerId . '-0"',
                '/ip hotspot profile print count-only where name="hs-profile-' . $routerId . '-0"',
                '/radius print count-only where service=hotspot',
            ], $this->tenantId);

            $serverCount = (int) trim((string) ($results[0]['output'] ?? '0'));
            $profileCount = (int) trim((string) ($results[1]['output'] ?? '0'));
            $radiusCount = (int) trim((string) ($results[2]['output'] ?? '0'));
            
            if ($serverCount < 1) {
                return [
                    'success' => false,
                    'error' => 'Hotspot server not found: hs-server-' . $routerId . '-0',
                ];
            }
            
            if ($profileCount < 1) {
                return [
                    'success' => false,
                    'error' => 'Hotspot profile not found: hs-profile-' . $routerId . '-0',
                ];
            }
            
            return [
                'success' => true,
                'hotspot_server' => 'hs-server-' . $routerId . '-0',
                'hotspot_profile' => 'hs-profile-' . $routerId . '-0',
                'radius_configured' => $radiusCount > 0,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Verification failed: ' . $e->getMessage(),
            ];
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProvisionHotspotJob failed permanently', [
            'service_id' => $this->serviceId,
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
        
        // Update service status
        try {
            RouterService::where('id', $this->serviceId)->update([
                'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                'last_error' => $exception->getMessage(),
            ]);
        } catch (\Exception $e) {
            // Ignore
        }
        
        // Broadcast failure
        broadcast(new HotspotProvisioned(
            $this->serviceId,
            $this->routerId,
            $this->tenantId,
            false,
            $exception->getMessage(),
            []
        ))->toOthers();
    }
}
