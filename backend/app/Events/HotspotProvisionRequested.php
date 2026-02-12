<?php

namespace App\Events;

use App\Models\RouterService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class HotspotProvisionRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $serviceId;
    public string $routerId;
    public string $tenantId;
    public array $config;

    public function __construct(string $serviceId, string $routerId, string $tenantId, array $config = [])
    {
        $this->serviceId = $serviceId;
        $this->routerId = $routerId;
        $this->tenantId = $tenantId;
        $this->config = $config;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.hotspot"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hotspot.provision.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'service_id' => $this->serviceId,
            'router_id' => $this->routerId,
            'status' => 'pending',
            'message' => 'Hotspot provisioning requested',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
