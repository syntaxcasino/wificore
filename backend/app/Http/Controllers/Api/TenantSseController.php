<?php

namespace App\Http\Controllers\Api;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TenantSseController
 *
 * Event-driven SSE endpoint for tenant users.
 *
 * Design:
 * - Uses Redis SUBSCRIBE instead of DB polling — zero CPU/DB spin
 * - A single persistent connection per browser tab
 * - The client passes a comma-separated list of sub-channels to subscribe to
 *   e.g. GET /api/sse/tenant?channels=router-updates,dashboard-stats,packages
 * - The server resolves them to Redis channels: sse:tenant.{id}.{sub}
 * - On connect, an initial state snapshot is pushed for supported channels
 *
 * Security:
 * - tenantId is ALWAYS read from the authenticated user — never from request
 * - All Redis channel names include the tenant UUID, preventing cross-tenant data leaks
 */
class TenantSseController extends Controller
{
    private const MAX_DURATION   = 300;  // 5 min — client must reconnect (browser does this automatically)
    private const HEARTBEAT_SEC  = 25;   // Keep-alive ping interval

    /** Sub-channels that are valid for tenant SSE */
    private const VALID_CHANNELS = [
        'router-updates',
        'dashboard-stats',
        'packages',
        'payments',
        'pppoe-sessions',
        'hotspot-sessions',
        'pppoe-users',
        'hotspot-users',
        'users',
        'settings',
        'security-alerts',
        'todos',
        'access-points',
        'expenses',
        'revenues',
        'departments',
        'employees',
        'positions',
        'vouchers',
    ];

    public function stream(Request $request): StreamedResponse
    {
        $user = Auth::user();

        if (!$user) {
            return $this->errorStream('Authentication required', 401);
        }

        $tenantId = $user->tenant_id;
        if (!$tenantId) {
            return $this->errorStream('No tenant assigned', 403);
        }

        $tenant = Tenant::where('id', $tenantId)->where('is_active', true)->first();
        if (!$tenant) {
            return $this->errorStream('Tenant not found or inactive', 403);
        }

        // Parse and validate requested sub-channels
        $requested = array_filter(array_map('trim', explode(',', $request->input('channels', 'router-updates,dashboard-stats'))));
        $invalid   = array_diff($requested, self::VALID_CHANNELS);
        if ($invalid) {
            return $this->errorStream('Invalid channels: ' . implode(', ', $invalid), 400);
        }

        // Build full Redis channel names: sse:tenant.{uuid}.{sub}
        $redisChannels = array_map(fn($sub) => "sse:tenant.{$tenantId}.{$sub}", $requested);

        Log::info('Tenant SSE stream opened', [
            'user_id'   => $user->id,
            'tenant_id' => $tenantId,
            'channels'  => $requested,
            'ip'        => $request->ip(),
        ]);

        return response()->stream(
            function () use ($tenant, $tenantId, $redisChannels, $requested) {
                // Set tenant schema context for any initial-state DB queries
                $ctx = app(TenantContext::class);
                $ctx->setTenant($tenant);
                DB::statement('SET search_path TO ?, public', [$tenant->schema_name]);

                $eventId   = 0;
                $startTime = time();

                // Push initial state snapshot
                $this->sendInitialState($tenantId, $requested, $eventId);

                // Heartbeat before blocking subscribe
                $this->sendHeartbeat($eventId++);

                // Create a dedicated Redis connection for blocking SUBSCRIBE
                $redis = Redis::connection('default')->client();

                // Subscribe to all requested channels
                $redis->subscribe($redisChannels, function ($message, $channel) use (
                    &$eventId, $startTime, $redis, $tenantId
                ) {
                    // Hard timeout — force client reconnect
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

                    // Security: verify tenantId in payload matches the authenticated tenant
                    if (isset($payload['data']['tenant_id']) && (string) $payload['data']['tenant_id'] !== (string) $tenantId) {
                        Log::warning('SSE: tenant mismatch in Redis message', [
                            'expected' => $tenantId,
                            'got'      => $payload['data']['tenant_id'],
                        ]);
                        return;
                    }

                    $this->sseWrite($payload['event'] ?? 'update', $eventId++, [
                        'channel' => $payload['channel'] ?? '',
                        'data'    => $payload['data'] ?? [],
                        'ts'      => $payload['ts']   ?? now()->toIso8601String(),
                    ]);
                });

                DB::statement('SET search_path TO public');

                Log::info('Tenant SSE stream closed', [
                    'tenant_id' => $tenantId,
                    'duration'  => time() - $startTime,
                ]);
            },
            200,
            $this->sseHeaders()
        );
    }

    // -------------------------------------------------------------------------
    // Initial state snapshots (sent once on connect)
    // -------------------------------------------------------------------------

    private function sendInitialState(string $tenantId, array $subChannels, int &$eventId): void
    {
        foreach ($subChannels as $sub) {
            $data = match ($sub) {
                'router-updates' => $this->initialRouterStatus($tenantId),
                default          => null,
            };

            if ($data !== null) {
                $this->sseWrite("initial.{$sub}", $eventId++, $data);
            }
        }
    }

    private function initialRouterStatus(string $tenantId): array
    {
        $routers = Router::where('tenant_id', $tenantId)
            ->select(['id', 'name', 'status', 'vpn_status', 'last_seen', 'ip_address'])
            ->get();

        return [
            'routers' => $routers->toArray(),
            'online'  => $routers->where('status', 'online')->count(),
            'offline' => $routers->where('status', 'offline')->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // SSE helpers
    // -------------------------------------------------------------------------

    private function sendHeartbeat(int $id): void
    {
        $this->sseWrite('heartbeat', $id, ['ts' => now()->toIso8601String()]);
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
