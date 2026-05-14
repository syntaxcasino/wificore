<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class HotspotUserDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public string $userId;
    public string $username;
    public string $tenantId;

    public function __construct(string $userId, string $username, string $tenantId)
    {
        $this->userId = $userId;
        $this->username = $username;
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['hotspot-users', 'dashboard-stats']);
    }

    public function broadcastAs(): string
    {
        return 'HotspotUserDeleted';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'message' => 'Hotspot user deleted',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
