<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class HotspotAccessRevoked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $userId;
    public string $tenantId;
    public string $username;
    public string $reason;
    public ?string $sessionId;

    public function __construct(
        string $userId,
        string $tenantId,
        string $username,
        string $reason = 'expired',
        ?string $sessionId = null
    ) {
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->username = $username;
        $this->reason = $reason;
        $this->sessionId = $sessionId;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.hotspot"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hotspot.access.revoked';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'session_id' => $this->sessionId,
            'reason' => $this->reason,
            'status' => 'revoked',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
