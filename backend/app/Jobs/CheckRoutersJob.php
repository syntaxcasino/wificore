<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnConfiguration;
use App\Services\ProvisioningServiceClient;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckRoutersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 120;

    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('router-checks');
    }

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        if (! $this->tenantId) {
            $tenants = Tenant::query()
                ->where('is_active', true)
                ->useWritePdo()
                ->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::debug('Dispatched router check jobs for ' . $tenants->count() . ' tenants');
            return;
        }

        $this->executeInTenantContext(function () use ($provisioningClient) {
            $context = [
                'job' => 'CheckRoutersJob',
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
                'job_id' => $this->job?->getJobId() ?? 'unknown',
            ];

            Log::withContext($context)->debug('Submitting router status refresh command for tenant');

            try {
                $routers = Router::query()
                    ->limit(1000)
                    ->get(['id', 'name', 'ip_address', 'vpn_ip', 'status', 'vpn_status', 'vpn_last_handshake', 'last_seen', 'last_checked', 'provisioning_stage', 'model', 'os_version']);

                if ($routers->isEmpty()) {
                    Log::withContext($context)->debug('Skipping router status refresh because tenant has no routers');
                    return;
                }

                $vpnConfigs = VpnConfiguration::query()
                    ->limit(1000)
                    ->get(['router_id', 'client_ip'])
                    ->keyBy('router_id');

                $payload = [
                    'monitoring' => [
                        'routers' => $routers->map(function (Router $router) use ($vpnConfigs) {
                            $vpnConfig = $vpnConfigs->get($router->id);

                            return [
                                'router_id' => (string) $router->id,
                                'router_name' => (string) $router->name,
                                'ip_address' => (string) $router->ip_address,
                                'vpn_ip' => (string) ($router->vpn_ip ?? ''),
                                'status' => (string) ($router->status ?? ''),
                                'vpn_status' => (string) ($router->vpn_status ?? ''),
                                'vpn_last_handshake' => optional($router->vpn_last_handshake)?->toIso8601String(),
                                'last_seen' => optional($router->last_seen)?->toIso8601String(),
                                'last_checked' => optional($router->last_checked)?->toIso8601String(),
                                'client_ip' => (string) ($vpnConfig?->client_ip ?? ''),
                                'provisioning_stage' => (string) ($router->provisioning_stage ?? ''),
                                'model' => (string) ($router->model ?? ''),
                                'os_version' => (string) ($router->os_version ?? ''),
                            ];
                        })->values()->all(),
                        'inactive_threshold' => (int) config('vpn.monitoring.inactive_threshold', 190),
                        'offline_grace_period' => (int) config('vpn.monitoring.offline_grace_period', 60),
                        'recent_metrics_window' => 120,
                    ],
                ];

                $provisioningClient->submitRouterStatusRefreshCommand((string) $this->tenantId, $payload);

                Log::withContext($context)->debug('Router status refresh command submitted', [
                    'router_count' => $routers->count(),
                ]);
            } catch (Throwable $e) {
                Log::withContext($context)->error('Router status refresh submission failed', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

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
