<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PasswordChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $userId;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user)
    {
        $this->userId = (string) $user->id;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'PasswordChanged';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message' => 'Password changed successfully',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
