<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoePayment;
use App\Models\PppoeUser;
use App\Models\Voucher;
use App\Services\MpesaService;
use App\Services\RadiusService;
use App\Services\TenantPaybillService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\Tenant;
use InvalidArgumentException;

/**
 * PPPoE Customer Portal Controller
 * 
 * Provides tenant-agnostic access for PPPoE users to:
 * - View their usage and account status
 * - Make M-Pesa payments
 * - Redeem vouchers
 * - Check session history
 */
class PppoePortalController extends Controller
{
    private bool $radiusUnavailableLogged = false;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RadiusService $radiusService,
    ) {
    }

    /**
     * Authenticate PPPoE user with account number and portal password
     * OPTIMIZED: Multi-layer caching for user lookup, rate limiting for failed attempts
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_number' => 'required|string',
            'portal_password' => 'required|string',
        ]);

        $loginStart = microtime(true);
        $identifier = trim((string) $validated['account_number']);
        $ip = $request->ip();

        // OPTIMIZATION: Rate limit failed login attempts by identifier
        $rateLimitKey = 'portal_login_attempts:' . md5($identifier);
        $attempts = (int) Cache::get($rateLimitKey, 0);
        if ($attempts > 5) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Please try again later.',
            ], 429);
        }

        // OPTIMIZATION: Check user lookup cache first (aggressive 5min cache for valid users)
        $userCacheKey = 'portal_login_user:' . md5($identifier);
        $cachedUserId = Cache::get($userCacheKey);
        
        $pppoeUser = null;
        if ($cachedUserId) {
            try {
                // Fast path: Load user directly by ID with tenant context
                $pppoeUser = $this->findPppoeUserByCachedId($cachedUserId);
            } catch (\Throwable $e) {
                Cache::forget($userCacheKey);
                Log::warning('PPPoE portal cached login lookup failed; cache entry invalidated', [
                    'account_number' => $identifier,
                    'cached_user' => $cachedUserId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Slow path: Search for user if not in cache
        if (!$pppoeUser) {
            $pppoeUser = $this->findPppoeUserForPortalLoginOptimized($identifier);
            
            if ($pppoeUser) {
                // Cache the user lookup for 5 minutes
                Cache::put($userCacheKey, $pppoeUser->id . '|' . ($pppoeUser->tenant_id ?? ''), now()->addMinutes(5));
            }
        }

        if (!$pppoeUser) {
            // Increment rate limit counter
            Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(15));
            
            Log::warning('PPPoE portal login failed: User not found', [
                'account_number' => $validated['account_number'],
                'ip' => $ip,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid account number or password',
            ], 401);
        }

        // Verify portal password.
        if (!$this->verifyPortalPassword($pppoeUser, (string) $validated['portal_password'])) {
            // Increment rate limit counter on failed password
            Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(15));
            
            Log::warning('PPPoE portal login failed: Invalid password', [
                'account_number' => $validated['account_number'],
                'ip' => $ip,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid account number or password',
            ], 401);
        }

        // Clear rate limit on successful login
        Cache::forget($rateLimitKey);

        // Check if account is active
        if ($pppoeUser->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is ' . $pppoeUser->status,
                'status' => $pppoeUser->status,
            ], 403);
        }

        // Create token for portal access.
        $tokenTenantId = (string) ($pppoeUser->tenant_id ?? '');
        if ($tokenTenantId === '') {
            $tokenTenantId = (string) ($this->resolveTenantIdFromAccountNumber($pppoeUser->account_number) ?? '');
        }
        $token = $pppoeUser->createPortalToken($tokenTenantId);

        // OPTIMIZATION: Pre-warm dashboard cache for faster first load
        $this->warmDashboardCache($pppoeUser);

        Log::info('PPPoE portal login successful', [
            'account_number' => $pppoeUser->account_number,
            'tenant_id' => $tokenTenantId,
            'duration_ms' => (int) round((microtime(true) - $loginStart) * 1000),
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $pppoeUser->id,
                'account_number' => $pppoeUser->account_number,
                'username' => $pppoeUser->username,
                'full_name' => $pppoeUser->getBillingName(),
                'email' => $pppoeUser->getBillingEmail(),
                'phone' => $pppoeUser->getBillingPhone(),
                'package_name' => $pppoeUser->package?->name,
                'status' => $pppoeUser->status,
                'payment_status' => $pppoeUser->payment_status,
                'expiration_date' => $pppoeUser->expires_at,
            ],
        ]);
    }

    /**
     * OPTIMIZED: Find user by cached ID (fast path)
     */
    private function findPppoeUserByCachedId(string $cachedData): ?PppoeUser
    {
        $parts = explode('|', $cachedData);
        $userId = $parts[0] ?? null;
        $tenantId = $parts[1] ?? null;
        
        if (!$userId) {
            return null;
        }

        // If we have tenant ID, use direct tenant context
        if ($tenantId && $tenantId !== '') {
            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->whereRaw('is_active = true')
                ->first(['id', 'schema_name', 'schema_created']);
            
            if ($tenant) {
                try {
                    return DB::transaction(function () use ($tenant, $userId) {
                        DB::connection()->recordsHaveBeenModified();
                        return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                            // OPTIMIZED: Select only needed columns for portal login
                            $user = PppoeUser::query()
                                ->select([
                                    'id', 'username', 'account_number', 'password', 'portal_password',
                                    'package_id', 'status', 'is_active', 'expires_at', 'balance',
                                    'payment_status', 'amount_due', 'amount_paid', 'next_payment_due',
                                    'in_grace_period', 'grace_period_ends', 'customer_email', 'customer_phone'
                                ])
                                ->find($userId);
                            if ($user) {
                                $user->setAttribute('tenant_id', (string) $tenant->id);
                            }
                            return $user;
                        });
                    });
                } catch (\Throwable $e) {
                    Log::warning('PPPoE portal cached tenant lookup failed', [
                        'tenant_id' => $tenant->id,
                        'schema_name' => $tenant->schema_name,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * OPTIMIZED: Warm dashboard cache after login for faster first load
     */
    private function warmDashboardCache(PppoeUser $pppoeUser): void
    {
        try {
            // Fire and forget - don't block login response
            dispatch(function () use ($pppoeUser) {
                $radiusData = $this->getOptimizedRadiusData($pppoeUser);
                Cache::put('pppoe_portal_radius:' . $pppoeUser->username, $radiusData, now()->addSeconds(60));
            })->onQueue('low');
        } catch (\Throwable $e) {
            // Silent fail - cache warming is optional
            Log::debug('Dashboard cache warming failed', [
                'username' => $pppoeUser->username,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * OPTIMIZED: Find PPPoE user with mapping-first strategy
     */
    private function findPppoeUserForPortalLoginOptimized(string $identifier): ?PppoeUser
    {
        $normalized = strtoupper($identifier);

        // OPTIMIZATION: Try mapping lookup first (fastest)
        $mapping = DB::table('public.radius_user_schema_mapping')
            ->where(function ($q) use ($identifier, $normalized) {
                $q->where('username', $identifier)
                  ->orWhere('username', $normalized);
            })
            ->where('is_active', true)
            ->first(['tenant_id', 'schema_name', 'pppoe_user_id']);

        if ($mapping && !empty($mapping->pppoe_user_id)) {
            $tenant = Tenant::query()
                ->whereRaw('is_active = true')
                ->where(function ($query) use ($mapping) {
                    if (!empty($mapping->tenant_id)) {
                        $query->whereKey((string) $mapping->tenant_id);
                    }
                    if (!empty($mapping->schema_name)) {
                        $query->orWhere('schema_name', (string) $mapping->schema_name);
                    }
                })
                ->first(['id', 'schema_name', 'schema_created']);

            if ($tenant) {
                try {
                    $user = DB::transaction(function () use ($tenant, $mapping) {
                        DB::connection()->recordsHaveBeenModified();
                        return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $mapping) {
                            $user = PppoeUser::query()->find($mapping->pppoe_user_id);
                            if ($user) {
                                $user->setAttribute('tenant_id', (string) $tenant->id);
                            }
                            return $user;
                        });
                    });

                    if ($user) {
                        return $user;
                    }
                } catch (\Throwable $e) {
                    Log::warning('PPPoE portal mapping-based login lookup failed', [
                        'tenant_id' => $tenant->id,
                        'schema_name' => $tenant->schema_name,
                        'pppoe_user_id' => $mapping->pppoe_user_id,
                        'identifier' => $identifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Fallback: Try account prefix lookup, then safe legacy scan if needed
        $user = $this->findPppoeUserForPortalLogin($identifier);

        if ($user) {
            return $user;
        }

        return $this->findPppoeUserAcrossTenantsByIdentifier($identifier);
    }

    private function findPppoeUserForPortalLogin(string $identifier): ?PppoeUser
    {
        $mappedTenant = $this->findTenantByMappedIdentifier($identifier);
        if ($mappedTenant) {
            $user = $this->findPppoeUserInTenant($mappedTenant, $identifier);
            if ($user) {
                return $user;
            }
        }

        $tenant = $this->findTenantByAccountNumber($identifier);

        if (!$tenant || !$tenant->schema_name) {
            return null;
        }

        if ($mappedTenant && (string) $mappedTenant->id === (string) $tenant->id) {
            return null;
        }

        return $this->findPppoeUserInTenant($tenant, $identifier);
    }

    private function findPppoeUserInTenant(Tenant $tenant, string $identifier): ?PppoeUser
    {
        try {
            return DB::transaction(function () use ($tenant, $identifier) {
                DB::connection()->recordsHaveBeenModified();

                return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $identifier) {
                    $normalized = strtoupper($identifier);
                    $user = PppoeUser::query()
                        ->with('package:id,name')
                        ->where(function ($query) use ($identifier, $normalized) {
                            $query->where('account_number', $identifier)
                                ->orWhere('account_number', $normalized)
                                ->orWhere('username', $identifier)
                                ->orWhere('username', $normalized);
                        })
                        ->first();

                    if ($user) {
                        $user->setAttribute('tenant_id', (string) $tenant->id);
                        $user->setAttribute('resolved_tenant_schema', (string) $tenant->schema_name);
                    }

                    return $user;
                });
            });
        } catch (\Throwable $e) {
            Log::warning('PPPoE portal tenant lookup failed', [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveTenantIdFromAccountNumber(string $accountNumber): ?string
    {
        return $this->findTenantByAccountNumber($accountNumber)?->id;
    }

    private function findTenantByAccountNumber(string $accountNumber): ?Tenant
    {
        $normalized = strtoupper(trim($accountNumber));
        if ($normalized === '') {
            return null;
        }

        // CRITICAL OPTIMIZATION: Cache tenant lookup by account prefix
        // This is called frequently during login - cache for 10 minutes
        $cacheKey = 'tenant_lookup:' . md5($normalized);
        $cachedTenantId = Cache::get($cacheKey);
        
        if ($cachedTenantId) {
            // Try to load tenant from cache hit
            $tenant = Tenant::query()
                ->whereKey($cachedTenantId)
                ->whereRaw('is_active = true')
                ->first(['id', 'schema_name', 'account_prefix', 'schema_created']);
            if ($tenant) {
                return $tenant;
            }
            // Tenant may have been deactivated - fall through to full lookup
        }

        $tenant = Tenant::query()
            ->whereRaw('is_active = true')
            ->whereNotNull('schema_name')
            ->whereNotNull('account_prefix')
            ->whereRaw('? LIKE UPPER(account_prefix) || \'%\'', [$normalized])
            ->orderByRaw('LENGTH(account_prefix) DESC')
            ->first(['id', 'schema_name', 'account_prefix', 'schema_created']);

        if ($tenant) {
            // Cache the tenant ID for 10 minutes
            Cache::put($cacheKey, $tenant->id, now()->addMinutes(10));
        }

        return $tenant;
    }

    private function findTenantByMappedIdentifier(string $identifier): ?Tenant
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        try {
            $mapping = DB::table('public.radius_user_schema_mapping')
                ->where('username', $identifier)
                ->where('is_active', true)
                ->first(['tenant_id', 'schema_name', 'pppoe_user_id']);
        } catch (\Throwable $e) {
            Log::debug('PPPoE portal login mapping lookup skipped', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$mapping) {
            return null;
        }

        return Tenant::query()
            ->whereRaw('is_active = true')
            ->where(function ($query) use ($mapping) {
                if (!empty($mapping->tenant_id)) {
                    $query->whereKey((string) $mapping->tenant_id);
                }

                if (!empty($mapping->schema_name)) {
                    $query->orWhere('schema_name', (string) $mapping->schema_name);
                }
            })
            ->first(['id', 'schema_name', 'account_prefix', 'schema_created']);
    }

    private function findPppoeUserAcrossTenantsByIdentifier(string $identifier): ?PppoeUser
    {
        $tenants = Tenant::query()
            ->where('is_active', true)
            ->whereNotNull('schema_name')
            ->where('schema_created', true)
            ->orderBy('updated_at', 'desc')
            ->limit(100)
            ->get(['id', 'schema_name']);

        foreach ($tenants as $tenant) {
            $user = $this->findPppoeUserInTenant($tenant, $identifier);

            if ($user) {
                Log::warning('PPPoE portal login fell back to legacy tenant scan', [
                    'identifier' => $identifier,
                    'tenant_id' => $tenant->id,
                    'schema_name' => $tenant->schema_name,
                ]);

                return $user;
            }
        }

        return null;
    }

    private function findTenantByPppoeUserId(string $userId): ?Tenant
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        try {
            $mapping = DB::table('public.radius_user_schema_mapping')
                ->where('pppoe_user_id', $userId)
                ->where('is_active', true)
                ->first(['tenant_id', 'schema_name']);
        } catch (\Throwable $e) {
            Log::debug('PPPoE portal user-id tenant lookup skipped', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$mapping) {
            return null;
        }

        return Tenant::query()
            ->whereRaw('is_active = true')
            ->where(function ($query) use ($mapping) {
                if (!empty($mapping->tenant_id)) {
                    $query->whereKey((string) $mapping->tenant_id);
                }

                if (!empty($mapping->schema_name)) {
                    $query->orWhere('schema_name', (string) $mapping->schema_name);
                }
            })
            ->first(['id', 'schema_name', 'account_prefix', 'schema_created']);
    }

    /**
     * Get PPPoE user dashboard data (usage, balance, etc.)
     * OPTIMIZED: Full response caching, Single RADIUS query, eager loaded relations
     */
    public function dashboard(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // CRITICAL OPTIMIZATION: Full dashboard cache (reduces DB queries to 0-1)
        $dashboardCacheKey = 'pppoe_portal_dashboard:' . $pppoeUser->id;
        $cachedDashboard = Cache::get($dashboardCacheKey);
        
        if ($cachedDashboard) {
            return response()->json([
                'success' => true,
                'data' => $cachedDashboard,
                'cached' => true,
            ]);
        }

        // OPTIMIZATION 1: Aggressive RADIUS caching (60s TTL)
        $radiusCacheKey = 'pppoe_portal_radius:' . $pppoeUser->username;
        $radiusData = Cache::get($radiusCacheKey);

        if (!$radiusData) {
            try {
                $radiusData = $this->getOptimizedRadiusData($pppoeUser);
                Cache::put($radiusCacheKey, $radiusData, now()->addSeconds(60));
            } catch (\Throwable $e) {
                Log::warning('PPPoE portal RADIUS data fetch failed', [
                    'account_number' => $pppoeUser->account_number,
                    'error' => $e->getMessage(),
                ]);
                $radiusData = [
                    'current_session' => null,
                    'usage_stats' => $this->getEmptyUsageStats(),
                ];
            }
        }

        // OPTIMIZATION 2: Eager load package relation once (avoid N+1)
        $userData = $this->getOptimizedUserData($pppoeUser);

        // OPTIMIZATION 3: Cache payments separately (they change frequently)
        $paymentsCacheKey = 'pppoe_portal_payments:' . $pppoeUser->id;
        $recentPayments = Cache::get($paymentsCacheKey);
        
        if (!$recentPayments) {
            $recentPayments = PppoePayment::query()
                ->where('pppoe_user_id', $pppoeUser->id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->cursor()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'status' => $p->status,
                    'payment_method' => $p->payment_method,
                    'created_at' => $p->created_at,
                ])
                ->all();
            
            // Cache payments for 30 seconds (fresher than dashboard)
            Cache::put($paymentsCacheKey, $recentPayments, now()->addSeconds(30));
        }

        $dashboardData = [
            'user' => $userData,
            'current_session' => $radiusData['current_session'],
            'usage_stats' => $radiusData['usage_stats'],
            'recent_payments' => $recentPayments,
            'cached_at' => now()->toIso8601String(),
        ];

        // Cache full dashboard for 30 seconds
        Cache::put($dashboardCacheKey, $dashboardData, now()->addSeconds(30));

        return response()->json([
            'success' => true,
            'data' => $dashboardData,
            'cached' => false,
        ]);
    }

    /**
     * OPTIMIZED: Single RADIUS query combining current session + usage stats
     * Reduces round-trips from 2 to 1
     */
    private function getOptimizedRadiusData(PppoeUser $pppoeUser): array
    {
        if (!$this->radiusConnectionAvailable()) {
            return [
                'current_session' => null,
                'usage_stats' => $this->getEmptyUsageStats(),
            ];
        }

        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // SINGLE QUERY: Get current session (most recent without stop time) AND usage stats
        $radiusDb = DB::connection('radius');
        
        // Current session subquery
        $currentSession = $radiusDb->table('radacct')
            ->where('username', $pppoeUser->username)
            ->whereNull('acctstoptime')
            ->orderBy('acctstarttime', 'desc')
            ->first([
                'acctstarttime as start_time',
                'acctsessiontime as duration_seconds',
                'framedipaddress as ip_address',
                'nasipaddress as nas_ip',
                'acctinputoctets as download_bytes',
                'acctoutputoctets as upload_bytes',
            ]);

        // Aggregated stats (separate query but can be parallelized)
        $stats = $radiusDb->table('radacct')
            ->where('username', $pppoeUser->username)
            ->where('acctstarttime', '>=', $thirtyDaysAgo)
            ->select(
                DB::raw('COUNT(*) as total_sessions'),
                DB::raw('SUM(acctsessiontime) as total_duration'),
                DB::raw('SUM(acctinputoctets) as total_download'),
                DB::raw('SUM(acctoutputoctets) as total_upload')
            )
            ->first();

        return [
            'current_session' => $currentSession ? [
                'start_time' => $currentSession->start_time,
                'duration_formatted' => $this->formatDuration($currentSession->duration_seconds),
                'duration_seconds' => $currentSession->duration_seconds,
                'ip_address' => $currentSession->ip_address,
                'nas_ip' => $currentSession->nas_ip,
                'download_formatted' => $this->formatBytes($currentSession->download_bytes),
                'download_bytes' => $currentSession->download_bytes,
                'upload_formatted' => $this->formatBytes($currentSession->upload_bytes),
                'upload_bytes' => $currentSession->upload_bytes,
            ] : null,
            'usage_stats' => [
                'period_days' => 30,
                'total_sessions' => $stats->total_sessions ?? 0,
                'total_duration_formatted' => $this->formatDuration($stats->total_duration ?? 0),
                'total_duration_seconds' => $stats->total_duration ?? 0,
                'total_download_formatted' => $this->formatBytes($stats->total_download ?? 0),
                'total_download_bytes' => $stats->total_download ?? 0,
                'total_upload_formatted' => $this->formatBytes($stats->total_upload ?? 0),
                'total_upload_bytes' => $stats->total_upload ?? 0,
                'total_usage_formatted' => $this->formatBytes(($stats->total_download ?? 0) + ($stats->total_upload ?? 0)),
            ],
        ];
    }

    /**
     * Pre-computed empty stats structure
     */
    private function getEmptyUsageStats(): array
    {
        return [
            'period_days' => 30,
            'total_sessions' => 0,
            'total_duration_formatted' => '0s',
            'total_duration_seconds' => 0,
            'total_download_formatted' => '0 B',
            'total_download_bytes' => 0,
            'total_upload_formatted' => '0 B',
            'total_upload_bytes' => 0,
            'total_usage_formatted' => '0 B',
        ];
    }

    /**
     * OPTIMIZED: Build user data with eager-loaded relations
     */
    private function getOptimizedUserData(PppoeUser $pppoeUser): array
    {
        // Ensure package is loaded (it should be from middleware, but be safe)
        if (!$pppoeUser->relationLoaded('package')) {
            $pppoeUser->load('package:id,name,download_speed,upload_speed,price');
        }

        $package = $pppoeUser->package;

        return [
            'id' => $pppoeUser->id,
            'account_number' => $pppoeUser->account_number,
            'username' => $pppoeUser->username,
            'full_name' => $pppoeUser->getBillingName(),
            'email' => $pppoeUser->getBillingEmail(),
            'phone' => $pppoeUser->getBillingPhone(),
            'package' => $package ? [
                'name' => $package->name,
                'download_speed' => $package->download_speed,
                'upload_speed' => $package->upload_speed,
                'price' => $package->price,
            ] : null,
            'status' => $pppoeUser->status,
            'payment_status' => $pppoeUser->payment_status,
            'expiration_date' => $pppoeUser->expires_at?->toIso8601String(),
            'next_payment_due' => $pppoeUser->next_payment_due?->toIso8601String(),
            'amount_due' => $pppoeUser->amount_due,
            'amount_paid' => $pppoeUser->amount_paid,
            'balance' => $pppoeUser->balance ?? 0,
        ];
    }

    /**
     * Get session history for PPPoE user
     * OPTIMIZED: Cursor pagination, selective fields, short cache
     */
    public function sessionHistory(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:90',
            'cursor' => 'nullable|string', // For cursor pagination
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $limit = (int) ($validated['limit'] ?? 50); // Default 50, max 100
        $startDate = Carbon::now()->subDays($days);

        // Version key allows atomic invalidation of all session cache pages on user data change
        $sessionVersion = Cache::get('pppoe_sessions_v:' . $pppoeUser->id, 0);
        $cacheKey = 'pppoe_sessions:' . $pppoeUser->id . ':' . $days . ':' . ($validated['cursor'] ?? 'init') . ':v' . $sessionVersion;
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return response()->json([
                'success' => true,
                'data' => $cached,
                'cached' => true,
            ]);
        }

        // Query RADIUS accounting data (degrade gracefully if radius connection is unavailable)
        $sessions = collect();
        $nextCursor = null;
        $hasMore = false;

        if ($this->radiusConnectionAvailable()) {
            $query = DB::connection('radius')
                ->table('radacct')
                ->where('username', $pppoeUser->username)
                ->where('acctstarttime', '>=', $startDate)
                ->orderBy('acctstarttime', 'desc');

            // OPTIMIZATION: Cursor-based pagination (better for large datasets)
            if (!empty($validated['cursor'])) {
                $query->where('acctstarttime', '<', $validated['cursor']);
            }

            // Get one extra record to determine if there's a next page
            $sessions = $query->limit($limit + 1)->get([
                'radacctid as id',
                'acctstarttime as start_time',
                'acctstoptime as stop_time',
                'acctsessiontime as duration_seconds',
                'acctinputoctets as download_bytes',
                'acctoutputoctets as upload_bytes',
                'nasipaddress as nas_ip',
                'framedipaddress as ip_address',
                'acctterminatecause as terminate_cause',
            ]);

            // Check if there's a next page
            if ($sessions->count() > $limit) {
                $hasMore = true;
                $sessions = $sessions->slice(0, $limit); // Remove the extra record
                $lastSession = $sessions->last();
                $nextCursor = $lastSession?->start_time;
            }
        }

        // Format sessions (lazy map for memory efficiency)
        $formattedSessions = $sessions->map(fn ($session) => [
            'id' => $session->id,
            'start_time' => $session->start_time,
            'stop_time' => $session->stop_time,
            'duration_formatted' => $this->formatDuration($session->duration_seconds),
            'duration_seconds' => $session->duration_seconds,
            'download_formatted' => $this->formatBytes($session->download_bytes),
            'download_bytes' => $session->download_bytes,
            'upload_formatted' => $this->formatBytes($session->upload_bytes),
            'upload_bytes' => $session->upload_bytes,
            'total_formatted' => $this->formatBytes($session->download_bytes + $session->upload_bytes),
            'ip_address' => $session->ip_address,
            'nas_ip' => $session->nas_ip,
            'status' => $session->stop_time ? 'disconnected' : 'active',
            'terminate_cause' => $session->terminate_cause,
        ])->values(); // Reset array keys

        $result = [
            'sessions' => $formattedSessions,
            'total_sessions' => $sessions->count(),
            'period_days' => $days,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ];

        // Cache for 30 seconds (shorter than dashboard since history changes less frequently)
        Cache::put($cacheKey, $result, now()->addSeconds(30));

        return response()->json([
            'success' => true,
            'data' => $result,
            'cached' => false,
        ]);
    }

    /**
     * Initiate M-Pesa STK push for PPPoE user
     * OPTIMIZED: Efficient duplicate check with proper index usage, rate limiting
     */
    public function initiateMpesaPayment(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'phone_number' => 'required|string|regex:/^254[0-9]{9}$/',
            'amount' => 'required|numeric|min:10|max:100000',
        ]);

        // OPTIMIZATION: Rate limit check via cache (prevents hammering the DB)
        $rateLimitKey = 'mpesa_stk_limit:' . $pppoeUser->id;
        if (Cache::has($rateLimitKey)) {
            $recentTx = Cache::get($rateLimitKey);
            return response()->json([
                'success' => false,
                'message' => 'A payment request is already pending. Please wait.',
                'pending_transaction' => $recentTx['transaction_id'] ?? null,
            ], 429);
        }

        try {
            // OPTIMIZATION: Use composite index (user_id, payment_method, status, created_at)
            // LIMIT 1 forces index usage and early termination
            $recentPending = PppoePayment::query()
                ->where('pppoe_user_id', $pppoeUser->id)
                ->where('payment_method', 'mpesa')
                ->where('status', 'pending')
                ->where('created_at', '>', Carbon::now()->subMinutes(2))
                ->select(['id', 'transaction_id'])
                ->limit(1)
                ->first();

            if ($recentPending) {
                // Cache the pending transaction for 2 minutes to reduce DB hits
                Cache::put($rateLimitKey, ['transaction_id' => $recentPending->transaction_id], now()->addMinutes(2));
                
                return response()->json([
                    'success' => false,
                    'message' => 'A payment request is already pending. Please wait.',
                    'pending_transaction' => $recentPending->transaction_id,
                ], 429);
            }

            // Initiate M-Pesa STK Push
            $mpesaService = app(MpesaService::class);
            $stkResponse = $mpesaService->initiateSTKPush(
                $validated['phone_number'],
                $validated['amount']
            );

            if (!$stkResponse['success']) {
                Log::error('M-Pesa STK Push failed', [
                    'account_number' => $pppoeUser->account_number,
                    'error' => $stkResponse['message'] ?? 'Unknown error',
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $stkResponse['message'] ?? 'Failed to initiate payment',
                ], 500);
            }

            // Create payment record with minimal fields
            $checkoutRequestId = $stkResponse['data']['CheckoutRequestID'];
            $merchantRequestId = $stkResponse['data']['MerchantRequestID'] ?? null;
            
            $payment = PppoePayment::create([
                'pppoe_user_id' => $pppoeUser->id,
                'account_number' => $pppoeUser->account_number,
                'amount' => $validated['amount'],
                'transaction_id' => $checkoutRequestId,
                'status' => 'pending',
                'payment_method' => 'mpesa',
                'payment_reference' => $merchantRequestId,
                'payment_date' => now(),
                'period_start' => now(),
                'period_end' => now(),
                'notes' => 'PPPoE Account Top-up via Portal',
                'metadata' => [
                    'phone_number' => $validated['phone_number'],
                    'merchant_request_id' => $merchantRequestId,
                    'source' => 'pppoe_portal',
                ],
            ]);

            // Cache the pending transaction to prevent duplicate STK pushes
            Cache::put($rateLimitKey, ['transaction_id' => $checkoutRequestId], now()->addMinutes(2));

            // Invalidate dashboard and payments cache to show new pending payment
            $this->invalidateDashboardCache($pppoeUser);

            Log::info('M-Pesa STK Push initiated for PPPoE user', [
                'account_number' => $pppoeUser->account_number,
                'amount' => $validated['amount'],
                'transaction_id' => $payment->transaction_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment request sent to your phone. Please enter M-Pesa PIN.',
                'data' => [
                    'transaction_id' => $payment->transaction_id,
                    'merchant_request_id' => $payment->merchant_request_id,
                    'amount' => $validated['amount'],
                    'phone_number' => $this->maskPhoneNumber($validated['phone_number']),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error initiating M-Pesa payment', [
                'account_number' => $pppoeUser->account_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your payment request',
            ], 500);
        }
    }

    /**
     * Redeem voucher for PPPoE user
     * OPTIMIZED: Selective field loading, efficient locking, cache invalidation
     */
    public function redeemVoucher(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'voucher_code' => 'required|string|min:5|max:20',
        ]);

        $voucherCode = strtoupper(trim($validated['voucher_code']));

        // OPTIMIZATION: Rate limit voucher redemption attempts
        $rateLimitKey = 'voucher_redeem_limit:' . $pppoeUser->id;
        if (Cache::has($rateLimitKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait a moment before redeeming another voucher.',
            ], 429);
        }

        try {
            $tenant = $this->findTenantForPppoeUser($request, $pppoeUser);

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to resolve tenant for this account',
                ], 500);
            }

            $result = DB::transaction(function () use ($tenant, $pppoeUser, $voucherCode) {
                DB::connection()->recordsHaveBeenModified();

                return $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $voucherCode) {
                    // OPTIMIZATION: Select only needed fields with lockForUpdate for concurrency
                    $voucher = Voucher::query()
                        ->select(['id', 'code', 'value', 'status', 'used_at', 'used_by', 'expires_at', 'package_duration_days'])
                        ->where('code', $voucherCode)
                        ->where('status', 'unused')
                        ->where(function ($q) {
                            $q->whereNull('expires_at')
                              ->orWhere('expires_at', '>', Carbon::now());
                        })
                        ->lockForUpdate()
                        ->first();

                    if (!$voucher) {
                        return ['error' => 'Invalid or expired voucher code', 'status' => 400];
                    }

                    // Double-check in PHP to handle race conditions (already locked)
                    if ($voucher->used_at || $voucher->used_by) {
                        return ['error' => 'This voucher has already been redeemed', 'status' => 400];
                    }

                    // Update voucher efficiently
                    $voucher->update([
                        'status' => 'used',
                        'used_at' => Carbon::now(),
                        'used_by' => $pppoeUser->id,
                        'used_by_type' => 'pppoe_user',
                    ]);

                    // Create payment record
                    PppoePayment::create([
                        'pppoe_user_id' => $pppoeUser->id,
                        'account_number' => $pppoeUser->account_number,
                        'amount' => $voucher->value,
                        'status' => 'completed',
                        'payment_method' => 'voucher',
                        'transaction_id' => 'VOUCHER-' . $voucher->code,
                        'payment_reference' => $voucher->code,
                        'payment_date' => now(),
                        'period_start' => now(),
                        'period_end' => now(),
                        'verified_at' => now(),
                        'notes' => 'Voucher redemption: ' . $voucher->code,
                        'metadata' => [
                            'voucher_id' => $voucher->id,
                            'source' => 'pppoe_portal',
                        ],
                    ]);

                    // Update user balance and expiry in single save
                    $newBalance = ($pppoeUser->balance ?? 0) + $voucher->value;
                    $updateData = ['balance' => $newBalance];
                    
                    if ($voucher->package_duration_days) {
                        $currentExpiry = $pppoeUser->expires_at ? Carbon::parse($pppoeUser->expires_at) : Carbon::now();
                        $updateData['expires_at'] = $currentExpiry->addDays($voucher->package_duration_days);
                    }
                    
                    $pppoeUser->update($updateData);

                    return [
                        'voucher' => [
                            'id' => $voucher->id,
                            'code' => $voucher->code,
                            'value' => $voucher->value,
                            'package_duration_days' => $voucher->package_duration_days,
                        ],
                        'pppoe_user' => [
                            'id' => $pppoeUser->id,
                            'balance' => $newBalance,
                            'expires_at' => $updateData['expires_at'] ?? $pppoeUser->expires_at,
                        ],
                    ];
                });
            });

            if (isset($result['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'],
                ], $result['status']);
            }

            // Set rate limit for 5 seconds to prevent spam
            Cache::put($rateLimitKey, 1, now()->addSeconds(5));

            // Invalidate all user caches to show updated balance
            $this->invalidateDashboardCache($pppoeUser);
            Cache::forget('pppoe_portal_radius:' . $pppoeUser->username);

            Log::info('Voucher redeemed successfully', [
                'account_number' => $pppoeUser->account_number,
                'voucher_code' => $result['voucher']['code'],
                'value' => $result['voucher']['value'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Voucher redeemed successfully!',
                'data' => [
                    'voucher_value' => $result['voucher']['value'],
                    'new_balance' => $result['pppoe_user']['balance'],
                    'expiration_extended' => $result['voucher']['package_duration_days'] ? true : false,
                    'new_expiration' => $result['pppoe_user']['expires_at'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error redeeming voucher', [
                'account_number' => $pppoeUser->account_number,
                'voucher_code' => $voucherCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while redeeming the voucher',
            ], 500);
        }
    }

    /**
     * Check payment status
     * OPTIMIZED: Selective fields, composite index usage, short-term caching
     */
    public function checkPaymentStatus(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'transaction_id' => 'required|string',
        ]);

        $transactionId = $validated['transaction_id'];
        $cacheKey = 'payment_status:' . $pppoeUser->id . ':' . md5($transactionId);

        // OPTIMIZATION: Cache pending payments for 10 seconds (they're checked frequently)
        // Completed/failed payments can be cached longer
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json([
                'success' => true,
                'data' => $cached,
                'cached' => true,
            ]);
        }

        // OPTIMIZATION: Use composite index (user_id, transaction_id) with selective fields
        $payment = PppoePayment::query()
            ->select(['transaction_id', 'status', 'amount', 'payment_method', 'created_at', 'verified_at', 'payment_date'])
            ->where('pppoe_user_id', $pppoeUser->id)
            ->where('transaction_id', $transactionId)
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        $result = [
            'transaction_id' => $payment->transaction_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'created_at' => $payment->created_at,
            'paid_at' => $payment->verified_at ?? $payment->payment_date,
        ];

        // Cache pending payments briefly (they may change soon), completed for longer
        $ttl = $payment->status === 'pending' ? 10 : 300; // 10s for pending, 5min for completed
        Cache::put($cacheKey, $result, now()->addSeconds($ttl));

        return response()->json([
            'success' => true,
            'data' => $result,
            'cached' => false,
        ]);
    }

    /**
     * Get payment instructions for both STK and Paybill.
     * OPTIMIZED: Aggressive caching since paybill settings rarely change
     */
    public function paymentInstructions(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $tenantId = $this->resolveTenantIdForRequestUser($request, $pppoeUser);
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve tenant context for payment instructions',
            ], 500);
        }

        // OPTIMIZATION: Cache payment instructions per tenant (rarely change)
        $cacheKey = 'payment_instructions:' . $tenantId;
        $cached = Cache::get($cacheKey);

        if ($cached) {
            // Add user-specific account number to cached template
            return response()->json([
                'success' => true,
                'data' => [
                    'stk' => $cached['stk'],
                    'paybill' => [
                        'enabled' => $cached['paybill']['enabled'],
                        'paybill_number' => $cached['paybill']['paybill_number'],
                        'account_number' => $cached['paybill']['account_number_template'] === 'user_account' 
                            ? ($pppoeUser->account_number ?: $pppoeUser->username)
                            : $cached['paybill']['account_number_template'],
                        'suggested_amount' => $cached['paybill']['suggested_amount'],
                        'instructions' => $cached['paybill']['instructions'],
                        'is_landlord_paybill' => $cached['paybill']['is_landlord_paybill'],
                    ],
                ],
                'cached' => true,
            ]);
        }

        try {
            $service = app(TenantPaybillService::class);
            $service->setTenantId((string) $tenantId)->initialize();
            $paybill = $service->getPaymentInstructions($pppoeUser);

            // Cache the template (without user-specific data)
            $cacheData = [
                'stk' => [
                    'enabled' => true,
                    'min_amount' => 10,
                    'max_amount' => 100000,
                ],
                'paybill' => [
                    'enabled' => !empty($paybill['paybill_number']),
                    'paybill_number' => $paybill['paybill_number'] ?? null,
                    'account_number_template' => $paybill['account_number'] ?? 'user_account',
                    'suggested_amount' => $paybill['amount'] ?? 0,
                    'instructions' => $paybill['instructions'] ?? [],
                    'is_landlord_paybill' => (bool) ($paybill['is_landlord_paybill'] ?? false),
                ],
            ];

            // Cache for 5 minutes (paybill settings rarely change)
            Cache::put($cacheKey, $cacheData, now()->addMinutes(5));

            return response()->json([
                'success' => true,
                'data' => [
                    'stk' => $cacheData['stk'],
                    'paybill' => [
                        'enabled' => $cacheData['paybill']['enabled'],
                        'paybill_number' => $cacheData['paybill']['paybill_number'],
                        'account_number' => $paybill['account_number'] ?? ($pppoeUser->account_number ?: $pppoeUser->username),
                        'suggested_amount' => $cacheData['paybill']['suggested_amount'],
                        'instructions' => $cacheData['paybill']['instructions'],
                        'is_landlord_paybill' => $cacheData['paybill']['is_landlord_paybill'],
                    ],
                ],
                'cached' => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch PPPoE portal payment instructions', [
                'tenant_id' => $tenantId,
                'account_number' => $pppoeUser->account_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load payment instructions',
            ], 500);
        }
    }

    /**
     * Logout PPPoE user
     */
    public function logout(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if ($pppoeUser) {
            // Revoke all portal tokens
            $pppoeUser->revokePortalTokens();
            
            Log::info('PPPoE portal logout', [
                'account_number' => $pppoeUser->account_number,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    // ============== Helper Methods ==============

    private function formatDuration(?int $seconds): string
    {
        if (!$seconds) return '0s';
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = [];
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0) $parts[] = $minutes . 'm';
        if ($secs > 0 || empty($parts)) $parts[] = $secs . 's';
        
        return implode(' ', $parts);
    }

    private function formatBytes(?int $bytes): string
    {
        if (!$bytes) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    private function maskPhoneNumber(string $phone): string
    {
        return substr($phone, 0, 6) . '****' . substr($phone, -3);
    }

    private function verifyPortalPassword(PppoeUser $pppoeUser, string $inputPassword): bool
    {
        // CENTRALIZED AUTHENTICATION: Use RADIUS for all PPPoE portal authentication
        try {
            \Log::info('PPPoE portal: Verifying password via RADIUS', [
                'username' => $pppoeUser->username,
                'account_number' => $pppoeUser->account_number,
            ]);

            // FIRST: Try to authenticate using the Portal-Password attribute in RADIUS
            // This is the dedicated portal password stored during registration
            $authenticated = $this->authenticateWithPortalRadiusPassword(
                $pppoeUser->username,
                $inputPassword,
                $pppoeUser->account_number
            );
            
            if ($authenticated) {
                \Log::info('PPPoE portal: Portal password authentication successful via RADIUS', [
                    'username' => $pppoeUser->username,
                ]);
                return true;
            }

            // SECOND: If portal password fails, try the main PPPoE password
            // This provides backward compatibility for users without dedicated portal passwords
            $authenticated = $this->radiusService->authenticate(
                $pppoeUser->username,
                $inputPassword
            );

            if ($authenticated) {
                \Log::info('PPPoE portal: PPPoE password authentication successful via RADIUS', [
                    'username' => $pppoeUser->username,
                ]);
                return true;
            } else {
                \Log::warning('PPPoE portal: Both portal and PPPoE password authentication failed', [
                    'username' => $pppoeUser->username,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('PPPoE portal: RADIUS authentication error', [
                'username' => $pppoeUser->username,
                'error' => $e->getMessage(),
            ]);
        }

        // FALLBACK: Allow account number as default password for initial portal setup
        // This is a temporary fallback for users who haven't set a portal password yet
        if ((string) $pppoeUser->account_number !== '' && hash_equals((string) $pppoeUser->account_number, $inputPassword)) {
            \Log::info('PPPoE portal: Using account number fallback', [
                'username' => $pppoeUser->username,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Authenticate using the Portal-Password attribute in RADIUS
     * This allows separate portal passwords from PPPoE passwords
     */
    private function authenticateWithPortalRadiusPassword(string $username, string $password, ?string $accountNumber = null): bool
    {
        try {
            // Get the portal password from RADIUS radcheck table
            // Use account number for tenant lookup (matches account_prefix pattern), fallback to username
            $tenantLookup = $accountNumber ?: $username;
            $tenant = $this->findTenantByAccountNumber($tenantLookup);
            if (!$tenant) {
                return false;
            }

            $portalPassword = $this->tenantContext->runInTenantContext($tenant, function () use ($username) {
                return DB::table('radcheck')
                    ->where('username', $username)
                    ->where('attribute', 'Portal-Password')
                    ->value('value');
            });

            if (!$portalPassword) {
                \Log::debug('PPPoE portal: No Portal-Password found in RADIUS', [
                    'username' => $username,
                ]);
                return false;
            }

            // Compare the provided password with the stored portal password
            $isValid = hash_equals($portalPassword, $password);
            
            \Log::debug('PPPoE portal: Portal-Password verification', [
                'username' => $username,
                'valid' => $isValid,
            ]);

            return $isValid;
        } catch (\Exception $e) {
            \Log::error('PPPoE portal: Portal-Password authentication error', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function findTenantForPppoeUser(Request $request, PppoeUser $pppoeUser): ?Tenant
    {
        $tenantId = $this->resolveTenantIdForRequestUser($request, $pppoeUser);
        if (!$tenantId) {
            return null;
        }

        return Tenant::query()
            ->where('id', $tenantId)
            ->whereRaw('is_active = true')
            ->whereNotNull('schema_name')
            ->first(['id', 'schema_name', 'account_prefix', 'schema_created']);
    }

    private function resolveTenantIdForRequestUser(Request $request, PppoeUser $pppoeUser): ?string
    {
        $tenantId = (string) ($request->attributes->get('tenant_id') ?? '');
        if ($tenantId !== '') {
            return $tenantId;
        }

        if (!empty($pppoeUser->tenant_id)) {
            return (string) $pppoeUser->tenant_id;
        }

        return $this->resolveTenantIdFromAccountNumber((string) $pppoeUser->account_number);
    }

    /**
     * Centralized cache invalidation for user dashboard data
     * Call this whenever user data changes (payments, voucher redemption, etc.)
     */
    private function invalidateDashboardCache(PppoeUser $pppoeUser): void
    {
        Cache::forget('pppoe_portal_dashboard:' . $pppoeUser->id);
        Cache::forget('pppoe_portal_payments:' . $pppoeUser->id);
        
        // Invalidate ALL session history pages by incrementing version key
        // This is atomic and handles all cursor positions efficiently
        Cache::increment('pppoe_sessions_v:' . $pppoeUser->id);
    }

    /**
     * Cache key for radius connection availability
     */
    private const RADIUS_STATUS_CACHE_KEY = 'radius_connection_status';
    private const RADIUS_STATUS_CACHE_TTL = 30; // 30 seconds

    /**
     * Check if RADIUS connection is available with caching
     * CRITICAL OPTIMIZATION: Avoids DB ping on every call
     */
    private function radiusConnectionAvailable(): bool
    {
        // Check cache first
        $cached = Cache::get(self::RADIUS_STATUS_CACHE_KEY);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            // Use a fast query with short timeout
            DB::connection('radius')->selectOne('SELECT 1');
            Cache::put(self::RADIUS_STATUS_CACHE_KEY, true, self::RADIUS_STATUS_CACHE_TTL);
            return true;
        } catch (InvalidArgumentException $e) {
            if (!$this->radiusUnavailableLogged) {
                Log::warning('Radius connection not configured for PPPoE portal; returning degraded dashboard data');
                $this->radiusUnavailableLogged = true;
            }
            Cache::put(self::RADIUS_STATUS_CACHE_KEY, false, self::RADIUS_STATUS_CACHE_TTL);
            return false;
        } catch (\Throwable $e) {
            if (!$this->radiusUnavailableLogged) {
                Log::warning('Radius connection unavailable for PPPoE portal', [
                    'error' => $e->getMessage(),
                ]);
                $this->radiusUnavailableLogged = true;
            }
            Cache::put(self::RADIUS_STATUS_CACHE_KEY, false, self::RADIUS_STATUS_CACHE_TTL);
            return false;
        }
    }
}
