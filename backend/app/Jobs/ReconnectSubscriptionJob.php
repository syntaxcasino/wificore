<?php

namespace App\Jobs;

use App\Jobs\ReconnectUserJob;
use App\Models\Payment;
use App\Models\UserSubscription;
use App\Services\SubscriptionManager;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job to reconnect subscription after payment
 * Replaces synchronous processPayment() call in controller
 */
class ReconnectSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public array $backoff = [15, 60, 120];

    public $paymentId;
    public $subscriptionId;

    /**
     * Create a new job instance.
     */
    public function __construct($paymentId, $subscriptionId, $tenantId)
    {
        $this->paymentId = $paymentId;
        $this->subscriptionId = $subscriptionId;
        $this->setTenantContext($tenantId);

        // High priority queue for reconnection
        $this->onQueue('subscription-reconnection');
    }

    /**
     * Execute the job.
     */
    public function handle(SubscriptionManager $subscriptionManager): void
    {
        $this->executeInTenantContext(function () use ($subscriptionManager) {
            try {
                $subscription = UserSubscription::find($this->subscriptionId);

                if (!$subscription) {
                    Log::error('Subscription not found for reconnection', [
                        'subscription_id' => $this->subscriptionId,
                        'tenant_id' => $this->tenantId
                    ]);
                    return;
                }

                $shouldReconnect = $subscriptionManager->renewPayment($subscription);

                if ($shouldReconnect) {
                    ReconnectUserJob::dispatch($subscription->id, $this->tenantId)
                        ->onQueue('service-control')
                        ->afterCommit();
                }

                Log::info('Subscription reconnection processed (async)', [
                    'payment_id' => $this->paymentId,
                    'subscription_id' => $this->subscriptionId,
                    'user_id' => $subscription->user_id,
                    'should_reconnect' => $shouldReconnect,
                    'job' => 'ReconnectSubscriptionJob',
                    'tenant_id' => $this->tenantId
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to process subscription reconnection (async)', [
                    'payment_id' => $this->paymentId,
                    'subscription_id' => $this->subscriptionId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'job' => 'ReconnectSubscriptionJob',
                    'tenant_id' => $this->tenantId
                ]);

                throw $e;
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Log::critical('ReconnectSubscriptionJob permanently failed', [
            'payment_id' => $this->paymentId,
            'subscription_id' => $this->subscriptionId,
            'tenant_id' => $this->tenantId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
