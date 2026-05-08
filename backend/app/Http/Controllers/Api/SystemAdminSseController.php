<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SystemAdminSseController
 *
 * Event-driven SSE for system-admin (landlord) users.
 * Subscribes to:
 *   sse:system.admin      — TenantCreated, UserCreated, UserDeleted, SystemAlert …
 *   sse:system.tenants    — TenantCreated
 *
 * Security: Only users with role=system_admin and tenant_id=NULL may connect.
 */
class SystemAdminSseController extends \App\Http\Controllers\Controller
{
    private const MAX_DURATION  = 300;
    private const VALID_CHANNELS = ['system.admin', 'system.tenants', 'system.metrics', 'system.queue-stats'];

    public function stream(Request $request): StreamedResponse
    {
        $user = Auth::user();

        if (!$user) {
            return $this->errorStream('Authentication required', 401);
        }

        if ($user->role !== 'system_admin' || $user->tenant_id !== null) {
            return $this->errorStream('System administrator access required', 403);
        }

        $requested = array_filter(array_map('trim', explode(',', $request->input('channels', 'system.admin,system.tenants'))));
        $invalid   = array_diff($requested, self::VALID_CHANNELS);
        if ($invalid) {
            return $this->errorStream('Invalid channels: ' . implode(', ', $invalid), 400);
        }

        $redisChannels = array_map(fn($ch) => "sse:{$ch}", $requested);

        Log::info('System-admin SSE stream opened', [
            'user_id'  => $user->id,
            'channels' => $requested,
            'ip'       => $request->ip(),
        ]);

        return response()->stream(
            function () use ($redisChannels, $requested, $user) {
                $eventId   = 0;
                $startTime = time();

                // Initial heartbeat
                $this->sseWrite('heartbeat', $eventId++, ['ts' => now()->toIso8601String()]);

                $redis = Redis::connection('default')->client();

                $redis->subscribe($redisChannels, function ($message, $channel) use (
                    &$eventId, $startTime, $redis
                ) {
                    if (time() - $startTime > self::MAX_DURATION) {
                        $this->sseWrite('timeout', $eventId++, ['message' => 'Stream timeout — reconnecting']);
                        $redis->close();
                        return;
                    }

                    if (connection_aborted()) {
                        $redis->close();
                        return;
                    }

                    $payload = json_decode($message, true);
                    if (!$payload) {
                        return;
                    }

                    $this->sseWrite($payload['event'] ?? 'update', $eventId++, [
                        'channel' => $payload['channel'] ?? '',
                        'data'    => $payload['data']    ?? [],
                        'ts'      => $payload['ts']      ?? now()->toIso8601String(),
                    ]);
                });

                Log::info('System-admin SSE stream closed', [
                    'user_id'  => $user->id,
                    'duration' => time() - $startTime,
                ]);
            },
            200,
            $this->sseHeaders()
        );
    }

    private function sseWrite(string $event, int $id, array $data): void
    {
        echo "id: {$id}\n";
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate, private',
            'Pragma'            => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ];
    }

    private function errorStream(string $message, int $status): StreamedResponse
    {
        return response()->stream(function () use ($message) {
            echo "event: error\n";
            echo 'data: ' . json_encode(['error' => $message]) . "\n\n";
            ob_flush();
            flush();
        }, $status, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }
}
