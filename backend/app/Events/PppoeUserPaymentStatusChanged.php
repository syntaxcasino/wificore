<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PPPoE User Payment Status Changed Event
 * 
 * Broadcast when a PPPoE user's payment status changes.
 * Used for real-time UI updates on tenant dashboard.
 */
class PppoeUserPaymentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $tenantId;
    public string $userId;
    public string $status;
    public string $action;
    public string $timestamp;

    /**
     * @param string $tenantId
     * @param string $userId
     * @param string $status Payment status: paid, unpaid, grace_period, suspended
     * @param string $action Action taken: renewed, reconnected, disconnected, grace_started
     */
    public function __construct(string $tenantId, string $userId, string $status, string $action)
    {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->status = $status;
        $this->action = $action;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.' . $this->tenantId . '.pppoe-users'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pppoe.payment.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'status' => $this->status,
            'action' => $this->action,
            'timestamp' => $this->timestamp,
        ];
    }
}
