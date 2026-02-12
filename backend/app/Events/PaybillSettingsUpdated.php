<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Paybill Settings Updated Event
 * 
 * Broadcast when tenant's Paybill settings are updated.
 */
class PaybillSettingsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $tenantId;
    public array $settings;
    public string $timestamp;

    public function __construct(string $tenantId, array $settings)
    {
        $this->tenantId = $tenantId;
        $this->settings = $settings;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.' . $this->tenantId . '.settings'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'paybill.settings.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'settings' => $this->settings,
            'timestamp' => $this->timestamp,
        ];
    }
}
