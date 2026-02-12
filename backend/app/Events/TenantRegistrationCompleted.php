<?php

namespace App\Events;

use App\Models\TenantRegistration;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TenantRegistrationCompleted implements ShouldBroadcast
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
            'tenant_email' => $registration->tenant_email,
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
        return 'registration.completed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'status' => 'completed',
            'message' => 'Registration completed successfully!',
            'registration' => array_merge($this->registrationData, ['status' => 'completed']),
        ];
    }
}
