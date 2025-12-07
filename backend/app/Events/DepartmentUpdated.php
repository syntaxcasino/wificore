<?php

namespace App\Events;

use App\Models\Department;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class DepartmentUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $departmentData;
    public $changes;
    public $tenantId;

    public function __construct(Department $department, array $changes = [], ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->changes = $changes;
        
        $this->departmentData = [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'status' => $department->status,
            'is_active' => $department->is_active,
            'updated_at' => $department->updated_at?->toIso8601String(),
        ];
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
        return 'department.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'department' => $this->departmentData,
            'changes' => $this->changes,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
