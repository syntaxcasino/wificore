<?php

namespace App\Events;

use App\Models\TenantRegistration;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TenantWorkspaceCreated implements ShouldBroadcast
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
            'tenant_id' => $registration->tenant_id,
            'tenant_slug' => $registration->tenant_slug,
            'email_verified' => $registration->email_verified,
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
        return 'workspace.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'status' => 'workspace_created',
            'message' => 'Workspace created successfully!',
            'registration' => $this->registrationData,
        ];
    }
}
