<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class UserDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public int $userId;
    public string $username;
    public string $email;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(int $userId, string $username, string $email, ?string $tenantId = null)
    {
        $this->userId = $userId;
        $this->username = $username;
        $this->email = $email;
        $this->tenantId = $tenantId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId) {
            return [
                new PrivateChannel("tenant.{$this->tenantId}.users"),
            ];
        }

        // System-level user deletion (no tenant) — system admin channel only
        return [
            new PrivateChannel('system.admin'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'UserDeleted';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'email' => $this->email,
            'message' => 'User deleted',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
