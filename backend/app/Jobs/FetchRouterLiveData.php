<?php

namespace App\Jobs;

use App\Events\RouterLiveDataUpdated;
use App\Models\Router;
use App\Services\MikrotikProvisioningService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchRouterLiveData implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;
    public $maxExceptions = 3;
    public $backoff = [10, 30, 60];
    public $deleteWhenMissingModels = true;

    public function __construct(public array $routerIds = [])
    {
        $this->onQueue('router-data');
        
        Log::withContext(['job' => 'FetchRouterLiveData'])->info('Job initialized', [
            'router_ids' => $this->routerIds,
            'router_count' => count($this->routerIds),
        ]);
    }

    public function handle(MikrotikProvisioningService $routerService)
    {
        $startTime = microtime(true);
        
        Log::withContext([
            'job' => 'FetchRouterLiveData',
            'attempt' => $this->attempts(),
            'job_id' => $this->job->getJobId() ?? 'unknown'
        ])->info('Starting job execution');

        try {
            $routers = Router::whereIn('id', $this->routerIds)->get();

            Log::info('Retrieved routers from database', [
                'router_count' => $routers->count(),
                'router_ids' => $routers->pluck('id')->toArray()
            ]);

            if ($routers->isEmpty()) {
                Log::warning('No routers found for provided IDs');
                return;
            }

            $successfulRouters = [];
            $failedRouters = [];

            foreach ($routers->lazy() as $router) {
                Log::info('Processing router', [
                    'router_id' => $router->id,
                    'router_name' => $router->name
                ]);

                try {
                    $liveData = $routerService->fetchLiveRouterData($router);
                    
                    Log::debug('Fetched live data for router', [
                        'router_id' => $router->id,
                        'live_data' => $liveData
                    ]);

                    // Broadcast event
                    broadcast(new RouterLiveDataUpdated($router->id, $liveData));
                    
                    Log::info('Broadcasted update event', [
                        'router_id' => $router->id,
                        'data_size' => strlen(json_encode($liveData))
                    ]);

                    // FIXED: Remove tags() - use regular cache put
                    Cache::put(
                        "router_live_data_{$router->id}", 
                        $liveData, 
                        now()->addMinutes(5)
                    );

                    // Update router status
                    $this->updateRouterStatus($router, $liveData, 'online');
                    $successfulRouters[] = $router->id;
                    

                } catch (\Exception $e) {
                    Log::warning('Failed to fetch live data for router', [
                        'router_id' => $router->id,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e)
                    ]);

                    $this->cacheErrorState($router, $e);
                    $this->updateRouterStatus($router, [], 'offline');
                    $failedRouters[] = $router->id;
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Job completed successfully', [
                'successful_routers' => $successfulRouters,
                'failed_routers' => $failedRouters,
                'success_count' => count($successfulRouters),
                'failure_count' => count($failedRouters),
                'duration_seconds' => $duration
            ]);

        } catch (\Exception $e) {
            Log::error('Job execution failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'attempt' => $this->attempts()
            ]);

            if ($this->attempts() >= $this->tries) {
                $this->markAllRoutersOffline();
            }

            throw $e;
        }
    }

    protected function updateRouterStatus(Router $router, array $liveData, string $status): void
    {
        try {
            $router->update([
                'status' => $status,
                'model' => $liveData['board_name'] ?? $router->model,
                'os_version' => $liveData['version'] ?? $router->os_version,
                'last_seen' => $status === 'online' ? now() : $router->last_seen,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update router status', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function cacheErrorState(Router $router, \Exception $e): void
    {
        // FIXED: Remove tags() - use regular cache put
        Cache::put(
            "router_live_data_{$router->id}", 
            [
                'error' => 'Unable to connect to router: ' . $e->getMessage(),
                'status' => 'offline',
                'last_attempt' => now()->toISOString(),
                'exception' => get_class($e)
            ], 
            now()->addMinutes(5)
        );
    }

    protected function markAllRoutersOffline(): void
    {
        try {
            Router::whereIn('id', $this->routerIds)
                ->update([
                    'status' => 'offline',
                    'updated_at' => now()
                ]);

            Log::warning('Marked all routers as offline due to job failure');
        } catch (\Exception $e) {
            Log::critical('Failed to mark routers as offline', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::withContext(['job' => 'FetchRouterLiveData'])->critical('Job failed permanently', [
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
            'router_ids' => $this->routerIds,
            'max_retries_reached' => true
        ]);

        $this->markAllRoutersOffline();
    }
}