<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\MikrotikProvisioningService;
use App\Events\RouterProvisioningProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RouterProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $router;
    public $provisioningData;
    
    public $tries = 5; // Match supervisor configuration
    public $timeout = 600; // 10 minutes - increased for script execution
    public $backoff = [30, 60, 120, 300, 600]; // Exponential backoff for provisioning retries

    /**
     * Create a new job instance.
     */
    public function __construct(Router $router, array $provisioningData)
    {
        $this->router = $router;
        $this->provisioningData = $provisioningData;
        $this->onQueue('router-provisioning');
    }

    /**
     * Execute the job.
     */
    public function handle(MikrotikProvisioningService $provisioningService): void
    {
        try {
            // Stage 1: Verify connectivity (should already be done, but double-check)
            $this->broadcastProgress('verifying', 10, 'Verifying router connectivity...');
            
            $connectivity = $provisioningService->verifyConnectivity($this->router);
            
            if ($connectivity['status'] !== 'connected' && $connectivity['status'] !== 'online') {
                throw new \Exception('Router not connected');
            }

            $this->broadcastProgress('connected', 20, 'Router connected successfully', [
                'model' => $connectivity['model'],
                'version' => $connectivity['os_version'],
            ]);

            // Stage 2: Apply saved service configuration (already generated in previous step)
            $this->broadcastProgress('deploying', 40, 'Deploying service configuration to router...');
            
            $applyResult = $provisioningService->applyConfigs($this->router);

            $this->broadcastProgress('deployed', 70, 'Configuration deployed successfully');

            // Stage 3: Verify deployment
            $this->broadcastProgress('verifying_deployment', 85, 'Verifying deployment...');
            
            // Small delay to let router process configs
            sleep(3);

            // If hotspot was requested, verify hotspot resources exist
            $serviceType = $this->provisioningData['service_type'] ?? 'unknown';
            if ($serviceType === 'hotspot' || ($this->provisioningData['enable_hotspot'] ?? false)) {
                $this->broadcastProgress('verifying_hotspot', 88, 'Verifying hotspot deployment...');
                $verified = false;
                for ($i = 0; $i < 5; $i++) {
                    if ($provisioningService->verifyHotspotDeployment($this->router)) {
                        $verified = true;
                        break;
                    }
                    sleep(2);
                }
                if (!$verified) {
                    throw new \Exception('Hotspot deployment verification failed: hotspot resources not found');
                }
            }

            // Fetch live data to verify device responsiveness
            $liveData = $provisioningService->fetchLiveRouterData($this->router);
            
            // Update router status
            $this->router->update([
                'status' => 'active',
                'last_seen' => now(),
            ]);

            // Stage 4: Complete
            $this->broadcastProgress('completed', 100, 'Router provisioned successfully!', [
                'router_id' => $this->router->id,
                'interfaces' => $liveData['interface_count'] ?? 0,
                'uptime' => $liveData['uptime'] ?? 'N/A',
            ]);

            Log::info('Router provisioning completed', [
                'router_id' => $this->router->id,
                'router_name' => $this->router->name,
                'service_type' => $this->provisioningData['service_type'] ?? 'unknown',
            ]);

        } catch (\Exception $e) {
            Log::error('Router provisioning failed', [
                'router_id' => $this->router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->router->update(['status' => 'failed']);

            $this->broadcastProgress('failed', 0, 'Provisioning failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Broadcast progress update
     */
    private function broadcastProgress(string $stage, float $progress, string $message, array $data = []): void
    {
        broadcast(new RouterProvisioningProgress(
            $this->router->id,
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
            'router_id' => $this->router->id,
            'error' => $exception->getMessage(),
        ]);

        $this->router->update(['status' => 'failed']);

        $this->broadcastProgress('failed', 0, 'Provisioning failed: ' . $exception->getMessage(), [
            'error' => $exception->getMessage(),
        ]);
    }
}
