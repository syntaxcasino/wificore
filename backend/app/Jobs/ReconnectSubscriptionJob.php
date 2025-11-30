<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\UserSubscription;
use App\Services\SubscriptionManager;
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

    public Payment $payment;
    public UserSubscription $subscription;

    /**
     * Create a new job instance.
     */
    public function __construct(Payment $payment, UserSubscription $subscription)
    {
        $this->payment = $payment;
        $this->subscription = $subscription;
        
        // High priority queue for reconnection
        $this->onQueue('subscription-reconnection');
    }

    /**
     * Execute the job.
     */
    public function handle(SubscriptionManager $subscriptionManager): void
    {
        try {
            $subscriptionManager->processPayment($this->subscription);
            
            Log::info('Subscription reconnection processed (async)', [
                'payment_id' => $this->payment->id,
                'subscription_id' => $this->subscription->id,
                'user_id' => $this->subscription->user_id,
                'job' => 'ReconnectSubscriptionJob',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to process subscription reconnection (async)', [
                'payment_id' => $this->payment->id,
                'subscription_id' => $this->subscription->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'job' => 'ReconnectSubscriptionJob',
            ]);
            
            // Retry the job
            $this->release(60);
        }
    }
}
