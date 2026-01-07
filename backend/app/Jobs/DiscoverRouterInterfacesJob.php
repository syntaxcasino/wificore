<?php

namespace App\Jobs;

use App\Events\RouterInterfacesDiscovered;
use App\Models\Router;
use App\Services\MikrotikProvisioningService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverRouterInterfacesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;

    public string $routerId;

    public function __construct(string $tenantId, string $routerId)
    {
        $this->tenantId = $tenantId;
        $this->routerId = $routerId;
    }

    public function handle(MikrotikProvisioningService $provisioningService): void
    {
        $this->executeInTenantContext(function () use ($provisioningService) {
            $router = Router::find($this->routerId);
            
            if (!$router) {
                Log::warning('Router not found for interface discovery', [
                    'router_id' => $this->routerId,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }

            Log::info('Discovering router interfaces', [
                'router_id' => $router->id,
                'tenant_id' => $this->tenantId,
            ]);

            try {
                $liveData = $provisioningService->fetchLiveRouterData($router);
                
                if (isset($liveData['interfaces']) && is_array($liveData['interfaces'])) {
                    $router->update([
                        'status' => 'online',
                        'model' => $liveData['board_name'] ?? $router->model,
                        'os_version' => $liveData['version'] ?? $router->os_version,
                        'last_seen' => now(),
                    ]);

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
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Router interface discovery job failed', [
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
