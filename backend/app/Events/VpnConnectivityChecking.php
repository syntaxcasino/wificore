<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class VpnConnectivityChecking implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $tenantId,
        public string $routerId,
        public int $vpnConfigId,
        public string $clientIp,
        public int $attempt,
        public int $maxAttempts
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
            'event' => 'vpn.connectivity.checking',
            'router_id' => $this->routerId,
            'vpn_config_id' => $this->vpnConfigId,
            'client_ip' => $this->clientIp,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'progress' => round(($this->attempt / $this->maxAttempts) * 100, 1),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'vpn.connectivity.checking';
    }
}
