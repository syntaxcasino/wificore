<?php

namespace App\Events;

use App\Models\Revenue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class RevenueCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $tenantId;

    public function __construct(Revenue $revenue, ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        
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
        return 'revenuecreated';
    }

    public function broadcastWith(): array
    {
        return [
            'revenue' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
