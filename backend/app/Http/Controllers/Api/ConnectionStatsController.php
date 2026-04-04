<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoeUser;
use App\Models\HotspotUser;
use App\Models\HotspotSession;
use App\Models\RadiusSession;
use App\Services\MikroTik\SshExecutor;
use App\Services\VictoriaMetricsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Connection Stats Controller
 * 
 * Aggregates real-time statistics for PPPoE and Hotspot connections.
 * NO APPLICATION CACHE - always returns fresh data for data consistency.
 * Database query optimization is used instead of caching.
 */
class ConnectionStatsController extends Controller
{
    /**
     * Get aggregated connection statistics
     * 
     * ALWAYS returns fresh data - no cache to prevent stale data issues.
     * Database queries are optimized for performance.
     */
    public function stats(Request $request, VictoriaMetricsClient $vm)
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $startTime = microtime(true);
            
            // Fetch both PPPoE and Hotspot sessions
            $pppoeData = $this->fetchPppoeSessions($tenantId, $vm);
            $hotspotData = $this->fetchHotspotSessions($tenantId);
            
            // Merge and calculate statistics
            $allConnections = array_merge($pppoeData, $hotspotData);
            $stats = $this->calculateStats($allConnections, $tenantId);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('Connection stats calculated', [
                'tenant_id' => $tenantId,
                'total_connections' => $stats['total'],
                'calculation_time_ms' => $duration,
            ]);

