<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoeUser;
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
            if (!Schema::hasTable('radacct')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'tenant_id' => $tenantId,
                ]);
            }

            $rows = DB::table('radacct')
                ->whereNull('acctstoptime')
                ->orderByDesc('acctstarttime')
                ->limit(500)
                ->get();

            $usernames = $rows->pluck('username')->filter()->unique()->values()->all();

            $pppoeUsersByUsername = PppoeUser::query()
                ->whereIn('username', $usernames)
                ->with(['package:id,name,download_speed,upload_speed,speed'])
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

                return [
                    'id' => (string) ($row->acctuniqueid ?? $row->acctsessionid ?? $username),
                    'acct_session_id' => $row->acctsessionid ?? null,
                    'acct_unique_id' => $row->acctuniqueid ?? null,
                    'username' => $username,
                    'user' => [
                        'phone' => null,
                    ],
                    'framed_ip' => $row->framedipaddress ?? null,
                    'calling_station_id' => $row->callingstationid ?? null,
                    'nas_ip_address' => $row->nasipaddress ?? null,
                    'profile' => [
                        'id' => $profileId,
                        'name' => $profileName,
                        'speed' => $profileSpeed,
                        'max_download' => $maxDownload,
                        'max_upload' => $maxUpload,
                    ],
                    'start_time' => $row->acctstarttime ?? null,
                    'duration' => $duration,
                    'input_octets' => $input,
                    'output_octets' => $output,
                    'download_speed' => $downloadSpeed,
                    'upload_speed' => $uploadSpeed,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $data,
                'tenant_id' => $tenantId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch PPPoE sessions', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load PPPoE sessions',
            ], 500);
        }
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
