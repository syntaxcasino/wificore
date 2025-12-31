<?php

namespace App\Jobs;

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
        $this->executeInTenantContext(function() use ($subscriptionManager) {
            try {
                $subscription = UserSubscription::find($this->subscriptionId);
                
                if (!$subscription) {
                    Log::error('Subscription not found for reconnection', [
                        'subscription_id' => $this->subscriptionId,
                        'tenant_id' => $this->tenantId
                    ]);
                    return;
                }

                $subscriptionManager->processPayment($subscription);
                
                Log::info('Subscription reconnection processed (async)', [
                    'payment_id' => $this->paymentId,
                    'subscription_id' => $this->subscriptionId,
                    'user_id' => $subscription->user_id,
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
                
                // Retry the job
                $this->release(60);
            }
        });
    }
}
