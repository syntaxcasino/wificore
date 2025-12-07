<?php

namespace App\Events;

use App\Models\Employee;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmployeeCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $tenantId;

    public function __construct(Employee ${strtolower(Employee)}, ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        
        $this->data = [
            'id' => ${strtolower(Employee)}->id,
            'created_at' => ${strtolower(Employee)}->created_at?->toIso8601String(),
            'updated_at' => ${strtolower(Employee)}->updated_at?->toIso8601String(),
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
