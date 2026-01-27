<?php

namespace App\Jobs;

use App\Events\RouterLiveDataUpdated;
use App\Events\RouterStatusUpdated;
use App\Models\Router;
use App\Services\CacheInvalidationService;
use App\Services\MikrotikProvisioningService;
use App\Services\MikrotikSnmpService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchRouterLiveData implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $timeout = 60;
    public $tries = 3;
    public $maxExceptions = 3;
    public $backoff = [10, 30, 60];
    public $deleteWhenMissingModels = true;
    public $routerIds;

    public function __construct(string $tenantId, array $routerIds = [])
    {
        $this->setTenantContext($tenantId);
        $this->routerIds = $routerIds;
        $this->onQueue('router-data');
        
        Log::withContext(['job' => 'FetchRouterLiveData'])->info('Job initialized', [
            'tenant_id' => $tenantId,
            'router_ids' => $this->routerIds,
            'router_count' => count($this->routerIds),
        ]);
    }

    public function handle(MikrotikProvisioningService $routerService, MikrotikSnmpService $snmpService)
    {
        $this->executeInTenantContext(function() use ($routerService, $snmpService) {
            $startTime = microtime(true);
            
            Log::withContext([
                'job' => 'FetchRouterLiveData',
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
                'job_id' => $this->job->getJobId() ?? 'unknown'
            ])->info('Starting job execution');

            try {
                $routers = Router::whereIn('id', $this->routerIds)->get();

                Log::info('Retrieved routers from database', [
                    'router_count' => $routers->count(),
                    'router_ids' => $routers->pluck('id')->toArray()
                ]);

                if ($routers->isEmpty()) {
                    Log::warning('No routers found for provided IDs');
                    return;
                }

                $successfulRouters = [];
                $failedRouters = [];

                foreach ($routers->lazy() as $router) {
                    Log::info('Processing router', [
                        'router_id' => $router->id,
                        'router_name' => $router->name
                    ]);

                    // Skip routers that are under provisioning
                    if (in_array($router->status, ['pending', 'deploying', 'provisioning', 'verifying'])) {
                        Log::info('Skipping router - under provisioning', [
                            'router_id' => $router->id,
                            'status' => $router->status
                        ]);
                        continue;
                    }

                    try {
                        $useSnmp = filter_var(env('MIKROTIK_SNMP_ENABLED', true), FILTER_VALIDATE_BOOL);

                        if ($useSnmp) {
                            try {
                                $liveData = $snmpService->fetchLiveData($router, false);
                            } catch (\Exception $snmpException) {
                                $liveData = $routerService->fetchLiveRouterData($router, 'live', false);
                            }
                        } else {
                            $liveData = $routerService->fetchLiveRouterData($router, 'live', false);
                        }
                        
                        Log::debug('Fetched live data for router', [
                            'router_id' => $router->id,
                            'live_data' => $liveData
                        ]);

                        // Broadcast event on tenant-scoped channel
                        broadcast(new RouterLiveDataUpdated((string) $this->tenantId, (string) $router->id, $liveData));
                        
                        Log::info('Broadcasted update event', [
                            'router_id' => $router->id,
                            'data_size' => strlen(json_encode($liveData))
                        ]);

                        // FIXED: Remove tags() - use regular cache put
                        Cache::put(
                            "router_live_data_{$router->id}", 
                            $liveData, 
                            now()->addSeconds(60)
                        );

                        // Update router status
                        $this->updateRouterStatus($router, $liveData, 'online');
                        $successfulRouters[] = $router->id;
                    } catch (\Exception $e) {
                        // Don't count as failure if router is busy
                        if ($e->getCode() === 503 || str_contains($e->getMessage(), 'busy')) {
                            Log::info('Router is busy, skipping live data fetch', [
                                'router_id' => $router->id
                            ]);
                            continue;
                        }

                        // Don't count as failure if password decryption failed (APP_KEY issue)
                        if (str_contains($e->getMessage(), 'decrypt')) {
                            Log::error('Password decryption failed - possible APP_KEY mismatch', [
                                'router_id' => $router->id,
                                'router_name' => $router->name,
                                'error' => $e->getMessage(),
                                'hint' => 'Check if APP_KEY in .env.production matches the key used when router was created'
                            ]);
                        }

                        $failedRouters[] = $router->id;
                        
                        Log::warning('Failed to fetch live data for router', [
                            'router_id' => $router->id,
                            'error' => $e->getMessage(),
                            'exception' => get_class($e)
                        ]);

                        $this->cacheErrorState($router, $e);
                        $this->updateRouterStatus($router, [], 'offline');
                    }
                }

                $duration = round(microtime(true) - $startTime, 2);

                Log::info('Job completed successfully', [
                    'successful_routers' => $successfulRouters,
                    'failed_routers' => $failedRouters,
                    'success_count' => count($successfulRouters),
                    'failure_count' => count($failedRouters),
                    'duration_seconds' => $duration
                ]);

            } catch (\Exception $e) {
                Log::error('Job execution failed', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'attempt' => $this->attempts()
                ]);

                if ($this->attempts() >= $this->tries) {
                    $this->markAllRoutersOffline();
                }

                throw $e;
            }
        });
    }

    protected function updateRouterStatus(Router $router, array $liveData, string $status): void
    {
        try {
            $previousStatus = $router->status;
            $router->update([
                'status' => $status,
                'model' => $liveData['board_name'] ?? $router->model,
                'os_version' => $liveData['version'] ?? $router->os_version,
                'last_seen' => $status === 'online' ? now() : $router->last_seen,
            ]);

            if ($previousStatus !== $status) {
                CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);

                $payload = [
                    'id' => (string) $router->id,
                    'ip_address' => $router->ip_address,
                    'name' => $router->name,
                    'status' => $status,
                    'last_checked' => $router->last_checked,
                    'model' => $router->model,
                    'os_version' => $router->os_version,
                    'last_seen' => $router->last_seen,
                    'tenant_id' => (string) $this->tenantId,
                ];

                try {
                    broadcast(new RouterStatusUpdated([$payload], (string) $this->tenantId))->toOthers();
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast RouterStatusUpdated from FetchRouterLiveData', [
                        'router_id' => $router->id,
                        'tenant_id' => $this->tenantId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to update router status', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function cacheErrorState(Router $router, \Exception $e): void
    {
        // FIXED: Remove tags() - use regular cache put
        Cache::put(
            "router_live_data_{$router->id}", 
            [
                'error' => 'Unable to connect to router: ' . $e->getMessage(),
                'status' => 'offline',
                'last_attempt' => now()->toISOString(),
                'exception' => get_class($e)
            ], 
            now()->addSeconds(60)
        );
    }

    protected function markAllRoutersOffline(): void
    {
        try {
            $routers = Router::whereIn('id', $this->routerIds)->get();
            $broadcastPayloads = [];

            foreach ($routers as $router) {
                $previousStatus = $router->status;
                if ($previousStatus === 'offline') {
                    continue;
                }

                $router->update([
                    'status' => 'offline',
                    'updated_at' => now(),
                ]);

                CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);

                $broadcastPayloads[] = [
                    'id' => (string) $router->id,
                    'ip_address' => $router->ip_address,
                    'name' => $router->name,
                    'status' => 'offline',
                    'last_checked' => $router->last_checked,
                    'model' => $router->model,
                    'os_version' => $router->os_version,
                    'last_seen' => $router->last_seen,
                    'tenant_id' => (string) $this->tenantId,
                ];
            }

            if (!empty($broadcastPayloads)) {
                try {
                    broadcast(new RouterStatusUpdated($broadcastPayloads, (string) $this->tenantId))->toOthers();
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast RouterStatusUpdated from markAllRoutersOffline', [
                        'tenant_id' => $this->tenantId,
                        'router_count' => count($broadcastPayloads),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::warning('Marked routers as offline due to job failure', [
                'tenant_id' => $this->tenantId,
                'router_count' => $routers->count(),
                'changed_count' => count($broadcastPayloads),
            ]);
        } catch (\Exception $e) {
            Log::critical('Failed to mark routers as offline', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::withContext(['job' => 'FetchRouterLiveData'])->critical('Job failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
            'router_ids' => $this->routerIds,
            'max_retries_reached' => true
        ]);

        // We can try to mark routers as offline if we can switch context, 
        // but it's tricky in failed(). 
        // We'll try to rely on the try-catch block in handle().
        // Or we can try:
        try {
            $this->executeInTenantContext(function() {
                $this->markAllRoutersOffline();
            });
        } catch (\Exception $e) {
            Log::error('Failed to execute markAllRoutersOffline in failed()', ['error' => $e->getMessage()]);
        }
    }
}