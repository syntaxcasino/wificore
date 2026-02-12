<?php

namespace App\Events;

use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TestMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public int $channelId;
    public string $status;
    public string $message;
    public $tenantId;

    public function __construct(int $channelId, string $status, string $message, $tenantId)
    {
        $this->channelId = $channelId;
        $this->status = $status;
        $this->message = $message;
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
        return 'TestMessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channelId,
            'status' => $this->status,
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
