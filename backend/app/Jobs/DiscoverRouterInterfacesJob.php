<?php

namespace App\Jobs;

use App\Events\RouterInterfacesDiscovered;
use App\Models\Router;
use App\Models\RouterTask;
use App\Services\ProvisioningServiceClient;
use App\Services\MikroTik\RouterOsCapabilityRegistry;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverRouterInterfacesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = 10;

    public string $routerId;
    public ?string $routerTaskId;

    public function __construct(string $tenantId, string $routerId, ?string $routerTaskId = null)
    {
        $this->tenantId = $tenantId;
        $this->routerId = $routerId;
        $this->routerTaskId = $routerTaskId;
        $this->onQueue('router-provisioning');
    }

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        $task = $this->routerTaskId ? RouterTask::find($this->routerTaskId) : null;
        if ($task) {
            $task->markRunning(10, 'Discovering router interfaces');
        }

        $this->executeInTenantContext(function () use ($provisioningClient, $task) {
            $router = Router::find($this->routerId);

            if (!$router) {
                Log::warning('Router not found for interface discovery', [
                    'router_id' => $this->routerId,
                    'tenant_id' => $this->tenantId,
                ]);
                if ($task) {
                    $task->markFailed('Router not found', 0, 'Router not found');
                }
                return;
            }

            // Router may still be provisioning; allow discovery once ping is verified.
            // This job just discovers interfaces for service configuration.
            $allowedStatuses = ['pending', 'deploying', 'provisioning', 'verifying', 'online'];
            if (!in_array($router->status, $allowedStatuses, true)) {
                Log::info('Router not in expected state for discovery, skipping', [
                    'router_id' => $router->id,
                    'status' => $router->status,
                    'tenant_id' => $this->tenantId,
                ]);
                if ($task) {
                    $task->markFailed('Router not in a discoverable state', 0, 'Router not in a discoverable state');
                }
                return;
            }

            // Deduplication: prevent concurrent discovery jobs for same router
            $discoveryLockKey = "router_discovery_lock_{$router->id}";
            $lock = \Illuminate\Support\Facades\Cache::lock($discoveryLockKey, 120); // 2 minute lock

            if (!$lock->get()) {
                Log::info('Discovery already in progress for router, skipping', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                ]);
                if ($task) {
                    $task->markFailed('Interface discovery already in progress', 0, 'Interface discovery already in progress');
                }
                return;
            }

            Log::info('Discovering router interfaces', [
                'router_id' => $router->id,
                'tenant_id' => $this->tenantId,
            ]);

            try {
                $liveData = $provisioningClient->fetchLiveData($router, 'provisioning', $this->tenantId);

                if (isset($liveData['interfaces']) && is_array($liveData['interfaces'])) {
                    // Update router metadata only (status already set to 'online' by CheckRoutersJob)
                    $router->update(array_merge([
                        'last_seen' => now(),
                    ], app(RouterOsCapabilityRegistry::class)->buildRouterUpdatePayload($liveData, $router)));

                    broadcast(new RouterInterfacesDiscovered(
                        $this->tenantId,
                        $router->id,
                        $liveData['interfaces'],
                        [
                            'model' => $liveData['board_name'] ?? null,
                            'version' => $liveData['version'] ?? null,
                            'uptime' => $liveData['uptime'] ?? null,
                            'interface_count' => count($liveData['interfaces']),
                        ]
                    ));

                    if ($task) {
                        $task->markCompleted([
                            'router_id' => $router->id,
                            'interfaces' => $liveData['interfaces'],
                            'model' => $liveData['board_name'] ?? null,
                            'version' => $liveData['version'] ?? null,
                            'uptime' => $liveData['uptime'] ?? null,
                        ], 100, 'Router interfaces discovered successfully');
                    }

                    Log::info('Router interface discovery completed', [
                        'router_id' => $router->id,
                        'router_name' => $router->name,
                        'interface_count' => count($liveData['interfaces']),
                        'tenant_id' => $this->tenantId,
                        'model' => $liveData['board_name'] ?? null,
                        'message' => 'Interfaces discovered - ready for service configuration',
                    ]);
                } else {
                    Log::warning('No interfaces found in live data', [
                        'router_id' => $router->id,
                        'live_data_keys' => array_keys($liveData ?? []),
                    ]);
                    if ($task) {
                        $task->markFailed('No interfaces found in live data', 100, 'No interfaces found in live data');
                    }
                }
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                $isPermanentFailure = str_contains($errorMessage, 'unable to authenticate')
                    || str_contains($errorMessage, 'no supported methods remain')
                    || str_contains($errorMessage, 'handshake failed')
                    || str_contains($errorMessage, 'Connection refused')
                    || str_contains($errorMessage, 'no route to host');

                Log::error('Failed to discover router interfaces', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                    'error' => $errorMessage,
                    'permanent_failure' => $isPermanentFailure,
                ]);

                if ($task) {
                    $task->markFailed($errorMessage, $task->progress, 'Failed to discover router interfaces');
                }

                if (! $isPermanentFailure) {
                    throw $e;
                }
            } finally {
                $lock->release();
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Router interface discovery job failed', [
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if ($this->routerTaskId && ($task = RouterTask::find($this->routerTaskId))) {
            $task->markFailed($exception->getMessage(), $task->progress, 'Router interface discovery job failed');
        }
    }
}
