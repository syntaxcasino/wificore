<?php

namespace App\Events;

use App\Models\Revenue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class RevenueUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $changes;
    public $tenantId;

    public function __construct(Revenue $revenue, array $changes = [], ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->changes = $changes;
        
        $this->data = [
            'id' => $revenue->id,
            'created_at' => $revenue->created_at?->toIso8601String(),
            'updated_at' => $revenue->updated_at?->toIso8601String(),
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [];
        if ($this->tenantId) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.revenues");
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'revenueupdated';
    }

    public function broadcastWith(): array
    {
        return [
            'revenue' => $this->data,
            'changes' => $this->changes,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
