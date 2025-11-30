<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use App\Services\SubscriptionManager;
use App\Notifications\GracePeriodWarningNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessGracePeriodJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('payment-checks');
    }

    /**
     * Execute the job.
     */
    public function handle(SubscriptionManager $subscriptionManager): void
    {
        Log::info('ProcessGracePeriodJob: Starting');

        try {
            // Get subscriptions in grace period
            $gracePeriodSubscriptions = UserSubscription::inGracePeriod()
                ->with(['user', 'package'])
                ->get();

            Log::info('ProcessGracePeriodJob: Found subscriptions in grace period', [
                'count' => $gracePeriodSubscriptions->count(),
            ]);

            foreach ($gracePeriodSubscriptions as $subscription) {
                try {
                    $daysRemaining = $subscription->getGracePeriodDaysRemaining();

                    // Send warnings at specific intervals
                    if (in_array($daysRemaining, [2, 1])) {
                        $this->sendGracePeriodWarning($subscription, $daysRemaining);
                    }

                    Log::info('ProcessGracePeriodJob: Processed grace period subscription', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'days_remaining' => $daysRemaining,
                    ]);

                } catch (\Exception $e) {
                    Log::error('ProcessGracePeriodJob: Failed to process subscription', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Get grace periods that have expired
            $expiredGracePeriods = $subscriptionManager->getGracePeriodExpired();

            Log::info('ProcessGracePeriodJob: Found expired grace periods', [
                'count' => $expiredGracePeriods->count(),
            ]);

            foreach ($expiredGracePeriods as $subscription) {
                try {
                    $subscriptionManager->disconnectUser(
                        $subscription,
                        'Grace period expired - no payment received'
                    );

                    Log::info('ProcessGracePeriodJob: Disconnected user after grace period', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                    ]);

                } catch (\Exception $e) {
                    Log::error('ProcessGracePeriodJob: Failed to disconnect user', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('ProcessGracePeriodJob: Completed', [
                'grace_period_processed' => $gracePeriodSubscriptions->count(),
                'expired_disconnected' => $expiredGracePeriods->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessGracePeriodJob: Job failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send grace period warning
     */
    protected function sendGracePeriodWarning($subscription, int $daysRemaining): void
    {
        $user = $subscription->user;

        // Send grace period warning notification
        $user->notify(new GracePeriodWarningNotification($subscription, $daysRemaining));

        Log::info('ProcessGracePeriodJob: Grace period warning sent', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'days_remaining' => $daysRemaining,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessGracePeriodJob: Job failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
