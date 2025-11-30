<?php

namespace App\Events;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Tenant $tenant;
    public User $adminUser;

    /**
     * Create a new event instance.
     */
    public function __construct(Tenant $tenant, User $adminUser)
    {
        $this->tenant = $tenant;
        $this->adminUser = $adminUser;
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
            'tenant' => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
                'email' => $this->tenant->email,
                'trial_ends_at' => $this->tenant->trial_ends_at?->toIso8601String(),
            ],
            'admin' => [
                'id' => $this->adminUser->id,
                'name' => $this->adminUser->name,
                'email' => $this->adminUser->email,
            ],
            'message' => 'New tenant registered',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
