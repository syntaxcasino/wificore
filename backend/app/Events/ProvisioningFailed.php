<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProvisioningFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $routerId;
    public $stage;
    public $message;
    public $data;

    /**
     * Create a new event instance.
     */
    public function __construct(string $routerId, string $stage, string $message, array $data = [])
    {
        $this->routerId = $routerId;
        $this->stage = $stage;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('router-provisioning.' . $this->routerId),
            new PrivateChannel('admin-notifications'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'provisioning_failed',
            'router_id' => $this->routerId,
            'stage' => $this->stage,
            'message' => $this->message,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'provisioning.failed';
    }
}
