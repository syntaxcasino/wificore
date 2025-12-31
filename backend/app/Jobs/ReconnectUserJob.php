<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use App\Services\RADIUSServiceController;
use App\Notifications\ServiceReconnectedNotification;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconnectUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60];

    protected $subscriptionId;

    /**
     * Create a new job instance.
     */
    public function __construct($subscriptionId, $tenantId)
    {
        $this->subscriptionId = $subscriptionId;
        $this->setTenantContext($tenantId);
        $this->onQueue('service-control'); // High priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(RADIUSServiceController $radiusController): void
    {
        $this->executeInTenantContext(function() use ($radiusController) {
            Log::info('ReconnectUserJob: Starting', [
                'subscription_id' => $this->subscriptionId,
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
            ]);

            try {
                $subscription = UserSubscription::find($this->subscriptionId);

                if (!$subscription) {
                    Log::warning('ReconnectUserJob: Subscription not found', [
                        'subscription_id' => $this->subscriptionId,
                        'tenant_id' => $this->tenantId,
                    ]);
                    return;
                }

                $user = $subscription->user;

                if (!$user) {
                    Log::warning('ReconnectUserJob: User not found', [
                        'subscription_id' => $this->subscriptionId,
                    ]);
                    return;
                }

                // Reconnect user to RADIUS
                $success = $radiusController->reconnectUser($user);

                if ($success) {
                    Log::info('ReconnectUserJob: User reconnected successfully', [
                        'subscription_id' => $this->subscriptionId,
                        'user_id' => $user->id,
                        'username' => $user->email,
                    ]);

                    // Send reconnection notification
                    $user->notify(new ServiceReconnectedNotification($subscription));
                } else {
                    throw new \Exception('Failed to reconnect user to RADIUS');
                }

            } catch (\Exception $e) {
                Log::error('ReconnectUserJob: Failed', [
                    'subscription_id' => $this->subscriptionId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                if ($this->attempts() >= $this->tries) {
                    Log::critical('ReconnectUserJob: All retries exhausted', [
                        'subscription_id' => $this->subscriptionId,
                        'tenant_id' => $this->tenantId,
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('ReconnectUserJob: Job failed permanently', [
            'subscription_id' => $this->subscriptionId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Send alert to admin
        // TODO: Create manual intervention ticket
    }
}
