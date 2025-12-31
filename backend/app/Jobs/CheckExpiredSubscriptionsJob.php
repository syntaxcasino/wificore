<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\SubscriptionManager;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckExpiredSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('payment-checks'); // Low priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(SubscriptionManager $subscriptionManager): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched check expired subscriptions jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() use ($subscriptionManager) {
            Log::info('CheckExpiredSubscriptionsJob: Starting', [
                'tenant_id' => $this->tenantId,
            ]);

            try {
                // Get expired subscriptions
                $expiredSubscriptions = $subscriptionManager->getExpiredSubscriptions();

                Log::info('CheckExpiredSubscriptionsJob: Found expired subscriptions', [
                    'count' => $expiredSubscriptions->count(),
                ]);

                foreach ($expiredSubscriptions as $subscription) {
                    try {
                        $subscriptionManager->handleExpiredSubscription($subscription);

                        Log::info('CheckExpiredSubscriptionsJob: Handled expired subscription', [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('CheckExpiredSubscriptionsJob: Failed to handle subscription', [
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue with next subscription
                    }
                }

                // Check grace period expirations
                $gracePeriodExpired = $subscriptionManager->getGracePeriodExpired();

                Log::info('CheckExpiredSubscriptionsJob: Found grace period expired', [
                    'count' => $gracePeriodExpired->count(),
                ]);

                foreach ($gracePeriodExpired as $subscription) {
                    try {
                        $subscriptionManager->disconnectUser(
                            $subscription,
                            'Grace period expired'
                        );

                        Log::info('CheckExpiredSubscriptionsJob: Disconnected after grace period', [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('CheckExpiredSubscriptionsJob: Failed to disconnect', [
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                Log::info('CheckExpiredSubscriptionsJob: Completed', [
                    'tenant_id' => $this->tenantId,
                    'expired_handled' => $expiredSubscriptions->count(),
                    'grace_period_handled' => $gracePeriodExpired->count(),
                ]);

            } catch (\Exception $e) {
                Log::error('CheckExpiredSubscriptionsJob: Job failed', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('CheckExpiredSubscriptionsJob: Job failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Send alert to tenant admin
    }
}
