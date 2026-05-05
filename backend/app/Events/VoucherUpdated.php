<?php

namespace App\Events;

use App\Models\Voucher;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class VoucherUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $voucherData;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(Voucher $voucher, ?string $tenantId = null)
    {
        $this->voucherData = [
            'id' => $voucher->id,
            'code' => $voucher->code,
            'package_id' => $voucher->package_id,
            'router_id' => $voucher->router_id,
            'status' => $voucher->status,
            'expires_at' => $voucher->expires_at,
            'used_at' => $voucher->used_at,
            'used_by' => $voucher->used_by,
            'updated_at' => $voucher->updated_at->toIso8601String(),
        ];
        $this->tenantId = $tenantId;
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
        return 'VoucherUpdated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'voucher' => $this->voucherData,
            'message' => "Voucher '{$this->voucherData['code']}' updated",
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
