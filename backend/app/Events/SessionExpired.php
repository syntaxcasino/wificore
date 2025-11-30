<?php

namespace App\Events;

use App\Models\RadiusSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $session;
    public $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(RadiusSession $session, string $reason = 'Session expired')
    {
        $this->session = $session;
        $this->reason = $reason;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard-stats'),
            new PrivateChannel('user.' . $this->session->hotspot_user_id),
        ];
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
