<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Jobs\DiscoverRouterInterfacesJob;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnConfiguration;
use App\Models\WireguardPeer;
use App\Services\CacheInvalidationService;
use App\Services\RouterStatusCheckService;
use App\Services\VpnConnectivityService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckRoutersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('router-checks'); // Assign to specific queue
    }

    /**
     * Execute the job.
     * 
     * OPTIMIZED: Uses WireGuard handshake age for online status checks.
     * No SSH/TCP probes are made - this drastically reduces resource usage.
     * Metrics collection is handled by Telegraf via SNMP (separate from this job).
     */
    public function handle(): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::debug("Dispatched router check jobs for " . $tenants->count() . " tenants");
            return;
        }

        // Execute checking logic within tenant context
        $this->executeInTenantContext(function() {
            $context = [
                'job' => 'CheckRoutersJob',
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
                'job_id' => $this->job?->getJobId() ?? 'unknown',
            ];

            $statusCheckService = app(RouterStatusCheckService::class);

            Log::withContext($context)->debug('Starting router status check job for tenant (strict phase-based mode)');

            try {
                // Get all routers for this tenant
                $routers = Router::whereNotIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])->get();
                $pendingRouters = Router::whereIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])->get();

                $updatedStatuses = [];

                // Check pending routers for VPN connectivity (triggers discovery)
                foreach ($pendingRouters as $router) {
                    $this->checkPendingRouterVpn($router, $context, $statusCheckService);
                }

                // Check operational routers using STRICT handshake-only (no ping/TCP probes)
                foreach ($routers as $router) {
                    $this->checkOperationalRouter($router, $context, $updatedStatuses, $statusCheckService);
                }

                // Broadcast batch updates if any
                if (!empty($updatedStatuses)) {
                    try {
                        broadcast(new RouterStatusUpdated($updatedStatuses, (string) $this->tenantId))->toOthers();
                        Log::withContext($context)->debug('Broadcasted RouterStatusUpdated', [
                            'router_count' => count($updatedStatuses),
                        ]);
                    } catch (\Exception $e) {
                        Log::withContext($context)->warning('Failed to broadcast batch RouterStatusUpdated', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

            } catch (Throwable $e) {
                Log::withContext($context)->error('Router check job failed', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            Log::withContext($context)->debug('Completed router status check job');
        });
    }

    /**
     * Mark a router as offline and prepare broadcast payload
     */
    private function markRouterOffline(
        Router $router,
        array &$updatedStatuses,
        array $context,
        ?string $vpnStatus = null,
        $vpnLastHandshake = null
    ): void
    {
        $previousStatus = $router->status;
        $previousVpnStatus = $router->vpn_status;
        $previousVpnHandshake = $router->vpn_last_handshake;
        $vpnStatus = $vpnStatus ?? $previousVpnStatus;
        $vpnLastHandshake = $vpnLastHandshake ?? $previousVpnHandshake;

        $router->update([
            'status' => 'offline',
            'last_checked' => now(),
            'vpn_status' => $vpnStatus,
            'vpn_last_handshake' => $vpnLastHandshake,
        ]);

        $handshakeChanged = ($previousVpnHandshake?->getTimestamp() ?? null)
            !== ($vpnLastHandshake?->getTimestamp() ?? null);
        $shouldBroadcast = $previousStatus !== 'offline'
            || $previousVpnStatus !== $vpnStatus
            || $handshakeChanged;

        if ($shouldBroadcast) {
            CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);
        }

        $payload = array_merge([
            'id' => $router->id,
            'ip_address' => $router->ip_address,
            'vpn_ip' => $router->vpn_ip,
            'name' => $router->name,
            'status' => 'offline',
            'last_checked' => $router->last_checked,
            'vpn_status' => $vpnStatus,
            'vpn_last_handshake' => $vpnLastHandshake,
            'tenant_id' => (string) $this->tenantId,
        ], $this->buildHandshakeTimezonePayload($vpnLastHandshake));

        if ($shouldBroadcast) {
            $updatedStatuses[] = $payload;
        }

        try {
            if ($shouldBroadcast) {
                broadcast(new RouterStatusUpdated([$payload], (string) $this->tenantId))->toOthers();
            }
        } catch (\Exception $e) {
            Log::withContext($context)->warning('Failed to broadcast offline status', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a pending router's VPN is now reachable and trigger discovery.
     * This allows provisioning to continue after user applies the config script.
     * Uses STRICT PING-ONLY checking during provisioning phase.
     */
    private function checkPendingRouterVpn(Router $router, array $context, RouterStatusCheckService $statusCheckService): void
    {
        $routerContext = array_merge($context, [
            'router_id' => $router->id,
            'ip_address' => $router->ip_address,
            'name' => $router->name,
            'status' => $router->status,
        ]);

        // Check if there's a VPN configuration for this router
        $vpnConfig = VpnConfiguration::where('router_id', $router->id)->first();
        
        if (!$vpnConfig) {
            Log::withContext($routerContext)->info('No VPN config found for pending router');
            return;
        }

        // Rate limit VPN checks to avoid excessive pinging (once per minute per router)
        $vpnCheckKey = "vpn_check_pending_{$router->id}";
        if (Cache::has($vpnCheckKey)) {
            Log::withContext($routerContext)->debug('VPN check rate limited, skipping');
            return;
        }

        // Set rate limit for 30 seconds (reduced from 55 to prevent stale cache)
        Cache::put($vpnCheckKey, true, 30);

        // Use RouterStatusCheckService for strict ping-only check
        $result = $statusCheckService->checkStatusProvisioning($router);

        if ($result['online']) {
            Log::withContext($routerContext)->info('Pending router VPN now reachable via Ping - marking ONLINE', [
                'latency_ms' => $result['latency_ms'],
                'packet_loss' => $result['packet_loss'],
            ]);

            $vpnConfig->update([
                'status' => 'connected',
                'last_handshake_at' => now(),
            ]);

            $this->markRouterOnline($router, $vpnConfig, now(), $routerContext);
        } else {
            Log::withContext($routerContext)->debug('Pending router VPN not reachable via ping', [
                'reason' => $result['reason'] ?? 'Ping failed',
            ]);
        }
    }

    /**
     * Check operational router status using metrics as primary source.
     * If metrics are flowing, router is online regardless of VPN handshake.
     */
    private function checkOperationalRouter(
        Router $router,
        array $context,
        array &$updatedStatuses,
        RouterStatusCheckService $statusCheckService
    ): void {
        // Check if router is currently locked by another operation
        $lockKey = "router_api_lock_{$router->id}";
        if (Cache::has($lockKey)) {
            return;
        }

        try {
            // Refresh router to get latest data
            $router->refresh();

            $previousStatus = $router->status;
            $previousVpnStatus = $router->vpn_status;
            $previousVpnHandshake = $router->vpn_last_handshake;

            // Check if we have recent metrics data (from FetchRouterLiveData job)
            // If metrics are being collected, the router is definitely online
            $lastChecked = $router->last_checked;
            $hasRecentMetrics = $lastChecked && $lastChecked->gt(now()->subMinutes(2));
            
            if ($hasRecentMetrics && $previousStatus === 'online') {
                // Metrics are flowing and router was recently online - trust metrics
                // Skip the strict VPN handshake check to avoid false negatives
                Log::withContext($context)->debug('Router has recent metrics, preserving online status', [
                    'router_id' => $router->id,
                    'last_checked' => $lastChecked->toIso8601String(),
                    'vpn_status' => $router->vpn_status,
                ]);
                
                // Still update VPN-specific fields from handshake check
                // but don't override the online status if metrics prove connectivity
                $result = $statusCheckService->checkStatusOperational($router);
                
                // Update VPN status fields without overriding online status from metrics
                $router->update([
                    'last_checked' => now(),
                    'vpn_status' => $result['vpn_status'],
                    'vpn_last_handshake' => $result['handshake_at'] ?? null,
                ]);
                
                // Only mark offline if BOTH metrics stopped AND VPN is inactive
                if (!$result['online'] && $router->vpn_status === 'inactive') {
                    // Check if metrics have been missing for a while (> 5 minutes)
                    $metricsStale = !$lastChecked || $lastChecked->lt(now()->subMinutes(5));
                    if ($metricsStale) {
                        $status = 'offline';
                        $this->markRouterOffline($router, $updatedStatuses, $context, $result['vpn_status'], $result['handshake_at'] ?? null);
                        return;
                    }
                }
                
                return; // Keep current online status, metrics are flowing
            }

            // No recent metrics - fall back to strict VPN handshake check
            $result = $statusCheckService->checkStatusOperational($router);

            $status = $result['online'] ? 'online' : 'offline';
            $vpnStatus = $result['vpn_status'];
            $latestHandshake = $result['handshake_at'] ?? null;

            // Update router status
            $router->update([
                'status' => $status,
                'last_checked' => now(),
                'last_seen' => ($status === 'online' && $vpnStatus === 'active') ? now() : $router->last_seen,
                'vpn_status' => $vpnStatus,
                'vpn_last_handshake' => $latestHandshake,
            ]);

            // Determine if we should broadcast
            $handshakeChanged = ($previousVpnHandshake?->getTimestamp() ?? null)
                !== ($latestHandshake?->getTimestamp() ?? null);
            $shouldBroadcast = $previousStatus !== $status
                || $previousVpnStatus !== $vpnStatus
                || $handshakeChanged;

            if ($shouldBroadcast) {
                CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);

                $payload = array_merge([
                    'id' => $router->id,
                    'ip_address' => $router->ip_address,
                    'vpn_ip' => $router->vpn_ip,
                    'name' => $router->name,
                    'status' => $status,
                    'last_checked' => $router->last_checked,
                    'model' => $router->model,
                    'os_version' => $router->os_version,
                    'last_seen' => $router->last_seen,
                    'latency_ms' => null, // No latency from handshake-only check
                    'vpn_status' => $vpnStatus,
                    'vpn_last_handshake' => $latestHandshake,
                    'tenant_id' => (string) $this->tenantId,
                ], $this->buildHandshakeTimezonePayload($latestHandshake));

                $updatedStatuses[] = $payload;

                try {
                    broadcast(new RouterStatusUpdated([$payload], (string) $this->tenantId))->toOthers();
                } catch (\Exception $e) {
                    Log::withContext($context)->warning('Failed to broadcast RouterStatusUpdated', [
                        'router_id' => $router->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (Throwable $e) {
            Log::withContext($context)->warning('Operational router check failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            // Mark as offline on error
            $this->markRouterOffline($router, $updatedStatuses, $context, $router->vpn_status, $router->vpn_last_handshake);
        }
    }

    /**
     * Helper to mark router online and dispatch discovery
     */
    private function markRouterOnline(Router $router, $vpnConfig, $handshakeAt, array $context): void
    {
        // Mark router as online FIRST
        $router->update([
            'status' => 'online',
            'last_seen' => now(),
            'last_checked' => now(),
            'vpn_status' => 'active',
            'vpn_last_handshake' => $handshakeAt,
        ]);
        
        CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);
        try {
            broadcast(new RouterStatusUpdated([array_merge([
                'id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
                'vpn_ip' => $router->vpn_ip,
                'status' => 'online',
                'last_seen' => $router->last_seen,
                'vpn_status' => $router->vpn_status,
                'vpn_last_handshake' => $router->vpn_last_handshake,
                'tenant_id' => (string) $this->tenantId,
            ], $this->buildHandshakeTimezonePayload($router->vpn_last_handshake))], (string) $this->tenantId))->toOthers();
        } catch (\Exception $e) {
            Log::withContext($context)->warning('Failed to broadcast online status', ['error' => $e->getMessage()]);
        }
        
        Log::withContext($context)->info('VPN connected - marked router ONLINE');
        
        // Then dispatch discovery for interfaces (non-blocking)
        $discoveryDispatchKey = "discovery_dispatch_{$router->id}";
        if (!Cache::has($discoveryDispatchKey)) {
            Cache::put($discoveryDispatchKey, true, 30); // 30 second deduplication
            Log::withContext($context)->info('Dispatching interface discovery job');
            dispatch(new DiscoverRouterInterfacesJob($this->tenantId, $router->id))
                ->onQueue('router-provisioning');
        } else {
            Log::withContext($context)->info('Discovery job already dispatched recently, skipping');
        }
    }

    /**
     * Handle job failure after all attempts.
     */
    public function failed(Throwable $exception): void
    {
        $context = [
            'job' => 'CheckRoutersJob',
            'attempt' => $this->attempts(),
            'job_id' => $this->job?->getJobId() ?? 'unknown',
        ];

        Log::withContext($context)->error('Job failed after all retry attempts', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    private function buildHandshakeTimezonePayload($handshakeAt): array
    {
        $handshake = null;

        if ($handshakeAt instanceof Carbon) {
            $handshake = $handshakeAt;
        } elseif (!empty($handshakeAt)) {
            $handshake = Carbon::parse($handshakeAt);
        }

        $utc = $handshake?->copy()->timezone('UTC')->toIso8601String();
        $eat = $handshake?->copy()->timezone('Africa/Nairobi')->toIso8601String();

        return [
            'vpn_last_handshake_utc' => $utc,
            'vpn_last_handshake_eat' => $eat,
            'vpn_last_handshake_timezones' => [
                'UTC' => $utc,
                'Africa/Nairobi' => $eat,
            ],
        ];
    }
}
