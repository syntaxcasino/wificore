<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PppoeSessionEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $session;
    public string $tenantId;

    public function __construct(array $session, string $tenantId)
    {
        $this->session = $session;
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['pppoe-sessions', 'dashboard-stats']);
    }

    public function broadcastAs(): string
    {
        return 'PppoeSessionEnded';
    }

    public function broadcastWith(): array
    {
        return [
            'session' => $this->session,
            'message' => 'PPPoE session ended',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
