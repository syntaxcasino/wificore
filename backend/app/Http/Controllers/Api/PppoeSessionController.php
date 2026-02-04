<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Services\MikroTik\SshExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PppoeSessionController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $rows = collect();

            if (Schema::hasTable('radacct')) {
                $rows = DB::table('radacct')
                    ->whereNull('acctstoptime')
                    ->orderByDesc('acctstarttime')
                    ->limit(500)
                    ->get();
            }

            // Fallback: if tenant-schema accounting is empty/missing, pull from public.radacct but
            // strictly filter to this tenant's PPPoE usernames to avoid cross-tenant leakage.
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
                            ->whereNull('acctstoptime')
                            ->whereIn('username', $tenantUsernames)
                            ->orderByDesc('acctstarttime')
                            ->limit(500)
                            ->get();
                    }
                }
            }

            $usernames = $rows->pluck('username')->filter()->unique()->values()->all();

            $pppoeUsersByUsername = PppoeUser::query()
                ->whereIn('username', $usernames)
                ->with(['package:id,name,download_speed,upload_speed,speed', 'router:id,name'])
                ->get()
                ->keyBy('username');

            $data = $rows->map(function ($row) use ($pppoeUsersByUsername) {
                $username = (string) ($row->username ?? '');
                $pppoeUser = $pppoeUsersByUsername->get($username);
                $pkg = $pppoeUser?->package;

                $start = $row->acctstarttime ? \Carbon\Carbon::parse($row->acctstarttime) : null;
                $duration = $start ? max(0, $start->diffInSeconds(now(), false)) : (int) ($row->acctsessiontime ?? 0);
                $input = (int) ($row->acctinputoctets ?? 0);
                $output = (int) ($row->acctoutputoctets ?? 0);

                $downloadSpeed = $duration > 0 ? (int) floor($input / $duration) : 0;
                $uploadSpeed = $duration > 0 ? (int) floor($output / $duration) : 0;

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
                    'download_speed' => $downloadSpeed,
                    'upload_speed' => $uploadSpeed,
                    'download_rate' => $downloadSpeed,
                    'upload_rate' => $uploadSpeed,
                ];
            })->values();

            // If no radacct data, try live fetch from routers
            if ($data->isEmpty()) {
                return $this->live($request);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'source' => 'radacct',
                'tenant_id' => $tenantId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch PPPoE sessions', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            // Fallback to live fetch on error
            try {
                return $this->live($request);
            } catch (\Exception $e2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to load PPPoE sessions',
                ], 500);
            }
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
            if (!$pppoeUser || !$pppoeUser->router_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'PPPoE user/router not found for session',
                ], 404);
            }

            $router = Router::find($pppoeUser->router_id);
            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router not found for PPPoE user',
                ], 404);
            }

            $ssh = new SshExecutor($router, 5);
            $ssh->connect();
            $ssh->exec(sprintf('/ppp active remove [find name="%s"]', addslashes($username)));
            $ssh->exec(sprintf('/ppp active remove [find user="%s"]', addslashes($username)));
            $ssh->disconnect();

            Log::info('PPPoE session disconnected (manual)', [
                'tenant_id' => (string) $tenantId,
                'username' => $username,
                'router_id' => (string) $router->id,
            ]);

            return response()->json([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to disconnect PPPoE session (manual)', [
                'tenant_id' => (string) $tenantId,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to disconnect session',
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
            try {
                $pppoeUser = PppoeUser::where('username', $username)->first();
                if (!$pppoeUser || !$pppoeUser->router_id) {
                    $failed++;
                    continue;
                }

                $router = Router::find($pppoeUser->router_id);
                if (!$router) {
                    $failed++;
                    continue;
                }

                $ssh = new SshExecutor($router, 5);
                $ssh->connect();
                $ssh->exec(sprintf('/ppp active remove [find name="%s"]', addslashes($username)));
                $ssh->exec(sprintf('/ppp active remove [find user="%s"]', addslashes($username)));
                $ssh->disconnect();
                $disconnected++;
            } catch (\Exception $e) {
                $failed++;
                Log::warning('Failed to disconnect PPPoE session in bulk (best-effort)', [
                    'tenant_id' => (string) $tenantId,
                    'username' => (string) $username,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('PPPoE sessions bulk disconnect completed (best-effort)', [
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

    /**
     * Get live PPPoE sessions directly from routers (fallback when radacct is empty)
     */
    public function live(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        try {
            $sessions = collect();
            $routers = Router::where('status', 'online')->get();

            foreach ($routers as $router) {
                try {
                    $ssh = new SshExecutor($router, 10);
                    $ssh->connect();
                    
                    // Get active PPP sessions
                    $output = $ssh->exec('/ppp active print terse');
                    $ssh->disconnect();

                    if (empty(trim($output))) {
                        continue;
                    }

                    // Parse terse output
                    foreach (explode("\n", $output) as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;

                        $data = $this->parseTerseLine($line);
                        if (empty($data['name'])) continue;

                        $username = $data['name'] ?? '';
                        $pppoeUser = PppoeUser::where('username', $username)->with('package')->first();

                        $sessions->push([
                            'id' => $data['.id'] ?? uniqid(),
                            'username' => $username,
                            'type' => 'pppoe',
                            'router_id' => (string) $router->id,
                            'router_name' => $router->name,
                            'ip_address' => $data['address'] ?? null,
                            'framed_ip' => $data['address'] ?? null,
                            'calling_station_id' => $data['caller-id'] ?? null,
                            'mac_address' => $data['caller-id'] ?? null,
                            'service' => $data['service'] ?? 'pppoe',
                            'profile' => [
                                'id' => $pppoeUser?->package?->id,
                                'name' => $pppoeUser?->package?->name ?? 'N/A',
                                'speed' => $pppoeUser?->package?->speed ?? null,
                            ],
                            'uptime' => $this->parseUptime($data['uptime'] ?? '0s'),
                            'duration' => $this->parseUptime($data['uptime'] ?? '0s'),
                            'connected_at' => now()->subSeconds($this->parseUptime($data['uptime'] ?? '0s'))->toIso8601String(),
                            'start_time' => now()->subSeconds($this->parseUptime($data['uptime'] ?? '0s'))->toIso8601String(),
                            'input_octets' => 0,
                            'output_octets' => 0,
                            'download_speed' => 0,
                            'upload_speed' => 0,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::debug('Failed to fetch live PPPoE sessions from router', [
                        'router_id' => (string) $router->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $sessions->values(),
                'source' => 'live',
                'tenant_id' => $tenantId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch live PPPoE sessions', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch live sessions',
            ], 500);
        }
    }

    private function parseTerseLine(string $line): array
    {
        $data = [];
        // Format: 0 name="user1" service=pppoe caller-id="AA:BB:CC:DD:EE:FF" address=10.0.0.2 uptime=1h2m3s
        if (preg_match_all('/(\S+)=("[^"]*"|\S+)/', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = trim($match[2], '"');
                $data[$key] = $value;
            }
        }
        // Handle .id at start
        if (preg_match('/^\s*(\d+)\s/', $line, $m)) {
            $data['.id'] = '*' . $m[1];
        }
        return $data;
    }

    private function parseUptime(string $uptime): int
    {
        $seconds = 0;
        if (preg_match('/(\d+)w/', $uptime, $m)) $seconds += (int)$m[1] * 604800;
        if (preg_match('/(\d+)d/', $uptime, $m)) $seconds += (int)$m[1] * 86400;
        if (preg_match('/(\d+)h/', $uptime, $m)) $seconds += (int)$m[1] * 3600;
        if (preg_match('/(\d+)m/', $uptime, $m)) $seconds += (int)$m[1] * 60;
        if (preg_match('/(\d+)s/', $uptime, $m)) $seconds += (int)$m[1];
        return $seconds;
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
