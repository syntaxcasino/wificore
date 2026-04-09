<?php

namespace App\Events;

use App\Models\AccessPoint;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccessPointUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $accessPoint;

    /**
     * Create a new event instance.
     */
    public function __construct(AccessPoint $accessPoint)
    {
        $this->accessPoint = $accessPoint;
    }

    /**
     * Get the channels the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.' . $this->accessPoint->tenant_id . '.access-points'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'access-point-updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'access_point' => $this->accessPoint->load('router', 'activeSessions'),
        ];
    }
}
