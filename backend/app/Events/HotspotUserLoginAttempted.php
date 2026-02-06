<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HotspotUserLoginAttempted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $tenantId;
    public string $username;
    public bool $success;
    public ?string $userId;
    public ?string $reason;
    public ?string $ipAddress;
    public ?string $macAddress;

    public function __construct(
        string $tenantId,
        string $username,
        bool $success,
        ?string $userId = null,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $macAddress = null
    ) {
        $this->tenantId = $tenantId;
        $this->username = $username;
        $this->success = $success;
        $this->userId = $userId;
        $this->reason = $reason;
        $this->ipAddress = $ipAddress;
        $this->macAddress = $macAddress;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.hotspot"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hotspot.login.attempted';
    }

    public function broadcastWith(): array
    {
        return [
            'username' => $this->username,
            'user_id' => $this->userId,
            'success' => $this->success,
            'reason' => $this->reason,
            'ip_address' => $this->ipAddress,
            'mac_address' => $this->macAddress,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
