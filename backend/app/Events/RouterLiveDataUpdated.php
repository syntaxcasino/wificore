<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class RouterLiveDataUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $tenantId,
        public string $routerId,
        public array $liveData
    ) {}

    public function broadcastOn(): array
    {
        // Tenant-scoped private channel to match frontend subscription
        return [
            new PrivateChannel('tenant.' . $this->tenantId . '.router-updates'),
        ];
    }

  public function broadcastAs(): string
    {
        return 'RouterLiveDataUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'router_id' => $this->routerId,
            'data' => $this->liveData,
            'timestamp' => now()->toISOString(),
            'event_id' => uniqid('event_', true)
        ];
    }

    public function broadcastWhen(): bool
    {
        return !empty($this->liveData) && $this->routerId !== '';
    }
}
