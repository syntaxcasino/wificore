<?php

namespace App\Events;

use App\Models\HotspotUser;
use App\Models\Payment;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class HotspotUserCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $hotspotUserData;
    public array $paymentData;
    public array $credentials;
    public $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(HotspotUser $hotspotUser, Payment $payment, array $credentials, ?string $tenantId = null)
    {
        // Extract data instead of serializing models
        // Both models are in tenant schema; SerializesModels fails on deserialization
        $this->hotspotUserData = [
            'id' => $hotspotUser->id,
            'username' => $hotspotUser->username,
            'phone_number' => $hotspotUser->phone_number,
            'package_name' => $hotspotUser->package_name,
            'subscription_expires_at' => $hotspotUser->subscription_expires_at,
        ];
        $this->paymentData = [
            'id' => $payment->id,
            'amount' => $payment->amount,
        ];
        $this->credentials = $credentials;
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
                'id' => $this->hotspotUserData['id'],
                'username' => $this->hotspotUserData['username'],
                'phone_number' => $this->hotspotUserData['phone_number'],
                'package_name' => $this->hotspotUserData['package_name'],
                'expires_at' => $this->hotspotUserData['subscription_expires_at'],
            ],
            'payment' => $this->paymentData,
            'message' => 'New hotspot user created',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
