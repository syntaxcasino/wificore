<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LogRotationCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Log rotation results or message.
     *
     * @var array|string
     */
    public $results;

    /**
     * Create a new event instance.
     *
     * @param array|string $results
     */
    public function __construct(array|string $results)
    {
        // Normalize results to an array
        $this->results = is_array($results)
            ? $results
            : [['message' => $results, 'status' => 'info']];

        // Safely extract info for logging
        $rotatedFiles = array_filter(array_column($this->results, 'file') ?? []);
        $successCount = count(array_filter($this->results, fn($r) => ($r['status'] ?? null) === 'success'));
        $errorCount = count(array_filter($this->results, fn($r) => ($r['status'] ?? null) === 'error'));

        Log::info('LogRotationCompleted event created', [
            'rotated_files' => $rotatedFiles,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'raw_results' => $this->results,
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('router-updates'),
        ];
    }

    /**
     * Get the event name for broadcasting.
     */
    public function broadcastAs(): string
    {
        return 'LogRotationCompleted';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return ['results' => $this->results];
    }
}
