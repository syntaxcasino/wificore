<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\UserSubscription;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a subscription needs to be reconnected after payment
 * This triggers async reconnection job
 */
class SubscriptionReconnectionRequested
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Payment $payment;
    public UserSubscription $subscription;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment, UserSubscription $subscription)
    {
        $this->payment = $payment;
        $this->subscription = $subscription;
    }
}
