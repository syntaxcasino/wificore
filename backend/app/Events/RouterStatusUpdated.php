<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;

class RouterStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;
    /**
     * Array of router statuses.
     *
     * @var array
     */
    public $routers;
    public $tenantId;

    /**
     * Create a new event instance.
     *
     * @param array $routers Array of router status data
     * @param string|null $tenantId Tenant ID (extracted from first router if not provided)
     */
    public function __construct(array $routers, string $tenantId = null)
    {
        $this->routers = $routers;
        
        // Routers are in tenant schema - no tenant_id in router data
        $this->tenantId = $tenantId ?? (auth()->check() ? auth()->user()->tenant_id : null);

        Log::info('RouterStatusUpdated event created', [
            'tenant_id' => $this->tenantId,
            'router_count' => count($routers),
            'routers' => array_map(function ($router) {
                return [
                    'id' => $router['id'] ?? null,
                    'ip_address' => $router['ip_address'] ?? null,
                    'name' => $router['name'] ?? null,
                    'status' => $router['status'] ?? null,
                ];
            }, $routers),
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     * Tenant-specific channel
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Don't broadcast if we don't have a tenant ID
        if (!$this->tenantId) {
            Log::warning('RouterStatusUpdated: Cannot broadcast without tenant ID', [
                'router_count' => count($this->routers)
            ]);
            return [];
        }
        
        return [
            $this->getTenantChannel('router-updates'),
        ];
    }

    /**
     * Get the event name for broadcasting.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'RouterStatusUpdated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return ['routers' => $this->routers];
    }
}