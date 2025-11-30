<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardStatsUpdated implements ShouldBroadcastNow, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;
    
    /**
     * The name of the queue connection to use when broadcasting the event.
     */
    public $connection = 'redis';
    
    /**
     * The name of the queue on which to place the broadcasting job.
     */
    public $queue = 'broadcasts';

    public $stats;
    public $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(array $stats, string $tenantId = null)
    {
        $this->stats = $stats;
        $this->tenantId = $tenantId ?? auth()->user()?->tenant_id;
    }

    /**
     * Get the channels the event should broadcast on.
     * Tenant-specific channel
     */
    public function broadcastOn(): array
    {
        return [
            $this->getTenantChannel('dashboard-stats'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'stats.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'stats' => $this->stats,
        ];
    }
}
