<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommunicationChannelDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public int $channelId;
    public $tenantId;

    public function __construct(int $channelId, $tenantId)
    {
        $this->channelId = $channelId;
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
        return 'CommunicationChannelDeleted';
    }

    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channelId,
            'message' => 'Communication channel deleted',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
