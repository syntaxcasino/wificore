<?php

namespace App\Events;

use App\Models\Router;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RouterCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public Router $router;

    /**
     * Create a new event instance.
     */
    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['routers', 'dashboard-stats']);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'RouterCreated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'router' => [
                'id' => $this->router->id,
                'tenant_id' => $this->router->tenant_id,
                'name' => $this->router->name,
                'ip_address' => $this->router->ip_address,
                'vpn_ip' => $this->router->vpn_ip,
                'vpn_status' => $this->router->vpn_status,
                'vpn_enabled' => $this->router->vpn_enabled,
                'status' => $this->router->status,
                'model' => $this->router->model,
                'os_version' => $this->router->os_version,
                'created_at' => $this->router->created_at->toIso8601String(),
            ],
            'message' => 'Router created successfully',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Determine tenant ID for broadcasting
     */
    protected function getTenantId(): string
    {
        return (string) $this->router->tenant_id;
    }
}
