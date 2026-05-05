<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SystemMetricsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public array $queue,
        public array $health,
        public array $performance
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('system.admin');
    }

    public function broadcastAs(): string
    {
        return 'SystemMetricsUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'queue'       => $this->queue,
            'health'      => $this->health,
            'performance' => $this->performance,
            'timestamp'   => now()->toIso8601String(),
        ];
    }
}
