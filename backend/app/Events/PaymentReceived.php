<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment Received Event
 * 
 * Broadcast when a payment is received and processed.
 * Tenant-scoped to ensure zero cross-tenant data leaks.
 */
class PaymentReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $tenantId;
    public string $userId;
    public string $paymentId;
    public float $amount;
    public string $timestamp;

    public function __construct(string $tenantId, string $userId, string $paymentId, float $amount)
    {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->paymentId = $paymentId;
        $this->amount = $amount;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.' . $this->tenantId . '.payments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.received';
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'payment_id' => $this->paymentId,
            'amount' => $this->amount,
            'timestamp' => $this->timestamp,
        ];
    }
}
