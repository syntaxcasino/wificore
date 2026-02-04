<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Jobs\DiscoverRouterInterfacesJob;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnConfiguration;
use App\Services\CacheInvalidationService;
use App\Services\VpnConnectivityService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
     * OPTIMIZED: Uses VPN IP ping only for online status checks.
     * No SSH connections are made - this drastically reduces resource usage.
     * Metrics collection is handled by Telegraf via SNMP (separate from this job).
     */
    public function handle(VpnConnectivityService $vpnService): void
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
        $this->executeInTenantContext(function() use ($vpnService) {
            $context = [
                'job' => 'CheckRoutersJob',
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
                'job_id' => $this->job?->getJobId() ?? 'unknown',
            ];

            Log::withContext($context)->debug('Starting router status check job for tenant (VPN ping mode)');

            try {
                // Get all routers for this tenant
                $routers = Router::whereNotIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])->get();
                $pendingRouters = Router::whereIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])->get();
                
                $updatedStatuses = [];

                // Check pending routers for VPN connectivity (triggers discovery)
                foreach ($pendingRouters as $router) {
                    $this->checkPendingRouterVpn($router, $context);
                }

                // Check online routers using VPN IP ping ONLY (no SSH)
                foreach ($routers as $router) {
                    // Check if router is currently locked by another operation
                    $lockKey = "router_api_lock_{$router->id}";
                    if (Cache::has($lockKey)) {
                        continue;
                    }

                    try {
                        // Get VPN configuration for this router
                        $vpnConfig = VpnConfiguration::where('router_id', $router->id)->first();
                        
                        if (!$vpnConfig || !$vpnConfig->client_ip) {
                            // No VPN config - mark as offline if it was online
                            if ($router->status === 'online') {
                                $this->markRouterOffline($router, $updatedStatuses, $context);
                            }
                            continue;
                        }

                        // Quick VPN ping check (2 pings, 3 second timeout) - NO SSH
                        $result = $vpnService->verifyConnectivity($vpnConfig, 2, 3);
                        
                        $status = ($result['success'] && $result['packet_loss'] < 50) ? 'online' : 'offline';
                        $previousStatus = $router->status;

                        // Update router status (model/os_version come from Telegraf SNMP, not here)
                        $router->update([
                            'status' => $status,
                            'last_checked' => now(),
                            'last_seen' => $status === 'online' ? now() : $router->last_seen,
                        ]);

                        if ($previousStatus !== $status) {
                            CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);

                            $payload = [
                                'id' => $router->id,
                                'ip_address' => $router->ip_address,
                                'vpn_ip' => $router->vpn_ip,
                                'name' => $router->name,
                                'status' => $status,
                                'last_checked' => $router->last_checked,
                                'model' => $router->model,
                                'os_version' => $router->os_version,
                                'last_seen' => $router->last_seen,
                                'latency_ms' => $result['latency'] ?? null,
                                'tenant_id' => (string) $this->tenantId,
                            ];

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
                        $this->markRouterOffline($router, $updatedStatuses, $context);
                        
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
    private function markRouterOffline(Router $router, array &$updatedStatuses, array $context): void
    {
        $previousStatus = $router->status;
        if ($previousStatus === 'offline') {
            return;
        }

        $router->update([
            'status' => 'offline',
            'last_checked' => now(),
        ]);

        CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);

        $payload = [
            'id' => $router->id,
            'ip_address' => $router->ip_address,
            'vpn_ip' => $router->vpn_ip,
            'name' => $router->name,
            'status' => 'offline',
            'last_checked' => $router->last_checked,
            'tenant_id' => (string) $this->tenantId,
        ];

        $updatedStatuses[] = $payload;

        try {
            broadcast(new RouterStatusUpdated([$payload], (string) $this->tenantId))->toOthers();
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
    private function checkPendingRouterVpn(Router $router, array $context): void
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

        Log::withContext($routerContext)->info('Checking VPN connectivity for pending router', [
            'vpn_config_id' => $vpnConfig->id,
            'client_ip' => $vpnConfig->client_ip,
            'vpn_status' => $vpnConfig->status,
        ]);

        // Skip if VPN is already connected
        if ($vpnConfig->status === 'connected') {
            // Refresh router to get latest status (another job may have updated it)
            $router->refresh();
            
            // If VPN is connected but router is still pending, mark online FIRST then dispatch discovery
            if ($router->status === 'pending') {
                // Mark router as online immediately (VPN is connected = online)
                $router->update([
                    'status' => 'online',
                    'last_seen' => now(),
                    'last_checked' => now(),
                ]);
                
                CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);
                try {
                    broadcast(new RouterStatusUpdated([[
                        'id' => $router->id,
                        'name' => $router->name,
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'status' => 'online',
                        'last_seen' => $router->last_seen,
                        'tenant_id' => (string) $this->tenantId,
                    ]], (string) $this->tenantId))->toOthers();
                } catch (\Exception $e) {
                    Log::withContext($routerContext)->warning('Failed to broadcast online status', ['error' => $e->getMessage()]);
                }
                
                Log::withContext($routerContext)->info('VPN connected - marked router ONLINE');
                
                // Then dispatch discovery for interfaces (non-blocking)
                $discoveryDispatchKey = "discovery_dispatch_{$router->id}";
                if (!Cache::has($discoveryDispatchKey)) {
                    Cache::put($discoveryDispatchKey, true, 120); // 2 minute deduplication
                    Log::withContext($routerContext)->info('Dispatching interface discovery job');
                    dispatch(new DiscoverRouterInterfacesJob($this->tenantId, $router->id))
                        ->onQueue('router-provisioning');
                } else {
                    Log::withContext($routerContext)->info('Discovery job already dispatched recently, skipping');
                }
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
            
            // Quick ping check (2 pings, 3 second timeout)
            $result = $connectivityService->verifyConnectivity($vpnConfig, 2, 3);

            if ($result['success'] && $result['packet_loss'] === 0) {
                // VPN is now reachable! Mark router as ONLINE immediately (no SSH required)
                Log::withContext($routerContext)->info('Pending router VPN now reachable - marking ONLINE', [
                    'latency_ms' => $result['latency'],
                ]);

                $vpnConfig->update([
                    'status' => 'connected',
                    'last_handshake_at' => now(),
                ]);

                // Mark router as online FIRST (based on VPN ping success alone)
                $router->update([
                    'status' => 'online',
                    'last_seen' => now(),
                    'last_checked' => now(),
                ]);

                // Broadcast status change
                CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);
                try {
                    broadcast(new RouterStatusUpdated([[
                        'id' => $router->id,
                        'name' => $router->name,
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'status' => 'online',
                        'last_seen' => $router->last_seen,
                        'tenant_id' => (string) $this->tenantId,
                    ]], (string) $this->tenantId))->toOthers();
                } catch (\Exception $e) {
                    Log::withContext($routerContext)->warning('Failed to broadcast online status', ['error' => $e->getMessage()]);
                }

                // THEN dispatch interface discovery job (non-blocking for online status)
                // Discovery will fetch interfaces via SSH for service configuration
                $discoveryDispatchKey = "discovery_dispatch_{$router->id}";
                if (!Cache::has($discoveryDispatchKey)) {
                    Cache::put($discoveryDispatchKey, true, 120); // 2 minute deduplication
                    dispatch(new DiscoverRouterInterfacesJob($this->tenantId, $router->id))
                        ->onQueue('router-provisioning');
                    Log::withContext($routerContext)->info('Dispatched interface discovery for online router');
                } else {
                    Log::withContext($routerContext)->info('Discovery job already dispatched recently, skipping duplicate');
                }
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
}
