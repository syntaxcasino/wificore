<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RouterLiveDataUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct( public string $routerId, public array $liveData ) {
        // Log when event is created
        Log::info('RouterLiveDataUpdated event created', [
            'router_id' => $this->routerId,
            'data_keys' => array_keys($this->liveData)
        ]);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('router-updates'),
        ];
    }

  public function broadcastAs(): string
    {
        return 'RouterLiveDataUpdated';
    }

    public function broadcastWith(): array
    {
        Log::info('Broadcasting router data', [
            'router_id' => $this->routerId,
            'timestamp' => now()->toISOString()
        ]);

        return [
            'router_id' => $this->routerId,
            'data' => $this->liveData,
            'timestamp' => now()->toISOString(),
            'event_id' => uniqid('event_', true)
        ];
    }

    public function broadcastWhen(): bool
    {
        $shouldBroadcast = !empty($this->liveData) && $this->routerId > 0;
        
        Log::info('Checking if should broadcast', [
            'should_broadcast' => $shouldBroadcast,
            'router_id' => $this->routerId,
            'has_data' => !empty($this->liveData)
        ]);

        return true;
    }
}