<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\ProvisioningServiceClient;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScheduleRouterPollingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public function __construct(string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('router-monitoring');
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

            return;
        }

        $this->executeInTenantContext(function () use ($provisioningClient) {
            try {
                $routers = Router::whereNotIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])
                    ->limit(1000)
                    ->get(['id', 'name', 'ip_address', 'vpn_ip', 'status', 'vpn_status', 'model', 'os_version']);

                if ($routers->isEmpty()) {
                    Log::debug('Skipping live data refresh because tenant has no operational routers', [
                        'tenant_id' => $this->tenantId,
                    ]);
                    return;
                }

                $payload = [
                    'monitoring' => [
                        'routers' => $routers->map(fn (Router $router) => [
                            'router_id' => (string) $router->id,
                            'router_name' => (string) $router->name,
                            'ip_address' => (string) $router->ip_address,
                            'vpn_ip' => (string) ($router->vpn_ip ?? ''),
                            'status' => (string) ($router->status ?? ''),
                            'vpn_status' => (string) ($router->vpn_status ?? ''),
                            'model' => (string) ($router->model ?? ''),
                            'os_version' => (string) ($router->os_version ?? ''),
                        ])->values()->all(),
                    ],
                ];

                $response = $provisioningClient->submitLiveDataRefreshCommand((string) $this->tenantId, $payload);

                if ($response['data']['skipped'] ?? false) {
                    Log::info('Skipped live data refresh - provisioning service busy', [
                        'tenant_id' => $this->tenantId,
                        'router_count' => $routers->count(),
                    ]);
                    return;
                }

                Log::debug('Submitted live data refresh command', [
                    'tenant_id' => $this->tenantId,
                    'router_count' => $routers->count(),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to schedule router polling', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }
}
