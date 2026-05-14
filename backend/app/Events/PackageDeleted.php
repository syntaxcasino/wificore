<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PackageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public string $packageId;
    public string $packageName;
    public string $tenantId;

    public function __construct(string $packageId, string $packageName, string $tenantId)
    {
        $this->packageId = $packageId;
        $this->packageName = $packageName;
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        return $this->getTenantChannels(['packages', 'dashboard-stats']);
    }

    public function broadcastAs(): string
    {
        return 'PackageDeleted';
    }

    public function broadcastWith(): array
    {
        return [
            'package' => [
                'id' => $this->packageId,
                'name' => $this->packageName,
            ],
            'id' => $this->packageId,
            'packageId' => $this->packageId,
            'message' => "Package '{$this->packageName}' deleted",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
