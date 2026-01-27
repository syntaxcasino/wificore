<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use App\Models\PppoeUser;

class PppoeUserCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $pppoeUser;
    public string $tenantId;

    public function __construct(PppoeUser $pppoeUser, string $tenantId)
    {
        $expiresAt = $pppoeUser->expires_at;
        if ($expiresAt instanceof \DateTimeInterface) {
            $expiresAt = $expiresAt->format(DATE_ATOM);
        }

        $this->pppoeUser = [
            'id' => $pppoeUser->id,
            'username' => $pppoeUser->username,
            'package_id' => $pppoeUser->package_id,
            'router_id' => $pppoeUser->router_id,
            'expires_at' => $expiresAt,
            'rate_limit' => $pppoeUser->rate_limit,
            'simultaneous_use' => $pppoeUser->simultaneous_use,
            'is_active' => $pppoeUser->is_active,
            'status' => $pppoeUser->status,
        ];
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['pppoe-users', 'dashboard-stats']);
    }

    public function broadcastAs(): string
    {
        return 'PppoeUserCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'pppoe_user' => $this->pppoeUser,
            'message' => 'PPPoE user created',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
