<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Jobs\DiscoverRouterInterfacesJob;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnConfiguration;
use App\Models\WireguardPeer;
use App\Services\CacheInvalidationService;
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

            Log::withContext($context)->debug('Starting router status check job for tenant (WireGuard handshake mode)');

            try {
                // Get all routers for this tenant
                $routers = Router::whereNotIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])->get();
                $pendingRouters = Router::whereIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])->get();
                
                $updatedStatuses = [];
                $inactiveThreshold = (int) config('vpn.monitoring.inactive_threshold', 120);
                
                // Get handshakes for ALL routers (including pending)
                $allRouterIds = $routers->pluck('id')->merge($pendingRouters->pluck('id'));
                $peerHandshakes = WireguardPeer::whereIn('router_id', $allRouterIds)
                    ->orderBy('last_handshake', 'desc')
                    ->get(['router_id', 'last_handshake'])
                    ->groupBy('router_id');

                // Check pending routers for VPN connectivity (triggers discovery)
                foreach ($pendingRouters as $router) {
                    $this->checkPendingRouterVpn($router, $context, $peerHandshakes);
                }

                // Check non-pending routers using WireGuard handshake only (no SSH/TCP/ping probes)
                foreach ($routers as $router) {
                    // Check if router is currently locked by another operation
                    $lockKey = "router_api_lock_{$router->id}";
                    if (Cache::has($lockKey)) {
                        continue;
                    }

                    try {
                        // Refresh router to get latest data (prevents race conditions with VerifyVpnConnectivityJob)
                        $router->refresh();

                        $latestHandshake = $peerHandshakes->get($router->id)?->first()?->last_handshake;
                        $previousVpnHandshake = $router->vpn_last_handshake;

                        // Use the most recent handshake from either WireGuard peer or existing router record
                        // This prevents flipping to offline if we just verified connectivity via ping (which updates router record)
                        // but the WireGuard dump hasn't updated yet or is lagging
                        if ($previousVpnHandshake && (!$latestHandshake || $previousVpnHandshake > $latestHandshake)) {
                            $latestHandshake = $previousVpnHandshake;
                        }

                        $vpnStatus = ($latestHandshake && now()->diffInSeconds($latestHandshake) <= $inactiveThreshold)
                            ? 'active'
                            : 'inactive';

                        // Get VPN configuration for this router
                        $previousStatus = $router->status;
                        $previousVpnStatus = $router->vpn_status;
                        
                        $status = $vpnStatus === 'active' ? 'online' : 'offline';
                        $latency = null;

                        // Update router status (model/os_version come from Telegraf SNMP, not here)
                        $router->update([
                            'status' => $status,
                            'last_checked' => now(),
                            'last_seen' => $status === 'online' ? now() : $router->last_seen,
                            'vpn_status' => $vpnStatus,
                            'vpn_last_handshake' => $latestHandshake,
                        ]);

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
                                'latency_ms' => $latency,
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
                        $this->markRouterOffline(
                            $router,
                            $updatedStatuses,
                            $context,
                            $vpnStatus ?? $router->vpn_status,
                            $latestHandshake ?? $router->vpn_last_handshake
                        );
                        
                        Log::withContext($context)->warning('Router VPN check failed', [
                            'router_id' => $router->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
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
     */
    private function checkPendingRouterVpn(Router $router, array $context, $peerHandshakes = null): void
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

        // PRIORITY CHECK: Check WireGuard handshake directly
        // If we have a recent handshake, the tunnel is UP, even if Ping is blocked by firewall
        $latestHandshake = $peerHandshakes?->get($router->id)?->first()?->last_handshake;
        $inactiveThreshold = (int) config('vpn.monitoring.inactive_threshold', 120);
        $isHandshakeActive = $latestHandshake && now()->diffInSeconds($latestHandshake) <= $inactiveThreshold;

        if ($isHandshakeActive) {
            Log::withContext($routerContext)->info('Pending router has active WireGuard handshake - marking ONLINE', [
                'handshake_ago' => now()->diffInSeconds($latestHandshake) . 's',
            ]);

            // Ensure vpnConfig is marked connected
            if ($vpnConfig->status !== 'connected') {
                $vpnConfig->update([
                    'status' => 'connected',
                    'last_handshake_at' => $latestHandshake,
                ]);
            }

            // Mark router online and trigger discovery
            $this->markRouterOnline($router, $vpnConfig, $latestHandshake, $routerContext);
            return;
        }

        Log::withContext($routerContext)->info('Checking VPN connectivity for pending router', [
            'vpn_config_id' => $vpnConfig->id,
            'client_ip' => $vpnConfig->client_ip,
            'vpn_status' => $vpnConfig->status,
        ]);

        // Skip if VPN is already connected (legacy check)
        if ($vpnConfig->status === 'connected') {
            // Refresh router to get latest status (another job may have updated it)
            $router->refresh();
            
            // If VPN is connected but router is still pending, mark online FIRST then dispatch discovery
            if ($router->status === 'pending') {
                $this->markRouterOnline($router, $vpnConfig, $vpnConfig->last_handshake_at, $routerContext);
            }
            return;
        }

        // Rate limit VPN checks to avoid excessive pinging (once per minute per router)
        $vpnCheckKey = "vpn_check_pending_{$router->id}";
        if (Cache::has($vpnCheckKey)) {
            Log::withContext($routerContext)->info('VPN check rate limited, skipping');
            return;
        }

        // Set rate limit for 55 seconds (job runs every minute)
        Cache::put($vpnCheckKey, true, 55);

        try {
            $connectivityService = app(VpnConnectivityService::class);
            
            // Quick ping check (2 pings, 3 second timeout) for provisioning/pending routers
            $result = $connectivityService->verifyConnectivity($vpnConfig, 2, 3, true);

            if ($result['success'] && $result['packet_loss'] === 0) {
                // VPN is now reachable! Mark router as ONLINE immediately (no SSH required)
                Log::withContext($routerContext)->info('Pending router VPN now reachable via Ping - marking ONLINE', [
                    'latency_ms' => $result['latency'],
                ]);

                $vpnConfig->update([
                    'status' => 'connected',
                    'last_handshake_at' => now(),
                ]);

                $this->markRouterOnline($router, $vpnConfig, now(), $routerContext);
            } else {
                Log::withContext($routerContext)->info('Pending router VPN not reachable', [
                    'success' => $result['success'] ?? false,
                    'packet_loss' => $result['packet_loss'] ?? 100,
                    'raw_output' => substr($result['raw_output'] ?? '', 0, 500),
                ]);
            }
        } catch (Throwable $e) {
            Log::withContext($routerContext)->error('VPN check failed for pending router', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
            Cache::put($discoveryDispatchKey, true, 120); // 2 minute deduplication
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
