<?php

namespace App\Jobs;

use App\Events\RouterProvisioningProgress;
use App\Models\Tenant;
use App\Models\Router;
use App\Models\RouterService;
use App\Services\MikroTik\ZeroConfigHotspotGenerator;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Services\MikroTik\ZeroConfigHybridGenerator;
use App\Services\ProvisioningServiceClient;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeployRouterServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    protected string $serviceId;

    public int $tries = 0;
    public int $timeout = 180;
    public array $backoff = [15, 30, 60];
    public int $maxExceptions = 3;

    public function __construct(string $serviceId, string $tenantId)
    {
        $this->serviceId = $serviceId;
        $this->tenantId = $tenantId;
        $this->onQueue('router-provisioning');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        $deployData = $this->executeInTenantContext(function () {
            $service = RouterService::with(['router', 'ipPool', 'vlans'])->find($this->serviceId);

            if (! $service) {
                Log::error('DeployRouterServiceJob: Service not found', ['service_id' => $this->serviceId]);
                return null;
            }

            if (! $service->router) {
                Log::error('DeployRouterServiceJob: Router not found for service', [
                    'service_id' => $service->id,
                ]);
                $service->update([
                    'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                    'status' => RouterService::STATUS_INACTIVE,
                ]);
                return null;
            }

            if ($service->deployment_status === RouterService::DEPLOYMENT_DEPLOYED) {
                Log::info('DeployRouterServiceJob: Already deployed, skipping', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                ]);
                $this->broadcastServiceProgress($service, 'completed', 100, 'Service already deployed');
                return null;
            }

            $siblings = RouterService::with(['router', 'ipPool', 'vlans'])
                ->where('router_id', $service->router_id)
                ->where('service_type', $service->service_type)
                ->get();

            $merged = $this->mergeServiceGroup($siblings, $service);
            $config = $this->generateConfiguration($merged);

            return [
                'service' => $service,
                'router' => $service->router,
                'config' => $config,
            ];
        });

        if ($deployData === null) {
            return;
        }

        $service = $deployData['service'];
        $router = $deployData['router'];
        $config = $deployData['config'];

        $lockKey = 'deploy_router_' . $router->id;
        $lock = Cache::lock($lockKey, 60);

        if (! $lock->get()) {
            Log::warning('DeployRouterServiceJob: Deployment already in progress on this router', [
                'service_id' => $this->serviceId,
                'router_id' => $router->id,
                'attempt' => $this->attempts(),
            ]);
            $this->release(10);
            return;
        }

        try {
            $this->executeInTenantContext(function () use ($service) {
                $service->update(['deployment_status' => RouterService::DEPLOYMENT_IN_PROGRESS]);
            });

            $this->broadcastServiceProgress($service, 'deploying', 30, 'Generating configuration...');
            $this->broadcastServiceProgress($service, 'applying', 50, 'Submitting configuration to provisioning service...');

            $response = $provisioningClient->submitRouterServiceDeploymentCommand(
                $router,
                (string) $this->tenantId,
                (string) $service->id,
                $config,
                (string) (auth()->id() ?? '')
            );

            $this->executeInTenantContext(function () use ($service, $response) {
                $service->update([
                    'configuration' => array_merge((array) $service->configuration, [
                        'command_submission' => $response['data'] ?? $response,
                    ]),
                ]);
            });

            Log::info('DeployRouterServiceJob: Command accepted by provisioning service', [
                'service_id' => $service->id,
                'router_id' => $router->id,
                'service_type' => $service->service_type,
            ]);
        } catch (\Exception $e) {
            $this->executeInTenantContext(function () use ($service, $e) {
                $this->handleDeploymentException($service, $e);
            });
            // Code 503 (router busy) already triggered job release for retry.
            // Don't re-throw to avoid "failed" noise in logs and UI.
            if ((int) $e->getCode() !== 503) {
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    private function handleDeploymentException(RouterService $service, \Exception $e): void
    {
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
        ]);

        RouterService::where('router_id', $service->router_id)
            ->where('service_type', $service->service_type)
            ->update([
                'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                'status' => RouterService::STATUS_INACTIVE,
            ]);

        $this->broadcastServiceProgress($service, 'failed', 0, 'Deployment failed: ' . $e->getMessage());
    }

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
                    RouterService::where('router_id', $service->router_id)
                        ->where('service_type', $service->service_type)
                        ->update([
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

    private function broadcastServiceProgress(RouterService $service, string $stage, float $progress, string $message): void
    {
        try {
            if ($service->router) {
                broadcast(new RouterProvisioningProgress(
                    (string) $service->router_id,
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

    private function mergeServiceGroup(\Illuminate\Support\Collection $group, RouterService $primary): RouterService
    {
        if ($group->count() === 1) {
            return $primary;
        }

        $allInterfaces = $group->flatMap(function (RouterService $svc) {
            $raw = $svc->interface_name;
            if (is_array($raw)) {
                return $raw;
            }
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : [$raw];
        })
        ->filter(fn ($i) => is_string($i) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', trim($i)))
        ->unique()
        ->values()
        ->toArray();

        $merged = $primary->replicate();
        $merged->id = $primary->id;
        $merged->interface_name = $allInterfaces;
        $merged->setRelation('router', $primary->router);
        $merged->setRelation('ipPool', $primary->ipPool);
        $merged->setRelation('vlans', $primary->vlans);

        return $merged;
    }

    private function generateConfiguration(RouterService $service): string
    {
        return match ($service->service_type) {
            RouterService::TYPE_HOTSPOT => (new ZeroConfigHotspotGenerator())->generate($service),
            RouterService::TYPE_PPPOE => (new ZeroConfigPPPoEGenerator())->generate($service),
            RouterService::TYPE_HYBRID => (new ZeroConfigHybridGenerator())->generate($service),
            default => throw new \Exception("Unsupported service type: {$service->service_type}"),
        };
    }

    private function ensureTenantSearchPath(): void
    {
        if (! $this->tenantId) {
            return;
        }

        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || ! $tenant->schema_created || empty($tenant->schema_name)) {
            return;
        }

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
    }
}
