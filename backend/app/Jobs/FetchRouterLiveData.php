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
        
        Log::withContext(['job' => 'FetchRouterLiveData'])->debug('Job initialized', [
            'tenant_id' => $tenantId,
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
            ])->debug('Starting job execution using VictoriaMetrics');

            try {
                $routers = Router::whereIn('id', $this->routerIds)->get()->keyBy('id');

                Log::debug('Retrieved routers from database', [
                    'router_count' => $routers->count(),
                ]);

                if ($routers->isEmpty()) {
                    Log::warning('No routers found for provided IDs');
                    return;
                }

                // Batch fetch metrics from VictoriaMetrics
                $liveDataBatch = [];
                try {
                    $liveDataBatch = $metricsService->getLatestRouterMetrics($vm, (string) $this->tenantId, $this->routerIds);
                } catch (\Throwable $e) {
                    $this->logVmBatchIssueOnce($e);

                    $liveDataBatch = $this->getCachedLiveDataForRouters();
                }

                $successfulRouters = [];
                $failedRouters = [];

                $inactiveThreshold = (int) config('vpn.monitoring.inactive_threshold', 190);

                $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];

                foreach ($this->routerIds as $routerId) {
                    $router = $routers->get($routerId);
                    if (!$router) continue;

                    // Skip routers that are under provisioning
                    if (in_array($router->status, $provisioningStatuses)) {
                        Log::info('Skipping router - under provisioning', [
                            'router_id' => $router->id,
                            'status' => $router->status
                        ]);
                        continue;
                    }

                    $liveData = $liveDataBatch[$routerId] ?? [];
                    
                    // Refresh router to get latest VPN status (set by WireGuard monitoring)
                    $router->refresh();

                    $inProvisioning = in_array($router->status, $provisioningStatuses);

                    // Determine status based on both metrics AND VPN handshake
                    // VPN handshake is the source of truth for router connectivity
                    $hasMetrics = !empty($liveData) && isset($liveData['uptime']);
                    $isVpnActive = $router->vpn_status === 'active' && $router->vpn_last_handshake;
                    
                    // Only mark router online if BOTH metrics exist AND VPN is active
                    // Metrics alone are not sufficient - VPN tunnel is the primary connectivity
                    if ($hasMetrics && $isVpnActive) {
                        Log::debug('Fetched live data for router from VM - VPN active', [
                            'router_id' => $router->id,
                            'live_data' => $liveData,
                            'vpn_status' => $router->vpn_status,
                        ]);

                        // Broadcast event on tenant-scoped channel
                        broadcast(new RouterLiveDataUpdated((string) $this->tenantId, (string) $router->id, $liveData));
                        
                        // Cache data (30 seconds max to prevent stale data)
                        Cache::put(
                            "router_live_data_{$router->id}", 
                            $liveData, 
                            now()->addSeconds(30)
                        );

                        // Update router status to ONLINE - both metrics AND VPN confirm connectivity
                        $this->updateRouterStatus($router, $liveData, 'online');
                        $successfulRouters[] = $router->id;
                        
                        // Update last_seen since VPN is active
                        $router->update(['last_seen' => now()]);
                    } elseif ($hasMetrics && !$isVpnActive) {
                        // Metrics exist but VPN is inactive - possible edge case (cached data or direct IP access)
                        // Do NOT mark router online - let CheckRoutersJob handle based on VPN handshake
                        Log::warning('Router has metrics but VPN is inactive - not marking online', [
                            'router_id' => $router->id,
                            'vpn_status' => $router->vpn_status,
                            'vpn_last_handshake' => $router->vpn_last_handshake,
                            'current_status' => $router->status,
                        ]);
                        
                        // Still cache the metrics data for display purposes
                        Cache::put(
                            "router_live_data_{$router->id}", 
                            $liveData, 
                            now()->addSeconds(30)
                        );
                        
                        // Update last_checked only
                        $router->update(['last_checked' => now()]);
                    } else {
                        if ($inProvisioning) {
                            Log::info('Router in provisioning has no metrics yet - leaving status untouched', [
                                'router_id' => $router->id,
                                'status' => $router->status,
                            ]);
                            continue;
                        }

                        $handshakeRecentlyActive = false;
                        if ($router->vpn_status === 'active' && $router->vpn_last_handshake) {
                            $handshakeAge = abs(now()->diffInSeconds($router->vpn_last_handshake, false));
                            $handshakeRecentlyActive = $handshakeAge <= $inactiveThreshold;
                        }

                        if ($handshakeRecentlyActive) {
                            Log::debug('Router has active VPN but no metrics yet - keeping online', [
                                'router_id' => $router->id,
                                'handshake_age_seconds' => $handshakeAge ?? null,
                                'threshold_seconds' => $inactiveThreshold,
                            ]);

                            $this->updateRouterStatus($router, [
                                'handshake_only' => true,
                                'vpn_status' => $router->vpn_status,
                                'vpn_last_handshake' => $router->vpn_last_handshake,
                            ], 'online');
                            $successfulRouters[] = $router->id;
                            continue;
                        }

                        // No metrics and no recent handshake - mark offline
                        $failedRouters[] = $routerId;
                        $router->update(['last_checked' => now()]);

                        Log::info('Router has no metrics and inactive VPN - marking offline', [
                            'router_id' => $router->id,
                            'vpn_status' => $router->vpn_status,
                            'vpn_last_handshake' => $router->vpn_last_handshake,
                        ]);
                        continue;
                    }
                }

                $duration = round(microtime(true) - $startTime, 2);

                Log::debug('Job completed successfully', [
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
                    Log::debug('Skipping forced offline transition after VM fetch failure', [
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

    protected function getCachedLiveDataForRouters(): array
    {
        $cachedBatch = [];

        foreach ($this->routerIds as $routerId) {
            $rid = (string) $routerId;
            $cached = Cache::get("router_live_data_{$rid}");

            if (is_array($cached) && !empty($cached) && !isset($cached['error'])) {
                $cachedBatch[$rid] = $cached;
            }
        }

        return $cachedBatch;
    }

    private function logVmBatchIssueOnce(\Throwable $e): void
    {
        $cacheKey = sprintf('fetch_router_live_data_vm_batch_failed:%s', (string) $this->tenantId);
        $context = [
            'tenant_id' => $this->tenantId,
            'router_count' => count($this->routerIds),
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ];

        if (Cache::add($cacheKey, true, now()->addMinutes(5))) {
            Log::warning('Failed to fetch batch metrics from VictoriaMetrics; using cached live data', $context);
            return;
        }

        Log::debug('Failed to fetch batch metrics from VictoriaMetrics; using cached live data', $context);
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
            now()->addSeconds(30)
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
