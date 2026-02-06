<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HotspotAccessGranted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $userId;
    public string $tenantId;
    public string $username;
    public ?string $packageName;
    public ?string $expiresAt;
    public string $reason;

    public function __construct(
        string $userId,
        string $tenantId,
        string $username,
        ?string $packageName = null,
        ?string $expiresAt = null,
        string $reason = 'payment'
    ) {
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->username = $username;
        $this->packageName = $packageName;
        $this->expiresAt = $expiresAt;
        $this->reason = $reason;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.hotspot"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hotspot.access.granted';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'package_name' => $this->packageName,
            'expires_at' => $this->expiresAt,
            'reason' => $this->reason,
            'status' => 'active',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
