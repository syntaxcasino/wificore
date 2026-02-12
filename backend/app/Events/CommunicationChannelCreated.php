<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class CommunicationChannelCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $channelData;
    public $tenantId;

    public function __construct(array $channelData, $tenantId)
    {
        $this->channelData = $channelData;
        $this->tenantId = $tenantId;
    }

    public function broadcastOn(): array
    {
        if ($this->tenantId) {
            return $this->getTenantChannels(['settings']);
        }
        return [];
    }

    public function broadcastAs(): string
    {
        return 'CommunicationChannelCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'channel' => $this->channelData,
            'message' => 'Communication channel created',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
