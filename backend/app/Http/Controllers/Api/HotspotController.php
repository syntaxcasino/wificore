<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\HotspotUser;
use App\Models\HotspotSession;
use App\Models\RadiusSession;
use App\Models\Package;
use App\Jobs\DisconnectHotspotUserJob;
use App\Jobs\GrantHotspotAccessJob;
use App\Events\HotspotAccessRevoked;
use App\Services\Hotspot\HotspotRadiusService;
use Carbon\Carbon;

class HotspotController extends Controller
{
    /**
     * Hotspot user login
     * 
     * This endpoint authenticates hotspot users who have purchased packages
     * and creates an active session for internet access.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
            'mac_address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $username = $request->input('username');
            $password = $request->input('password');
            $macAddress = $request->input('mac_address');

            // Find hotspot user by username
            $hotspotUser = HotspotUser::where('username', $username)->first();

            if (!$hotspotUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid username or password',
                ], 401);
            }

            // Verify password
            if (!password_verify($password, $hotspotUser->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid username or password',
                ], 401);
            }

            // Check if user has an active subscription
            if (!$hotspotUser->has_active_subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription. Please purchase a package first.',
                ], 403);
            }

            // Check if subscription is expired
            if ($hotspotUser->subscription_expires_at && 
                Carbon::parse($hotspotUser->subscription_expires_at)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your subscription has expired. Please renew your package.',
                ], 403);
            }

            // Create or update session
            $session = HotspotSession::updateOrCreate(
                ['hotspot_user_id' => $hotspotUser->id],
                [
                    'mac_address' => $macAddress,
                    'ip_address' => $request->ip(),
                    'session_start' => now(),
                    'last_activity' => now(),
                    'is_active' => true,
                    'expires_at' => $hotspotUser->subscription_expires_at,
                ]
            );

            // Update last login
            $hotspotUser->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            // Log successful login
            Log::info('Hotspot user logged in', [
                'user_id' => $hotspotUser->id,
                'username' => $username,
                'mac_address' => $macAddress,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login successful. You are now connected to the internet.',
                'data' => [
                    'user' => [
                        'id' => $hotspotUser->id,
                        'username' => $hotspotUser->username,
                        'phone_number' => $hotspotUser->phone_number,
                    ],
                    'session' => [
                        'id' => $session->id,
                        'session_start' => $session->session_start,
                        'expires_at' => $session->expires_at,
                    ],
                    'subscription' => [
                        'package_name' => $hotspotUser->package_name ?? 'N/A',
                        'expires_at' => $hotspotUser->subscription_expires_at,
                        'data_limit' => $hotspotUser->data_limit,
                        'data_used' => $hotspotUser->data_used,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Hotspot login error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during login. Please try again.',
            ], 500);
        }
    }

    /**
     * Hotspot user logout
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        try {
            $username = $request->input('username');
            $macAddress = $request->input('mac_address');

            // Find user
            $hotspotUser = HotspotUser::where('username', $username)->first();

            if (!$hotspotUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // End session
            $session = HotspotSession::where('hotspot_user_id', $hotspotUser->id)
                ->where('is_active', true)
                ->first();

            if ($session) {
                $session->update([
                    'is_active' => false,
                    'session_end' => now(),
                ]);
            }

            Log::info('Hotspot user logged out', [
                'user_id' => $hotspotUser->id,
                'username' => $username,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Hotspot logout error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during logout',
            ], 500);
        }
    }

    /**
     * Check session status
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkSession(Request $request)
    {
        try {
            $username = $request->input('username');
            $macAddress = $request->input('mac_address');

            $hotspotUser = HotspotUser::where('username', $username)->first();

            if (!$hotspotUser) {
                return response()->json([
                    'success' => false,
                    'is_active' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $session = HotspotSession::where('hotspot_user_id', $hotspotUser->id)
                ->where('is_active', true)
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => true,
                    'is_active' => false,
                    'message' => 'No active session',
                ], 200);
            }

            // Check if session expired
            if ($session->expires_at && Carbon::parse($session->expires_at)->isPast()) {
                $session->update(['is_active' => false, 'session_end' => now()]);
                
                return response()->json([
                    'success' => true,
                    'is_active' => false,
                    'message' => 'Session expired',
                ], 200);
            }

            // Update last activity
            $session->update(['last_activity' => now()]);

            return response()->json([
                'success' => true,
                'is_active' => true,
                'session' => [
                    'session_start' => $session->session_start,
                    'expires_at' => $session->expires_at,
                    'last_activity' => $session->last_activity,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Check session error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
            ], 500);
        }
    }

    // =========================================================================
    // ADMIN METHODS - Authenticated tenant admin endpoints
    // =========================================================================

    /**
     * List hotspot users with pagination and filtering
     */
    public function listUsers(Request $request)
    {
        try {
            $query = HotspotUser::with(['package', 'credential'])
                ->when($request->status, function ($q, $status) {
                    return $q->where('status', $status);
                })
                ->when($request->package_id, function ($q, $packageId) {
                    return $q->where('package_id', $packageId);
                })
                ->when($request->search, function ($q, $search) {
                    return $q->where(function ($query) use ($search) {
                        $query->where('username', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('voucher_code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
                })
                ->when($request->has_subscription !== null, function ($q) use ($request) {
                    return $q->where('has_active_subscription', filter_var($request->has_subscription, FILTER_VALIDATE_BOOLEAN));
                });

            $users = $query->latest()->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $users->items(),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing hotspot users', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch hotspot users',
            ], 500);
        }
    }

    /**
     * Get specific hotspot user details
     */
    public function showUser(string $userId)
    {
        try {
            $user = HotspotUser::with(['package', 'credential', 'sessions' => function ($q) {
                $q->latest()->limit(10);
            }])->findOrFail($userId);

            // Get RADIUS data usage
            $dataUsage = DB::table('radacct')
                ->where('username', $user->username)
                ->selectRaw('SUM(acctinputoctets) as upload, SUM(acctoutputoctets) as download')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'data_usage' => [
                        'upload' => (int) ($dataUsage->upload ?? 0),
                        'download' => (int) ($dataUsage->download ?? 0),
                        'total' => (int) (($dataUsage->upload ?? 0) + ($dataUsage->download ?? 0)),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching hotspot user', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }
    }

    /**
     * Disconnect a hotspot user (queued job)
     */
    public function disconnectUser(Request $request, string $userId)
    {
        try {
            $user = HotspotUser::findOrFail($userId);
            $reason = $request->input('reason', 'Admin disconnect');
            $tenantId = auth()->user()->tenant_id;

            // Find active sessions
            $activeSessions = RadiusSession::where('hotspot_user_id', $user->id)
                ->where('status', 'active')
                ->get();

            if ($activeSessions->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'User has no active sessions',
                ]);
            }

            // Dispatch disconnect jobs for each session
            foreach ($activeSessions as $session) {
                DisconnectHotspotUserJob::dispatch(
                    $session->id,
                    $tenantId,
                    $reason,
                    auth()->id()
                )->onQueue('hotspot-sessions');
            }

            Log::info('Disconnect jobs dispatched for hotspot user', [
                'user_id' => $userId,
                'username' => $user->username,
                'sessions_count' => $activeSessions->count(),
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Disconnect request queued',
                'sessions_affected' => $activeSessions->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error disconnecting hotspot user', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to disconnect user',
            ], 500);
        }
    }

    /**
     * Grant access to a hotspot user
     */
    public function grantAccess(Request $request, string $userId)
    {
        try {
            $user = HotspotUser::findOrFail($userId);
            $packageId = $request->input('package_id');
            $tenantId = auth()->user()->tenant_id;

            // Validate package if provided
            if ($packageId) {
                $package = Package::where('id', $packageId)->where('type', 'hotspot')->first();
                if (!$package) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid package',
                    ], 400);
                }
            }

            // Dispatch grant access job
            GrantHotspotAccessJob::dispatch(
                $userId,
                $tenantId,
                $packageId,
                'admin_grant'
            )->onQueue('hotspot-access');

            Log::info('Grant access job dispatched for hotspot user', [
                'user_id' => $userId,
                'package_id' => $packageId,
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Access grant request queued',
            ]);
        } catch (\Exception $e) {
            Log::error('Error granting hotspot access', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to grant access',
            ], 500);
        }
    }

    /**
     * Revoke access from a hotspot user
     */
    public function revokeAccess(Request $request, string $userId)
    {
        try {
            $user = HotspotUser::findOrFail($userId);
            $reason = $request->input('reason', 'Admin revoked');
            $tenantId = auth()->user()->tenant_id;

            DB::beginTransaction();

            // Block in RADIUS
            DB::table('radcheck')
                ->where('username', $user->username)
                ->where('attribute', 'Auth-Type')
                ->delete();

            DB::table('radcheck')->insert([
                'username' => $user->username,
                'attribute' => 'Auth-Type',
                'op' => ':=',
                'value' => 'Reject',
            ]);

            // Update user status
            $user->update([
                'has_active_subscription' => false,
                'status' => 'revoked',
            ]);

            DB::commit();

            // Disconnect active sessions
            $activeSessions = RadiusSession::where('hotspot_user_id', $user->id)
                ->where('status', 'active')
                ->get();

            foreach ($activeSessions as $session) {
                DisconnectHotspotUserJob::dispatch(
                    $session->id,
                    $tenantId,
                    $reason,
                    auth()->id()
                )->onQueue('hotspot-sessions');
            }

            // Broadcast event
            broadcast(new HotspotAccessRevoked(
                $user->id,
                $tenantId,
                $user->username,
                $reason
            ))->toOthers();

            Log::info('Access revoked for hotspot user', [
                'user_id' => $userId,
                'username' => $user->username,
                'reason' => $reason,
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Access revoked successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error revoking hotspot access', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke access',
            ], 500);
        }
    }

    /**
     * List hotspot sessions from our RadiusSession DB cache
     */
    public function listSessions(Request $request)
    {
        try {
            $query = RadiusSession::with(['hotspotUser'])
                ->when($request->status, function ($q, $status) {
                    return $q->where('status', $status);
                })
                ->when($request->user_id, function ($q, $userId) {
                    return $q->where('hotspot_user_id', $userId);
                })
                ->when($request->active_only || !$request->status, function ($q) {
                    return $q->where('status', 'active');
                });

            $sessions = $query->latest('session_start')->paginate($request->per_page ?? 20);

            return response()->json([
                'success' => true,
                'data' => $sessions->items(),
                'meta' => [
                    'current_page' => $sessions->currentPage(),
                    'last_page' => $sessions->lastPage(),
                    'per_page' => $sessions->perPage(),
                    'total' => $sessions->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing hotspot sessions', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sessions',
            ], 500);
        }
    }

    /**
     * List LIVE hotspot sessions directly from RADIUS accounting (radacct).
     * This is the authoritative source — mirrors GET /pppoe/sessions.
     * Returns rows where acctstoptime IS NULL (i.e. still connected).
     */
    public function listLiveSessions(Request $request)
    {
        try {
            $source = 'none';

            // 1. Try tenant-schema radacct first
            $rows = collect();
            if (\Illuminate\Support\Facades\Schema::hasTable('radacct')) {
                $rows = DB::table('radacct')
                    ->select([
                        'acctsessionid', 'acctuniqueid', 'username',
                        'acctstarttime', 'acctsessiontime',
                        'acctinputoctets', 'acctoutputoctets',
                        'framedipaddress', 'callingstationid', 'nasipaddress',
                        'nasportid', 'servicetype',
                    ])
                    ->whereNull('acctstoptime')
                    ->whereIn('servicetype', ['Framed-User', 'Login-User', 'Call-Check', ''])
                    ->orderByDesc('acctstarttime')
                    ->limit(500)
                    ->get();

                if ($rows->isNotEmpty()) {
                    $source = 'tenant_radacct';
                }
            }

            // 2. Fallback: public.radacct filtered by tenant hotspot usernames
            if ($rows->isEmpty()) {
                $publicExists = (bool) (DB::selectOne("SELECT to_regclass('public.radacct') AS t")->t ?? null);
                if ($publicExists) {
                    $usernames = HotspotUser::query()->pluck('username')->filter()->unique()->values()->all();
                    if (!empty($usernames)) {
                        $rows = DB::table('public.radacct')
                            ->select([
                                'acctsessionid', 'acctuniqueid', 'username',
                                'acctstarttime', 'acctsessiontime',
                                'acctinputoctets', 'acctoutputoctets',
                                'framedipaddress', 'callingstationid', 'nasipaddress',
                                'nasportid', 'servicetype',
                            ])
                            ->whereNull('acctstoptime')
                            ->whereIn('username', $usernames)
                            ->orderByDesc('acctstarttime')
                            ->limit(500)
                            ->get();

                        if ($rows->isNotEmpty()) {
                            $source = 'public_radacct';
                        }
                    }
                }
            }

            $usernames = $rows->pluck('username')->filter()->unique()->values()->all();

            $hotspotUsersByUsername = HotspotUser::whereIn('username', $usernames)
                ->with(['package:id,name,data_limit'])
                ->get()
                ->keyBy('username');

            $data = $rows->map(function ($row) use ($hotspotUsersByUsername) {
                $username    = (string) ($row->username ?? '');
                $hsUser      = $hotspotUsersByUsername->get($username);
                $pkg         = $hsUser?->package;
                $start       = $row->acctstarttime ? \Carbon\Carbon::parse($row->acctstarttime) : null;
                $duration    = $start ? max(0, $start->diffInSeconds(now(), false)) : (int) ($row->acctsessiontime ?? 0);
                $bytesIn     = (int) ($row->acctinputoctets ?? 0);
                $bytesOut    = (int) ($row->acctoutputoctets ?? 0);

                return [
                    'id'                 => (string) ($row->acctuniqueid ?? $row->acctsessionid ?? $username),
                    'acct_session_id'    => $row->acctsessionid ?? null,
                    'acct_unique_id'     => $row->acctuniqueid ?? null,
                    'username'           => $username,
                    'type'               => 'hotspot',
                    'hotspot_user_id'    => $hsUser?->id,
                    'framed_ip'          => $row->framedipaddress ?? null,
                    'ip_address'         => $row->framedipaddress ?? null,
                    'calling_station_id' => $row->callingstationid ?? null,
                    'mac_address'        => $row->callingstationid ?? null,
                    'nas_ip_address'     => $row->nasipaddress ?? null,
                    'nas_port_id'        => $row->nasportid ?? null,
                    'start_time'         => $row->acctstarttime ?? null,
                    'connected_at'       => $row->acctstarttime ?? null,
                    'duration'           => $duration,
                    'uptime'             => $duration,
                    'input_octets'       => $bytesIn,
                    'output_octets'      => $bytesOut,
                    'package'            => $pkg ? [
                        'id'         => (string) $pkg->id,
                        'name'       => $pkg->name,
                        'data_limit' => $pkg->data_limit,
                    ] : null,
                    'subscription_expires_at' => $hsUser?->subscription_expires_at,
                    'status'             => 'active',
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data'    => $data,
                'source'  => $source,
                'total'   => $data->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching live hotspot sessions', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch live sessions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get hotspot statistics
     */
    public function getStats()
    {
        try {
            $stats = [
                'total_users' => HotspotUser::count(),
                'active_users' => HotspotUser::where('has_active_subscription', true)->count(),
                'expired_users' => HotspotUser::where('status', 'expired')->count(),
                'active_sessions' => RadiusSession::where('status', 'active')->count(),
                'today_logins' => RadiusSession::whereDate('session_start', today())->count(),
                'total_data_usage' => DB::table('radacct')
                    ->selectRaw('SUM(acctinputoctets + acctoutputoctets) as total')
                    ->value('total') ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching hotspot stats', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
            ], 500);
        }
    }
}
