<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * SsePublisher
 *
 * Publishes structured event payloads to Redis pub/sub channels so that
 * the SSE controllers can stream them to clients instantly — no DB polling.
 *
 * Channel naming mirrors the broadcast channel names used by the WebSocket
 * layer so both transports share the same semantic model.
 *
 * Redis key format: sse:{channel}
 * e.g. sse:tenant.{tenantId}.router-updates
 *      sse:system.admin
 */
class SsePublisher
{
    /**
     * Publish an event payload to a Redis SSE channel.
     *
     * @param  string  $channel   Logical channel name (without sse: prefix)
     * @param  string  $event     Event type identifier (e.g. RouterStatusUpdated)
     * @param  array   $payload   Event data
     */
    public static function publish(string $channel, string $event, array $payload): void
    {
        try {
            $message = json_encode([
                'event'   => $event,
                'channel' => $channel,
                'data'    => $payload,
                'ts'      => now()->toIso8601String(),
            ]);

            $redisChannel = "sse:{$channel}";

            Log::debug('SsePublisher: Publishing event', [
                'redis_channel' => $redisChannel,
                'event' => $event,
                'channel' => $channel,
            ]);

            $result = Redis::publish($redisChannel, $message);

            Log::debug('SsePublisher: Published successfully', [
                'redis_channel' => $redisChannel,
                'subscribers' => $result,
            ]);

        } catch (\Throwable $e) {
            // SSE publish is best-effort — never block the main request
            Log::warning('SsePublisher: failed to publish event', [
                'channel' => $channel,
                'event'   => $event,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish to the tenant-scoped channel.
     */
    public static function publishToTenant(string $tenantId, string $channel, string $event, array $payload): void
    {
        static::publish("tenant.{$tenantId}.{$channel}", $event, $payload);
    }

    /**
     * Publish to the system-admin channel.
     */
    public static function publishToSystemAdmin(string $event, array $payload): void
    {
        static::publish('system.admin', $event, $payload);
    }
}
