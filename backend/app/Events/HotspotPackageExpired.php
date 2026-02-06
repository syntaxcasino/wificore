<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HotspotPackageExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $userId;
    public string $tenantId;
    public string $username;
    public ?string $packageName;
    public string $expiredAt;
    public bool $wasDisconnected;

    public function __construct(
        string $userId,
        string $tenantId,
        string $username,
        ?string $packageName = null,
        ?string $expiredAt = null,
        bool $wasDisconnected = true
    ) {
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->username = $username;
        $this->packageName = $packageName;
        $this->expiredAt = $expiredAt ?? now()->toIso8601String();
        $this->wasDisconnected = $wasDisconnected;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.hotspot"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hotspot.package.expired';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'package_name' => $this->packageName,
            'expired_at' => $this->expiredAt,
            'was_disconnected' => $this->wasDisconnected,
            'status' => 'expired',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
