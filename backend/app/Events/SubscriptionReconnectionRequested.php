<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\UserSubscription;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event fired when a subscription needs to be reconnected after payment
 * This triggers async reconnection job
 */
class SubscriptionReconnectionRequested
{
    use Dispatchable, InteractsWithSockets;

    public string $paymentId;
    public string $subscriptionId;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment, UserSubscription $subscription)
    {
        $this->paymentId = (string) $payment->id;
        $this->subscriptionId = (string) $subscription->id;
    }
}
