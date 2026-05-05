<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class VoucherDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public int $voucherId;
    public ?string $tenantId;
    public ?string $code;

    /**
     * Create a new event instance.
     */
    public function __construct(int $voucherId, ?string $tenantId, ?string $code = null)
    {
        $this->voucherId = $voucherId;
        $this->tenantId = $tenantId;
        $this->code = $code;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['vouchers', 'dashboard-stats']);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'VoucherDeleted';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'voucher_id' => $this->voucherId,
            'code' => $this->code,
            'message' => $this->code ? "Voucher '{$this->code}' deleted" : 'Voucher deleted',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Determine tenant ID for broadcasting
     */
    protected function getTenantId(): string
    {
        return (string) $this->tenantId;
    }

    /**
     * Check if the event should be broadcast.
     */
    public function shouldBroadcast(): bool
    {
        return $this->tenantId !== null;
    }
}
