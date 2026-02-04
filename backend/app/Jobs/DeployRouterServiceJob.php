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

            // Skip if already deployed or currently deploying (prevent duplicate deployments)
            if ($service->deployment_status === RouterService::DEPLOYMENT_DEPLOYED) {
                Log::info('Service already deployed, skipping', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                ]);
                return;
            }

            if ($service->deployment_status === RouterService::DEPLOYMENT_IN_PROGRESS) {
                Log::info('Service deployment already in progress, skipping', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                ]);
                return;
            }

            // Mark as in progress immediately to prevent concurrent deployments
            $service->update(['deployment_status' => RouterService::DEPLOYMENT_IN_PROGRESS]);

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
                // Collect all interfaces from all PPPoE services on this router
                $cleanInterfaces = $this->collectCleanInterfaces($service->router_id);

                // CRITICAL: Clone the service model to avoid corrupting the original
                // The original model will be used for DB updates later
                $serviceForGeneration = $service->replicate();
                $serviceForGeneration->id = $service->id;
                $serviceForGeneration->setRelation('router', $service->router);
                $serviceForGeneration->setRelation('ipPool', $service->ipPool);
                
                if (!empty($cleanInterfaces)) {
                    $serviceForGeneration->interface_name = $cleanInterfaces;
                }

                $generator = new ZeroConfigPPPoEGenerator();
                return $generator->generate($serviceForGeneration);

            case RouterService::TYPE_HYBRID:
                $generator = new ZeroConfigHybridGenerator();
                return $generator->generate($service);

            default:
                throw new \Exception("Unsupported service type: {$service->service_type}");
        }
    }

    /**
     * Collect and clean interface names from all PPPoE services on router
     */
    private function collectCleanInterfaces(string $routerId): array
    {
        $allInterfaces = [];
        $pppoeServices = RouterService::where('router_id', $routerId)
            ->where('service_type', RouterService::TYPE_PPPOE)
            ->pluck('interface_name');

        foreach ($pppoeServices as $iface) {
            if (empty($iface)) {
                continue;
            }
            $this->extractInterfaces($iface, $allInterfaces);
        }

        // Clean: unique, non-empty, valid interface names only
        return array_values(array_unique(array_filter($allInterfaces, function ($i) {
            return is_string($i) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', trim($i));
        })));
    }

    /**
     * Recursively extract interface names from potentially nested JSON
     */
    private function extractInterfaces($value, array &$interfaces): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->extractInterfaces($item, $interfaces);
            }
            return;
        }

        if (!is_string($value) || empty($value)) {
            return;
        }

        // Try JSON decode
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $this->extractInterfaces($item, $interfaces);
            }
            return;
        }

        // Check if comma-separated
        if (str_contains($value, ',')) {
            foreach (explode(',', $value) as $part) {
                $trimmed = trim($part);
                if (!empty($trimmed) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $trimmed)) {
                    $interfaces[] = $trimmed;
                }
            }
            return;
        }

        // Plain interface name
        $trimmed = trim($value);
        if (!empty($trimmed) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $trimmed)) {
            $interfaces[] = $trimmed;
        }
    }
}
