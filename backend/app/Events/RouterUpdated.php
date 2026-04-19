<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RouterUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public array $router,
        public string $tenantId
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new \Illuminate\Broadcasting\PrivateChannel('tenant.' . $this->tenantId . '.routers'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'RouterUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'router' => $this->router,
            'tenantId' => $this->tenantId,
        ];
    }
}
