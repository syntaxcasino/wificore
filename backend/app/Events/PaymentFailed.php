<?php

namespace App\Events;

use App\Models\Payment;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public $payment;
    public $error;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment, string $error)
    {
        $this->payment = $payment;
        $this->error = $error;
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
            'type' => 'payment_failed',
            'payment' => [
                'id' => $this->payment->id,
                'amount' => $this->payment->amount,
                'phone_number' => $this->maskPhoneNumber($this->payment->phone_number),
                'transaction_id' => substr($this->payment->transaction_id, 0, 8) . '...',
                'package' => $this->payment->package->name ?? 'Unknown',
            ],
            'error' => $this->error,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'payment.failed';
    }
}
