<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Models\Router;
use App\Services\MikrotikProvisioningService;
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

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('router-checks'); // Assign to specific queue
    }

    /**
     * Execute the job.
     */
    public function handle(MikrotikProvisioningService $service): void
    {
        $context = [
            'job' => 'CheckRoutersJob',
            'attempt' => $this->attempts(),
            'job_id' => $this->job?->getJobId() ?? 'unknown',
        ];

        Log::withContext($context)->info('Starting router status check job');

        try {
            $routers = $service->getAllRouters();
            $updatedStatuses = [];

            foreach ($routers as $router) {
                try {
                    $connectivityData = $service->verifyConnectivity($router);
                    $status = $connectivityData['status'] === 'connected' ? 'online' : 'offline';

                    $router->update([
                        'status' => $status,
                        'last_checked' => now(),
                        'model' => $connectivityData['model'] ?? $router->model,
                        'os_version' => $connectivityData['os_version'] ?? $router->os_version,
                        'last_seen' => $connectivityData['last_seen'] ?? $router->last_seen,
                    ]);

                    $updatedStatuses[] = [
                        'id' => $router->id,
                        'ip_address' => $router->ip_address,
                        'name' => $router->name,
                        'status' => $status,
                        'last_checked' => $router->last_checked,
                        'model' => $router->model,
                        'os_version' => $router->os_version,
                        'last_seen' => $router->last_seen,
                    ];

                    Log::withContext(array_merge($context, [
                        'router_id' => $router->id,
                        'ip_address' => $router->ip_address,
                        'name' => $router->name,
                    ]))->info('Router status updated', [
                        'status' => $status,
                        'model' => $router->model,
                        'os_version' => $router->os_version,
                    ]);

                } catch (Throwable $e) {
                    // Mark as offline on failure
                    $router->update([
                        'status' => 'offline',
                        'last_checked' => now(),
                    ]);

                    $updatedStatuses[] = [
                        'id' => $router->id,
                        'ip_address' => $router->ip_address,
                        'name' => $router->name,
                        'status' => 'offline',
                        'last_checked' => $router->last_checked,
                    ];

                    Log::withContext(array_merge($context, [
                        'router_id' => $router->id,
                        'ip_address' => $router->ip_address,
                        'name' => $router->name,
                    ]))->error('Failed to verify router connectivity', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Broadcast updates to frontend (e.g. Livewire or Vue via Soketi)
            if (!empty($updatedStatuses)) {
                try {
                    broadcast(new RouterStatusUpdated($updatedStatuses))->toOthers();

                    Log::withContext($context)->info('Broadcasted RouterStatusUpdated event', [
                        'router_count' => count($updatedStatuses),
                    ]);
                } catch (\Exception $e) {
                    Log::withContext($context)->warning('Failed to broadcast RouterStatusUpdated event', [
                        'error' => $e->getMessage(),
                        'router_count' => count($updatedStatuses),
                    ]);
                    // Don't fail the job if broadcasting fails
                }
            } else {
                Log::withContext($context)->warning('No router statuses updated to broadcast');
            }

        } catch (Throwable $e) {
            Log::withContext($context)->error('Router check job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Let Laravel handle retries
        }

        Log::withContext($context)->info('Completed router status check job');
    }

    /**
     * Handle job failure after all attempts.
     */
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
