<?php

namespace App\Events;

use App\Models\VpnConfiguration;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VpnConfigurationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public VpnConfiguration $vpnConfig;

    /**
     * Create a new event instance.
     */
    public function __construct(VpnConfiguration $vpnConfig)
    {
        $this->vpnConfig = $vpnConfig;
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
            'vpn_config' => [
                'id' => $this->vpnConfig->id,
                'tenant_id' => $this->vpnConfig->tenant_id,
                'router_id' => $this->vpnConfig->router_id,
                'vpn_type' => $this->vpnConfig->vpn_type,
                'client_ip' => $this->vpnConfig->client_ip,
                'server_ip' => $this->vpnConfig->server_ip,
                'subnet_cidr' => $this->vpnConfig->subnet_cidr,
                'status' => $this->vpnConfig->status,
                'interface_name' => $this->vpnConfig->interface_name,
                'created_at' => $this->vpnConfig->created_at->toIso8601String(),
            ],
            'router' => $this->vpnConfig->router ? [
                'id' => $this->vpnConfig->router->id,
                'name' => $this->vpnConfig->router->name,
                'vpn_ip' => $this->vpnConfig->client_ip,
            ] : null,
            'message' => 'VPN configuration created successfully',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Determine tenant ID for broadcasting
     */
    protected function getTenantId(): string
    {
        return (string) $this->vpnConfig->tenant_id;
    }
}
