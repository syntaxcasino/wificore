<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\TenantVpnTunnel;
use App\Models\VpnConfiguration;
use App\Services\ProvisioningServiceClient;
use App\Traits\TenantAwareJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateVpnStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [5, 15, 30];

    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('router-checks');
    }

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        if (! $this->tenantId) {
            $tenants = Tenant::active()
                ->useWritePdo()
                ->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::info('Dispatched VPN status update jobs for ' . $tenants->count() . ' tenants');
            return;
        }

        $this->executeInTenantContext(function () use ($provisioningClient) {
            Log::info('Submitting WireGuard peer health refresh command', ['tenant_id' => $this->tenantId]);

            $tunnels = TenantVpnTunnel::query()
                ->get(['interface_name']);

            $peerMappings = VpnConfiguration::query()
                ->with(['router:id,name,ip_address,vpn_ip,model,os_version,status'])
                ->get()
                ->filter(fn (VpnConfiguration $config) => $config->router !== null && ! empty($config->client_public_key))
                ->map(function (VpnConfiguration $config) {
                    $router = $config->router;

                    return [
                        'public_key' => (string) $config->client_public_key,
                        'router_id' => (string) $router->id,
                        'router_name' => (string) $router->name,
                        'ip_address' => (string) $router->ip_address,
                        'vpn_ip' => (string) $router->vpn_ip,
                        'model' => (string) ($router->model ?? ''),
                        'os_version' => (string) ($router->os_version ?? ''),
                        'vpn_config_status' => (string) ($config->status ?? ''),
                        'previous_router_status' => (string) ($router->status ?? ''),
                    ];
                })
                ->values()
                ->all();

            if ($tunnels->isEmpty() || $peerMappings === []) {
                Log::info('Skipping VPN status refresh command because tenant has no WireGuard monitoring payload', [
                    'tenant_id' => $this->tenantId,
                    'tunnel_count' => $tunnels->count(),
                    'peer_mapping_count' => count($peerMappings),
                ]);
                return;
            }

            $payload = [
                'monitoring' => [
                    'tunnels' => $tunnels->map(fn (TenantVpnTunnel $tunnel) => [
                        'interface_name' => (string) $tunnel->interface_name,
                    ])->values()->all(),
                    'peer_mappings' => $peerMappings,
                    'inactive_threshold' => (int) config('vpn.monitoring.inactive_threshold', 190),
                ],
            ];

            try {
                $provisioningClient->submitVpnStatusRefreshCommand((string) $this->tenantId, $payload);

                Log::info('WireGuard peer health refresh command submitted', [
                    'tenant_id' => $this->tenantId,
                    'tunnel_count' => $tunnels->count(),
                    'peer_mapping_count' => count($peerMappings),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to submit WireGuard peer health refresh command', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    public function middleware(): array
    {
        $key = $this->tenantId ? 'update-vpn-status:tenant:' . $this->tenantId : 'update-vpn-status:dispatcher';

        return [
            (new WithoutOverlapping($key))
                ->expireAfter(120)
                ->releaseAfter(5),
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UpdateVpnStatusJob failed', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
