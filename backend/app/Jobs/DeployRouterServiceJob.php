<?php

namespace App\Jobs;

use App\Models\RouterService;
use App\Services\MikroTik\ZeroConfigHotspotGenerator;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Services\MikroTik\ZeroConfigHybridGenerator;
use App\Services\MikrotikProvisioningService;
use App\Events\RouterProvisioningProgress;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeployRouterServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    protected string $serviceId;

    public $tries = 3;
    public $timeout = 300;
    public $backoff = [15, 30, 60];

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
                Log::error('DeployRouterServiceJob: Service not found', ['service_id' => $this->serviceId]);
                return;
            }

            if (!$service->router) {
                Log::error('DeployRouterServiceJob: Router not found for service', [
                    'service_id' => $service->id,
                ]);
                $service->update([
                    'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                    'status' => RouterService::STATUS_INACTIVE,
                ]);
                return;
            }

            // Skip if already deployed (prevent re-deployment of working config)
            if ($service->deployment_status === RouterService::DEPLOYMENT_DEPLOYED) {
                Log::info('DeployRouterServiceJob: Already deployed, skipping', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                ]);
                return;
            }

            // Idempotency lock - prevent concurrent deployments of same service
            $lock = Cache::lock('deploy_service_' . $this->serviceId, 300);
            if (!$lock->get()) {
                Log::warning('DeployRouterServiceJob: Deployment already in progress (lock held)', [
                    'service_id' => $this->serviceId,
                ]);
                $this->release(15);
                return;
            }

            try {
                // Mark as in progress
                $service->update(['deployment_status' => RouterService::DEPLOYMENT_IN_PROGRESS]);

                Log::info('DeployRouterServiceJob: Starting deployment', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                    'service_type' => $service->service_type,
                    'attempt' => $this->attempts(),
                ]);

                // Broadcast progress
                $this->broadcastServiceProgress($service, 'deploying', 30, 'Generating configuration...');

                // Generate configuration based on service type
                $config = $this->generateConfiguration($service);

                $this->broadcastServiceProgress($service, 'applying', 50, 'Applying configuration to router...');

                // Deploy to router via provisioning service
                $provisioningService = app(MikrotikProvisioningService::class);
                $result = $provisioningService->applyConfigs($service->router, $config, false);

                if ($result['success']) {
                    $service->update([
                        'deployment_status' => RouterService::DEPLOYMENT_DEPLOYED,
                        'status' => RouterService::STATUS_ACTIVE,
                        'deployed_at' => now(),
                    ]);

                    $this->broadcastServiceProgress($service, 'completed', 100, 'Service deployed successfully');

                    Log::info('DeployRouterServiceJob: Deployed successfully', [
                        'service_id' => $service->id,
                        'router_id' => $service->router_id,
                        'attempt' => $this->attempts(),
                    ]);
                } else {
                    throw new \Exception($result['message'] ?? 'Deployment failed');
                }

            } catch (\Exception $e) {
                if ((int) $e->getCode() === 503) {
                    Log::warning('DeployRouterServiceJob: Deferred (router busy)', [
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

                Log::error('DeployRouterServiceJob: Failed', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                $service->update([
                    'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                    'status' => RouterService::STATUS_INACTIVE,
                ]);

                $this->broadcastServiceProgress($service, 'failed', 0, 'Deployment failed: ' . $e->getMessage());

                throw $e;
            } finally {
                $lock->release();
            }
        });
    }

    /**
     * Handle permanent job failure (all retries exhausted)
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('DeployRouterServiceJob: Permanently failed', [
            'service_id' => $this->serviceId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->executeInTenantContext(function () use ($exception) {
                $service = RouterService::with('router')->find($this->serviceId);
                if ($service) {
                    $service->update([
                        'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                        'status' => RouterService::STATUS_INACTIVE,
                    ]);

                    $this->broadcastServiceProgress($service, 'failed', 0, 'Deployment failed permanently: ' . $exception->getMessage());
                }
            });
        } catch (\Exception $e) {
            Log::error('DeployRouterServiceJob: Could not update status in failed()', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast deployment progress for UI updates
     */
    private function broadcastServiceProgress(RouterService $service, string $stage, float $progress, string $message): void
    {
        try {
            if ($service->router) {
                broadcast(new RouterProvisioningProgress(
                    $service->router_id,
                    'service_deploy_' . $stage,
                    $progress,
                    $message,
                    [
                        'service_id' => $service->id,
                        'service_type' => $service->service_type,
                        'deployment_status' => $service->deployment_status,
                    ]
                ))->toOthers();
            }
        } catch (\Exception $e) {
            Log::debug('DeployRouterServiceJob: Broadcast failed (non-critical)', [
                'error' => $e->getMessage(),
            ]);
        }
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
