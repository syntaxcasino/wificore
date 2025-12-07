<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class TodoDeleted implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, InteractsWithQueue;
    
    public $connection = 'database';
    public $queue = 'broadcasts';

    public $todoId;
    public $userId;
    public $tenantId;

    public function __construct(string $todoId, ?string $userId, string $tenantId)
    {
        $this->todoId = $todoId;
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        
        \Log::info("📡 TodoDeleted event constructed", [
            'todo_id' => $todoId,
            'user_id' => $userId,
            'tenant_id' => $tenantId
        ]);
    }

    public function broadcastOn(): array
    {
        $channels = [];
        
        if ($this->tenantId) {
            $channels[] = new PrivateChannel('tenant.' . $this->tenantId . '.todos');
        }

        if ($this->userId) {
            $channels[] = new PrivateChannel('user.' . $this->userId . '.todos');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'todo.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'todoId' => $this->todoId,
            'userId' => $this->userId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
