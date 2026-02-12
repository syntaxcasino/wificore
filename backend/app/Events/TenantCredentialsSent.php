<?php

namespace App\Events;

use App\Models\TenantRegistration;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TenantCredentialsSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public array $registrationData;

    /**
     * Create a new event instance.
     */
    public function __construct(TenantRegistration $registration)
    {
        $this->registrationData = [
            'token' => $registration->token,
            'tenant_slug' => $registration->tenant_slug,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('tenant-registration.' . $this->registrationData['token']);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'credentials.sent';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'status' => 'credentials_sent',
            'message' => 'Login credentials sent to your email!',
            'registration' => array_merge($this->registrationData, ['credentials_sent' => true]),
        ];
    }
}
