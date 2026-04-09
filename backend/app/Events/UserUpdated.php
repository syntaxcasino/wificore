<?php

namespace App\Events;

use App\Models\User;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class UserUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $userData;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user)
    {
        // Extract data instead of serializing the model
        $this->userData = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'tenant_id' => $user->tenant_id,
        ];
        $this->tenantId = $user->tenant_id;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId) {
            return $this->getTenantChannels(['users']);
        }

        // System-level update — only visible to system admins
        return [
            new PrivateChannel('system.admin'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'UserUpdated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user' => $this->userData,
            'message' => 'User updated',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Determine tenant ID for broadcasting
     */
    protected function getTenantId(): string
    {
        return (string) $this->tenantId;
    }
}
