<?php

namespace App\Events;

use App\Models\PppoePayment;
use App\Models\PppoeUser;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PppoePortalPaymentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $transactionId,
        public readonly string $status,
        public readonly ?string $tenantId = null,
        public readonly ?PppoePayment $payment = null,
        public readonly ?PppoeUser $user = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('pppoe-portal.payment.' . $this->transactionId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pppoe.payment.updated';
    }

    public function broadcastWith(): array
    {
        $payment = $this->payment;
        $user = $this->user;

        return [
            'tenant_id' => $this->tenantId,
            'transaction_id' => $this->transactionId,
            'status' => $this->status,
            'amount' => $payment?->amount,
            'payment_method' => $payment?->payment_method,
            'payment_reference' => $payment?->payment_reference,
            'created_at' => $payment?->created_at?->toIso8601String(),
            'paid_at' => ($payment?->verified_at ?? $payment?->payment_date)?->toIso8601String(),
            'next_payment_due' => $payment?->period_end?->toIso8601String(),
            'user' => $user ? [
                'status' => $user->status,
                'payment_status' => $user->payment_status,
                'next_payment_due' => $user->next_payment_due?->toIso8601String(),
                'last_payment_date' => $user->last_payment_date?->toIso8601String(),
                'expiration_date' => $user->expires_at?->toIso8601String(),
                'amount_due' => $user->amount_due,
                'amount_paid' => $user->amount_paid,
                'balance' => $user->balance ?? 0,
            ] : null,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
