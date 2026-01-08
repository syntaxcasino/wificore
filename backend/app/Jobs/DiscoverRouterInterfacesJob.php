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
    public $timeout = 120;
    public $backoff = 10;

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

            // Skip if router is already online (another job completed discovery)
            if ($router->status === 'online') {
                Log::info('Router already online, skipping discovery', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }

            // Deduplication: prevent concurrent discovery jobs for same router
            $discoveryLockKey = "router_discovery_lock_{$router->id}";
            $lock = \Illuminate\Support\Facades\Cache::lock($discoveryLockKey, 120); // 2 minute lock
            
            if (!$lock->get()) {
                Log::info('Discovery already in progress for router, skipping', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }

            Log::info('Discovering router interfaces', [
                'router_id' => $router->id,
                'tenant_id' => $this->tenantId,
            ]);

            try {
                // Add a small delay to ensure router is fully ready
                sleep(2);
                
                // Use context-aware method: provisioning context + filter for configurable interfaces only
                $liveData = $provisioningService->fetchLiveRouterData($router, 'provisioning', true);
                
                if (isset($liveData['interfaces']) && is_array($liveData['interfaces'])) {
                    // Update router to 'online' status - provisioning complete
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

                    Log::info('Router provisioning completed successfully', [
                        'router_id' => $router->id,
                        'router_name' => $router->name,
                        'interface_count' => count($liveData['interfaces']),
                        'tenant_id' => $this->tenantId,
                        'status' => 'online',
                        'message' => 'Router is now online and ready for service configuration',
                    ]);
                } else {
                    Log::warning('No interfaces found in live data', [
                        'router_id' => $router->id,
                        'live_data_keys' => array_keys($liveData ?? []),
                    ]);
                }
                
                // Release lock on success
                $lock->release();
            } catch (\RouterOS\Exceptions\StreamException $e) {
                // Release lock before retry
                $lock->release();
                
                Log::warning('Router API stream timeout during interface discovery - will retry', [
                    'router_id' => $router->id,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);
                
                // Release the job back to queue for retry
                $this->release(30);
            } catch (\Exception $e) {
                // Release lock on error
                $lock->release();
                
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
