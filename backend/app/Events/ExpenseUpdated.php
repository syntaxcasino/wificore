<?php

namespace App\Events;

use App\Models\Expense;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ExpenseUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public $connection = 'database';
    public $queue = 'broadcasts';

    public $data;
    public $changes;
    public $tenantId;

    public function __construct(Expense $expense, array $changes = [], ?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->changes = $changes;
        
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
        return 'expenseupdated';
    }

    public function broadcastWith(): array
    {
        return [
            'expense' => $this->data,
            'changes' => $this->changes,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
