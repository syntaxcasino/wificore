<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PppoeUserDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public string $pppoeUserId;
    public string $username;
    public string $tenantId;

    public function __construct(string $pppoeUserId, string $username, string $tenantId)
    {
        $this->pppoeUserId = $pppoeUserId;
        $this->username = $username;
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['pppoe-users', 'dashboard-stats']);
    }

    public function broadcastAs(): string
    {
        return 'PppoeUserDeleted';
    }

    public function broadcastWith(): array
    {
        return [
            'pppoe_user' => [
                'id' => $this->pppoeUserId,
                'username' => $this->username,
            ],
            'message' => 'PPPoE user deleted',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
