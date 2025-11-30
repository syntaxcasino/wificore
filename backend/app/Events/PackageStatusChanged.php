<?php

namespace App\Events;

use App\Models\Package;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PackageStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $package;
    public $oldStatus;
    public $newStatus;
    public $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct(Package $package, string $oldStatus, string $newStatus)
    {
        $this->package = $package;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->timestamp = now()->toIso8601String();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('packages'),
            new PrivateChannel('admin-notifications'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'package.status.changed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'package_id' => $this->package->id,
            'package_name' => $this->package->name,
            'package_type' => $this->package->type,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'is_active' => $this->package->is_active,
            'scheduled_activation_time' => $this->package->scheduled_activation_time,
            'timestamp' => $this->timestamp,
            'message' => "Package '{$this->package->name}' status changed from {$this->oldStatus} to {$this->newStatus}",
        ];
    }
}
