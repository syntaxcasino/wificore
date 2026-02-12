<?php

namespace App\Events;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TenantCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public array $tenantData;
    public array $adminData;

    /**
     * Create a new event instance.
     */
    public function __construct(Tenant $tenant, User $adminUser)
    {
        $this->tenantData = [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'email' => $tenant->email,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
        ];
        $this->adminData = [
            'id' => $adminUser->id,
            'name' => $adminUser->name,
            'email' => $adminUser->email,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('system-admin'),
            new Channel('tenants'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'TenantCreated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'tenant' => $this->tenantData,
            'admin' => $this->adminData,
            'message' => 'New tenant registered',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
