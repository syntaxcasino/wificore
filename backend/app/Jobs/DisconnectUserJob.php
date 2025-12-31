<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use App\Services\RADIUSServiceController;
use App\Notifications\ServiceDisconnectedNotification;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DisconnectUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60]; // Retry after 10s, 30s, 60s

    protected $subscriptionId;
    protected $reason;

    /**
     * Create a new job instance.
     */
    public function __construct($subscriptionId, $tenantId, string $reason)
    {
        $this->subscriptionId = $subscriptionId;
        $this->setTenantContext($tenantId);
        $this->reason = $reason;
        $this->onQueue('service-control'); // High priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(RADIUSServiceController $radiusController): void
    {
        $this->executeInTenantContext(function() use ($radiusController) {
            Log::info('DisconnectUserJob: Starting', [
                'subscription_id' => $this->subscriptionId,
                'tenant_id' => $this->tenantId,
                'reason' => $this->reason,
                'attempt' => $this->attempts(),
            ]);

            try {
                $subscription = UserSubscription::find($this->subscriptionId);

                if (!$subscription) {
                    Log::warning('DisconnectUserJob: Subscription not found', [
                        'subscription_id' => $this->subscriptionId,
                        'tenant_id' => $this->tenantId,
                    ]);
                    return;
                }

                $user = $subscription->user;

                if (!$user) {
                    Log::warning('DisconnectUserJob: User not found', [
                        'subscription_id' => $this->subscriptionId,
                    ]);
                    return;
                }

                // Disconnect user from RADIUS
                $success = $radiusController->disconnectUser($user, $this->reason);

                if ($success) {
                    Log::info('DisconnectUserJob: User disconnected successfully', [
                        'subscription_id' => $this->subscriptionId,
                        'user_id' => $user->id,
                        'username' => $user->email,
                    ]);

                    // Send disconnection notification
                    $user->notify(new ServiceDisconnectedNotification($subscription, $this->reason));
                } else {
                    throw new \Exception('Failed to disconnect user from RADIUS');
                }

            } catch (\Exception $e) {
                Log::error('DisconnectUserJob: Failed', [
                    'subscription_id' => $this->subscriptionId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                // If we've exhausted all retries, mark as failed
                if ($this->attempts() >= $this->tries) {
                    Log::critical('DisconnectUserJob: All retries exhausted', [
                        'subscription_id' => $this->subscriptionId,
                        'tenant_id' => $this->tenantId,
                    ]);
                }

                throw $e; // Re-throw to trigger retry
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('DisconnectUserJob: Job failed permanently', [
            'subscription_id' => $this->subscriptionId,
            'tenant_id' => $this->tenantId,
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Send alert to admin
        // TODO: Create manual intervention ticket
    }
}