            return response()->json([
                'success' => true,
                'data' => $stats,
                'timestamp' => now()->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to calculate connection stats', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate connection statistics',
            ], 500);
        }
    }

    /**
     * Fetch PPPoE sessions from radacct and VictoriaMetrics
     */
    private function fetchPppoeSessions(string $tenantId, VictoriaMetricsClient $vm): array
    {
        $sessions = [];
        
        // Check tenant schema first
        $tenantSchemaExists = Schema::hasTable('radacct');
        
        $rows = collect();
        
        if ($tenantSchemaExists) {
            $rows = DB::table('radacct')
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
                ->whereNull('acctstoptime')
                ->orderByDesc('acctstarttime')
                ->limit(1000)
                ->get();
        }
        
        // Fallback to public.radacct
        if ($rows->isEmpty()) {
            $publicRadacctExists = (bool) (DB::selectOne("SELECT to_regclass('public.radacct') AS t")->t ?? null);
            
            if ($publicRadacctExists) {
                $tenantUsernames = PppoeUser::query()
                    ->select('username')
                    ->pluck('username')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                
                if (!empty($tenantUsernames)) {
                    $rows = DB::table('public.radacct')
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
                        ->whereNull('acctstoptime')
                        ->whereIn('username', $tenantUsernames)
                        ->orderByDesc('acctstarttime')
                        ->limit(1000)
                        ->get();
                }
            }
        }
        
        // Get live metrics from VictoriaMetrics
        $usernames = $rows->pluck('username')->filter()->unique()->values()->all();
        $liveMetrics = $this->fetchVictoriaMetrics($vm, $tenantId, $usernames);
        
        foreach ($rows as $row) {
            $username = $row->username ?? '';
            $metrics = $liveMetrics[$username] ?? [];
            
            $start = $row->acctstarttime ? Carbon::parse($row->acctstarttime) : null;
            $duration = $start ? max(0, $start->diffInSeconds(now(), false)) : (int) ($row->acctsessiontime ?? 0);
            
            $sessions[] = [
                'id' => $row->acctsessionid ?? uniqid(),
                'username' => $username,
                'type' => 'pppoe',
                'ip_address' => $row->framedipaddress ?? null,
                'mac_address' => $row->callingstationid ?? null,
                'download_rate' => $metrics['download_rate'] ?? 0,
                'upload_rate' => $metrics['upload_rate'] ?? 0,
                'download_total' => $metrics['output_octets'] ?? (int) ($row->acctoutputoctets ?? 0),
                'upload_total' => $metrics['input_octets'] ?? (int) ($row->acctinputoctets ?? 0),
                'uptime' => $duration,
                'connected_at' => $row->acctstarttime ?? null,
            ];
        }
        
        return $sessions;
    }

    /**
     * Fetch Hotspot sessions from database
     */
    private function fetchHotspotSessions(string $tenantId): array
    {
        $sessions = [];
        
        try {
            // Get active hotspot sessions from radius_sessions table
            $radiusSessions = RadiusSession::query()
                ->with(['hotspotUser'])
                ->whereNull('acct_stop_time')
                ->orWhere('acct_stop_time', '>=', now()->subMinutes(5))
                ->limit(1000)
                ->get();
            
            foreach ($radiusSessions as $session) {
                $user = $session->hotspotUser;
                
                $start = $session->acct_start_time ? Carbon::parse($session->acct_start_time) : null;
                $duration = $start ? $start->diffInSeconds(now()) : 0;
                
                $sessions[] = [
                    'id' => $session->id ?? uniqid(),
                    'username' => $user?->username ?? $session->username ?? 'Unknown',
                    'type' => 'hotspot',
                    'ip_address' => $session->framed_ip_address ?? null,
                    'mac_address' => $user?->mac_address ?? $session->calling_station_id ?? null,
                    'download_rate' => (int) ($session->bytes_out ?? 0), // Current rate if available
                    'upload_rate' => (int) ($session->bytes_in ?? 0),
                    'download_total' => (int) ($session->acct_output_octets ?? 0),
                    'upload_total' => (int) ($session->acct_input_octets ?? 0),
                    'uptime' => $duration,
                    'connected_at' => $session->acct_start_time ?? null,
                ];
            }
            
            // Also check hotspot_sessions table if exists
            if (Schema::hasTable('hotspot_sessions')) {
                $dbSessions = HotspotSession::query()
                    ->where('status', 'active')
                    ->orWhere('updated_at', '>=', now()->subMinutes(5))
                    ->limit(1000)
                    ->get();
                
                foreach ($dbSessions as $session) {
                    $sessions[] = [
                        'id' => $session->id ?? uniqid(),
                        'username' => $session->username ?? 'Unknown',
                        'type' => 'hotspot',
                        'ip_address' => $session->ip_address ?? null,
                        'mac_address' => $session->mac_address ?? null,
                        'download_rate' => (int) ($session->download_rate ?? 0),
                        'upload_rate' => (int) ($session->upload_rate ?? 0),
                        'download_total' => (int) ($session->bytes_out ?? 0),
                        'upload_total' => (int) ($session->bytes_in ?? 0),
                        'uptime' => $session->uptime ?? 0,
                        'connected_at' => $session->created_at ?? null,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch hotspot sessions for stats', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
        
        return $sessions;
    }

    /**
     * Calculate aggregated statistics from all connections
     */
    private function calculateStats(array $connections, string $tenantId): array
    {
        $total = count($connections);
        
        // Count by type
        $hotspot = count(array_filter($connections, fn($c) => ($c['type'] ?? '') === 'hotspot'));
        $pppoe = count(array_filter($connections, fn($c) => ($c['type'] ?? '') === 'pppoe'));
        
        // Active users (with traffic)
        $active = count(array_filter($connections, fn($c) => 
            ($c['download_rate'] ?? 0) > 0 || ($c['upload_rate'] ?? 0) > 0
        ));
        
        $idle = $total - $active;
        
        // Bandwidth calculations
        $download = array_sum(array_column($connections, 'download_rate'));
        $upload = array_sum(array_column($connections, 'upload_rate'));
        $bandwidth = $download + $upload;
        
        // Average session duration
        $uptimes = array_column($connections, 'uptime');
        $avgSessionDuration = !empty($uptimes) 
            ? round(array_sum($uptimes) / count($uptimes) / 60, 1) // Convert to minutes
            : 0;
        
        // Peak concurrent today (calculated from current connections)
        // Note: To persist across requests, store in database table, not cache
        $peakToday = $total;
        
        return [
            'total' => $total,
            'hotspot' => $hotspot,
            'pppoe' => $pppoe,
            'active' => $active,
            'idle' => $idle,
            'download' => (int) $download,
            'upload' => (int) $upload,
            'bandwidth' => (int) $bandwidth,
            'avgSessionDuration' => (float) $avgSessionDuration,
            'peakToday' => (int) $peakToday,
            'connectionCount' => $total,
        ];
    }

    /**
     * Fetch live metrics from VictoriaMetrics for given usernames
     */
    private function fetchVictoriaMetrics(VictoriaMetricsClient $vm, string $tenantId, array $usernames): array
    {
        if (empty($usernames)) {
            return [];
        }

        $tenantIdEscaped = $this->escapeLabelValue($tenantId);
        $usernamePatterns = array_map(fn($u) => $this->escapeRegexValue((string) $u), $usernames);
        $ifNameRegex = '^(<pppoe-)?(' . implode('|', $usernamePatterns) . ')(>)?$';

        $selector = sprintf(
            'tenant_id="%s",ifName=~"%s"',
            $tenantIdEscaped,
            $this->escapeLabelValue($ifNameRegex)
        );

        $inRateQuery = sprintf('rate(interface_counters_ifHCInOctets{%s}[1m])', $selector);
        $outRateQuery = sprintf('rate(interface_counters_ifHCOutOctets{%s}[1m])', $selector);
        $inBytesQuery = sprintf('interface_counters_ifHCInOctets{%s}', $selector);
        $outBytesQuery = sprintf('interface_counters_ifHCOutOctets{%s}', $selector);

        try {
            $inRate = $vm->queryInstant($inRateQuery);
            $outRate = $vm->queryInstant($outRateQuery);
            $inBytes = $vm->queryInstant($inBytesQuery);
            $outBytes = $vm->queryInstant($outBytesQuery);

            $metrics = [];
            $this->processMetrics($inRate, $metrics, 'download_rate');
            $this->processMetrics($outRate, $metrics, 'upload_rate');
            $this->processMetrics($inBytes, $metrics, 'input_octets');
            $this->processMetrics($outBytes, $metrics, 'output_octets');

            return $metrics;
        } catch (\Exception $e) {
            Log::warning('VictoriaMetrics query failed for stats', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Process VictoriaMetrics results into username-indexed array
     */
    private function processMetrics(array $response, array &$metrics, string $field): void
    {
        $results = $response['data']['result'] ?? [];
        foreach ($results as $series) {
            $ifName = $series['metric']['ifName'] ?? '';
            $username = preg_replace('/^<pppoe-/', '', $ifName);
            $username = preg_replace('/>$/', '', $username);

            if ($username) {
                if (!isset($metrics[$username])) {
                    $metrics[$username] = [];
                }
                $value = $series['value'][1] ?? 0;
                $metrics[$username][$field] = (float) $value;
            }
        }
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(["\\", '"'], ["\\\\", '\\"'], $value);
    }

    private function escapeRegexValue(string $value): string
    {
        return preg_replace('/([\\.^$|?*+()\[\]{}])/', '\\\\$1', $value) ?? '';
    }
}
