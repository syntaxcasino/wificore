<?php

namespace App\Events;

use App\Models\Payment;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $paymentData;
    public string $error;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment, string $error, ?string $tenantId = null)
    {
        // Extract data instead of serializing the model
        // Payment is in tenant schema; SerializesModels fails on deserialization
        $this->paymentData = [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'phone_number' => $payment->phone_number,
            'transaction_id' => $payment->transaction_id,
            'package_name' => $payment->package?->name ?? 'Unknown',
        ];
        $this->error = $error;
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
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
                'id' => $this->paymentData['id'],
                'amount' => $this->paymentData['amount'],
                'phone_number' => $this->maskPhoneNumber($this->paymentData['phone_number'] ?? ''),
                'transaction_id' => substr($this->paymentData['transaction_id'] ?? '', 0, 8) . '...',
                'package' => $this->paymentData['package_name'],
            ],
            'error' => $this->error,
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
        return 'payment.failed';
    }
}
