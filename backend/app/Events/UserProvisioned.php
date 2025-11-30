<?php

namespace App\Events;

use App\Models\UserSubscription;
use App\Models\Router;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserProvisioned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $subscription;
    public $router;

    /**
     * Create a new event instance.
     */
    public function __construct(UserSubscription $subscription, Router $router)
    {
        $this->subscription = $subscription;
        $this->router = $router;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin-notifications'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'user_provisioned',
            'subscription' => [
                'id' => $this->subscription->id,
                'user_id' => $this->subscription->user_id,
                'username' => $this->subscription->mikrotik_username,
                'package' => $this->subscription->package->name,
                'end_time' => $this->subscription->end_time,
            ],
            'router' => [
                'id' => $this->router->id,
                'name' => $this->router->name,
                'ip_address' => $this->router->ip_address,
            ],
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
