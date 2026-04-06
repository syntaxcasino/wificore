<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\VpnConfiguration;
use App\Services\MikrotikProvisioningService;
use App\Services\RouterStatusCheckService;
use App\Events\RouterProvisioningProgress;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
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
    public function handle(
        MikrotikProvisioningService $provisioningService,
        RouterStatusCheckService $statusCheckService
    ): void
    {
        $this->executeInTenantContext(function() use ($provisioningService, $statusCheckService) {
            $router = Router::find($this->routerId);

            if (!$router) {
                Log::error('RouterProvisioningJob: Router not found', [
                    'router_id' => $this->routerId,
                    'tenant_id' => $this->tenantId
                ]);
                return;
            }

            // Idempotency lock - prevent concurrent provisioning of same router
            $lock = Cache::lock('provision_router_' . $this->routerId, 600);
            if (!$lock->get()) {
                Log::warning('RouterProvisioningJob: Provisioning already in progress (lock held)', [
                    'router_id' => $this->routerId,
                ]);
                $this->release(30);
                return;
            }

            try {
                // Stage 1: Verify status via ping only (strict provisioning policy)
                $router->update([
                    'status' => 'provisioning',
                    'provisioning_stage' => 'ping_verification',
                    'last_checked' => now(),
                ]);

                $this->broadcastProgress($router, 'verifying', 5, 'Verifying router status via ping...');

                // Use RouterStatusCheckService for strict ping-only check during provisioning
                $connectivity = $statusCheckService->checkStatusProvisioning($router);

                if (!$connectivity['online']) {
                    throw new \Exception($connectivity['reason'] ?? 'Router ping check failed during provisioning');
                }

                $this->broadcastProgress($router, 'connected', 20, 'Router connected successfully', [
                    'model' => $router->model,
                    'version' => $router->os_version,
                    'method' => $connectivity['method'] ?? 'wireguard_api',
                ]);

                $router->update([
                    'status' => 'deploying',
                    'provisioning_stage' => 'deploying_config',
                    'last_seen' => now(),
                    'last_checked' => now(),
                ]);

                // Stage 2: Apply saved service configuration (already generated in previous step)
                $this->broadcastProgress($router, 'deploying', 40, 'Deploying service configuration to router...');
                
                $applyResult = $provisioningService->applyConfigs($router);

                $this->broadcastProgress($router, 'deployed', 70, 'Configuration deployed successfully');

                $router->update([
                    'status' => 'verifying',
                    'provisioning_stage' => 'verifying_deployment',
                    'last_checked' => now(),
                ]);

                // Stage 3: Verify deployment
                $this->broadcastProgress($router, 'verifying_deployment', 85, 'Verifying deployment...');
                
                // Update router status after verification completes
                $router->update([
                    'status' => 'online',
                    'provisioning_stage' => 'completed',
                    'last_seen' => now(),
                    'last_checked' => now(),
                ]);

                // Stage 4: Complete
                $this->broadcastProgress($router, 'completed', 100, 'Router provisioned successfully!', [
                    'router_id' => $router->id,
                    'service_type' => $this->provisioningData['service_type'] ?? 'unknown',
                    'execution_time' => $applyResult['execution_time'] ?? null,
                    'method' => $applyResult['method'] ?? 'SSH',
                ]);

                Log::info('Router provisioning completed', [
                    'router_id' => $router->id,
                    'router_name' => $router->name,
                    'service_type' => $this->provisioningData['service_type'] ?? 'unknown',
                    'tenant_id' => $this->tenantId,
                    'provision_method' => $applyResult['method'] ?? 'SSH',
                    'execution_time' => $applyResult['execution_time'] ?? null,
                ]);

            } catch (\Exception $e) {
                Log::error('Router provisioning failed', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $router->update([
                    'status' => 'failed',
                    'provisioning_stage' => 'failed',
                ]);

                $this->broadcastProgress($router, 'failed', 0, 'Provisioning failed: ' . $e->getMessage(), [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            } finally {
                $lock->release();
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

        // Reduced delay to ensure broadcast is sent (low-end device optimized)
        usleep(50000); // 50ms
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
                    $router->update([
                        'status' => 'failed',
                        'provisioning_stage' => 'failed',
                        'last_checked' => now(),
                    ]);
                    
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
