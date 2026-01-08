<?php

namespace App\Jobs;

use App\Events\RouterInterfacesDiscovered;
use App\Events\VpnConnectivityChecking;
use App\Events\VpnConnectivityFailed;
use App\Events\VpnConnectivityVerified;
use App\Models\Router;
use App\Models\VpnConfiguration;
use App\Services\VpnConnectivityService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class VerifyVpnConnectivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;
    public int $timeout = 300; // 5 minutes max

    public int $vpnConfigId;
    public int $maxWaitSeconds;
    public int $retryInterval;

    public function __construct(
        string $tenantId,
        int $vpnConfigId,
        int $maxWaitSeconds = 120,
        int $retryInterval = 5
    ) {
        $this->tenantId = $tenantId;
        $this->vpnConfigId = $vpnConfigId;
        $this->maxWaitSeconds = $maxWaitSeconds;
        $this->retryInterval = $retryInterval;
    }

    public function handle(VpnConnectivityService $connectivityService): void
    {
        $this->executeInTenantContext(function () use ($connectivityService) {
            Log::info('Starting VPN connectivity verification job', [
                'tenant_id' => $this->tenantId,
                'vpn_config_id' => $this->vpnConfigId,
                'max_wait_seconds' => $this->maxWaitSeconds,
            ]);

            // Get VPN configuration
            $vpnConfig = VpnConfiguration::find($this->vpnConfigId);
            
            if (!$vpnConfig) {
                Log::error('VPN configuration not found', [
                    'vpn_config_id' => $this->vpnConfigId,
                ]);
                return;
            }

            $startTime = time();
            $attempt = 0;
            $maxAttempts = (int) ceil($this->maxWaitSeconds / $this->retryInterval);

            while ((time() - $startTime) < $this->maxWaitSeconds) {
                $attempt++;

                // Broadcast checking event
                broadcast(new VpnConnectivityChecking(
                    $this->tenantId,
                    $vpnConfig->router_id,
                    $this->vpnConfigId,
                    $vpnConfig->client_ip,
                    $attempt,
                    $maxAttempts
                ));

                Log::debug('VPN connectivity check attempt', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'client_ip' => $vpnConfig->client_ip,
                ]);

                // Perform connectivity check (quick: 2 pings, 3s timeout)
                $result = $connectivityService->verifyConnectivity($vpnConfig, 2, 3);

                if ($result['success'] && $result['packet_loss'] === 0) {
                    // Success! Update status and broadcast success event
                    $vpnConfig->update([
                        'status' => 'connected',
                        'last_handshake_at' => now(),
                    ]);

                    Log::info('VPN connectivity verified successfully', [
                        'tenant_id' => $this->tenantId,
                        'router_id' => $vpnConfig->router_id,
                        'client_ip' => $vpnConfig->client_ip,
                        'attempt' => $attempt,
                        'elapsed_seconds' => time() - $startTime,
                        'latency_ms' => $result['latency'],
                    ]);

                    broadcast(new VpnConnectivityVerified(
                        $this->tenantId,
                        $vpnConfig->router_id,
                        $this->vpnConfigId,
                        $vpnConfig->client_ip,
                        $result['latency'] ?? 0.0,
                        $result['packet_loss'],
                        $attempt
                    ));

                    // Auto-discover router interfaces after VPN is verified
                    $this->discoverRouterInterfaces($vpnConfig);

                    return;
                }

                // Not connected yet, wait before retry
                if ((time() - $startTime) < $this->maxWaitSeconds) {
                    sleep($this->retryInterval);
                }
            }

            // Timeout reached - broadcast failure event
            $vpnConfig->update([
                'status' => 'disconnected',
            ]);

            Log::warning('VPN connectivity verification timeout', [
                'tenant_id' => $this->tenantId,
                'router_id' => $vpnConfig->router_id,
                'client_ip' => $vpnConfig->client_ip,
                'elapsed_seconds' => time() - $startTime,
                'attempts' => $attempt,
            ]);

            broadcast(new VpnConnectivityFailed(
                $this->tenantId,
                $vpnConfig->router_id,
                $this->vpnConfigId,
                $vpnConfig->client_ip,
                'VPN connectivity timeout - router did not respond within ' . $this->maxWaitSeconds . ' seconds',
                $attempt
            ));
        });
    }

    /**
     * Discover router interfaces after VPN connectivity is verified
     */
    private function discoverRouterInterfaces($vpnConfig): void
    {
        try {
            $router = Router::find($vpnConfig->router_id);
            if (!$router) {
                Log::warning('Router not found for interface discovery', [
                    'router_id' => $vpnConfig->router_id,
                ]);
                return;
            }

            Log::info('Starting interface discovery after VPN verification', [
                'router_id' => $router->id,
                'vpn_ip' => $vpnConfig->client_ip,
            ]);

            // Dispatch interface discovery as a separate job (with deduplication)
            $discoveryDispatchKey = "discovery_dispatch_{$router->id}";
            if (\Illuminate\Support\Facades\Cache::has($discoveryDispatchKey)) {
                Log::info('Discovery job already dispatched recently, skipping duplicate', [
                    'router_id' => $router->id,
                ]);
                return;
            }
            
            \Illuminate\Support\Facades\Cache::put($discoveryDispatchKey, true, 120); // 2 minute deduplication
            
            dispatch(new \App\Jobs\DiscoverRouterInterfacesJob(
                $this->tenantId,
                $router->id
            ))->onQueue('router-provisioning');
            
            Log::info('Interface discovery job dispatched', [
                'router_id' => $router->id,
                'tenant_id' => $this->tenantId,
            ]);
            
            return;
            
            // Fetch live data which includes interfaces
            $liveData = $provisioningService->fetchLiveRouterData($router);
            
            if (isset($liveData['interfaces']) && is_array($liveData['interfaces'])) {
                // Update router status
                $router->update([
                    'status' => 'online',
                    'model' => $liveData['board_name'] ?? $router->model,
                    'os_version' => $liveData['version'] ?? $router->os_version,
                    'last_seen' => now(),
                ]);

                // Broadcast interfaces discovered event
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

                Log::info('Router interfaces discovered and broadcasted', [
                    'router_id' => $router->id,
                    'interface_count' => count($liveData['interfaces']),
                    'tenant_id' => $this->tenantId,
                ]);
            } else {
                Log::warning('No interfaces found in live data', [
                    'router_id' => $router->id,
                    'live_data_keys' => array_keys($liveData ?? []),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to discover router interfaces', [
                'router_id' => $vpnConfig->router_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('VPN connectivity verification job failed', [
            'tenant_id' => $this->tenantId,
            'vpn_config_id' => $this->vpnConfigId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Try to broadcast failure event
        try {
            $this->executeInTenantContext(function () use ($exception) {
                $vpnConfig = VpnConfiguration::find($this->vpnConfigId);
                if ($vpnConfig) {
                    broadcast(new VpnConnectivityFailed(
                        $this->tenantId,
                        $vpnConfig->router_id,
                        $this->vpnConfigId,
                        $vpnConfig->client_ip,
                        'Job failed: ' . $exception->getMessage(),
                        0
                    ));
                }
            });
        } catch (\Exception $e) {
            Log::error('Failed to broadcast VPN connectivity failure event', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
