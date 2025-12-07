<?php

namespace App\Events;

use App\Models\Todo;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class TodoUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, InteractsWithQueue;
    
    public $connection = 'database';
    public $queue = 'broadcasts';

    public $todoData;
    public $changes;
    public $tenantId;

    public function __construct(Todo $todo, array $changes = [], ?string $tenantId = null)
    {
        // Tenant ID comes from authenticated user context
        $this->tenantId = $tenantId ?? auth()->user()?->tenant_id;
        $this->changes = $changes;
        
        $this->todoData = [
            'id' => $todo->id,
            'title' => $todo->title,
            'description' => $todo->description,
            'priority' => $todo->priority,
            'status' => $todo->status,
            'due_date' => $todo->due_date?->format('Y-m-d'),
            'completed_at' => $todo->completed_at?->toIso8601String(),
            'user_id' => $todo->user_id,
            'related_type' => $todo->related_type,
            'related_id' => $todo->related_id,
            'metadata' => $todo->metadata,
            'creator' => $todo->creator ? [
                'id' => $todo->creator->id,
                'name' => $todo->creator->name,
                'email' => $todo->creator->email,
            ] : null,
            'user' => $todo->user ? [
                'id' => $todo->user->id,
                'name' => $todo->user->name,
                'email' => $todo->user->email,
            ] : null,
            'created_at' => $todo->created_at->toIso8601String(),
            'updated_at' => $todo->updated_at->toIso8601String(),
        ];
        
        \Log::info("📡 TodoUpdated event constructed", [
            'todo_id' => $todo->id,
            'tenant_id' => $this->tenantId,
            'changes' => array_keys($changes)
        ]);
    }

    public function broadcastOn(): array
    {
        $channels = [];
        
        if ($this->tenantId) {
            $channels[] = new PrivateChannel('tenant.' . $this->tenantId . '.todos');
        }

        if (!empty($this->todoData['user_id'])) {
            $channels[] = new PrivateChannel('user.' . $this->todoData['user_id'] . '.todos');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'todo.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'todo' => $this->todoData,
            'changes' => $this->changes,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
