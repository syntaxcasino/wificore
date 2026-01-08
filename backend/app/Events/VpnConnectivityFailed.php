<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class VpnConnectivityFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $tenantId,
        public string $routerId,
        public int $vpnConfigId,
        public string $clientIp,
        public string $reason,
        public int $attempts
    ) {}

    /**
     * Get the channels the event should broadcast on.
     * Uses PrivateChannel for tenant data security.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("tenant.{$this->tenantId}.vpn");
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'event' => 'vpn.connectivity.failed',
            'router_id' => $this->routerId,
            'vpn_config_id' => $this->vpnConfigId,
            'client_ip' => $this->clientIp,
            'connectivity' => [
                'reachable' => false,
                'packet_loss' => 100,
                'latency_ms' => null,
                'status' => 'timeout',
            ],
            'reason' => $this->reason,
            'attempts' => $this->attempts,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'vpn.connectivity.failed';
    }
}
