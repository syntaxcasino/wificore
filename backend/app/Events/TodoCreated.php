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

class TodoCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, InteractsWithQueue;
    
    /**
     * The name of the queue connection to use when broadcasting the event.
     */
    public $connection = 'database';
    
    /**
     * The name of the queue on which to place the broadcasting job.
     */
    public $queue = 'broadcasts';

    public $todoData;
    public $tenantId;

    public function __construct(Todo $todo, ?string $tenantId = null)
    {
        // Don't serialize the model - extract data to avoid schema context issues
        // Tenant ID comes from authenticated user context
        $this->tenantId = $tenantId;
        
        $this->todoData = [
            'id' => $todo->id,
            'title' => $todo->title,
            'description' => $todo->description,
            'priority' => $todo->priority,
            'status' => $todo->status,
            'due_date' => $todo->due_date?->format('Y-m-d'),
            'user_id' => $todo->user_id,
            'related_type' => $todo->related_type,
            'related_id' => $todo->related_id,
            'metadata' => $todo->metadata,
            'creator' => $todo->creator ? [
                'id' => $todo->creator->id,
                'name' => $todo->creator->name,
                'email' => $todo->creator->email,
                'tenant_id' => $todo->creator->tenant_id,
            ] : null,
            'user' => $todo->user ? [
                'id' => $todo->user->id,
                'name' => $todo->user->name,
                'email' => $todo->user->email,
                'tenant_id' => $todo->user->tenant_id,
            ] : null,
            'created_at' => $todo->created_at->toIso8601String(),
            'updated_at' => $todo->updated_at->toIso8601String(),
        ];
        
        \Log::info("📡 TodoCreated event constructed", [
            'todo_id' => $todo->id,
            'tenant_id' => $this->tenantId,
            'will_broadcast_to' => 'tenant.' . $this->tenantId . '.todos'
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [];
        
        // Broadcast to tenant channel if we have tenant_id
        if ($this->tenantId) {
            $channels[] = new PrivateChannel('tenant.' . $this->tenantId . '.todos');
        }

        // Only add user channel if task is assigned
        if (!empty($this->todoData['user_id'])) {
            $channels[] = new PrivateChannel('user.' . $this->todoData['user_id'] . '.todos');
        }

        \Log::info("📡 TodoCreated broadcastOn() called", [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->todoData['user_id'] ?? null,
            'channels' => array_map(fn($ch) => $ch->name, $channels),
            'todo_id' => $this->todoData['id']
        ]);

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'todo.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $data = [
            'todo' => $this->todoData,
            'timestamp' => now()->toIso8601String(),
        ];
        
        \Log::info("📡 TodoCreated broadcastWith() called", [
            'todo_id' => $this->todoData['id'],
            'tenant_id' => $this->tenantId,
            'data_keys' => array_keys($data)
        ]);
        
        return $data;
    }
}
