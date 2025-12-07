<?php

namespace App\Events;

use App\Models\Position;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class PositionCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $tenantId;

    public function __construct(Position ${strtolower(Position)}, ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        
        $this->data = [
            'id' => ${strtolower(Position)}->id,
            'created_at' => ${strtolower(Position)}->created_at?->toIso8601String(),
            'updated_at' => ${strtolower(Position)}->updated_at?->toIso8601String(),
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
        return 'positioncreated';
    }

    public function broadcastWith(): array
    {
        return [
            'position' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
