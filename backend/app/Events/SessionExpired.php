<?php

namespace App\Events;

use App\Models\RadiusSession;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public $session;
    public $reason;
    public $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(RadiusSession $session, string $reason = 'Session expired', ?string $tenantId = null)
    {
        $this->session = $session;
        $this->reason = $reason;
        // RadiusSession is in tenant schema - no tenant_id column
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->session->hotspot_user_id),
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
                'id' => $this->session->id,
                'username' => $this->session->username,
                'duration' => $this->session->duration_seconds,
                'data_used' => $this->session->total_bytes,
            ],
            'reason' => $this->reason,
            'message' => 'Session expired and user disconnected',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
