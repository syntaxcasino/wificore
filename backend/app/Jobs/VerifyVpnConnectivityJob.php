<?php

namespace App\Jobs;

use App\Events\ProvisioningFailed;
use App\Events\RouterProvisioningProgress;
use App\Events\VpnConnectivityFailed;
use App\Models\Router;
use App\Models\VpnConfiguration;
use App\Services\ProvisioningServiceClient;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyVpnConnectivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;
    public int $timeout = 60;

    public int $vpnConfigId;
    public int $maxWaitSeconds;
    public int $retryInterval;
    public ?string $routerId = null;

    public function __construct(
        string $tenantId,
        int $vpnConfigId,
        int $maxWaitSeconds = 120,
        int $retryInterval = 5,
        ?string $routerId = null,
    ) {
        $this->tenantId = $tenantId;
        $this->vpnConfigId = $vpnConfigId;
        $this->maxWaitSeconds = $maxWaitSeconds;
        $this->retryInterval = $retryInterval;
        $this->routerId = $routerId;
    }

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        $this->executeInTenantContext(function () use ($provisioningClient) {
            Log::info('Submitting VPN connectivity verification command', [
                'tenant_id' => $this->tenantId,
                'vpn_config_id' => $this->vpnConfigId,
                'router_id' => $this->routerId,
                'max_wait_seconds' => $this->maxWaitSeconds,
            ]);

            $vpnConfig = VpnConfiguration::find($this->vpnConfigId);
            if (! $vpnConfig && $this->routerId) {
                $vpnConfig = VpnConfiguration::where('router_id', $this->routerId)
                    ->latest('id')
                    ->first();
            }

            $router = $vpnConfig
                ? Router::find($vpnConfig->router_id)
                : ($this->routerId ? Router::find($this->routerId) : null);

            if (! $vpnConfig) {
                $message = 'VPN configuration not found for connectivity verification';
                Log::error($message, [
                    'tenant_id' => $this->tenantId,
                    'vpn_config_id' => $this->vpnConfigId,
                    'router_id' => $this->routerId,
                ]);

                $this->markVerificationFailed($router, null, $message);
                return;
            }

            if (! $router) {
                $message = 'Router not found for VPN connectivity verification';
                Log::error($message, [
                    'tenant_id' => $this->tenantId,
                    'vpn_config_id' => $this->vpnConfigId,
                    'router_id' => $vpnConfig->router_id,
                ]);

                $this->markVerificationFailed(null, $vpnConfig, $message);
                return;
            }

            $payload = [
                'monitoring' => [
                    'routers' => [[
                        'router_id' => (string) $router->id,
                        'router_name' => (string) $router->name,
                        'status' => (string) ($router->status ?? ''),
                        'client_ip' => (string) ($vpnConfig->client_ip ?? ''),
                        'provisioning_stage' => (string) ($router->provisioning_stage ?? ''),
                        'vpn_config_id' => (int) $vpnConfig->id,
                    ]],
                    'max_wait_seconds' => $this->maxWaitSeconds,
                    'retry_interval' => $this->retryInterval,
                ],
            ];

            try {
                $provisioningClient->submitVpnConnectivityWaitCommand((string) $this->tenantId, $payload);
            } catch (Throwable $e) {
                $this->markVerificationFailed($router, $vpnConfig, 'VPN connectivity verification command submission failed: ' . $e->getMessage());
                throw $e;
            }
        });
    }

    private function markVerificationFailed(?Router $router, ?VpnConfiguration $vpnConfig, string $message): void
    {
        if ($vpnConfig) {
            $vpnConfig->update(['status' => 'disconnected']);
        }

        if ($router) {
            $router->update([
                'status' => 'failed',
                'provisioning_stage' => 'verify_connectivity_failed',
                'vpn_status' => 'inactive',
                'last_checked' => now(),
            ]);

            broadcast(new VpnConnectivityFailed(
                (string) $this->tenantId,
                (string) $router->id,
                (int) ($vpnConfig?->id ?? $this->vpnConfigId),
                (string) ($vpnConfig?->client_ip ?? $router->vpn_ip ?? ''),
                $message,
                0,
            ));

            broadcast(new RouterProvisioningProgress(
                (string) $router->id,
                'failed',
                40.0,
                $message,
                ['tenant_id' => $this->tenantId, 'vpn_config_id' => $vpnConfig?->id ?? $this->vpnConfigId]
            ));

            broadcast(new ProvisioningFailed(
                (string) $router->id,
                'failed',
                $message,
                ['tenant_id' => $this->tenantId, 'vpn_config_id' => $vpnConfig?->id ?? $this->vpnConfigId]
            ));
        }
    }
}
