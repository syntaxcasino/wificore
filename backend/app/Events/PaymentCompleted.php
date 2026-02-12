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

class PaymentCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $paymentData;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment, ?string $tenantId = null)
    {
        // Extract data instead of serializing the model
        // Payment is in tenant schema; SerializesModels fails on deserialization
        $this->paymentData = [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'phone_number' => $payment->phone_number,
            'package_id' => $payment->package_id,
            'status' => $payment->status,
        ];
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
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
                'id' => $this->paymentData['id'],
                'amount' => $this->paymentData['amount'],
                'phone_number' => $this->maskPhoneNumber($this->paymentData['phone_number'] ?? ''),
                'package_id' => $this->paymentData['package_id'],
                'status' => $this->paymentData['status'],
            ],
            'message' => 'Payment completed successfully',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected function getTenantId(): string
    {
        return (string) $this->tenantId;
    }
}
