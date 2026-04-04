<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class RouterMetricsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $tenantId;
    public string $routerId;
    public array $metrics;
    public string $metricType;
    public string $timeRange;

    /**
     * Create a new event instance.
     *
     * @param string $tenantId
     * @param string $routerId
     * @param array $metrics
     * @param string $metricType 'traffic' or 'resources'
     * @param string $timeRange
     */
    public function __construct(
        string $tenantId,
        string $routerId,
        array $metrics,
        string $metricType = 'traffic',
        string $timeRange = '1h'
    ) {
        $this->tenantId = $tenantId;
        $this->routerId = $routerId;
        $this->metrics = $metrics;
        $this->metricType = $metricType;
        $this->timeRange = $timeRange;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn(): Channel|array
    {
        return new PrivateChannel("tenant.{$this->tenantId}.router.{$this->routerId}");
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'router.metrics.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'router_id' => $this->routerId,
            'metric_type' => $this->metricType,
            'time_range' => $this->timeRange,
            'metrics' => $this->metrics,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
