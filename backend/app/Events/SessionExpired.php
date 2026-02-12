<?php

namespace App\Events;

use App\Models\RadiusSession;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class SessionExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $sessionData;
    public string $reason;
    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(RadiusSession $session, string $reason = 'Session expired', ?string $tenantId = null)
    {
        // Extract data instead of serializing the model
        // RadiusSession is in tenant schema; SerializesModels fails on deserialization
        $this->sessionData = [
            'id' => $session->id,
            'username' => $session->username,
            'duration_seconds' => $session->duration_seconds,
            'total_bytes' => $session->total_bytes,
            'hotspot_user_id' => $session->hotspot_user_id,
        ];
        $this->reason = $reason;
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->sessionData['hotspot_user_id']),
        ];

        if ($this->tenantId) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.dashboard-stats");
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'SessionExpired';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'session' => [
                'id' => $this->sessionData['id'],
                'username' => $this->sessionData['username'],
                'duration' => $this->sessionData['duration_seconds'],
                'data_used' => $this->sessionData['total_bytes'],
            ],
            'reason' => $this->reason,
            'message' => 'Session expired and user disconnected',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
