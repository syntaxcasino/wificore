<?php

namespace App\Listeners;

use App\Services\SsePublisher;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Log;

/**
 * PublishEventToSse
 *
 * Universal listener that catches every ShouldBroadcast event fired by the
 * application and republishes it to Redis SSE channels alongside the normal
 * WebSocket broadcast.
 *
 * Registered in EventServiceProvider via Event::listen('*', ...) wildcard.
 *
 * Security:
 * - Channel names are taken directly from the event's broadcastOn() return
 *   value, exactly as the WebSocket layer uses them — no external input.
 * - The tenant UUID is embedded in the Redis channel key, preventing
 *   cross-tenant leakage.
 */
class PublishEventToSse
{
    /**
     * Handle any fired event.
     *
     * @param  string  $eventName  Fully-qualified class name of the event
     * @param  array   $payload    [$eventInstance]
     */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        // Only process events that also broadcast over WebSockets
        if (!$event instanceof ShouldBroadcast) {
            return;
        }

        try {
            $channels = $event->broadcastOn();
            if (empty($channels)) {
                return;
            }

            $broadcastEventName = method_exists($event, 'broadcastAs')
                ? $event->broadcastAs()
                : class_basename($eventName);

            $data = method_exists($event, 'broadcastWith')
                ? $event->broadcastWith()
                : [];

            foreach ((array) $channels as $channel) {
                $channelName = $this->resolveChannelName($channel);
                if ($channelName) {
                    SsePublisher::publish($channelName, $broadcastEventName, $data);
                }
            }

        } catch (\Throwable $e) {
            // Never let SSE publishing break the main application flow
            Log::warning('PublishEventToSse: failed', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve a Channel object or string to its string name.
     */
    private function resolveChannelName(mixed $channel): ?string
    {
        if (is_string($channel)) {
            return $channel;
        }

        // Laravel's Channel/PrivateChannel/PresenceChannel expose ->name
        if (is_object($channel) && isset($channel->name)) {
            return $channel->name;
        }

        return null;
    }
}
