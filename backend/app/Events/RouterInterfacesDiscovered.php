<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RouterInterfacesDiscovered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $tenantId;
    public string $routerId;
    public array $interfaces;
    public array $routerInfo;

    public function __construct(
        string $tenantId,
        string $routerId,
        array $interfaces,
        array $routerInfo = []
    ) {
        $this->tenantId = $tenantId;
        $this->routerId = $routerId;
        $this->interfaces = $interfaces;
        $this->routerInfo = $routerInfo;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("tenant.{$this->tenantId}.routers");
    }

    public function broadcastAs(): string
    {
        return 'router.interfaces.discovered';
    }

    public function broadcastWith(): array
    {
        return [
            'router_id' => $this->routerId,
            'interfaces' => $this->interfaces,
            'router_info' => $this->routerInfo,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
