<?php

namespace App\Http\Controllers\Api;

use App\Helpers\PackageExpiryHelper;
use App\Http\Controllers\Controller;
use App\Models\HotspotUser;
use App\Models\HotspotSession;
use App\Models\Package;
use App\Models\RadiusSession;
use App\Jobs\DisconnectHotspotUserJob;
use App\Jobs\GrantHotspotAccessJob;
use App\Events\HotspotAccessRevoked;
use App\Events\HotspotUserCreated;
use App\Events\HotspotUserUpdated;
use App\Events\HotspotUserDeleted;
use App\Services\Hotspot\HotspotRadiusService;
use App\Services\MikroTik\BandwidthHelper;
use App\Services\RadiusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class HotspotController extends Controller
{
    private const LIST_CACHE_TTL_SECONDS = 15;
    private const LIVE_LIST_CACHE_TTL_SECONDS = 5;
    private const STATS_CACHE_TTL_SECONDS = 30;
    private const CACHE_VERSION_PREFIX = 'hotspot_cache_version:';

    protected $radiusService;

    public function __construct(RadiusService $radiusService)
    {
        $this->radiusService = $radiusService;
    }

    public function debugPaymentState(Request $request)
    {
        $user = $request->attributes->get('hotspot_user');

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'payment_id' => 'nullable|string',
        ]);

        $tenantId = (string) ($request->attributes->get('tenant_id') ?? $user->tenant_id ?? '');
        $paymentId = trim((string) ($validated['payment_id'] ?? ''));

        $latestPayment = null;
        if ($paymentId !== '') {
            $latestPayment = \App\Models\Payment::query()
                ->select(['id', 'phone_number', 'amount', 'package_id', 'router_id', 'status', 'mac_address', 'transaction_id', 'payment_method', 'payment_date', 'created_at', 'updated_at', 'callback_response'])
                ->where('id', $paymentId)
                ->where('phone_number', $user->phone_number)
                ->first();
        } else {
            $latestPayment = \App\Models\Payment::query()
                ->select(['id', 'phone_number', 'amount', 'package_id', 'router_id', 'status', 'mac_address', 'transaction_id', 'payment_method', 'payment_date', 'created_at', 'updated_at', 'callback_response'])
                ->where('phone_number', $user->phone_number)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->first();
        }

        $paymentTransactionId = (string) ($latestPayment?->transaction_id ?? '');
        $paymentStatusCacheKey = $paymentTransactionId !== ''
            ? 'payment_status:' . $user->id . ':' . md5($paymentTransactionId)
            : null;

        $jobCacheKey = 'hotspot_reconnect_job:' . $tenantId . ':' . ($latestPayment?->id ?? $user->id);
        $jobState = Cache::get($jobCacheKey) ?? [
            'status' => $user->has_active_subscription ? 'idle' : 'not_queued',
            'tenant_id' => $tenantId,
            'hotspot_user_id' => $user->id,
        ];

        $credentialsCacheKey = $latestPayment ? 'tenant_' . $tenantId . '_payment_credentials_' . $latestPayment->id : null;

        return response()->json([
            'success' => true,
            'data' => [
                'tenant_id' => $tenantId,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'phone_number' => $user->phone_number,
                    'status' => $user->status,
                    'has_active_subscription' => $user->has_active_subscription,
                    'subscription_expires_at' => $user->subscription_expires_at?->toIso8601String(),
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'last_login_ip' => $user->last_login_ip,
                    'package_id' => $user->package_id,
                    'package_name' => $user->package_name,
                    'data_used' => $user->data_used,
                    'data_limit' => $user->data_limit,
                ],
                'latest_payment' => $latestPayment ? [
                    'id' => $latestPayment->id,
                    'transaction_id' => $latestPayment->transaction_id,
                    'status' => $latestPayment->status,
                    'amount' => $latestPayment->amount,
                    'payment_date' => $latestPayment->payment_date?->toIso8601String(),
                    'created_at' => $latestPayment->created_at?->toIso8601String(),
                    'updated_at' => $latestPayment->updated_at?->toIso8601String(),
                    'callback_response' => $latestPayment->callback_response,
                ] : null,
                'callback' => $latestPayment ? [
                    'status' => $latestPayment->status,
                    'payment_date' => $latestPayment->payment_date?->toIso8601String(),
                    'callback_response' => $latestPayment->callback_response,
                    'payment_method' => $latestPayment->payment_method,
                ] : null,
                'cache' => [
                    'payment_status' => $paymentStatusCacheKey ? [
                        'key' => $paymentStatusCacheKey,
                        'exists' => Cache::has($paymentStatusCacheKey),
                        'value' => Cache::get($paymentStatusCacheKey),
                    ] : null,
                    'hotspot_credentials' => $credentialsCacheKey ? [
                        'key' => $credentialsCacheKey,
                        'exists' => Cache::has($credentialsCacheKey),
                        'value' => Cache::get($credentialsCacheKey),
                    ] : null,
                ],
                'provisioning_job' => $jobState,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

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

            // CENTRALIZED AUTHENTICATION: Use RADIUS for all hotspot authentication
            try {
                \Log::info('Hotspot: Verifying password via RADIUS', [
                    'username' => $username,
                ]);

                // Use RADIUS service for centralized authentication
                $authenticated = $this->radiusService->authenticate(
                    $username,
                    $password
                );

                if (!$authenticated) {
                    \Log::warning('Hotspot: RADIUS authentication failed', [
                        'username' => $username,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid username or password',
                    ], 401);
                }

                \Log::info('Hotspot: RADIUS authentication successful', [
                    'username' => $username,
                ]);
            } catch (\Exception $e) {
                \Log::error('Hotspot: RADIUS authentication error', [
                    'username' => $username,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication service unavailable',
                ], 503);
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

            $this->bustHotspotCache((string) ($hotspotUser->tenant_id ?? auth()->user()->tenant_id ?? ''));

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

            $this->bustHotspotCache((string) ($hotspotUser->tenant_id ?? auth()->user()->tenant_id ?? ''));

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
     * List hotspot users with optimized query
     */
    public function listUsers(Request $request)
    {
        try {
            $tenantId = (string) (auth()->user()->tenant_id ?? '');
            $cacheKey = $this->hotspotCacheKey('users', $tenantId, $request);

            $payload = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($request) {
                $query = HotspotUser::query()
                    ->select([
                        'id', 'username', 'phone_number', 'mac_address', 'status', 'is_active',
                        'has_active_subscription', 'package_id', 'package_name',
                        'subscription_expires_at', 'data_used', 'simultaneous_use',
                        'created_at', 'updated_at'
                    ])
                    ->with(['package:id,name,download_speed,upload_speed,speed'])
                    ->when($request->status, function ($q, $status) {
                        return $q->where('status', $status);
                    })
                    ->when($request->package_id, function ($q, $packageId) {
                        return $q->where('package_id', $packageId);
                    })
                    ->when($request->has_subscription !== null, function ($q) use ($request) {
                        return $q->where('has_active_subscription', filter_var($request->has_subscription, FILTER_VALIDATE_BOOLEAN));
                    })
                    ->when($request->search, function ($q, $search) {
                        $searchTerm = strtolower($search);
                        return $q->where(function ($sub) use ($searchTerm) {
                            $sub->whereRaw('LOWER(username) LIKE ?', ["%{$searchTerm}%"])
                                ->orWhereRaw('LOWER(phone_number) LIKE ?', ["%{$searchTerm}%"])
                                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
                        });
                    });

                $users = $query->latest()->paginate($request->per_page ?? 15);
                $users->getCollection()->transform(function ($user) {
                    $user->days_to_expiry = $user->subscription_expires_at
                        ? (int) now()->diffInDays($user->subscription_expires_at, false)
                        : null;
                    $user->is_expired = $user->subscription_expires_at && $user->subscription_expires_at->isPast();
                    return $user;
                });

                return [
                    'data' => $users->items(),
                    'meta' => [
                        'current_page' => $users->currentPage(),
                        'last_page' => $users->lastPage(),
                        'per_page' => $users->perPage(),
                        'total' => $users->total(),
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload['data'],
                'meta' => $payload['meta'],
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

            $user->days_to_expiry = $user->subscription_expires_at
                ? (int) now()->diffInDays($user->subscription_expires_at, false)
                : null;
            $user->is_expired = $user->subscription_expires_at && $user->subscription_expires_at->isPast();

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

            $this->bustHotspotCache((string) $tenantId);

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

            $this->bustHotspotCache((string) $tenantId);
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

            $this->bustHotspotCache((string) $tenantId);
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
            $tenantId = (string) (auth()->user()->tenant_id ?? '');
            $cacheKey = $this->hotspotCacheKey('sessions', $tenantId, $request);

            $payload = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($request) {
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

                return [
                    'data' => $sessions->items(),
                    'meta' => [
                        'current_page' => $sessions->currentPage(),
                        'last_page' => $sessions->lastPage(),
                        'per_page' => $sessions->perPage(),
                        'total' => $sessions->total(),
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload['data'],
                'meta' => $payload['meta'],
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
            $tenantId = (string) (auth()->user()->tenant_id ?? '');
            $cacheKey = $this->hotspotCacheKey('live_sessions', $tenantId, $request);

            $payload = Cache::remember($cacheKey, self::LIVE_LIST_CACHE_TTL_SECONDS, function () {
                $source = 'none';

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

                return [
                    'data' => $data,
                    'source' => $source,
                    'total' => $data->count(),
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $payload['data'],
                'source'  => $payload['source'],
                'total'   => $payload['total'],
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
            $tenantId = (string) (auth()->user()->tenant_id ?? '');
            $cacheKey = $this->hotspotCacheKey('stats', $tenantId);

            $stats = Cache::remember($cacheKey, self::STATS_CACHE_TTL_SECONDS, function () {
                $userCounts = HotspotUser::selectRaw("
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE has_active_subscription = true) as active,
                    COUNT(*) FILTER (WHERE status = 'expired') as expired
                ")->first();

                $today = now()->toDateString();
                $sessionCounts = RadiusSession::selectRaw("
                    COUNT(*) FILTER (WHERE status = 'active') as active_sessions,
                    COUNT(*) FILTER (WHERE session_start::date = ?) as today_logins
                ", [$today])->first();

                $dataUsage = DB::table('radacct')
                    ->selectRaw('COALESCE(SUM(acctinputoctets + acctoutputoctets), 0) as total')
                    ->value('total') ?? 0;

                return [
                    'total_users'      => (int) ($userCounts->total ?? 0),
                    'active_users'     => (int) ($userCounts->active ?? 0),
                    'expired_users'    => (int) ($userCounts->expired ?? 0),
                    'active_sessions'  => (int) ($sessionCounts->active_sessions ?? 0),
                    'today_logins'     => (int) ($sessionCounts->today_logins ?? 0),
                    'total_data_usage' => (float) $dataUsage,
                ];
            });

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

    /**
     * Delete a hotspot user
     */
    public function destroy(string $id)
    {
        try {
            // Select only needed columns
            $user = HotspotUser::query()
                ->select(['id', 'username'])
                ->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hotspot user not found',
                ], 404);
            }

            $username = $user->username;

            // Delete RADIUS entries
            DB::table('radcheck')->where('username', $username)->delete();
            DB::table('radreply')->where('username', $username)->delete();

            // Delete the user
            $user->delete();

            // Broadcast event for real-time updates
            $tenantId = auth()->user()->tenant_id ?? 'unknown';
            broadcast(new HotspotUserDeleted($id, $username, $tenantId))->toOthers();

            // Clear cache
            $this->bustHotspotCache((string) $tenantId);

            Log::info('Hotspot user deleted', ['user_id' => $id, 'username' => $username]);

            return response()->json([
                'success' => true,
                'message' => 'Hotspot user deleted',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete hotspot user', ['user_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete hotspot user: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function hotspotCacheKey(string $bucket, string $tenantId, Request $request = null): string
    {
        $tenantScope = $tenantId !== '' ? $tenantId : (string) (auth()->user()->tenant_id ?? 'global');
        $version = $this->hotspotCacheVersion($tenantScope, $bucket);
        $queryHash = $request ? md5(json_encode($request->query(), JSON_UNESCAPED_SLASHES)) : 'default';

        return "hotspot:{$bucket}:{$tenantScope}:v{$version}:{$queryHash}";
    }

    private function hotspotCacheVersion(string $tenantId, string $bucket): int
    {
        return (int) Cache::rememberForever(self::CACHE_VERSION_PREFIX . $bucket . ':' . $tenantId, static fn (): int => 1);
    }

    private function bustHotspotCache(string $tenantId, array $buckets = ['users', 'sessions', 'live_sessions', 'stats']): void
    {
        foreach ($buckets as $bucket) {
            $versionKey = self::CACHE_VERSION_PREFIX . $bucket . ':' . $tenantId;
            Cache::forever($versionKey, $this->hotspotCacheVersion($tenantId, $bucket) + 1);
        }
    }
}
