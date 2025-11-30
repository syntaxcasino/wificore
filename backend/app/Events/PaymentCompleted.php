<?php

namespace App\Events;

use App\Models\Payment;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public $payment;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    /**
     * Get the channels the event should broadcast on.
     * Tenant-specific channels
     */
    public function broadcastOn(): array
    {
        return $this->getTenantChannels([
            'dashboard-stats',
            'payments',
        ]);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'PaymentCompleted';
    }

    /**
     * Get the data to broadcast.
     * GDPR compliant - sensitive data masked
     */
    public function broadcastWith(): array
    {
        return [
            'payment' => [
                'id' => $this->payment->id,
                'amount' => $this->payment->amount,
                'phone_number' => $this->maskPhoneNumber($this->payment->phone_number),
                'package_id' => $this->payment->package_id,
                'status' => $this->payment->status,
            ],
            'message' => 'Payment completed successfully',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
