<?php

namespace App\Events;

use App\Models\Router;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RouterConnected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $router;

    /**
     * Create a new event instance.
     */
    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('router-provisioning.' . $this->router->id),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'router_id' => $this->router->id,
            'name' => $this->router->name,
            'status' => $this->router->status,
            'model' => $this->router->model,
            'os_version' => $this->router->os_version,
            'last_seen' => $this->router->last_seen,
            'stage' => 3, // Connected stage
            'message' => 'Router connected successfully!',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'router.connected';
    }
}
