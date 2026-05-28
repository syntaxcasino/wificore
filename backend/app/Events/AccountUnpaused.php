<?php

namespace App\Events;

use App\Models\PppoeUser;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;

class AccountUnpaused implements ShouldBroadcastNow, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    /**
     * The name of the queue connection to use when broadcasting the event.
     */
    public $connection = 'redis';

    /**
     * The name of the queue on which to place the broadcasting job.
     */
    public $queue = 'broadcasts';

    public array $userData;
    public ?string $tenantId;
    public ?string $wasPausedAt;
    public ?string $wasPauseEndsAt;
    public ?string $pauseReason;
    public int $pauseDurationDays;

    /**
     * Create a new event instance.
     */
    public function __construct(
        PppoeUser $pppoeUser,
        ?string $wasPausedAt,
        ?string $wasPauseEndsAt,
        ?string $pauseReason,
        int $pauseDurationDays
    ) {
        $this->userData = [
            'id' => $pppoeUser->id,
            'username' => $pppoeUser->username,
            'account_number' => $pppoeUser->account_number,
            'status' => $pppoeUser->status,
        ];
        $this->tenantId = $pppoeUser->tenant_id ?? null;
        $this->wasPausedAt = $wasPausedAt;
        $this->wasPauseEndsAt = $wasPauseEndsAt;
        $this->pauseReason = $pauseReason;
        $this->pauseDurationDays = $pauseDurationDays;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->tenantId) {
            $channels[] = $this->getTenantChannel('pppoe-alerts');
        }

        $channels[] = new PrivateChannel('system.admin.pppoe-alerts');

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'account.unpaused';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user' => $this->userData,
            'was_paused_at' => $this->wasPausedAt,
            'was_pause_ends_at' => $this->wasPauseEndsAt,
            'pause_reason' => $this->pauseReason,
            'pause_duration_days' => $this->pauseDurationDays,
            'timestamp' => now()->toIso8601String(),
            'severity' => 'info',
            'message' => "Account '{$this->userData['username']}' has been auto-unpaused.",
        ];
    }
}
