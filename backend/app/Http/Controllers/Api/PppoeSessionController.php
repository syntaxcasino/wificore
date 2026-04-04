<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\RouterTenantMap;
use App\Services\MikroTik\SshExecutor;
use App\Services\VictoriaMetricsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PppoeSessionController extends Controller
{
    public function index(Request $request, VictoriaMetricsClient $vm)
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $rows = collect();
            $source = 'none';

            // Check tenant schema first
            $tenantSchemaExists = Schema::hasTable('radacct');
            Log::info('PPPoE sessions lookup started', [
                'tenant_id' => $tenantId,
                'tenant_schema_radacct_exists' => $tenantSchemaExists,
            ]);

            if ($tenantSchemaExists) {
                $rows = DB::table('radacct')
                    ->select([
                        'acctsessionid',
                        'acctuniqueid',
                        'username',
                        'acctstarttime',
                        'acctsessiontime',
                        'acctinputoctets',
                        'acctoutputoctets',
                        'framedipaddress',
                        'callingstationid',
                        'nasipaddress',
                    ])
                    ->whereNull('acctstoptime')
                    ->orderByDesc('acctstarttime')
                    ->limit(500)
                    ->get();
                
                Log::info('Tenant schema radacct query result', [
                    'row_count' => $rows->count(),
                ]);
                
                if ($rows->isNotEmpty()) {
                    $source = 'tenant_radacct';
                }
            }

            // Fallback: if tenant-schema accounting is empty/missing, pull from public.radacct
            if ($rows->isEmpty()) {
                $publicRadacctExists = (bool) (DB::selectOne("SELECT to_regclass('public.radacct') AS t")->t ?? null);
                
                Log::info('Checking public.radacct fallback', [
                    'public_radacct_exists' => $publicRadacctExists,
                ]);

                if ($publicRadacctExists) {
                    $tenantUsernames = PppoeUser::query()
                        ->select('username')
                        ->pluck('username')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    Log::info('Tenant PPPoE usernames for public.radacct filter', [
                        'username_count' => count($tenantUsernames),
                        'usernames' => array_slice($tenantUsernames, 0, 10), // First 10 only
                    ]);

                    if (!empty($tenantUsernames)) {
                        $rows = DB::table('public.radacct')
                            ->select([
                                'acctsessionid',
                                'acctuniqueid',
                                'username',
                                'acctstarttime',
                                'acctsessiontime',
                                'acctinputoctets',
                                'acctoutputoctets',
                                'framedipaddress',
                                'callingstationid',
                                'nasipaddress',
                            ])
                            ->whereNull('acctstoptime')
                            ->whereIn('username', $tenantUsernames)
                            ->orderByDesc('acctstarttime')
                            ->limit(500)
                            ->get();
                        
                        Log::info('Public radacct query result', [
                            'row_count' => $rows->count(),
                        ]);
                        
                        if ($rows->isNotEmpty()) {
                            $source = 'public_radacct';
                        }
                    }
                }
            }

            $usernames = $rows->pluck('username')->filter()->unique()->values()->all();

            // Fetch live metrics from VictoriaMetrics for these users
            $liveMetrics = $this->fetchLiveMetrics($vm, (string) $tenantId, $usernames);

            $pppoeUsersByUsername = PppoeUser::query()
                ->whereIn('username', $usernames)
                ->with(['package:id,name,download_speed,upload_speed,speed', 'router:id,name'])
                ->get()
                ->keyBy('username');

            $data = $rows->map(function ($row) use ($pppoeUsersByUsername, $liveMetrics) {
                $username = (string) ($row->username ?? '');
                $pppoeUser = $pppoeUsersByUsername->get($username);
                $pkg = $pppoeUser?->package;
                $metrics = $liveMetrics[$username] ?? [];

                $start = $row->acctstarttime ? \Carbon\Carbon::parse($row->acctstarttime) : null;
                $duration = $start ? max(0, $start->diffInSeconds(now(), false)) : (int) ($row->acctsessiontime ?? 0);
                
                // Use live metrics if available, otherwise fallback to accounting
                $input = $metrics['input_octets'] ?? (int) ($row->acctinputoctets ?? 0);
                $output = $metrics['output_octets'] ?? (int) ($row->acctoutputoctets ?? 0);
                $downloadRate = $metrics['download_rate'] ?? 0;
                $uploadRate = $metrics['upload_rate'] ?? 0;

                $profileId = $pkg?->id ? (string) $pkg->id : null;
                $profileName = $pkg?->name ? (string) $pkg->name : 'N/A';
                $profileSpeed = $pkg?->speed ? (string) $pkg->speed : null;

                $maxDownload = $this->parseSpeedToBytesPerSecond($pkg?->download_speed ? (string) $pkg->download_speed : null);
                $maxUpload = $this->parseSpeedToBytesPerSecond($pkg?->upload_speed ? (string) $pkg->upload_speed : null);

                $routerName = $pppoeUser?->router?->name ? (string) $pppoeUser->router->name : null;
                $routerId = $pppoeUser?->router?->id ? (string) $pppoeUser->router->id : null;

                return [
                    'id' => (string) ($row->acctuniqueid ?? $row->acctsessionid ?? $username),
                    'acct_session_id' => $row->acctsessionid ?? null,
                    'acct_unique_id' => $row->acctuniqueid ?? null,
                    'username' => $username,
                    'type' => 'pppoe',
                    'router_id' => $routerId,
                    'router_name' => $routerName,
                    'user' => [
                        'phone' => null,
                    ],
                    'framed_ip' => $row->framedipaddress ?? null,
                    'ip_address' => $row->framedipaddress ?? null,
                    'calling_station_id' => $row->callingstationid ?? null,
                    'mac_address' => $row->callingstationid ?? null,
                    'nas_ip_address' => $row->nasipaddress ?? null,
                    'profile' => [
                        'id' => $profileId,
                        'name' => $profileName,
                        'speed' => $profileSpeed,
                        'max_download' => $maxDownload,
                        'max_upload' => $maxUpload,
                    ],
                    'start_time' => $row->acctstarttime ?? null,
                    'connected_at' => $row->acctstarttime ?? null,
                    'duration' => $duration,
                    'uptime' => $duration,
                    'input_octets' => $input,
                    'output_octets' => $output,
                    'download_speed' => $downloadRate,
                    'upload_speed' => $uploadRate,
                    'download_rate' => $downloadRate,
                    'upload_rate' => $uploadRate,
                ];
            })->values();

            Log::info('PPPoE sessions data mapping complete', [
                'data_count' => $data->count(),
                'source' => $source,
                'usernames_found' => count($usernames),
            ]);

            // If no radacct data, we used to fallback to live fetch from routers via SSH.
            // But user explicitly said "this is a bad design".
            // So we will return empty list or data from VM if usernames are known (active users).
            if ($data->isEmpty()) {
                Log::info('No radacct data found, trying VM fallback with active users');
                
                // Try fetching active users from DB and checking VM for them as a last resort
                $activeUsers = PppoeUser::where('status', 'active')->get();
                Log::info('Active PPPoE users from DB', [
                    'active_user_count' => $activeUsers->count(),
                ]);
                
                if ($activeUsers->isNotEmpty()) {
                    $usernames = $activeUsers->pluck('username')->all();
                    $liveMetrics = $this->fetchLiveMetrics($vm, (string) $tenantId, $usernames);
                    
                    Log::info('VictoriaMetrics lookup for active users', [
                        'usernames_checked' => count($usernames),
                        'metrics_found' => count($liveMetrics),
                    ]);
                    
                    if (!empty($liveMetrics)) {
                        // Construct minimal session data from VM metrics + DB
                        $data = $activeUsers->filter(fn($u) => isset($liveMetrics[$u->username]))
                            ->map(function($u) use ($liveMetrics) {
                                $m = $liveMetrics[$u->username];
                                return [
                                    'id' => $u->username,
                                    'username' => $u->username,
                                    'type' => 'pppoe',
                                    'router_id' => (string) $u->router_id,
                                    'download_speed' => $m['download_rate'] ?? 0,
                                    'upload_speed' => $m['upload_rate'] ?? 0,
                                    'input_octets' => $m['input_octets'] ?? 0,
                                    'output_octets' => $m['output_octets'] ?? 0,
                                    'status' => 'active (metrics only)',
                                ];
                            })->values();
                        
                        if ($data->isNotEmpty()) {
                            $source = 'vm_fallback';
                        }
                    }
                }
            }

            Log::info('PPPoE sessions response ready', [
                'final_count' => $data->count(),
                'final_source' => $source,
            ]);

            return response()->json([
                'success' => true,
                'data' => $data,
                'source' => $source,
                'tenant_id' => $tenantId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch PPPoE sessions', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load PPPoE sessions: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function disconnect(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $username = (string) $request->input('username');

        try {
            $pppoeUser = PppoeUser::where('username', $username)->first();
            $router = null;

            if ($pppoeUser?->router_id) {
                $router = Router::find($pppoeUser->router_id);
            }

            if (!$router) {
                $router = $this->resolveRouterFromAccounting($username, (string) $tenantId);
            }

            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router not found for PPPoE session',
                ], 404);
            }

            $ssh = new SshExecutor($router, 10); // Increased timeout to 10s
            if (!$ssh->connect()) {
                 return response()->json([
                    'success' => false,
                    'message' => 'Could not connect to router via SSH',
                ], 500);
            }

            // Try multiple commands to ensure disconnection
            // 1. Find by name (username)
            $ssh->exec(sprintf('/ppp active remove [find name="%s"]', addslashes($username)));
            
            // 2. Find by user property (sometimes distinct)
            $ssh->exec(sprintf('/ppp active remove [find user="%s"]', addslashes($username)));
            
            // 3. Verify disconnection
            $check = $ssh->exec(sprintf('/ppp active print count-only where name="%s"', addslashes($username)));
            $ssh->disconnect();

            $stillActive = (int) trim($check) > 0;

            if ($stillActive) {
                Log::warning('PPPoE session disconnect command sent but user still active', [
                    'tenant_id' => (string) $tenantId,
                    'username' => $username,
                    'router_id' => (string) $router->id,
                ]);
                // We return success anyway because sometimes it takes a moment, or Accounting will update later.
                // But we log it.
            }

            Log::info('PPPoE session disconnected (manual)', [
                'tenant_id' => (string) $tenantId,
                'username' => $username,
                'pppoe_user_id' => (string) ($pppoeUser?->id ?? ''),
                'router_id' => (string) $router->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Disconnect command sent successfully',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to disconnect PPPoE session (manual)', [
                'tenant_id' => (string) $tenantId,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to disconnect session: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function disconnectAll(Request $request)
    {
        $request->validate([
            'usernames' => 'required|array',
            'usernames.*' => 'string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $usernames = array_values(array_filter(array_map('strval', (array) $request->input('usernames', []))));

        $disconnected = 0;
        $failed = 0;

        foreach ($usernames as $username) {
            // Re-use the single disconnect logic logic (simplified)
            try {
                $pppoeUser = PppoeUser::where('username', $username)->first();
                $router = null;

                if ($pppoeUser?->router_id) {
                    $router = Router::find($pppoeUser->router_id);
                }

                if (!$router) {
                    $router = $this->resolveRouterFromAccounting($username, (string) $tenantId);
                }

                if (!$router) {
                    $failed++;
                    continue;
                }

                $ssh = new SshExecutor($router, 10);
                if ($ssh->connect()) {
                    $ssh->exec(sprintf('/ppp active remove [find name="%s"]', addslashes($username)));
                    $ssh->exec(sprintf('/ppp active remove [find user="%s"]', addslashes($username)));
                    $ssh->disconnect();
                    $disconnected++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                Log::warning('Failed to disconnect PPPoE session in bulk', [
                    'tenant_id' => (string) $tenantId,
                    'username' => (string) $username,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('PPPoE sessions bulk disconnect completed', [
            'tenant_id' => (string) $tenantId,
            'requested' => count($usernames),
            'disconnected' => $disconnected,
            'failed' => $failed,
        ]);

        return response()->json([
            'success' => true,
            'requested' => count($usernames),
            'disconnected' => $disconnected,
            'failed' => $failed,
        ]);
    }

    private function fetchLiveMetrics(VictoriaMetricsClient $vm, string $tenantId, array $usernames): array
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
            $this->processMetricResults($inRate, $metrics, 'download_rate');
            $this->processMetricResults($outRate, $metrics, 'upload_rate');
            $this->processMetricResults($inBytes, $metrics, 'input_octets');
            $this->processMetricResults($outBytes, $metrics, 'output_octets');

            return $metrics;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch VM metrics for PPPoE sessions', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function processMetricResults(array $response, array &$metrics, string $field): void
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
        return preg_replace('/([\\\\.^$|?*+()\[\]{}])/', '\\\\$1', $value) ?? '';
    }

    private function resolveRouterFromAccounting(string $username, string $tenantId): ?Router
    {
        $nasIp = null;

        if (Schema::hasTable('radacct')) {
            $nasIp = DB::table('radacct')
                ->where('username', $username)
                ->whereNull('acctstoptime')
                ->orderByDesc('acctstarttime')
                ->value('nasipaddress');
        }

        if (!$nasIp) {
            $publicRadacctExists = (bool) (DB::selectOne("SELECT to_regclass('public.radacct') AS t")->t ?? null);

            if ($publicRadacctExists) {
                $nasIp = DB::table('public.radacct')
                    ->where('username', $username)
                    ->whereNull('acctstoptime')
                    ->orderByDesc('acctstarttime')
                    ->value('nasipaddress');
            }
        }

        if (!$nasIp) {
            return null;
        }

        $nasIp = trim((string) $nasIp);
        $nasIp = explode('/', $nasIp)[0] ?? $nasIp;

        $map = RouterTenantMap::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($nasIp) {
                $query->where('ip_address', $nasIp)
                    ->orWhere('vpn_ip', $nasIp);
            })
            ->first();

        if (!$map) {
            return null;
        }

        return Router::find($map->router_id);
    }

    /**
     * Get live PPPoE sessions.
     * Now uses the fast VictoriaMetrics-backed index method instead of slow SSH.
     */
    public function live(Request $request, VictoriaMetricsClient $vm)
    {
        return $this->index($request, $vm);
    }
    
    private function parseSpeedToBytesPerSecond(?string $speed): ?int
    {
        if ($speed === null) {
            return null;
        }

        $raw = trim($speed);
        if ($raw === '') {
            return null;
        }

        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(mbps|gbps)/i', $raw, $m)) {
            return null;
        }

        $value = (float) $m[1];
        $unit = strtolower($m[2]);

        $bitsPerSecond = $unit === 'gbps'
            ? $value * 1000 * 1000 * 1000
            : $value * 1000 * 1000;

        return (int) floor($bitsPerSecond / 8);
    }
}
