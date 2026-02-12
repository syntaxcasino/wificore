<?php

namespace App\Events;

use App\Models\HotspotCredential;
use App\Traits\BroadcastsToTenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class CredentialsSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;
    use BroadcastsToTenant;

    public array $credentialData;
    public $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(HotspotCredential $credential, ?string $tenantId = null)
    {
        // Extract data instead of serializing the model
        // HotspotCredential is in tenant schema; SerializesModels fails on deserialization
        $this->credentialData = [
            'phone_number' => $credential->phone_number,
            'sms_status' => $credential->sms_status,
            'sms_sent_at' => $credential->sms_sent_at,
        ];
        $this->tenantId = $tenantId ?? (auth()->user()?->tenant_id);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        if ($this->tenantId) {
            return $this->getTenantChannels(['dashboard-stats']);
        }
        return [new PrivateChannel('dashboard-stats')];
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
                'phone_number' => $this->credentialData['phone_number'],
                'sms_status' => $this->credentialData['sms_status'],
                'sent_at' => $this->credentialData['sms_sent_at'],
            ],
            'message' => 'Credentials sent via SMS',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
