<?php

namespace App\Events;

use App\Models\TodoActivity;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class TodoActivityCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, InteractsWithQueue;
    
    public $connection = 'database';
    public $queue = 'broadcasts';

    public $activityData;
    public $todoId;
    public $tenantId;

    public function __construct(TodoActivity $activity)
    {
        $this->todoId = $activity->todo_id;
        $this->tenantId = $activity->todo->user?->tenant_id ?? tenant('id');
        
        $this->activityData = [
            'id' => $activity->id,
            'todo_id' => $activity->todo_id,
            'user_id' => $activity->user_id,
            'action' => $activity->action,
            'old_value' => $activity->old_value,
            'new_value' => $activity->new_value,
            'description' => $activity->description,
            'user' => $activity->user ? [
                'id' => $activity->user->id,
                'name' => $activity->user->name,
                'email' => $activity->user->email,
            ] : null,
            'created_at' => $activity->created_at->toIso8601String(),
        ];
        
        \Log::info("📡 TodoActivityCreated event constructed", [
            'activity_id' => $activity->id,
            'todo_id' => $this->todoId,
            'action' => $activity->action
        ]);
    }

    public function broadcastOn(): array
    {
        $channels = [];
        
        if ($this->tenantId) {
            $channels[] = new PrivateChannel('tenant.' . $this->tenantId . '.todos');
        }

        // Also broadcast to specific todo channel for real-time activity updates
        $channels[] = new PrivateChannel('todo.' . $this->todoId . '.activities');

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'todo.activity.created';
    }

    public function broadcastWith(): array
    {
        return [
            'activity' => $this->activityData,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
