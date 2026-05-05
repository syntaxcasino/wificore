<?php

namespace App\Jobs;

use App\Events\RouterInterfacesDiscovered;
use App\Events\VpnConnectivityChecking;
use App\Events\VpnConnectivityFailed;
use App\Events\VpnConnectivityVerified;
use App\Models\Router;
use App\Models\Scopes\TenantScope;
use App\Models\VpnConfiguration;
use App\Models\WireguardPeer;
use App\Services\RouterStatusCheckService;
use App\Services\VpnConnectivityService;
use App\Services\WireGuardService;
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
    public int $timeout = 660; // must exceed maxWaitSeconds (600) + overhead

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

    public function handle(
        VpnConnectivityService $connectivityService,
        WireGuardService $wireGuardService,
        RouterStatusCheckService $statusCheckService
    ): void
    {
        $this->executeInTenantContext(function () use ($connectivityService, $wireGuardService, $statusCheckService) {
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

            // Get router to determine which phase we are in
            $router = Router::find($vpnConfig->router_id);
            $phase = $statusCheckService->determinePhase($router ?? new Router());

            Log::info('VPN connectivity verification starting', [
                'tenant_id' => $this->tenantId,
                'router_id' => $vpnConfig->router_id,
                'phase' => $phase,
                'router_status' => $router?->status,
            ]);

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
                    'phase' => $phase,
                ]);

                // Use phase-appropriate checking method
                if ($phase === 'provisioning') {
                    // PROVISIONING PHASE: Use PING-ONLY check
                    // This is the only reliable method before firewall hardening
                    $statusResult = $statusCheckService->checkStatusProvisioning($router);
                    $isConnected = $statusResult['online'];
                    $method = 'ping';
                    $latency = $statusResult['latency_ms'] ?? 0;
                } else {
                    // OPERATIONAL PHASE: Use HANDSHAKE-ONLY check
                    // ICMP is blocked by firewall - only WireGuard is reliable
                    $statusResult = $statusCheckService->checkStatusOperational($router);
                    $isConnected = $statusResult['online'];
                    $method = 'handshake';
                    $latency = 0;

                    // Fallback to ping if no handshake record exists yet
                    // (e.g., router just came online, peer table not updated)
                    if (!$statusResult['has_handshake'] && $statusResult['online'] === false) {
                        $pingResult = $statusCheckService->pingOnlyCheck($vpnConfig, 1, 2);
                        if ($pingResult['success']) {
                            $isConnected = true;
                            $method = 'ping_fallback';
                            $latency = $pingResult['latency'];
                        }
                    }
                }

                if ($isConnected) {
                    // Success! Update status and broadcast success event
                    $vpnConfig->update([
                        'status' => 'connected',
                        'last_handshake_at' => now(),
                    ]);

                    if ($router) {
                        $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];
                        $inProvisioning = in_array($router->status, $provisioningStatuses, true);
                        $now = now();

                        if ($inProvisioning) {
                            $router->update([
                                'status' => $router->status === 'pending' ? 'provisioning' : $router->status,
                                'provisioning_stage' => $router->provisioning_stage ?? 'vpn_verified',
                                'last_seen' => $now,
                                'last_checked' => $now,
                            ]);
                        } else {
                            $router->update([
                                'status' => 'online',
                                'vpn_status' => 'active',
                                'last_seen' => $now,
                                'last_checked' => $now,
                                'vpn_last_handshake' => $now,
                            ]);

                            try {
                                $updated = WireguardPeer::withoutGlobalScope(TenantScope::class)
                                    ->where('router_id', $router->id)
                                    ->update(['last_handshake' => $now]);
                                Log::info('Updated wireguard_peers.last_handshake after ping success', [
                                    'router_id' => $router->id,
                                    'rows_updated' => $updated,
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Failed to update wireguard_peers.last_handshake', [
                                    'router_id' => $router->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }

                    Log::info('VPN connectivity verified successfully', [
                        'tenant_id' => $this->tenantId,
                        'router_id' => $vpnConfig->router_id,
                        'client_ip' => $vpnConfig->client_ip,
                        'method' => $method,
                        'phase' => $phase,
                        'attempt' => $attempt,
                        'elapsed_seconds' => time() - $startTime,
                        'latency_ms' => $latency,
                    ]);

                    broadcast(new VpnConnectivityVerified(
                        $this->tenantId,
                        $vpnConfig->router_id,
                        $this->vpnConfigId,
                        $vpnConfig->client_ip,
                        $latency,
                        $method === 'ping' ? 0 : null,
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
            
            \Illuminate\Support\Facades\Cache::put($discoveryDispatchKey, true, 30); // 30 second deduplication
            
            dispatch(new \App\Jobs\DiscoverRouterInterfacesJob(
                $this->tenantId,
                $router->id
            ))->onQueue('router-provisioning');
            
            Log::info('Interface discovery job dispatched', [
                'router_id' => $router->id,
                'tenant_id' => $this->tenantId,
            ]);
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
