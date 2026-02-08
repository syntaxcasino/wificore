<?php

namespace App\Events;

use App\Models\HotspotUser;
use App\Models\Payment;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HotspotUserCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use BroadcastsToTenant;

    public $hotspotUser;
    public $payment;
    public $credentials;
    public $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(HotspotUser $hotspotUser, Payment $payment, array $credentials, ?string $tenantId = null)
    {
        $this->hotspotUser = $hotspotUser;
        $this->payment = $payment;
        $this->credentials = $credentials;
        // Payment and HotspotUser are in tenant schema - no tenant_id column
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId) {
            return $this->getTenantChannels(['hotspot', 'hotspot-users', 'dashboard-stats']);
        }
        return [new PrivateChannel('hotspot-users')];
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
