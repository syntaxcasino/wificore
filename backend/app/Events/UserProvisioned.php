<?php

namespace App\Events;

use App\Models\UserSubscription;
use App\Models\Router;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class UserProvisioned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $subscriptionData;
    public array $routerData;
    public $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(UserSubscription $subscription, Router $router, ?string $tenantId = null)
    {
        // Extract data instead of serializing models
        // Both models are tenant-scoped; SerializesModels fails on deserialization
        $this->subscriptionData = [
            'id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'username' => $subscription->mikrotik_username,
            'package_name' => $subscription->package?->name,
            'end_time' => $subscription->end_time,
        ];
        $this->routerData = [
            'id' => $router->id,
            'name' => $router->name,
            'ip_address' => $router->ip_address,
        ];
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId) {
            return $this->getTenantChannels(['admin-notifications', 'dashboard-stats']);
        }
        return [new PrivateChannel('admin-notifications')];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'user_provisioned',
            'subscription' => $this->subscriptionData,
            'router' => $this->routerData,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'user.provisioned';
    }
}
