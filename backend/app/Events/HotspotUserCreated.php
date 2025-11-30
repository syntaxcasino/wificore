<?php

namespace App\Events;

use App\Models\HotspotUser;
use App\Models\Payment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HotspotUserCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $hotspotUser;
    public $payment;
    public $credentials;

    /**
     * Create a new event instance.
     */
    public function __construct(HotspotUser $hotspotUser, Payment $payment, array $credentials)
    {
        $this->hotspotUser = $hotspotUser;
        $this->payment = $payment;
        $this->credentials = $credentials;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard-stats'),
            new PrivateChannel('hotspot-users'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'HotspotUserCreated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->hotspotUser->id,
                'username' => $this->hotspotUser->username,
                'phone_number' => $this->hotspotUser->phone_number,
                'package_name' => $this->hotspotUser->package_name,
                'expires_at' => $this->hotspotUser->subscription_expires_at,
            ],
            'payment' => [
                'id' => $this->payment->id,
                'amount' => $this->payment->amount,
            ],
            'message' => 'New hotspot user created',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
