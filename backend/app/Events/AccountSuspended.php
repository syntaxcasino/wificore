<?php

namespace App\Events;

use App\Models\User;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountSuspended implements ShouldBroadcastNow, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;
    
    /**
     * The name of the queue connection to use when broadcasting the event.
     */
    public $connection = 'redis';
    
    /**
     * The name of the queue on which to place the broadcasting job.
     */
    public $queue = 'broadcasts';

    public $user;
    public $tenantId;
    public $suspendedUntil;
    public $reason;
    public $ipAddress;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, string $suspendedUntil, string $reason, string $ipAddress)
    {
        $this->user = $user;
        $this->tenantId = $user->tenant_id;
        $this->suspendedUntil = $suspendedUntil;
        $this->reason = $reason;
        $this->ipAddress = $ipAddress;
    }

    /**
     * Get the channels the event should broadcast on.
     * Broadcasts to:
     * - The user's tenant channel (if tenant user)
     * - System admin channel (if system admin or for system admin visibility)
     */
    public function broadcastOn(): array
    {
        $channels = [];

        // If user belongs to a tenant, broadcast to tenant channel
        if ($this->tenantId) {
            $channels[] = $this->getTenantChannel('security-alerts');
        }

        // Also broadcast to system admin channel for monitoring
        $channels[] = new PrivateChannel('system.admin.security-alerts');

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'account.suspended';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
                'tenant_id' => $this->user->tenant_id,
            ],
            'suspended_until' => $this->suspendedUntil,
            'reason' => $this->reason,
            'ip_address' => $this->ipAddress,
            'timestamp' => now()->toIso8601String(),
            'severity' => 'warning',
            'message' => "Account '{$this->user->username}' has been suspended until {$this->suspendedUntil}. Reason: {$this->reason}",
        ];
    }
}
