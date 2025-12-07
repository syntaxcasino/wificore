<?php

namespace App\Events;

use App\Models\Revenue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class RevenueCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $tenantId;

    public function __construct(Revenue ${strtolower(Revenue)}, ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        
        $this->data = [
            'id' => ${strtolower(Revenue)}->id,
            'created_at' => ${strtolower(Revenue)}->created_at?->toIso8601String(),
            'updated_at' => ${strtolower(Revenue)}->updated_at?->toIso8601String(),
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
