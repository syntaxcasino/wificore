<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use App\Services\RADIUSServiceController;
use App\Notifications\ServiceDisconnectedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DisconnectUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60]; // Retry after 10s, 30s, 60s

    protected UserSubscription $subscription;
    protected string $reason;

    /**
     * Create a new job instance.
     */
    public function __construct(UserSubscription $subscription, string $reason)
    {
        $this->subscription = $subscription;
        $this->reason = $reason;
        $this->onQueue('service-control'); // High priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(RADIUSServiceController $radiusController): void
    {
        Log::info('DisconnectUserJob: Starting', [
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->subscription->user_id,
            'reason' => $this->reason,
            'attempt' => $this->attempts(),
        ]);

        try {
            $user = $this->subscription->user;

            if (!$user) {
                Log::warning('DisconnectUserJob: User not found', [
                    'subscription_id' => $this->subscription->id,
                ]);
                return;
            }

            // Disconnect user from RADIUS
            $success = $radiusController->disconnectUser($user, $this->reason);

            if ($success) {
                Log::info('DisconnectUserJob: User disconnected successfully', [
                    'subscription_id' => $this->subscription->id,
                    'user_id' => $user->id,
                    'username' => $user->email,
                ]);

                // Send disconnection notification
                $user->notify(new ServiceDisconnectedNotification($this->subscription, $this->reason));
            } else {
                throw new \Exception('Failed to disconnect user from RADIUS');
            }

        } catch (\Exception $e) {
            Log::error('DisconnectUserJob: Failed', [
                'subscription_id' => $this->subscription->id,
                'user_id' => $this->subscription->user_id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // If we've exhausted all retries, mark as failed
            if ($this->attempts() >= $this->tries) {
                Log::critical('DisconnectUserJob: All retries exhausted', [
                    'subscription_id' => $this->subscription->id,
                    'user_id' => $this->subscription->user_id,
                ]);
            }

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('DisconnectUserJob: Job failed permanently', [
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->subscription->user_id,
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Send alert to admin
        // TODO: Create manual intervention ticket
    }
}
