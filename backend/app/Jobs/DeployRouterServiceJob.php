<?php

namespace App\Jobs;

use App\Models\RouterService;
use App\Services\MikroTik\ZeroConfigHotspotGenerator;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Services\MikroTik\ZeroConfigHybridGenerator;
use App\Services\MikrotikProvisioningService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class DeployRouterServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    protected string $serviceId;

    public function __construct(string $serviceId, string $tenantId)
    {
        $this->serviceId = $serviceId;
        $this->tenantId = $tenantId;

        $this->onQueue('router-provisioning');
    }

    public function handle(): void
    {
        $this->executeInTenantContext(function () {
            $service = RouterService::with(['router', 'ipPool', 'vlans'])->find($this->serviceId);

            if (!$service) {
                Log::error('Service not found for deployment', ['service_id' => $this->serviceId]);
                return;
            }

            try {
                Log::info('Starting service deployment', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                    'service_type' => $service->service_type,
                ]);

                // Generate configuration based on service type
                $config = $this->generateConfiguration($service);

                // Deploy to router via provisioning service
                $provisioningService = app(MikrotikProvisioningService::class);
                $result = $provisioningService->applyConfigs($service->router, $config, false);

                if ($result['success']) {
                    $service->update([
                        'deployment_status' => RouterService::DEPLOYMENT_DEPLOYED,
                        'status' => RouterService::STATUS_ACTIVE,
                        'deployed_at' => now(),
                    ]);

                    Log::info('Service deployed successfully', [
                        'service_id' => $service->id,
                        'router_id' => $service->router_id,
                    ]);
                } else {
                    throw new \Exception($result['message'] ?? 'Deployment failed');
                }

            } catch (\Exception $e) {
                if ((int) $e->getCode() === 503) {
                    Log::warning('Service deployment deferred (router busy)', [
                        'service_id' => $service->id,
                        'router_id' => $service->router_id,
                        'error' => $e->getMessage(),
                    ]);

                    $service->update([
                        'deployment_status' => RouterService::DEPLOYMENT_PENDING,
                        'status' => RouterService::STATUS_INACTIVE,
                    ]);

                    $this->release(15);
                    return;
                }

                Log::error('Service deployment failed', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                    'error' => $e->getMessage(),
                ]);

                $service->update([
                    'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                    'status' => RouterService::STATUS_INACTIVE,
                ]);

                throw $e;
            }
        });
    }

    /**
     * Generate configuration based on service type
     */
    private function generateConfiguration(RouterService $service): string
    {
        switch ($service->service_type) {
            case RouterService::TYPE_HOTSPOT:
                $generator = new ZeroConfigHotspotGenerator();
                return $generator->generate($service);

            case RouterService::TYPE_PPPOE:
                $generator = new ZeroConfigPPPoEGenerator();
                return $generator->generate($service);

            case RouterService::TYPE_HYBRID:
                $generator = new ZeroConfigHybridGenerator();
                return $generator->generate($service);

            default:
                throw new \Exception("Unsupported service type: {$service->service_type}");
        }
    }
}
