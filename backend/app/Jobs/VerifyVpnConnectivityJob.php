<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\VpnConfiguration;
use App\Services\ProvisioningServiceClient;
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
    public int $timeout = 60;

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

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        $this->executeInTenantContext(function () use ($provisioningClient) {
            Log::info('Submitting VPN connectivity verification command', [
                'tenant_id' => $this->tenantId,
                'vpn_config_id' => $this->vpnConfigId,
                'max_wait_seconds' => $this->maxWaitSeconds,
            ]);

            $vpnConfig = VpnConfiguration::find($this->vpnConfigId);
            if (! $vpnConfig) {
                Log::error('VPN configuration not found', [
                    'vpn_config_id' => $this->vpnConfigId,
                ]);
                return;
            }

            $router = Router::find($vpnConfig->router_id);
            if (! $router) {
                Log::error('Router not found for VPN connectivity verification', [
                    'vpn_config_id' => $this->vpnConfigId,
                    'router_id' => $vpnConfig->router_id,
                ]);
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

            $provisioningClient->submitVpnConnectivityWaitCommand((string) $this->tenantId, $payload);
        });
    }
}
