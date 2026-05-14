<?php

namespace App\Events;

use App\Models\HotspotUser;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class HotspotUserUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $user;
    public string $tenantId;

    public function __construct(HotspotUser $user, string $tenantId)
    {
        $this->user = [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'status' => $user->status,
            'is_active' => $user->is_active,
            'has_active_subscription' => $user->has_active_subscription,
            'package_id' => $user->package_id,
            'package_name' => $user->package_name,
            'subscription_expires_at' => $user->subscription_expires_at?->toIso8601String(),
            'data_used' => $user->data_used,
            'mac_address' => $user->mac_address,
            'simultaneous_use' => $user->simultaneous_use,
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['hotspot-users', 'dashboard-stats']);
    }

    public function broadcastAs(): string
    {
        return 'HotspotUserUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => $this->user,
            'message' => 'Hotspot user updated',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
