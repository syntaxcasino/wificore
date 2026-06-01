<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ProvisioningCallbackGuardAlertRaised implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $level,
        public int $windowMinutes,
        public int $totalDelta,
        public int $warnThreshold,
        public int $criticalThreshold,
        public array $deltas
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('system.admin');
    }

    public function broadcastAs(): string
    {
        return 'provisioning.callback_guard.alert';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'provisioning.callback_guard.alert',
            'level' => $this->level,
            'window_minutes' => $this->windowMinutes,
            'total_delta' => $this->totalDelta,
            'warn_threshold' => $this->warnThreshold,
            'critical_threshold' => $this->criticalThreshold,
            'deltas' => $this->deltas,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
