<?php

namespace App\Events;

use App\Models\VpnConfiguration;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VpnConnectivityVerified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public string $routerId,
        public int $vpnConfigId,
        public string $clientIp,
        public float $latencyMs,
        public int $packetLoss,
        public int $attempts
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel("tenant.{$this->tenantId}.vpn");
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'event' => 'vpn.connectivity.verified',
            'router_id' => $this->routerId,
            'vpn_config_id' => $this->vpnConfigId,
            'client_ip' => $this->clientIp,
            'connectivity' => [
                'reachable' => true,
                'packet_loss' => $this->packetLoss,
                'latency_ms' => $this->latencyMs,
                'status' => 'connected',
            ],
            'attempts' => $this->attempts,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'vpn.connectivity.verified';
    }
}
