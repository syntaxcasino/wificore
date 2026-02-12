<?php

namespace App\Events;

use App\Models\Employee;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmployeeCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $tenantId;

    public function __construct(Employee $employee, ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        
        $this->data = [
            'id' => $employee->id,
            'created_at' => $employee->created_at?->toIso8601String(),
            'updated_at' => $employee->updated_at?->toIso8601String(),
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [];
        if ($this->tenantId) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.employees");
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'employeecreated';
    }

    public function broadcastWith(): array
    {
        return [
            'employee' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
