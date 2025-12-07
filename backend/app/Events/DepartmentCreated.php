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

class DepartmentCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $departmentData;
    public $tenantId;

    public function __construct(Department $department, ?string $tenantId = null)
    {
        // Tenant ID comes from authenticated user context
        $this->tenantId = $tenantId;
        
        $this->departmentData = [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'description' => $department->description,
            'manager_id' => $department->manager_id,
            'budget' => $department->budget,
            'location' => $department->location,
            'status' => $department->status,
            'is_active' => $department->is_active,
            'employee_count' => $department->employee_count,
            'manager' => $department->manager ? [
                'id' => $department->manager->id,
                'full_name' => $department->manager->full_name,
                'employee_number' => $department->manager->employee_number,
            ] : null,
            'created_at' => $department->created_at?->toIso8601String(),
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
        return 'department.created';
    }

    public function broadcastWith(): array
    {
        return [
            'department' => $this->departmentData,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
