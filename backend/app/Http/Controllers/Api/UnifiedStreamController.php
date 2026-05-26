<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\TenantContext;
use App\Services\VictoriaMetricsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\QueueMetricsService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unified SSE Stream Controller
 *
 * Consolidates all Server-Sent Events streams into a single endpoint
 * with tenant isolation and usage-based tagging.
 *
 * SECURITY: All streams are strictly tenant-isolated. Users can only
 * access data for their assigned tenant. No tenant ID can be passed
 * via request parameters - only from authenticated user context.
 */
class UnifiedStreamController extends Controller
{
    private const STREAM_TIMEOUT = 300; // 5 minutes max connection
    private const HEARTBEAT_INTERVAL = 30; // Send heartbeat every 30s
    private const UPDATE_INTERVAL = 5; // Data update interval in seconds

    /**
     * Valid stream types and their tags
     */
    private const VALID_STREAMS = [
        'router-status' => [
            'description' => 'Real-time router online/offline status updates',
            'data_source' => 'database',
            'update_interval' => 30,
        ],
        'live-connections' => [
            'description' => 'Aggregated PPPoE and Hotspot connection statistics',
            'data_source' => 'database+victoria_metrics',
            'update_interval' => 5,
        ],
        'router-metrics' => [
            'description' => 'Router traffic and resource metrics from VictoriaMetrics',
            'data_source' => 'victoria_metrics',
            'update_interval' => 10,
        ],
        'system-health' => [
            'description' => 'System health metrics (queue, database, cache)',
            'data_source' => 'database',
            'update_interval' => 30,
        ],
    ];

