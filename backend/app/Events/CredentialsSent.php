<?php

namespace App\Events;

use App\Models\HotspotCredential;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CredentialsSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $credential;

    /**
     * Create a new event instance.
     */
    public function __construct(HotspotCredential $credential)
    {
        $this->credential = $credential;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard-stats'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'CredentialsSent';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'credential' => [
                'phone_number' => $this->credential->phone_number,
                'sms_status' => $this->credential->sms_status,
                'sent_at' => $this->credential->sms_sent_at,
            ],
            'message' => 'Credentials sent via SMS',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
