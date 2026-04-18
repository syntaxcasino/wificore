<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Router;
use App\Models\RouterService;
use App\Services\MikroTik\ZeroConfigHotspotGenerator;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Services\MikroTik\ZeroConfigHybridGenerator;
use App\Services\CacheInvalidationService;
use App\Services\MikrotikProvisioningService;
use App\Events\RouterStatusUpdated;
use App\Events\RouterProvisioningProgress;
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

    public $tries = 0; // Disabled — using retryUntil() instead
    public $timeout = 300;
    public $backoff = [15, 30, 60];
    public $maxExceptions = 3; // Fail permanently after 3 real exceptions (not lock releases)

    public function __construct(string $serviceId, string $tenantId)
    {
        $this->serviceId = $serviceId;
        $this->tenantId = $tenantId;

        $this->onQueue('router-provisioning');
    }

    /**
     * Determine the time at which the job should timeout.
     * Using time-based retry so release() for lock contention doesn't burn attempts.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(15);
    }

    public function handle(): void
    {
        // Phase 1: Load service and generate config within transaction
        // Then commit before SSH to avoid idle-in-transaction timeout
        $deployData = $this->executeInTenantContext(function () {
            $service = RouterService::with(['router', 'ipPool', 'vlans'])->find($this->serviceId);

            if (!$service) {
                Log::error('DeployRouterServiceJob: Service not found', ['service_id' => $this->serviceId]);
                return null;
            }

            if (!$service->router) {
                Log::error('DeployRouterServiceJob: Router not found for service', [
                    'service_id' => $service->id,
                ]);
                $service->update([
                    'deployment_status' => RouterService::DEPLOYMENT_FAILED,
                    'status' => RouterService::STATUS_INACTIVE,
                ]);
                return null;
            }

            // Skip if already deployed - but broadcast success so UI doesn't show error
            if ($service->deployment_status === RouterService::DEPLOYMENT_DEPLOYED) {
                Log::info('DeployRouterServiceJob: Already deployed, skipping', [
                    'service_id' => $service->id,
                    'router_id' => $service->router_id,
                ]);
                // Broadcast completed status so frontend shows success instead of error
                $this->broadcastServiceProgress($service, 'completed', 100, 'Service already deployed');
                return null;
            }

            // Collect ALL sibling rows for the same service_type on this router.
            // This prevents N jobs each generating a full N-interface script.
            // We merge them into ONE representative service so the generator runs once.
            $siblings = RouterService::with(['router', 'ipPool', 'vlans'])
                ->where('router_id', $service->router_id)
                ->where('service_type', $service->service_type)
                ->get();

            $siblingIds = $siblings->pluck('id')->toArray();
            $merged     = $this->mergeServiceGroup($siblings, $service);

            // Generate configuration before releasing transaction
            $config = $this->generateConfiguration($merged);

            // Return data needed for deployment phase
            return [
                'service'     => $service,
                'siblingIds'  => $siblingIds,
                'router'      => $service->router,
                'config'      => $config,
                'service_type'=> $service->service_type,
            ];
        });

        // If null, job is done (error or already deployed)
        if ($deployData === null) {
            return;
        }

        // Phase 2: Acquire lock and run SSH deployment OUTSIDE transaction
        $service    = $deployData['service'];
        $router     = $deployData['router'];
        $config     = $deployData['config'];
        $siblingIds = $deployData['siblingIds'] ?? [$service->id];
        
        $lockKey = 'deploy_router_' . $router->id;
        $lock = Cache::lock($lockKey, 60);
        
        if (!$lock->get()) {
            Log::warning('DeployRouterServiceJob: Deployment already in progress on this router', [
                'service_id' => $this->serviceId,
                'router_id' => $router->id,
                'attempt' => $this->attempts(),
            ]);
            $this->release(10);
            return;
        }

        $result = null;
        $exception = null;
        
        try {
            // Mark as in progress - short transaction
            $this->executeInTenantContext(function () use ($service) {
                $service->update(['deployment_status' => RouterService::DEPLOYMENT_IN_PROGRESS]);
            });

            Log::info('DeployRouterServiceJob: Starting deployment', [
                'service_id' => $service->id,
                'router_id' => $router->id,
                'service_type' => $service->service_type,
                'attempt' => $this->attempts(),
            ]);

            $this->broadcastServiceProgress($service, 'deploying', 30, 'Generating configuration...');
            $this->broadcastServiceProgress($service, 'applying', 50, 'Applying configuration to router...');

            // SSH deployment - OUTSIDE any DB transaction
            $provisioningService = app(MikrotikProvisioningService::class);
            $result = $provisioningService->applyConfigs($router, $config, false);

        } catch (\Exception $e) {
            $exception = $e;
        } finally {
            $lock->release();
        }

        // Phase 3: Update status in new transaction
        $this->executeInTenantContext(function () use ($service, $siblingIds, $result, $exception) {
            if ($exception !== null) {
                $this->handleDeploymentException($service, $exception);
                return;
            }

            if ($result && $result['success']) {
                // Mark ALL sibling rows for this service type as deployed in one query
                RouterService::whereIn('id', $siblingIds)->update([
                    'deployment_status' => RouterService::DEPLOYMENT_DEPLOYED,
                    'status'            => RouterService::STATUS_ACTIVE,
                    'deployed_at'       => now(),
                ]);

                $router = Router::find($service->router_id);
                if ($router) {
                    $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];
                    if (in_array($router->status, $provisioningStatuses, true)) {
                        $router->update([
                            'status' => 'online',
                            'provisioning_stage' => 'completed',
                            'last_seen' => now(),
                            'last_checked' => now(),
                        ]);

                        CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);
                        broadcast(new RouterStatusUpdated([
                            [
                                'id' => $router->id,
                                'ip_address' => $router->ip_address,
                                'vpn_ip' => $router->vpn_ip,
                                'name' => $router->name,
                                'status' => $router->status,
                                'last_seen' => $router->last_seen,
                                'last_checked' => $router->last_checked,
                                'tenant_id' => (string) $this->tenantId,
                            ],
                        ], (string) $this->tenantId))->toOthers();
                    }
                }

                $this->broadcastServiceProgress($service, 'completed', 100, 'Service deployed successfully');

                Log::info('DeployRouterServiceJob: Deployed successfully', [
                    'service_id'  => $service->id,
                    'sibling_ids' => $siblingIds,
                    'router_id'   => $service->router_id,
                ]);
            } else {
                throw new \Exception($result['message'] ?? 'Deployment failed');
            }
        });
        
        // Re-throw exception outside transaction to trigger retry
        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Handle deployment exceptions with retry logic
     */
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

        $service->update([
            'deployment_status' => RouterService::DEPLOYMENT_FAILED,
            'status' => RouterService::STATUS_INACTIVE,
        ]);

        $this->broadcastServiceProgress($service, 'failed', 0, 'Deployment failed: ' . $e->getMessage());
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
     * Merge all RouterService rows of the same type into one representative service
     * with all interface names combined. Falls back to $primary if only one row.
     */
    private function mergeServiceGroup(
        \Illuminate\Support\Collection $group,
        RouterService $primary
    ): RouterService {
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
        $merged->id             = $primary->id;
        $merged->interface_name = $allInterfaces;
        $merged->setRelation('router', $primary->router);
        $merged->setRelation('ipPool', $primary->ipPool);
        $merged->setRelation('vlans', $primary->vlans);

        return $merged;
    }

    /**
     * Generate configuration based on service type.
     * The service passed here is already merged — all interfaces present.
     */
    private function generateConfiguration(RouterService $service): string
    {
        switch ($service->service_type) {
            case RouterService::TYPE_HOTSPOT:
                return (new ZeroConfigHotspotGenerator())->generate($service);

            case RouterService::TYPE_PPPOE:
                return (new ZeroConfigPPPoEGenerator())->generate($service);

            case RouterService::TYPE_HYBRID:
                return (new ZeroConfigHybridGenerator())->generate($service);

            default:
                throw new \Exception("Unsupported service type: {$service->service_type}");
        }
    }

    private function ensureTenantSearchPath(): void
    {
        if (!$this->tenantId) {
            return;
        }

        $tenant = Tenant::find($this->tenantId);

        if (!$tenant || !$tenant->schema_created || empty($tenant->schema_name)) {
            return;
        }

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
    }
}
