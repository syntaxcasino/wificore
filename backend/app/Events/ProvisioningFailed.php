<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProvisioningFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public $routerId;
    public $stage;
    public $message;
    public $data;
    public $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(string $routerId, string $stage, string $message, array $data = [])
    {
        $this->routerId = $routerId;
        $this->stage = $stage;
        $this->message = $message;
        $this->data = $data;
        $this->tenantId = $data['tenant_id'] ?? null;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('router-provisioning.' . $this->routerId),
        ];

        if ($this->tenantId) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.admin-notifications");
        }

        return $channels;
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
