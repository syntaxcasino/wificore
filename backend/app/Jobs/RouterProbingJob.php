<?php

namespace App\Jobs;

use App\Models\Router;
use App\Events\RouterConnected;
use App\Services\MikrotikProvisioningService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RouterProbingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $routerId;
    public $attempts;
    public $maxAttempts;
    public $checkInterval;

    /**
     * Create a new job instance.
     */
    public function __construct(string $routerId, string $tenantId, int $attempts = 0, int $maxAttempts = 60, int $checkInterval = 10)
    {
        $this->routerId = $routerId;
        $this->setTenantContext($tenantId);
        $this->attempts = $attempts;
        $this->maxAttempts = $maxAttempts;
        $this->checkInterval = $checkInterval;
    }

    /**
     * Execute the job.
     */
    public function handle(MikrotikProvisioningService $provisioningService): void
    {
        $this->executeInTenantContext(function() use ($provisioningService) {
            $router = Router::find($this->routerId);

            if (!$router) {
                Log::warning('Router not found for probing', [
                    'router_id' => $this->routerId,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }

            // Skip if router is already connected or active
            if (in_array($router->status, ['connected', 'active', 'provisioning', 'online'])) {
                Log::info('Router already connected, stopping probing', [
                    'router_id' => $router->id,
                    'status' => $router->status,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }

            Log::info('Probing router connectivity', [
                'attempt' => $this->attempts + 1,
                'max_attempts' => $this->maxAttempts,
                'tenant_id' => $this->tenantId,
            ]);

            try {
                // Verify router connectivity
                $result = $provisioningService->verifyConnectivity($router);

                if ($result['status'] === 'connected' || $result['status'] === 'online') {
                    // Router is connected! Update status and broadcast event
                    $router->update([
                        'status' => 'online',
                        'model' => $result['model'],
                        'os_version' => $result['os_version'],
                        'last_seen' => now(),
                    ]);

                    Log::info('Router connected successfully during probing', [
                        'router_id' => $router->id,
                        'model' => $result['model'],
                        'os_version' => $result['os_version'],
                        'attempts' => $this->attempts + 1,
                        'tenant_id' => $this->tenantId,
                    ]);

                    // Broadcast router connected event
                    broadcast(new RouterConnected($router))->toOthers();

                    return; // Stop probing - router is connected
                }

            } catch (\Exception $e) {
                Log::debug('Router probing failed', [
                    'router_id' => $router->id,
                    'attempt' => $this->attempts + 1,
                    'error' => $e->getMessage(),
                    'tenant_id' => $this->tenantId,
                ]);
            }

            // Router not connected yet, check if we should continue probing
            if ($this->attempts < $this->maxAttempts) {
                // Schedule next probe
                self::dispatch($this->routerId, $this->tenantId, $this->attempts + 1, $this->maxAttempts, $this->checkInterval)
                    ->delay(now()->addSeconds($this->checkInterval))
                    ->onQueue('router-monitoring');

                Log::debug('Router not connected, scheduling next probe', [
                    'router_id' => $router->id,
                    'next_attempt' => $this->attempts + 2,
                    'delay_seconds' => $this->checkInterval,
                    'tenant_id' => $this->tenantId,
                ]);
            } else {
                // Max attempts reached, mark as failed
                $router->update(['status' => 'connection_failed']);

                Log::warning('Router probing failed after max attempts', [
                    'router_id' => $router->id,
                    'max_attempts' => $this->maxAttempts,
                    'total_time_minutes' => ($this->maxAttempts * $this->checkInterval) / 60,
                    'tenant_id' => $this->tenantId,
                ]);
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Router probing job failed permanently', [
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);

        // Mark router as probing failed
        // We can't easily execute in tenant context here without duplicating logic, 
        // but we can try if we are still in context (unlikely in failed())
        // Since we can't reliably switch context here without re-initializing, 
        // we'll skip DB update or do it via a fresh job/command if critical.
        // For now, just log.
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'router-probing',
            'router:' . $this->routerId,
            'tenant:' . $this->tenantId,
            'attempt:' . $this->attempts,
        ];
    }
}