    /**
     * Unified SSE Stream Endpoint
     *
     * Query Parameters:
     * - stream: Comma-separated list of stream types (e.g., "router-status,live-connections")
     * - format: Response format - 'sse' (default) or 'json' for single fetch
     *
     * Security:
     * - Tenant ID is ALWAYS derived from authenticated user only
     * - No tenant parameter accepted via request
     * - All database queries are scoped to user's tenant
     *
     * @param Request $request
     * @return StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function stream(Request $request, VictoriaMetricsClient $vm): StreamedResponse
    {
        $user = Auth::user();

        // Authentication required
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Get tenant from authenticated user - NEVER from request params
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return $this->errorResponse('No tenant assigned to user', 403);
        }

        // Verify tenant exists and is active
        $tenant = Tenant::where('id', $tenantId)
            ->whereRaw('is_active = true')
            ->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant not found or inactive', 403);
        }

        // Authorization check
        if (!$this->authorizeTenantAccess($user, $tenant)) {
            return $this->errorResponse('Access denied to this tenant', 403);
        }

        // Parse requested stream types
        $streamTypes = $this->parseStreamTypes($request->input('stream', 'router-status'));

        // Validate stream types
        $invalidStreams = array_diff($streamTypes, array_keys(self::VALID_STREAMS));
        if (!empty($invalidStreams)) {
            return $this->errorResponse(
                'Invalid stream types: ' . implode(', ', $invalidStreams) .
                '. Valid types: ' . implode(', ', array_keys(self::VALID_STREAMS)),
                400
            );
        }

        $lastEventId = $request->header('Last-Event-ID', 0);
        $startTime = time();

        Log::info('Unified SSE stream started', [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'streams' => $streamTypes,
            'ip' => $request->ip(),
        ]);

        return response()->stream(function () use ($tenant, $tenantId, $streamTypes, $lastEventId, $startTime, $vm) {
            try {
                // Set tenant context - scoped strictly to this tenant
                $tenantContext = app(TenantContext::class);
                $tenantContext->setTenant($tenant);
                DB::statement('SET search_path TO ' . $this->quoteSchemaName($tenant->schema_name) . ', public');

                $eventId = (int) $lastEventId;
                $lastUpdate = [];

                // Send initial state for all requested streams
                foreach ($streamTypes as $streamType) {
                    $data = $this->fetchStreamData($streamType, $tenant, $tenantId, $vm);
                    $this->sendEvent($streamType, 'initial', $eventId++, $data);
                    $lastUpdate[$streamType] = time();
                }

                // Send heartbeat
                $this->sendEvent('system', 'heartbeat', $eventId++, ['timestamp' => now()->toIso8601String()]);

                // Stream loop
                while (true) {
                    // Check max connection duration
                    if (time() - $startTime > self::STREAM_TIMEOUT) {
                        $this->sendEvent('system', 'complete', $eventId++, [
                            'message' => 'Stream timeout reached',
                            'duration' => self::STREAM_TIMEOUT,
                        ]);
                        break;
                    }

                    // Check connection aborted
                    if (connection_aborted()) {
                        Log::debug('SSE connection aborted by client', [
                            'tenant_id' => $tenantId,
                            'streams' => $streamTypes,
                        ]);
                        break;
                    }

                    // Update streams based on their intervals
                    foreach ($streamTypes as $streamType) {
                        $interval = self::VALID_STREAMS[$streamType]['update_interval'] ?? self::UPDATE_INTERVAL;

                        if (time() - ($lastUpdate[$streamType] ?? 0) >= $interval) {
                            $data = $this->fetchStreamData($streamType, $tenant, $tenantId, $vm);

                            // Only send if data changed (optimization)
                            $this->sendEvent($streamType, 'update', $eventId++, $data);
                            $lastUpdate[$streamType] = time();
                        }
                    }

                    // Send heartbeat every 30 seconds
                    if (((time() - $startTime) % self::HEARTBEAT_INTERVAL) === 0) {
                        $this->sendEvent('system', 'heartbeat', $eventId++, [
                            'timestamp' => now()->toIso8601String(),
                            'active_streams' => $streamTypes,
                        ]);
                    }

                    // Sleep to prevent CPU spinning
                    sleep(1);
                }
            } finally {
                // Cleanup
                DB::statement('SET search_path TO public');

                Log::info('Unified SSE stream ended', [
                    'tenant_id' => $tenantId,
                    'streams' => $streamTypes,
                    'duration' => time() - $startTime,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Get available stream types (for frontend discovery)
     */
    public function getAvailableStreams(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'streams' => self::VALID_STREAMS,
        ]);
    }

    /**
     * Parse stream types from request parameter
     */
    private function parseStreamTypes(string $streamParam): array
    {
        $types = array_map('trim', explode(',', $streamParam));
        return array_filter($types);
    }

    /**
     * Fetch data for a specific stream type
     *
     * SECURITY: All queries are scoped to the authenticated user's tenant
     */
    private function fetchStreamData(string $streamType, Tenant $tenant, string $tenantId, VictoriaMetricsClient $vm): array
    {
        try {
            switch ($streamType) {
                case 'router-status':
                    return $this->fetchRouterStatus($tenant, $tenantId);

                case 'live-connections':
                    return $this->fetchLiveConnections($tenantId, $vm);

                case 'router-metrics':
                    return $this->fetchRouterMetrics($tenantId, $vm);

                case 'system-health':
                    return $this->fetchSystemHealth($tenantId);

                default:
                    return ['error' => 'Unknown stream type'];
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch stream data', [
                'stream_type' => $streamType,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Failed to fetch data', 'stream_type' => $streamType];
        }
    }

    /**
     * Fetch router status data
     *
     * SECURITY: Strictly filtered by tenant_id
     */
    private function fetchRouterStatus(Tenant $tenant, string $tenantId): array
    {
        // Query from tenant schema (search_path isolation)
        $routers = Router::select(['id', 'name', 'ip_address', 'status', 'vpn_status', 'last_seen', 'model', 'os_version', 'vpn_last_handshake'])
            ->get();

        return [
            'routers' => $routers,
            'total' => $routers->count(),
            'online' => $routers->where('status', 'online')->count(),
            'offline' => $routers->where('status', 'offline')->count(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Fetch live connections data (PPPoE + Hotspot)
     *
     * SECURITY: Uses tenant schema for RADIUS data
     */
    private function fetchLiveConnections(string $tenantId, VictoriaMetricsClient $vm): array
    {
        // PPPoE Sessions from RADIUS (tenant schema)
        $pppoeSessions = [];
        if (Schema::hasTable('radacct')) {
            $pppoeSessions = DB::table('radacct')
                ->whereNull('acctstoptime')
                ->select([
                    'acctsessionid',
                    'username',
                    'acctsessiontime',
                    'acctinputoctets',
                    'acctoutputoctets',
                    'framedipaddress',
                    'callingstationid',
                    'acctstarttime',
                ])
                ->orderByDesc('acctstarttime')
                ->limit(100)
                ->get();
        }

        // Hotspot Sessions from tenant schema
        $hotspotSessions = [];
        if (Schema::hasTable('hotspot_sessions')) {
            $hotspotSessions = DB::table('hotspot_sessions')
                ->where('is_active', true)
                ->select([
                    'id',
                    'hotspot_user_id',
                    'mac_address',
                    'ip_address',
                    'session_start',
                    'last_activity',
                ])
                ->orderByDesc('session_start')
                ->limit(100)
                ->get();
        }

        // Calculate stats
        $totalConnections = $pppoeSessions->count() + $hotspotSessions->count();

        return [
            'pppoe_sessions' => $pppoeSessions,
            'hotspot_sessions' => $hotspotSessions,
            'total' => $totalConnections,
            'pppoe_count' => $pppoeSessions->count(),
            'hotspot_count' => $hotspotSessions->count(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Fetch router metrics from VictoriaMetrics
     *
     * SECURITY: Queries filtered by tenant_id label
     */
    private function fetchRouterMetrics(string $tenantId, VictoriaMetricsClient $vm): array
    {
        try {
            $tenantIdEscaped = $this->escapeLabelValue($tenantId);

            // Query for all routers in this tenant
            $selector = sprintf('tenant_id="%s"', $tenantIdEscaped);

            $trafficQuery = sprintf('rate(interface_counters_ifHCInOctets{%s}[5m])', $selector);
            $trafficData = $vm->queryInstant($trafficQuery);

            return [
                'traffic' => $trafficData['data']['result'] ?? [],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::warning('VictoriaMetrics query failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Metrics unavailable', 'timestamp' => now()->toIso8601String()];
        }
    }

    /**
     * Fetch system health metrics
     *
     * SECURITY: Tenant-scoped metrics only
     */
    private function fetchSystemHealth(string $tenantId): array
    {
        // Queue stats
        $queueMetrics = app(QueueMetricsService::class)->getRealtimeMetrics();
        $queueStats = [
            'pending' => $queueMetrics['pending_jobs'],
            'processing' => $queueMetrics['processing_jobs'],
            'delayed' => $queueMetrics['delayed_jobs'],
            'failed' => $queueMetrics['failed_jobs'],
            'workers' => $queueMetrics['active_workers'],
        ];

        // Connection counts
        $dbConnections = DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE datname = current_database()")[0]->count ?? 0;

        return [
            'queue' => $queueStats,
            'database_connections' => $dbConnections,
            'cache_status' => extension_loaded('redis') ? 'connected' : 'unavailable',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Authorize tenant access
     *
     * SECURITY: User must belong to the tenant they request data for
     */
    private function authorizeTenantAccess($user, Tenant $tenant): bool
    {
        if ($user->tenant_id !== $tenant->id) {
            Log::warning('SSE unauthorized access attempt', [
                'user_id' => $user->id,
                'user_tenant' => $user->tenant_id,
                'requested_tenant' => $tenant->id,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Send SSE event with tagging
     */
    private function sendEvent(string $tag, string $event, int $id, array $data): void
    {
        echo "id: {$id}\n";
        echo "event: {$event}\n";
        echo "data: " . json_encode([
            'tag' => $tag,
            'payload' => $data,
        ]) . "\n\n";
        ob_flush();
        flush();
    }

    /**
     * Send error response
     */
    private function errorResponse(string $message, int $status): StreamedResponse
    {
        return response()->stream(function () use ($message) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => $message]) . "\n\n";
            ob_flush();
            flush();
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Escape label value for VictoriaMetrics queries
     */
    private function escapeLabelValue(string $value): string
    {
        return str_replace(["\\", '"'], ["\\\\", '\\"'], $value);
    }

    private function quoteSchemaName(string $schemaName): string
    {
        if (!preg_match('/^[a-z0-9_]{1,63}$/', $schemaName)) {
            throw new \InvalidArgumentException('Invalid tenant schema name');
        }

        return '"' . str_replace('"', '""', $schemaName) . '"';
    }
}
