<?php

namespace App\Events;

use App\Models\Employee;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmployeeUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $changes;
    public $tenantId;

    public function __construct(Employee $employee, array $changes = [], ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->changes = $changes;
        
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
        return 'employeeupdated';
    }

    public function broadcastWith(): array
    {
        return [
            'employee' => $this->data,
            'changes' => $this->changes,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
