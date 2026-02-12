<?php

namespace App\Events;

use App\Models\VpnConfiguration;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class VpnConfigurationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $vpnConfigData;
    public ?array $routerData;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(VpnConfiguration $vpnConfig, ?string $tenantId = null)
    {
        // Extract data instead of serializing the model
        // VpnConfiguration is in tenant schema; SerializesModels fails on deserialization
        $this->vpnConfigData = [
            'id' => $vpnConfig->id,
            'router_id' => $vpnConfig->router_id,
            'vpn_type' => $vpnConfig->vpn_type,
            'client_ip' => $vpnConfig->client_ip,
            'server_ip' => $vpnConfig->server_ip,
            'subnet_cidr' => $vpnConfig->subnet_cidr,
            'status' => $vpnConfig->status,
            'interface_name' => $vpnConfig->interface_name,
            'created_at' => $vpnConfig->created_at?->toIso8601String(),
        ];
        $this->routerData = $vpnConfig->router ? [
            'id' => $vpnConfig->router->id,
            'name' => $vpnConfig->router->name,
            'vpn_ip' => $vpnConfig->client_ip,
        ] : null;
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Broadcast to tenant-specific channels
        return $this->getTenantChannels(['vpn-configs', 'routers', 'dashboard-stats']);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'VpnConfigurationCreated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'vpn_config' => $this->vpnConfigData,
            'router' => $this->routerData,
            'message' => 'VPN configuration created successfully',
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
