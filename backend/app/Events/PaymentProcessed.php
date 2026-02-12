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

class PaymentProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $paymentData;
    public array $userData;
    public array $subscriptionData;
    public array $credentials;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(
        Payment $payment,
        User $user,
        UserSubscription $subscription,
        array $credentials
    ) {
        // Extract data instead of serializing models
        // Payment and UserSubscription are tenant-scoped
        $this->paymentData = [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'phone_number' => $payment->phone_number,
            'transaction_id' => $payment->transaction_id,
            'package_name' => $payment->package?->name,
        ];
        $this->userData = [
            'id' => $user->id,
            'username' => $user->username,
            'phone_number' => $user->phone_number,
            'is_new' => $user->wasRecentlyCreated,
            'tenant_id' => $user->tenant_id,
        ];
        $this->subscriptionData = [
            'id' => $subscription->id,
            'start_time' => $subscription->start_time?->toIso8601String(),
            'end_time' => $subscription->end_time?->toIso8601String(),
            'status' => $subscription->status,
        ];
        $this->credentials = $credentials;
        $this->tenantId = $user->tenant_id;
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
                'id' => $this->paymentData['id'],
                'amount' => $this->paymentData['amount'],
                'phone_number' => $this->maskPhoneNumber($this->paymentData['phone_number'] ?? ''),
                'transaction_id' => substr($this->paymentData['transaction_id'] ?? '', 0, 8) . '...',
                'package' => $this->paymentData['package_name'],
            ],
            'user' => [
                'id' => $this->userData['id'],
                'username' => $this->userData['username'],
                'phone_number' => $this->maskPhoneNumber($this->userData['phone_number'] ?? ''),
                'is_new' => $this->userData['is_new'],
            ],
            'subscription' => $this->subscriptionData,
            // Credentials NOT broadcast for security
            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected function getTenantId(): string
    {
        return (string) $this->tenantId;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'payment.processed';
    }
}
