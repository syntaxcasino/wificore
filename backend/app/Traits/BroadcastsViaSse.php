<?php

namespace App\Traits;

use App\Services\SsePublisher;

/**
 * BroadcastsViaSse
 *
 * Drop this trait into any ShouldBroadcast event to automatically
 * publish the event payload to Redis SSE channels alongside the
 * existing WebSocket broadcast.
 *
 * The trait hooks into broadcastWith() via broadcastAndPublishSse().
 * Call broadcastAndPublishSse() from the event's broadcastWith() method:
 *
 *   public function broadcastWith(): array
 *   {
 *       $data = [...];
 *       $this->publishSse($data);
 *       return $data;
 *   }
 *
 * Or override broadcastOn() channels are read automatically if the event
 * uses the BroadcastsToTenant trait (tenantId property is expected).
 */
trait BroadcastsViaSse
{
    /**
     * Publish to all SSE channels that mirror the WS broadcast channels.
     * Call this inside broadcastWith() of the event.
     */
    protected function publishSse(array $data): void
    {
        $eventName = method_exists($this, 'broadcastAs')
            ? $this->broadcastAs()
            : class_basename(static::class);

        // Publish to tenant channel when tenantId is available
        if (property_exists($this, 'tenantId') && $this->tenantId) {
            // Derive the sub-channel from broadcastOn() channel names
            foreach ($this->broadcastOn() as $channel) {
                $channelName = $this->resolveChannelName($channel);
                if ($channelName) {
                    SsePublisher::publish($channelName, $eventName, $data);
                }
            }
            return;
        }

        // System-admin events
        SsePublisher::publishToSystemAdmin($eventName, $data);
    }

    /**
     * Resolve the string channel name from a Channel object.
     */
    private function resolveChannelName($channel): ?string
    {
        if (is_string($channel)) {
            return $channel;
        }

        // PrivateChannel / Channel objects expose their name
        if (isset($channel->name)) {
            return $channel->name;
        }

        return null;
    }
}
