<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\User;
use App\Models\UserSubscription;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public $payment;
    public $user;
    public $subscription;
    public $credentials;

    /**
     * Create a new event instance.
     */
    public function __construct(
        Payment $payment,
        User $user,
        UserSubscription $subscription,
        array $credentials
    ) {
        $this->payment = $payment;
        $this->user = $user;
        $this->subscription = $subscription;
        $this->credentials = $credentials;
    }

    /**
     * Get the channels the event should broadcast on.
     * Tenant-specific channel
     */
    public function broadcastOn(): array
    {
        return [
            $this->getTenantChannel('admin-notifications'),
        ];
    }

    /**
     * Get the data to broadcast.
     * GDPR compliant - sensitive data masked
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'payment_processed',
            'payment' => [
                'id' => $this->payment->id,
                'amount' => $this->payment->amount,
                'phone_number' => $this->maskPhoneNumber($this->payment->phone_number),
                'transaction_id' => substr($this->payment->transaction_id, 0, 8) . '...',
                'package' => $this->payment->package->name,
            ],
            'user' => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'phone_number' => $this->maskPhoneNumber($this->user->phone_number ?? ''),
                'is_new' => $this->user->wasRecentlyCreated,
            ],
            'subscription' => [
                'id' => $this->subscription->id,
                'start_time' => $this->subscription->start_time->toIso8601String(),
                'end_time' => $this->subscription->end_time->toIso8601String(),
                'status' => $this->subscription->status,
            ],
            // Credentials NOT broadcast for security
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'payment.processed';
    }
}
