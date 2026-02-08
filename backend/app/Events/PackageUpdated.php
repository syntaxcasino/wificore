<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PackageUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $package;
    public string $tenantId;

    public function __construct(array $packageData, string $tenantId)
    {
        $this->package = $packageData;
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['packages', 'dashboard-stats']);
    }

    public function broadcastAs(): string
    {
        return 'PackageUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'package' => $this->package,
            'message' => "Package '{$this->package['name']}' updated",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
