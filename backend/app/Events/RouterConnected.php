<?php

namespace App\Events;

use App\Models\Router;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class RouterConnected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public array $routerData;

    /**
     * Create a new event instance.
     */
    public function __construct(Router $router)
    {
        // Extract data instead of serializing the model
        // Router is tenant-scoped; SerializesModels would fail on deserialization
        // because the queue worker has no tenant search_path set
        $this->routerData = [
            'id' => $router->id,
            'name' => $router->name,
            'status' => $router->status,
            'model' => $router->model,
            'os_version' => $router->os_version,
            'last_seen' => $router->last_seen,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('router-provisioning.' . $this->routerData['id']),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'router_id' => $this->routerData['id'],
            'name' => $this->routerData['name'],
            'status' => $this->routerData['status'],
            'model' => $this->routerData['model'],
            'os_version' => $this->routerData['os_version'],
            'last_seen' => $this->routerData['last_seen'],
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
