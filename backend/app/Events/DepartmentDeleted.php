<?php

namespace App\Events;

use App\Models\Department;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class DepartmentDeleted implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $tenantId;

    public function __construct(string $id, array $data, ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        
        $this->data = array_merge(['id' => $id], $data);
    }

    public function broadcastOn(): array
    {
        $channels = [];
        if ($this->tenantId) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.departments");
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'departmentdeleted';
    }

    public function broadcastWith(): array
    {
        return [
            'department' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
