<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Jobs\DiscoverRouterInterfacesJob;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnConfiguration;
use App\Services\MikrotikProvisioningService;
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
     */
    public function handle(MikrotikProvisioningService $service): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched router check jobs for " . $tenants->count() . " tenants");
            return;
        }

        // Execute checking logic within tenant context
        $this->executeInTenantContext(function() use ($service) {
            $context = [
                'job' => 'CheckRoutersJob',
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
                'job_id' => $this->job?->getJobId() ?? 'unknown',
            ];

            Log::withContext($context)->info('Starting router status check job for tenant');

            try {
                $routers = $service->getAllRouters();
                $updatedStatuses = [];

                foreach ($routers as $router) {
                    // For pending routers, check if VPN is now reachable and trigger discovery
                    if (in_array($router->status, ['pending', 'deploying', 'provisioning', 'verifying'])) {
                        $this->checkPendingRouterVpn($router, $context);
                        continue;
                    }

                    // Check if router is currently locked by another operation
                    $lockKey = "router_api_lock_{$router->id}";
                    if (Cache::has($lockKey)) {
                        Log::withContext(array_merge($context, [
                            'router_id' => $router->id,
                            'ip_address' => $router->ip_address,
                            'name' => $router->name,
                        ]))->info('Skipping router health check - router is busy with another operation');
                        continue;
                    }

                    try {
                        $connectivityData = $service->verifyConnectivity($router);
                        $status = $connectivityData['status'] === 'connected' ? 'online' : 'offline';

                        $router->update([
                            'status' => $status,
                            'last_checked' => now(),
                            'model' => $connectivityData['model'] ?? $router->model,
                            'os_version' => $connectivityData['os_version'] ?? $router->os_version,
                            'last_seen' => $connectivityData['last_seen'] ?? $router->last_seen,
                        ]);

                        $updatedStatuses[] = [
                            'id' => $router->id,
                            'ip_address' => $router->ip_address,
                            'name' => $router->name,
                            'status' => $status,
                            'last_checked' => $router->last_checked,
                            'model' => $router->model,
                            'os_version' => $router->os_version,
                            'last_seen' => $router->last_seen,
                        ];

                        Log::withContext(array_merge($context, [
                            'router_id' => $router->id,
                            'ip_address' => $router->ip_address,
                            'name' => $router->name,
                        ]))->info('Router status updated', [
                            'status' => $status,
                            'model' => $router->model,
                            'os_version' => $router->os_version,
                        ]);

                    } catch (Throwable $e) {
                        // Don't mark as offline if router is busy (503 error)
                        if ($e->getCode() === 503 || str_contains($e->getMessage(), 'busy')) {
                            Log::withContext(array_merge($context, [
                                'router_id' => $router->id,
                                'ip_address' => $router->ip_address,
                                'name' => $router->name,
                            ]))->info('Router is busy with another operation, skipping health check');
                            continue;
                        }

                        // Mark as offline on failure
                        $router->update([
                            'status' => 'offline',
                            'last_checked' => now(),
                        ]);

                        $updatedStatuses[] = [
                            'id' => $router->id,
                            'ip_address' => $router->ip_address,
                            'name' => $router->name,
                            'status' => 'offline',
                            'last_checked' => $router->last_checked,
                        ];

                        Log::withContext(array_merge($context, [
                            'router_id' => $router->id,
                            'ip_address' => $router->ip_address,
                            'name' => $router->name,
                        ]))->error('Failed to verify router connectivity', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Broadcast updates to frontend (e.g. Livewire or Vue via Soketi)
                // Use tenant channel
                if (!empty($updatedStatuses)) {
                    try {
                        // TODO: Ensure RouterStatusUpdated supports tenant broadcasting
                        broadcast(new RouterStatusUpdated($updatedStatuses))->toOthers();

                        Log::withContext($context)->info('Broadcasted RouterStatusUpdated event', [
                            'router_count' => count($updatedStatuses),
                        ]);
                    } catch (\Exception $e) {
                        Log::withContext($context)->warning('Failed to broadcast RouterStatusUpdated event', [
                            'error' => $e->getMessage(),
                            'router_count' => count($updatedStatuses),
                        ]);
                        // Don't fail the job if broadcasting fails
                    }
                } else {
                    Log::withContext($context)->warning('No router statuses updated to broadcast');
                }

            } catch (Throwable $e) {
                Log::withContext($context)->error('Router check job failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e; // Let Laravel handle retries
            }

            Log::withContext($context)->info('Completed router status check job');
        });
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
            Log::withContext($routerContext)->info('VPN already connected, checking if discovery job needed');
            
            // If VPN is connected but router is still pending, dispatch discovery
            if ($router->status === 'pending') {
                Log::withContext($routerContext)->info('VPN connected but router pending - dispatching discovery job');
                dispatch(new DiscoverRouterInterfacesJob($this->tenantId, $router->id))
                    ->onQueue('router-provisioning');
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
                // VPN is now reachable! Update status and dispatch discovery
                Log::withContext($routerContext)->info('Pending router VPN now reachable!', [
                    'latency_ms' => $result['latency'],
                ]);

                $vpnConfig->update([
                    'status' => 'connected',
                    'last_handshake_at' => now(),
                ]);

                // Dispatch interface discovery job
                dispatch(new DiscoverRouterInterfacesJob($this->tenantId, $router->id))
                    ->onQueue('router-provisioning');

                Log::withContext($routerContext)->info('Dispatched interface discovery for newly connected router');
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
