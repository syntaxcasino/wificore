<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use App\Services\RADIUSServiceController;
use App\Notifications\ServiceReconnectedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconnectUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60];

    protected UserSubscription $subscription;

    /**
     * Create a new job instance.
     */
    public function __construct(UserSubscription $subscription)
    {
        $this->subscription = $subscription;
        $this->onQueue('service-control'); // High priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(RADIUSServiceController $radiusController): void
    {
        Log::info('ReconnectUserJob: Starting', [
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->subscription->user_id,
            'attempt' => $this->attempts(),
        ]);

        try {
            $user = $this->subscription->user;

            if (!$user) {
                Log::warning('ReconnectUserJob: User not found', [
                    'subscription_id' => $this->subscription->id,
                ]);
                return;
            }

            // Reconnect user to RADIUS
            $success = $radiusController->reconnectUser($user);

            if ($success) {
                Log::info('ReconnectUserJob: User reconnected successfully', [
                    'subscription_id' => $this->subscription->id,
                    'user_id' => $user->id,
                    'username' => $user->email,
                ]);

                // Send reconnection notification
                $user->notify(new ServiceReconnectedNotification($this->subscription));
            } else {
                throw new \Exception('Failed to reconnect user to RADIUS');
            }

        } catch (\Exception $e) {
            Log::error('ReconnectUserJob: Failed', [
                'subscription_id' => $this->subscription->id,
                'user_id' => $this->subscription->user_id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                Log::critical('ReconnectUserJob: All retries exhausted', [
                    'subscription_id' => $this->subscription->id,
                    'user_id' => $this->subscription->user_id,
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('ReconnectUserJob: Job failed permanently', [
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->subscription->user_id,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Send alert to admin
        // TODO: Create manual intervention ticket
    }
}
