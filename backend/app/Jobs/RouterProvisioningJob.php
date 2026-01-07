<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\MikrotikProvisioningService;
use App\Events\RouterProvisioningProgress;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RouterProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use TenantAwareJob;

    public $routerId;
    public $provisioningData;
    
    public $tries = 5; // Match supervisor configuration
    public $timeout = 600; // 10 minutes - increased for script execution
    public $backoff = [30, 60, 120, 300, 600]; // Exponential backoff for provisioning retries

    /**
     * Create a new job instance.
     */
    public function __construct(string $routerId, string $tenantId, array $provisioningData)
    {
        $this->routerId = $routerId;
        $this->setTenantContext($tenantId);
        $this->provisioningData = $provisioningData;
        $this->onQueue('router-provisioning');
    }

    /**
     * Execute the job.
     */
    public function handle(MikrotikProvisioningService $provisioningService): void
    {
        $this->executeInTenantContext(function() use ($provisioningService) {
            $router = Router::find($this->routerId);

            if (!$router) {
                Log::error('RouterProvisioningJob: Router not found', [
                    'router_id' => $this->routerId,
                    'tenant_id' => $this->tenantId
                ]);
                return;
            }

            try {
                // Stage 1: Verify connectivity (should already be done, but double-check)
                $this->broadcastProgress($router, 'verifying', 10, 'Verifying router connectivity...');
                
                $connectivity = $provisioningService->verifyConnectivity($router);
                
                if ($connectivity['status'] !== 'connected' && $connectivity['status'] !== 'online') {
                    throw new \Exception('Router not connected');
                }

                $this->broadcastProgress($router, 'connected', 20, 'Router connected successfully', [
                    'model' => $connectivity['model'],
                    'version' => $connectivity['os_version'],
                ]);

                // Stage 2: Apply saved service configuration (already generated in previous step)
                $this->broadcastProgress($router, 'deploying', 40, 'Deploying service configuration to router...');
                
                $applyResult = $provisioningService->applyConfigs($router);

                $this->broadcastProgress($router, 'deployed', 70, 'Configuration deployed successfully');

                // Stage 3: Verify deployment
                $this->broadcastProgress($router, 'verifying_deployment', 85, 'Verifying deployment...');
                
                // Small delay to let router process configs
                sleep(3);

                // If hotspot was requested, verify hotspot resources exist
                $serviceType = $this->provisioningData['service_type'] ?? 'unknown';
                if ($serviceType === 'hotspot' || ($this->provisioningData['enable_hotspot'] ?? false)) {
                    $this->broadcastProgress($router, 'verifying_hotspot', 88, 'Verifying hotspot deployment...');
                    $verified = false;
                    for ($i = 0; $i < 5; $i++) {
                        if ($provisioningService->verifyHotspotDeployment($router)) {
                            $verified = true;
                            break;
                        }
                        sleep(2);
                    }
                    if (!$verified) {
                        throw new \Exception('Hotspot deployment verification failed: hotspot resources not found');
                    }
                }

                // Fetch live data to verify device responsiveness (use 'live' context for full data)
                $liveData = $provisioningService->fetchLiveRouterData($router, 'live', false);
                
                // Update router status
                $router->update([
                    'status' => 'active',
                    'last_seen' => now(),
                ]);

                // Stage 4: Complete
                $this->broadcastProgress($router, 'completed', 100, 'Router provisioned successfully!', [
                    'router_id' => $router->id,
                    'interfaces' => $liveData['interface_count'] ?? 0,
                    'uptime' => $liveData['uptime'] ?? 'N/A',
                ]);

                Log::info('Router provisioning completed', [
                    'router_id' => $router->id,
                    'router_name' => $router->name,
                    'service_type' => $this->provisioningData['service_type'] ?? 'unknown',
                    'tenant_id' => $this->tenantId,
                ]);

            } catch (\Exception $e) {
                Log::error('Router provisioning failed', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $router->update(['status' => 'failed']);

                $this->broadcastProgress($router, 'failed', 0, 'Provisioning failed: ' . $e->getMessage(), [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Broadcast progress update
     */
    private function broadcastProgress(Router $router, string $stage, float $progress, string $message, array $data = []): void
    {
        broadcast(new RouterProvisioningProgress(
            $router->id,
            $stage,
            $progress,
            $message,
            $data
        ))->toOthers();

        // Small delay to ensure broadcast is sent
        usleep(100000); // 100ms
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Router provisioning job failed permanently', [
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);

        // We can't easily access the router model here to update status without tenant context.
        // But since we are likely out of retries, we can try to update it if we can get context,
        // or just rely on the logging.
        // Ideally we should try to mark it as failed in DB.
        
        // Try to set status to failed in a fresh context if possible
        try {
            $this->executeInTenantContext(function() {
                $router = Router::find($this->routerId);
                if ($router) {
                    $router->update(['status' => 'failed']);
                    
                    // Also broadcast failure
                     broadcast(new RouterProvisioningProgress(
                        $router->id,
                        'failed',
                        0,
                        'Provisioning failed permanently',
                        ['error' => 'Job failed after retries']
                    ))->toOthers();
                }
            });
        } catch (\Exception $e) {
            Log::error('Could not update router status to failed in failed()', ['error' => $e->getMessage()]);
        }
    }
}
