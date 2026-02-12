<?php

namespace App\Events;

use App\Models\Package;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PackageStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $packageData;
    public string $oldStatus;
    public string $newStatus;
    public string $timestamp;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(Package $package, string $oldStatus, string $newStatus, ?string $tenantId = null)
    {
        // Extract data instead of serializing the model
        // Package is in tenant schema; SerializesModels fails on deserialization
        $this->packageData = [
            'id' => $package->id,
            'name' => $package->name,
            'type' => $package->type,
            'is_active' => $package->is_active,
            'scheduled_activation_time' => $package->scheduled_activation_time,
        ];
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
        $this->timestamp = now()->toIso8601String();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['packages', 'dashboard-stats']);
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
            'package_id' => $this->packageData['id'],
            'package_name' => $this->packageData['name'],
            'package_type' => $this->packageData['type'],
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'is_active' => $this->packageData['is_active'],
            'scheduled_activation_time' => $this->packageData['scheduled_activation_time'],
            'timestamp' => $this->timestamp,
            'message' => "Package '{$this->packageData['name']}' status changed from {$this->oldStatus} to {$this->newStatus}",
        ];
    }
}
