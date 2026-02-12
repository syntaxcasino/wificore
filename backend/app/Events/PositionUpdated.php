<?php

namespace App\Events;

use App\Models\Position;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class PositionUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $changes;
    public $tenantId;

    public function __construct(Position $position, array $changes = [], ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->changes = $changes;
        
        $this->data = [
            'id' => $position->id,
            'created_at' => $position->created_at?->toIso8601String(),
            'updated_at' => $position->updated_at?->toIso8601String(),
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [];
        if ($this->tenantId) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.positions");
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'positionupdated';
    }

    public function broadcastWith(): array
    {
        return [
            'position' => $this->data,
            'changes' => $this->changes,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
