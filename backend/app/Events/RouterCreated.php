<?php

namespace App\Events;

use App\Models\Router;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class RouterCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $routerData;
    public string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(Router $router)
    {
        // Extract data instead of serializing the model
        $this->routerData = [
            'id' => $router->id,
            'tenant_id' => $router->tenant_id,
            'name' => $router->name,
            'ip_address' => $router->ip_address,
            'vpn_ip' => $router->vpn_ip,
            'vpn_status' => $router->vpn_status,
            'vpn_enabled' => $router->vpn_enabled,
            'status' => $router->status,
            'model' => $router->model,
            'os_version' => $router->os_version,
            'created_at' => $router->created_at->toIso8601String(),
        ];
        $this->tenantId = (string) $router->tenant_id;
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
            'router' => $this->routerData,
            'message' => 'Router created successfully',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Determine tenant ID for broadcasting
     */
    protected function getTenantId(): string
    {
        return $this->tenantId;
    }
}
