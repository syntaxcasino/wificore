<?php

namespace App\Jobs;

use App\Events\VpnConnectivityChecking;
use App\Events\VpnConnectivityFailed;
use App\Events\VpnConnectivityVerified;
use App\Models\VpnConfiguration;
use App\Services\VpnConnectivityService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyVpnConnectivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public int $tries = 1;
    public int $timeout = 300; // 5 minutes max

    public int $vpnConfigId;
    public int $maxWaitSeconds;
    public int $retryInterval;

    public function __construct(
        int $tenantId,
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
