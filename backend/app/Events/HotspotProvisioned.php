<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class HotspotProvisioned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $serviceId;
    public string $routerId;
    public string $tenantId;
    public bool $success;
    public ?string $error;
    public array $details;

    public function __construct(
        string $serviceId,
        string $routerId,
        string $tenantId,
        bool $success = true,
        ?string $error = null,
        array $details = []
    ) {
        $this->serviceId = $serviceId;
        $this->routerId = $routerId;
        $this->tenantId = $tenantId;
        $this->success = $success;
        $this->error = $error;
        $this->details = $details;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.hotspot"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hotspot.provisioned';
    }

    public function broadcastWith(): array
    {
        return [
            'service_id' => $this->serviceId,
            'router_id' => $this->routerId,
            'success' => $this->success,
            'error' => $this->error,
            'status' => $this->success ? 'deployed' : 'failed',
            'details' => $this->details,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
