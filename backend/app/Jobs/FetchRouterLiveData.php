<?php

namespace App\Jobs;

use App\Events\RouterLiveDataUpdated;
use App\Events\RouterStatusUpdated;
use App\Models\Router;
use App\Services\CacheInvalidationService;
use App\Services\RouterMetricsService;
use App\Services\VictoriaMetricsClient;
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

    public function handle(RouterMetricsService $metricsService, VictoriaMetricsClient $vm)
    {
        $this->executeInTenantContext(function() use ($metricsService, $vm) {
            $startTime = microtime(true);
            
            Log::withContext([
                'job' => 'FetchRouterLiveData',
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
                'job_id' => $this->job->getJobId() ?? 'unknown'
            ])->info('Starting job execution using VictoriaMetrics');

            try {
                $routers = Router::whereIn('id', $this->routerIds)->get()->keyBy('id');

                Log::info('Retrieved routers from database', [
                    'router_count' => $routers->count(),
                    'router_ids' => $routers->keys()->toArray()
                ]);

                if ($routers->isEmpty()) {
                    Log::warning('No routers found for provided IDs');
                    return;
                }

                // Batch fetch metrics from VictoriaMetrics
                $liveDataBatch = [];
                try {
                    $liveDataBatch = $metricsService->getLatestRouterMetrics($vm, (string) $this->tenantId, $this->routerIds);
                } catch (\Exception $e) {
                    Log::error('Failed to fetch batch metrics from VictoriaMetrics', [
                        'error' => $e->getMessage()
                    ]);
                    // Continue with empty batch - allows marking routers as offline if needed, 
                    // or better, rely on existing data if VM is temporarily down? 
                    // For now, we assume if VM fetch fails, we can't update status reliably.
                    // But we should probably not mark everything offline immediately on single scrape failure.
                    throw $e;
                }

                $successfulRouters = [];
                $failedRouters = [];

                foreach ($this->routerIds as $routerId) {
                    $router = $routers->get($routerId);
                    if (!$router) continue;

                    // Skip routers that are under provisioning
                    if (in_array($router->status, ['pending', 'deploying', 'provisioning', 'verifying'])) {
                        Log::info('Skipping router - under provisioning', [
                            'router_id' => $router->id,
                            'status' => $router->status
                        ]);
                        continue;
                    }

                    $liveData = $liveDataBatch[$routerId] ?? [];
                    
                    // Determine status based on presence of metrics
                    // If we got metrics (e.g. uptime), router is online.
                    // If array is empty, it might be offline or Telegraf issues.
                    $isOnline = !empty($liveData) && isset($liveData['uptime']);
                    
                    if ($isOnline) {
                        Log::debug('Fetched live data for router from VM', [
                            'router_id' => $router->id,
                            'live_data' => $liveData
                        ]);

                        // Broadcast event on tenant-scoped channel
                        broadcast(new RouterLiveDataUpdated((string) $this->tenantId, (string) $router->id, $liveData));
                        
                        // Cache data
                        Cache::put(
                            "router_live_data_{$router->id}", 
                            $liveData, 
                            now()->addSeconds(60)
                        );

                        // Update router status
                        $this->updateRouterStatus($router, $liveData, 'online');
                        $successfulRouters[] = $router->id;
                    } else {
                        // VM returned no data for this router
                        // It could be offline, new and not yet scraped, or VM/Telegraf lag.
                        // IMPORTANT: Do not force offline here; CheckRoutersJob is the
                        // connectivity source of truth during/after provisioning.
                        $failedRouters[] = $router->id;
                        $router->refresh();
                        $router->update(['last_checked' => now()]);

                        Log::debug('No VM metrics returned; preserving connectivity status', [
                            'router_id' => $router->id,
                            'current_status' => $router->status,
                            'vpn_status' => $router->vpn_status,
                            'last_seen' => $router->last_seen,
                        ]);

                        continue;
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
                    Log::warning('Skipping forced offline transition after VM fetch failure', [
                        'tenant_id' => $this->tenantId,
                        'router_ids' => $this->routerIds,
                        'reason' => 'Connectivity state is managed by CheckRoutersJob/VerifyVpnConnectivityJob',
                    ]);
                }

                throw $e;
            }
        });
    }

    protected function updateRouterStatus(Router $router, array $liveData, string $status): void
    {
        try {
            $previousStatus = $router->status;
            $updates = [
                'status' => $status,
                'last_checked' => now(),
            ];

            if ($status === 'online') {
                $updates['last_seen'] = now();
                if (isset($liveData['board_name'])) $updates['model'] = $liveData['board_name'];
                if (isset($liveData['version'])) $updates['os_version'] = $liveData['version'];
            }

            $router->update($updates);

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

        Log::warning('FetchRouterLiveData failed without forcing router offline state', [
            'tenant_id' => $this->tenantId,
            'router_ids' => $this->routerIds,
            'reason' => 'Connectivity state is managed by CheckRoutersJob/VerifyVpnConnectivityJob',
        ]);
    }
}