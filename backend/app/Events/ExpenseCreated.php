<?php

namespace App\Events;

use App\Models\Expense;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ExpenseCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $tenantId;

    public function __construct(Expense $expense, ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        
        $this->data = [
            'id' => $expense->id,
            'created_at' => $expense->created_at?->toIso8601String(),
            'updated_at' => $expense->updated_at?->toIso8601String(),
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [];
        if ($this->tenantId) {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.expenses");
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'expensecreated';
    }

    public function broadcastWith(): array
    {
        return [
            'expense' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
