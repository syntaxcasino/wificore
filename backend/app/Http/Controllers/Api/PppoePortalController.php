<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MpesaTransactionMap;
use App\Models\Package;
use App\Models\PppoePayment;
use App\Models\PppoeTimedVoucher;
use App\Models\PppoeUser;
use App\Models\SystemLog;
use App\Models\Voucher;
use App\Services\MpesaService;
use App\Services\RadiusService;
use App\Services\TenantPaybillService;
use App\Services\TenantContext;
use App\Services\PaymentTraceLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Tenant;
use App\Helpers\PackageExpiryHelper;
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
    private const LOGIN_USER_CACHE_TTL_SECONDS = 300;
    private const DASHBOARD_CACHE_TTL_SECONDS = 15;
    private const PAYMENTS_CACHE_TTL_SECONDS = 15;
    private const RADIUS_CACHE_TTL_SECONDS = 15;
    private const DASHBOARD_CACHE_LOCK_SECONDS = 10;

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
        $attempts = (int) $this->cacheGet($rateLimitKey, 0);
        if ($attempts > 5) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Please try again later.',
            ], 429);
        }

        // OPTIMIZATION: Check user lookup cache first (aggressive 5min cache for valid users)
        $userCacheKey = 'portal_login_user:' . md5($identifier);
        $cachedUserId = $this->cacheGet($userCacheKey);
        
        $pppoeUser = null;
        if ($cachedUserId) {
            try {
                // Fast path: Load user directly by ID with tenant context
                $pppoeUser = $this->findPppoeUserByCachedId($cachedUserId);
            } catch (\Throwable $e) {
                $this->cacheForget($userCacheKey);
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
                $this->cachePut($userCacheKey, $pppoeUser->id . '|' . ($pppoeUser->tenant_id ?? ''), now()->addSeconds(self::LOGIN_USER_CACHE_TTL_SECONDS));
            }
        }

        if (!$pppoeUser) {
            // Increment rate limit counter
            $this->cachePut($rateLimitKey, $attempts + 1, now()->addMinutes(15));
            
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
            $this->cachePut($rateLimitKey, $attempts + 1, now()->addMinutes(15));
            
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
        $this->cacheForget($rateLimitKey);

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

        // Resolve package name inside a tenant transaction so the search_path is set.
        // The package relation may not be loaded in memory (cache-path and mapping-path
        // users skip eager-loading), and a lazy-load outside tenant context hits the
        // public schema which has no `packages` table → SQLSTATE 42P01.
        $packageName = null;
        if ($pppoeUser->relationLoaded('package')) {
            $packageName = $pppoeUser->package?->name;
        } elseif ($pppoeUser->package_id && $tokenTenantId !== '') {
            try {
                $packageTenant = Tenant::query()
                    ->whereKey($tokenTenantId)
                    ->whereRaw('is_active = true')
                    ->first(['id', 'schema_name', 'schema_created']);

                if ($packageTenant) {
                    $packageName = DB::transaction(function () use ($packageTenant, $pppoeUser) {
                        DB::connection()->recordsHaveBeenModified();
                        return $this->tenantContext->runInTenantContext($packageTenant, function () use ($pppoeUser) {
                            return $pppoeUser->package?->name;
                        });
                    });
                }
            } catch (\Throwable $e) {
                Log::warning('PPPoE portal: failed to resolve package name', [
                    'username' => $pppoeUser->username,
                    'package_id' => $pppoeUser->package_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
                'package_name' => $packageName,
                'status' => $pppoeUser->status,
                'payment_status' => $pppoeUser->payment_status,
                'expiration_date' => $pppoeUser->expires_at,
            ],
        ]);
    }

    private function attachResolvedTenantId(PppoeUser $user, string $tenantId): PppoeUser
    {
        $user->tenant_id = $tenantId;
        $user->syncOriginalAttribute('tenant_id');

        return $user;
    }

    private function attachResolvedTenantContext(PppoeUser $user, string $tenantId, ?string $schemaName = null): PppoeUser
    {
        $user->tenant_id = $tenantId;
        $user->syncOriginalAttribute('tenant_id');

        if ($schemaName !== null && $schemaName !== '') {
            $user->resolved_tenant_schema = $schemaName;
            $user->syncOriginalAttribute('resolved_tenant_schema');
        }

        return $user;
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
                                $this->attachResolvedTenantId($user, (string) $tenant->id);
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
                $this->cachePut($this->radiusCacheKey($pppoeUser), $radiusData, now()->addSeconds(self::RADIUS_CACHE_TTL_SECONDS));

                $dashboardData = $this->buildDashboardData($pppoeUser);
                $this->cachePut($this->dashboardCacheKey($pppoeUser), $dashboardData, now()->addSeconds(self::DASHBOARD_CACHE_TTL_SECONDS));
                $this->cachePut($this->paymentsCacheKey($pppoeUser), $dashboardData['recent_payments'] ?? [], now()->addSeconds(self::PAYMENTS_CACHE_TTL_SECONDS));
            })->onQueue('low');
        } catch (\Throwable $e) {
            // Silent fail - cache warming is optional
            Log::debug('Dashboard cache warming failed', [
                'username' => $pppoeUser->username,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logPaymentTrace(string $stage, array $details, string $logLevel = 'info'): void
    {
        $logger = app(PaymentTraceLogger::class);
        $sanitizedDetails = $logger->sanitizeLogData($details);
        $logger->log($stage, $sanitizedDetails, $logLevel);

        try {
            SystemLog::create(['action' => 'PPPoE Payment Trace: ' . $stage, 'details' => $sanitizedDetails]);
        } catch (\Throwable $e) {
            Log::warning('SystemLog::create failed', [
                'action' => 'PPPoE Payment Trace: ' . $stage,
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
                                $this->attachResolvedTenantId($user, (string) $tenant->id);
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
                        $this->attachResolvedTenantId($user, (string) $tenant->id);
                        
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
        $cachedTenantId = $this->cacheGet($cacheKey);
        
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
            $this->cachePut($cacheKey, $tenant->id, now()->addMinutes(10));
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
            ->get(['id', 'schema_name', 'schema_created']);

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

        $dashboardCacheKey = $this->dashboardCacheKey($pppoeUser);

        try {
            $cachedDashboard = $this->cacheGet($dashboardCacheKey);
            if ($cachedDashboard !== null) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedDashboard,
                    'cached' => true,
                ]);
            }

            $lock = $this->cacheLock($this->dashboardLockKey($pppoeUser), self::DASHBOARD_CACHE_LOCK_SECONDS);
            $lockResult = $lock
                ? $lock->get(function () use ($dashboardCacheKey, $pppoeUser) {
                    $cached = $this->cacheGet($dashboardCacheKey);
                    if ($cached !== null) {
                        return ['data' => $cached, 'cached' => true];
                    }

                    $dashboardData = $this->buildDashboardData($pppoeUser);
                    $this->cachePut($dashboardCacheKey, $dashboardData, now()->addSeconds(self::DASHBOARD_CACHE_TTL_SECONDS));

                    return ['data' => $dashboardData, 'cached' => false];
                })
                : null;

            if ($lockResult === null) {
                $dashboardData = $this->buildDashboardData($pppoeUser);
                $this->cachePut($dashboardCacheKey, $dashboardData, now()->addSeconds(self::DASHBOARD_CACHE_TTL_SECONDS));

                return response()->json([
                    'success' => true,
                    'data' => $dashboardData,
                    'cached' => false,
                    'lock_timeout' => true,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $lockResult['data'],
                'cached' => (bool) ($lockResult['cached'] ?? false),
            ]);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $dashboardData = $this->buildDashboardData($pppoeUser);
            $this->cachePut($dashboardCacheKey, $dashboardData, now()->addSeconds(self::DASHBOARD_CACHE_TTL_SECONDS));

            return response()->json([
                'success' => true,
                'data' => $dashboardData,
                'cached' => false,
                'lock_timeout' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PPPoE portal dashboard cache failed', [
                'account_number' => $pppoeUser->account_number,
                'error' => $e->getMessage(),
            ]);

            $dashboardData = $this->buildDashboardData($pppoeUser);
            return response()->json([
                'success' => true,
                'data' => $dashboardData,
                'cached' => false,
                'cache_error' => true,
            ]);
        }
    }

    private function buildDashboardData(PppoeUser $pppoeUser): array
    {
        // OPTIMIZATION 1: Aggressive RADIUS caching (60s TTL)
        $radiusCacheKey = $this->radiusCacheKey($pppoeUser);
        $radiusData = $this->cacheGet($radiusCacheKey);

        if (!$radiusData) {
            try {
                $radiusData = $this->getOptimizedRadiusData($pppoeUser);
                $this->cachePut($radiusCacheKey, $radiusData, now()->addSeconds(self::RADIUS_CACHE_TTL_SECONDS));
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
        $paymentsCacheKey = $this->paymentsCacheKey($pppoeUser);
        $recentPayments = $this->cacheGet($paymentsCacheKey);

        if ($recentPayments === null) {
            $recentPayments = PppoePayment::query()
                ->where('pppoe_user_id', $pppoeUser->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get([
                    'id',
                    'amount',
                    'status',
                    'payment_method',
                    'transaction_id',
                    'payment_reference',
                    'notes',
                    'verified_at',
                    'created_at',
                ])
                ->map(fn ($p) => [
                    'id'                => $p->id,
                    'amount'            => $p->amount,
                    'status'            => $p->status,
                    'payment_method'    => $p->payment_method,
                    'transaction_id'    => $p->transaction_id,
                    'payment_reference' => $p->payment_reference,
                    'notes'             => $p->notes,
                    'verified_at'       => $p->verified_at,
                    'created_at'        => $p->created_at,
                ])
                ->all();

            $this->cachePut($paymentsCacheKey, $recentPayments, now()->addSeconds(self::PAYMENTS_CACHE_TTL_SECONDS));
        }

        return [
            'user' => $userData,
            'current_session' => $radiusData['current_session'],
            'usage_stats' => $radiusData['usage_stats'],
            'recent_payments' => $recentPayments,
            'cached_at' => now()->toIso8601String(),
        ];
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

        $thirtyDaysAgo = Carbon::now()->subDays(30)->toDateTimeString();
        $username = (string) $pppoeUser->username;

        // SINGLE QUERY: fetch current session and aggregate stats in one round-trip.
        $sql = <<<SQL
SELECT
    cs.start_time,
    cs.duration_seconds,
    cs.ip_address,
    cs.nas_ip,
    cs.download_bytes,
    cs.upload_bytes,
    st.total_sessions,
    st.total_duration,
    st.total_download,
    st.total_upload
FROM (
    SELECT
        acctstarttime AS start_time,
        acctsessiontime AS duration_seconds,
        framedipaddress AS ip_address,
        nasipaddress AS nas_ip,
        acctinputoctets AS download_bytes,
        acctoutputoctets AS upload_bytes
    FROM radacct
    WHERE username = ?
      AND acctstoptime IS NULL
    ORDER BY acctstarttime DESC
    LIMIT 1
) cs
LEFT JOIN (
    SELECT
        COUNT(*) AS total_sessions,
        COALESCE(SUM(acctsessiontime), 0) AS total_duration,
        COALESCE(SUM(acctinputoctets), 0) AS total_download,
        COALESCE(SUM(acctoutputoctets), 0) AS total_upload
    FROM radacct
    WHERE username = ?
      AND acctstarttime >= ?
) st ON 1 = 1
LIMIT 1
SQL;

        $rows = DB::connection('radius')->select($sql, [$username, $username, $thirtyDaysAgo]);
        $row = $rows[0] ?? null;

        return [
            'current_session' => $row && $row->start_time ? [
                'start_time' => $row->start_time,
                'duration_formatted' => $this->formatDuration($row->duration_seconds ?? 0),
                'duration_seconds' => $row->duration_seconds ?? 0,
                'ip_address' => $row->ip_address,
                'nas_ip' => $row->nas_ip,
                'download_formatted' => $this->formatBytes($row->download_bytes ?? 0),
                'download_bytes' => $row->download_bytes ?? 0,
                'upload_formatted' => $this->formatBytes($row->upload_bytes ?? 0),
                'upload_bytes' => $row->upload_bytes ?? 0,
            ] : null,
            'usage_stats' => [
                'period_days' => 30,
                'total_sessions' => (int) ($row->total_sessions ?? 0),
                'total_duration_formatted' => $this->formatDuration($row->total_duration ?? 0),
                'total_duration_seconds' => (int) ($row->total_duration ?? 0),
                'total_download_formatted' => $this->formatBytes($row->total_download ?? 0),
                'total_download_bytes' => (int) ($row->total_download ?? 0),
                'total_upload_formatted' => $this->formatBytes($row->total_upload ?? 0),
                'total_upload_bytes' => (int) ($row->total_upload ?? 0),
                'total_usage_formatted' => $this->formatBytes(((int) ($row->total_download ?? 0)) + ((int) ($row->total_upload ?? 0))),
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

        $providerName = null;
        if ($pppoeUser->tenant_id) {
            $providerName = $this->cacheRemember(
                'tenant_name:' . $pppoeUser->tenant_id,
                now()->addMinutes(60),
                fn () => Tenant::query()->whereKey($pppoeUser->tenant_id)->value('name')
            );
        }

        return [
            'id' => $pppoeUser->id,
            'provider_name' => $providerName,
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
            'balance'                    => $pppoeUser->balance ?? 0,
            'paused_at'                  => $pppoeUser->paused_at?->toIso8601String(),
            'pause_ends_at'              => $pppoeUser->pause_ends_at?->toIso8601String(),
            'is_paused'                  => $pppoeUser->isPaused(),
            'pending_package_id'         => $pppoeUser->pending_package_id,
            'plan_switch_effective_date' => $pppoeUser->plan_switch_effective_date?->toIso8601String(),
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
        $sessionVersion = $this->cacheGet('pppoe_sessions_v:' . $pppoeUser->id, 0);
        $cacheKey = 'pppoe_sessions:' . $pppoeUser->id . ':' . $days . ':' . ($validated['cursor'] ?? 'init') . ':v' . $sessionVersion;
        $cached = $this->cacheGet($cacheKey);

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
        $this->cachePut($cacheKey, $result, now()->addSeconds(30));

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

        // Block payments while a plan switch is pending
        if ($pppoeUser->hasPendingPlanSwitch()) {
            return response()->json([
                'success' => false,
                'message' => 'A plan switch is pending. Payments are disabled until the switch is completed or cancelled.',
            ], 422);
        }

        $validated = $request->validate([
            'phone_number' => 'required|string|regex:/^254[0-9]{9}$/',
            'amount' => 'required|numeric|min:10|max:100000',
        ]);

        // OPTIMIZATION: Rate limit check via cache (prevents hammering the DB)
        $rateLimitKey = 'mpesa_stk_limit:' . $pppoeUser->id;
        $traceId = 'mpesa:' . Str::uuid()->toString();
        if ($this->cacheHas($rateLimitKey)) {
            $recentTx = $this->cacheGet($rateLimitKey);
            $this->logPaymentTrace('pppoe.stk.rate_limited', [
                'trace_id' => $traceId,
                'account_number' => $pppoeUser->account_number,
                'pending_transaction' => $recentTx['transaction_id'] ?? null,
            ], 'warning');
            return response()->json([
                'success' => false,
                'message' => 'A payment request is already pending. Please wait.',
                'pending_transaction' => $recentTx['transaction_id'] ?? null,
            ], 429);
        }

        try {
            // IDEMPOTENCY: only one pending STK request should exist per user at a time
            $recentPending = PppoePayment::query()
                ->where('pppoe_user_id', $pppoeUser->id)
                ->where('payment_method', 'mpesa')
                ->where('status', 'pending')
                ->select(['id', 'transaction_id'])
                ->latest('created_at')
                ->first();

            if ($recentPending) {
                // Cache the pending transaction for 2 minutes to reduce DB hits
                $this->cachePut($rateLimitKey, ['transaction_id' => $recentPending->transaction_id], now()->addMinutes(2));
                $this->logPaymentTrace('pppoe.stk.recent_pending', [
                    'trace_id' => $traceId,
                    'account_number' => $pppoeUser->account_number,
                    'pending_transaction' => $recentPending->transaction_id,
                ], 'warning');

                return response()->json([
                    'success' => false,
                    'message' => 'A payment request is already pending. Please wait.',
                    'pending_transaction' => $recentPending->transaction_id,
                ], 429);
            }

            // Initiate M-Pesa STK Push
            $tenantIdForPayment = $request->attributes->get('tenant_id');
            $mpesaService = app(MpesaService::class);
            if ($tenantIdForPayment) {
                $mpesaService->setTenantPaymentContext((string) $tenantIdForPayment);
            }
            $stkResponse = $mpesaService->initiateSTKPush(
                $validated['phone_number'],
                $validated['amount']
            );

            if (!$stkResponse['success']) {
                $this->logPaymentTrace('pppoe.stk.failed', [
                    'trace_id' => $traceId,
                    'account_number' => $pppoeUser->account_number,
                    'tenant_id' => $tenantIdForPayment,
                    'error' => $stkResponse['message'] ?? 'Unknown error',
                    'response' => $stkResponse,
                ], 'error');
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

            // Register in the public-schema transaction map so the M-Pesa STK callback
            // can find this PppoePayment by CheckoutRequestID.
            $tenantId = $request->attributes->get('tenant_id');
            if ($tenantId) {
                MpesaTransactionMap::create([
                    'checkout_request_id' => $checkoutRequestId,
                    'merchant_request_id' => $merchantRequestId,
                    'tenant_id'           => $tenantId,
                    'payment_type'        => 'pppoe',
                    'related_id'          => $payment->id,
                ]);
            }

            $this->logPaymentTrace('pppoe.stk.created', [
                'trace_id' => $traceId,
                'account_number' => $pppoeUser->account_number,
                'payment_id' => $payment->id,
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $merchantRequestId,
                'tenant_id' => $tenantId,
            ]);

            // Cache the pending transaction to prevent duplicate STK pushes
            $this->cachePut($rateLimitKey, ['transaction_id' => $checkoutRequestId], now()->addMinutes(2));

            // Invalidate dashboard and payments cache to show new pending payment
            $this->invalidateDashboardCache($pppoeUser);

            $this->logPaymentTrace('pppoe.stk.initiated', [
                'trace_id' => $traceId,
                'account_number' => $pppoeUser->account_number,
                'amount' => $validated['amount'],
                'transaction_id' => $payment->transaction_id,
                'payment_id' => $payment->id,
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
            $this->logPaymentTrace('pppoe.stk.exception', [
                'account_number' => $pppoeUser->account_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'error');
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

        // Block voucher redemption while a plan switch is pending
        if ($pppoeUser->hasPendingPlanSwitch()) {
            return response()->json([
                'success' => false,
                'message' => 'A plan switch is pending. Voucher redemption is disabled until the switch is completed or cancelled.',
            ], 422);
        }

        $validated = $request->validate([
            'voucher_code' => 'required|string|min:5|max:20',
        ]);

        $voucherCode = strtoupper(trim($validated['voucher_code']));

        // OPTIMIZATION: Rate limit voucher redemption attempts
        $rateLimitKey = 'voucher_redeem_limit:' . $pppoeUser->id;
        if ($this->cacheHas($rateLimitKey)) {
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
                        ->select(['id', 'code', 'value', 'package_id', 'status', 'used_at', 'used_by', 'expires_at', 'package_duration_days'])
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

                    // Validate voucher has a monetary value
                    if ($voucher->value === null || $voucher->value <= 0) {
                        return ['error' => 'This voucher has no redeemable value', 'status' => 400];
                    }

                    // Validate voucher package matches user's current package
                    if ($pppoeUser->package_id && $pppoeUser->package_id !== $voucher->package_id) {
                        return ['error' => 'This voucher is for a different package than your current plan', 'status' => 400];
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

                    // Extend plan expiry if voucher has a duration
                    if ($voucher->package_duration_days && $voucher->package_id) {
                        $package = Package::find($voucher->package_id);
                        if ($package) {
                            $updateData['expires_at'] = PackageExpiryHelper::calculateRenewalExpiresAt(
                                $package,
                                Carbon::now(),
                                $pppoeUser->expires_at ? Carbon::parse($pppoeUser->expires_at) : null
                            );
                        } else {
                            $currentExpiry = $pppoeUser->expires_at ? Carbon::parse($pppoeUser->expires_at) : Carbon::now();
                            $updateData['expires_at'] = $currentExpiry->addDays($voucher->package_duration_days);
                        }
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
            $this->cachePut($rateLimitKey, 1, now()->addSeconds(5));

            // Invalidate all user caches to show updated balance
            $this->invalidateDashboardCache($pppoeUser);
            $this->cacheForget($this->radiusCacheKey($pppoeUser));

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
        $cached = $this->cacheGet($cacheKey);
        if ($cached) {
            return response()->json([
                'success' => true,
                'data' => $cached,
                'cached' => true,
            ]);
        }

        // OPTIMIZATION: Use composite index (user_id, transaction_id) with selective fields
        $payment = PppoePayment::query()
            ->select(['transaction_id', 'status', 'amount', 'payment_method', 'created_at', 'verified_at', 'payment_date', 'period_end', 'payment_reference'])
            ->where('pppoe_user_id', $pppoeUser->id)
            ->where('transaction_id', $transactionId)
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        // Reload user fields if payment completed so frontend can display updated billing
        $userUpdate = null;
        if ($payment->status === 'completed') {
            $fresh = PppoeUser::query()
                ->select(['id', 'status', 'payment_status', 'next_payment_due', 'last_payment_date', 'expires_at', 'amount_due', 'amount_paid', 'balance'])
                ->find($pppoeUser->id);
            if ($fresh) {
                $userUpdate = [
                    'status'            => $fresh->status,
                    'payment_status'    => $fresh->payment_status,
                    'next_payment_due'  => $fresh->next_payment_due?->toIso8601String(),
                    'last_payment_date' => $fresh->last_payment_date?->toIso8601String(),
                    'expiration_date'   => $fresh->expires_at?->toIso8601String(),
                    'amount_due'        => $fresh->amount_due,
                    'amount_paid'       => $fresh->amount_paid,
                    'balance'           => $fresh->balance ?? 0,
                ];
            }
        }

        $result = [
            'transaction_id'    => $payment->transaction_id,
            'status'            => $payment->status,
            'amount'            => $payment->amount,
            'payment_method'    => $payment->payment_method,
            'payment_reference' => $payment->payment_reference,
            'created_at'        => $payment->created_at,
            'paid_at'           => $payment->verified_at ?? $payment->payment_date,
            'next_payment_due'  => $payment->period_end?->toIso8601String(),
            'user'              => $userUpdate,
        ];

        // Cache pending payments briefly (they may change soon), completed for longer
        $ttl = $payment->status === 'pending' ? 10 : 300;
        $this->cachePut($cacheKey, $result, now()->addSeconds($ttl));

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
    public function debugPaymentState(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');

        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'transaction_id' => 'nullable|string',
        ]);

        $tenantId = $this->resolveTenantIdForRequestUser($request, $pppoeUser);
        $transactionId = trim((string) ($validated['transaction_id'] ?? ''));

        $latestPaymentQuery = PppoePayment::query()
            ->select([
                'id', 'pppoe_user_id', 'account_number', 'amount', 'payment_method',
                'payment_reference', 'transaction_id', 'status', 'payment_date',
                'verified_at', 'period_start', 'period_end', 'notes', 'metadata',
                'created_at', 'updated_at',
            ])
            ->where('pppoe_user_id', $pppoeUser->id);

        if ($transactionId !== '') {
            $latestPaymentQuery->where('transaction_id', $transactionId);
        }

        $latestPayment = $latestPaymentQuery
            ->orderByDesc('verified_at')
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->first();

        $latestTransactionId = $transactionId !== ''
            ? $transactionId
            : (string) ($latestPayment?->transaction_id ?? '');

        $paymentStatusCacheKey = $latestTransactionId !== ''
            ? 'payment_status:' . $pppoeUser->id . ':' . md5($latestTransactionId)
            : null;

        $reconnectJobCacheKey = 'pppoe_reconnect_job:' . $tenantId . ':' . $pppoeUser->id;

        $reconnectJobState = $this->cacheGet($reconnectJobCacheKey);
        if (!$reconnectJobState) {
            $reconnectJobState = [
                'status' => $pppoeUser->status === 'active' ? 'idle' : 'not_queued',
                'tenant_id' => $tenantId,
                'pppoe_user_id' => $pppoeUser->id,
            ];
        }

        $paymentStatusCache = null;
        if ($paymentStatusCacheKey) {
            $paymentStatusCache = [
                'key' => $paymentStatusCacheKey,
                'exists' => $this->cacheHas($paymentStatusCacheKey),
                'value' => $this->cacheGet($paymentStatusCacheKey),
            ];
        }

        $debug = [
            'tenant_id' => $tenantId,
            'user' => [
                'id' => $pppoeUser->id,
                'account_number' => $pppoeUser->account_number,
                'username' => $pppoeUser->username,
                'status' => $pppoeUser->status,
                'payment_status' => $pppoeUser->payment_status,
                'next_payment_due' => $pppoeUser->next_payment_due?->toIso8601String(),
                'last_payment_date' => $pppoeUser->last_payment_date?->toIso8601String(),
                'expires_at' => $pppoeUser->expires_at?->toIso8601String(),
                'amount_due' => $pppoeUser->amount_due,
                'amount_paid' => $pppoeUser->amount_paid,
                'balance' => $pppoeUser->balance ?? 0,
            ],
            'latest_payment' => $latestPayment ? [
                'id' => $latestPayment->id,
                'transaction_id' => $latestPayment->transaction_id,
                'status' => $latestPayment->status,
                'amount' => $latestPayment->amount,
                'payment_method' => $latestPayment->payment_method,
                'payment_reference' => $latestPayment->payment_reference,
                'payment_date' => $latestPayment->payment_date?->toIso8601String(),
                'verified_at' => $latestPayment->verified_at?->toIso8601String(),
                'period_start' => $latestPayment->period_start?->toIso8601String(),
                'period_end' => $latestPayment->period_end?->toIso8601String(),
                'notes' => $latestPayment->notes,
                'metadata' => $latestPayment->metadata,
            ] : null,
            'callback' => $latestPayment ? [
                'status' => $latestPayment->status,
                'verified_at' => $latestPayment->verified_at?->toIso8601String(),
                'payment_date' => $latestPayment->payment_date?->toIso8601String(),
                'callback_response' => data_get($latestPayment->metadata, 'callback_response'),
                'mpesa_receipt' => data_get($latestPayment->metadata, 'mpesa_receipt'),
                'source' => data_get($latestPayment->metadata, 'source'),
            ] : null,
            'cache' => [
                'dashboard_version' => $this->cacheGet($this->dashboardVersionKey($pppoeUser), 1),
                'payments_version' => $this->cacheGet($this->paymentsVersionKey($pppoeUser), 1),
                'radius_cache_present' => $this->cacheHas($this->radiusCacheKey($pppoeUser)),
                'payment_status' => $paymentStatusCache,
                'mpesa_stk_limit' => [
                    'key' => 'mpesa_stk_limit:' . $pppoeUser->id,
                    'exists' => $this->cacheHas('mpesa_stk_limit:' . $pppoeUser->id),
                    'value' => $this->cacheGet('mpesa_stk_limit:' . $pppoeUser->id),
                ],
            ],
            'reconnect_job' => $reconnectJobState,
            'generated_at' => now()->toIso8601String(),
        ];

        return response()->json(['success' => true, 'data' => $debug]);
    }

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
        $cached = $this->cacheGet($cacheKey);

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
            $this->cachePut($cacheKey, $cacheData, now()->addMinutes(5));

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

    // ============== Admin: Timed Voucher Markup Setting ==============

    /**
     * GET /api/pppoe/portal/admin/voucher-markup
     * Returns the current timed voucher markup % for the authenticated tenant admin.
     */
    public function getTimedVoucherMarkupSetting(Request $request): JsonResponse
    {
        $tenant = Tenant::find($request->user()->tenant_id);
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'markup_pct' => (float) ($tenant->getSetting('timed_voucher_markup_pct') ?? 50.0),
            ],
        ]);
    }

    /**
     * PUT /api/pppoe/portal/admin/voucher-markup
     * Allows the tenant admin to set the markup percentage applied to timed voucher prices.
     */
    public function updateTimedVoucherMarkupSetting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'markup_pct' => 'required|numeric|min:0|max:500',
        ]);

        $tenant = Tenant::find($request->user()->tenant_id);
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        $tenant->setSetting('timed_voucher_markup_pct', (float) $validated['markup_pct']);
        $this->cacheForget('pppoe_portal_timed_voucher_options:' . $tenant->id);

        Log::info('PPPoE timed voucher markup updated', [
            'tenant_id'  => $tenant->id,
            'markup_pct' => $validated['markup_pct'],
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Timed voucher markup updated successfully.',
            'data'    => ['markup_pct' => (float) $validated['markup_pct']],
        ]);
    }

    // ============== Feature: Account Pause ==============

    public function pauseAccount(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if ($pppoeUser->payment_status !== 'paid') {
            return response()->json(['success' => false, 'message' => 'Account must be fully paid before it can be paused.'], 422);
        }
        if ($pppoeUser->isPaused()) {
            return response()->json(['success' => false, 'message' => 'Account is already paused.'], 422);
        }
        if ($pppoeUser->isSuspended()) {
            return response()->json(['success' => false, 'message' => 'Suspended accounts cannot be paused.'], 422);
        }

        $pauseEndsAt = $pppoeUser->expires_at ?? now()->addDays(30);

        $pppoeUser->update([
            'paused_at'     => now(),
            'pause_ends_at' => $pauseEndsAt,
            'pause_reason'  => 'User-requested pause',
        ]);

        DB::table('radcheck')->updateOrInsert(
            ['username' => $pppoeUser->username, 'attribute' => 'Auth-Type'],
            ['op' => ':=', 'value' => 'Reject']
        );

        $this->invalidateDashboardCache($pppoeUser);

        Log::info('PPPoE portal: account paused', [
            'account_number' => $pppoeUser->account_number,
            'pause_ends_at'  => $pauseEndsAt,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account paused. Internet access is suspended until you resume or the pause expires.',
            'data'    => [
                'paused_at'     => $pppoeUser->paused_at?->toIso8601String(),
                'pause_ends_at' => $pppoeUser->pause_ends_at?->toIso8601String(),
            ],
        ]);
    }

    public function resumeAccount(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (!$pppoeUser->isPaused()) {
            return response()->json(['success' => false, 'message' => 'Account is not currently paused.'], 422);
        }

        $pausedAt       = $pppoeUser->paused_at ?? now();
        $daysElapsed    = (int) $pausedAt->diffInDays(now(), true);
        $pauseEndsAt    = $pppoeUser->pause_ends_at;
        $totalPauseDays = $pauseEndsAt ? (int) $pausedAt->diffInDays($pauseEndsAt, true) : 0;
        $remainingDays  = max(0, $totalPauseDays - $daysElapsed);

        $newExpiry = ($pppoeUser->expires_at ?? now())->addDays($remainingDays);

        $pppoeUser->update([
            'paused_at'     => null,
            'pause_ends_at' => null,
            'pause_reason'  => null,
            'expires_at'    => $newExpiry,
        ]);

        DB::table('radcheck')
            ->where('username', $pppoeUser->username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->delete();

        $this->invalidateDashboardCache($pppoeUser);

        Log::info('PPPoE portal: account resumed early', [
            'account_number' => $pppoeUser->account_number,
            'days_credited'  => $remainingDays,
            'new_expiry'     => $newExpiry,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Account resumed. {$remainingDays} day(s) credited back to your subscription.",
            'data'    => [
                'new_expiry'    => $newExpiry->toIso8601String(),
                'days_credited' => $remainingDays,
            ],
        ]);
    }

    // ============== Feature: Plan Switch ==============

    public function availablePlans(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $cacheKey = 'pppoe_portal_plans:' . $tenantId;
        $plans    = $this->cacheGet($cacheKey);

        if (!$plans) {
            $plans = Package::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('type', 'pppoe')->orWhereNull('type');
                })
                ->orderBy('price')
                ->get(['id', 'name', 'price', 'download_speed', 'upload_speed', 'duration', 'description'])
                ->map(fn ($p) => [
                    'id'             => $p->id,
                    'name'           => $p->name,
                    'price'          => $p->price,
                    'download_speed' => $p->download_speed,
                    'upload_speed'   => $p->upload_speed,
                    'duration'       => $p->duration,
                    'description'    => $p->description,
                ])
                ->all();

            $this->cachePut($cacheKey, $plans, now()->addMinutes(5));
        }

        return response()->json([
            'success'          => true,
            'data'             => $plans,
            'current_plan_id'  => $pppoeUser->package_id,
            'pending_plan_id'  => $pppoeUser->pending_package_id,
            'effective_date'   => $pppoeUser->plan_switch_effective_date?->toIso8601String(),
        ]);
    }

    public function requestPlanSwitch(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate(['package_id' => 'required|uuid']);

        if ($validated['package_id'] === (string) $pppoeUser->package_id) {
            return response()->json(['success' => false, 'message' => 'You are already on this plan.'], 422);
        }

        $package = Package::query()
            ->where('id', $validated['package_id'])
            ->where('is_active', true)
            ->first(['id', 'name', 'price']);

        if (!$package) {
            return response()->json(['success' => false, 'message' => 'Selected plan not found or unavailable.'], 404);
        }

        $effectiveDate = $pppoeUser->expires_at ?? now()->addDays(30);

        $pppoeUser->update([
            'pending_package_id'         => $package->id,
            'plan_switch_effective_date' => $effectiveDate,
        ]);

        $this->invalidateDashboardCache($pppoeUser);

        Log::info('PPPoE portal: plan switch requested', [
            'account_number'   => $pppoeUser->account_number,
            'new_package_id'   => $package->id,
            'new_package_name' => $package->name,
            'effective_date'   => $effectiveDate,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Plan switch to \"{$package->name}\" scheduled for " . $effectiveDate->toDateString() . '. It takes effect when your current subscription expires.',
            'data'    => [
                'pending_package_id'         => $package->id,
                'pending_package_name'       => $package->name,
                'plan_switch_effective_date' => $effectiveDate->toIso8601String(),
            ],
        ]);
    }

    public function cancelPlanSwitch(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (!$pppoeUser->hasPendingPlanSwitch()) {
            return response()->json([
                'success' => false,
                'message' => 'No pending plan switch to cancel.',
            ], 422);
        }

        $pendingPackageId = $pppoeUser->pending_package_id;

        $pppoeUser->update([
            'pending_package_id'         => null,
            'plan_switch_effective_date' => null,
        ]);

        $this->invalidateDashboardCache($pppoeUser);

        Log::info('PPPoE portal: plan switch cancelled', [
            'account_number'     => $pppoeUser->account_number,
            'pending_package_id' => $pendingPackageId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plan switch cancelled successfully.',
        ]);
    }

    // ============== Feature: Timed Vouchers ==============

    private const TIMED_VOUCHER_DURATIONS = [
        ['label' => '8 Hours',  'hours' => 8,   'fraction' => 8/720],
        ['label' => '1 Day',    'hours' => 24,  'fraction' => 1/30],
        ['label' => '3 Days',   'hours' => 72,  'fraction' => 3/30],
        ['label' => '1 Week',   'hours' => 168, 'fraction' => 7/30],
    ];

    /**
     * Get the configured markup percentage for timed vouchers.
     * Reads tenant.settings.timed_voucher_markup_pct (default 50).
     */
    private function getTimedVoucherMarkup(?Tenant $tenant): float
    {
        if (!$tenant) {
            return 50.0;
        }
        $pct = $tenant->getSetting('timed_voucher_markup_pct');
        return (float) ($pct ?? 50.0);
    }

    /**
     * Compute the timed-voucher price for a package + duration with markup.
     *   base  = monthly_price × duration_fraction
     *   final = ceil(base × (1 + markup/100))  — minimum KES 10
     */
    private function calcTimedVoucherPrice(float $monthlyPrice, float $fraction, float $markupPct): int
    {
        $base  = $monthlyPrice * $fraction;
        $final = $base * (1 + $markupPct / 100);
        return max(10, (int) ceil($final));
    }

    public function timedVoucherOptions(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Resolve tenant to read markup setting
        $tenantId = $request->attributes->get('tenant_id') ?? $pppoeUser->getAttribute('tenant_id');
        $cacheKey = 'pppoe_portal_timed_voucher_options:' . $tenantId;
        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached)) {
            return response()->json($cached);
        }

        $tenant   = $tenantId ? Tenant::find($tenantId) : null;
        $markup   = $this->getTimedVoucherMarkup($tenant);

        // Load active packages so user can pick bandwidth tier
        $packages = Package::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get(['id', 'name', 'download_speed', 'upload_speed', 'price']);

        // Build a matrix: for each package, compute price per duration (with markup)
        $packageOptions = $packages->map(function ($pkg) use ($markup) {
            $monthlyPrice = (float) $pkg->price;
            $durations = array_map(function ($d) use ($monthlyPrice, $markup) {
                return [
                    'label'  => $d['label'],
                    'hours'  => $d['hours'],
                    'price'  => $this->calcTimedVoucherPrice($monthlyPrice, $d['fraction'], $markup),
                ];
            }, self::TIMED_VOUCHER_DURATIONS);

            return [
                'id'             => $pkg->id,
                'name'           => $pkg->name,
                'download_speed' => $pkg->download_speed,
                'upload_speed'   => $pkg->upload_speed,
                'monthly_price'  => $monthlyPrice,
                'durations'      => $durations,
            ];
        })->values()->all();

        $active = PppoeTimedVoucher::query()
            ->where('pppoe_user_id', $pppoeUser->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first(['id', 'duration_label', 'duration_hours', 'activated_at', 'expires_at']);

        $response = [
            'success' => true,
            'data'    => [
                'packages'       => $packageOptions,
                'active_voucher' => $active ? [
                    'id'             => $active->id,
                    'duration_label' => $active->duration_label,
                    'duration_hours' => $active->duration_hours,
                    'activated_at'   => $active->activated_at?->toIso8601String(),
                    'expires_at'     => $active->expires_at?->toIso8601String(),
                ] : null,
            ],
        ];

        $this->cachePut($cacheKey, $response, now()->addMinutes(5));

        return response()->json($response);
    }

    public function buyTimedVoucher(Request $request): JsonResponse
    {
        $pppoeUser = $request->attributes->get('pppoe_user');
        if (!$pppoeUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validHours = array_column(self::TIMED_VOUCHER_DURATIONS, 'hours');
        $validated  = $request->validate([
            'phone_number'   => 'required|string|regex:/^254[0-9]{9}$/',
            'package_id'     => 'required|uuid',
            'duration_hours' => 'required|integer|in:' . implode(',', $validHours),
        ]);

        $package = Package::query()
            ->where('id', $validated['package_id'])
            ->where('is_active', true)
            ->first(['id', 'name', 'download_speed', 'upload_speed', 'price']);

        if (!$package) {
            return response()->json(['success' => false, 'message' => 'Selected package not found.'], 404);
        }

        // Find the matching duration and compute price with markup
        $durationDef = collect(self::TIMED_VOUCHER_DURATIONS)->firstWhere('hours', $validated['duration_hours']);
        $tenantId    = $request->attributes->get('tenant_id') ?? $pppoeUser->getAttribute('tenant_id');
        $tenant      = $tenantId ? Tenant::find($tenantId) : null;
        $markup      = $this->getTimedVoucherMarkup($tenant);
        $price       = $this->calcTimedVoucherPrice((float) $package->price, $durationDef['fraction'], $markup);

        $option = [
            'label' => $durationDef['label'],
            'hours' => $durationDef['hours'],
            'price' => $price,
        ];

        $rateLimitKey = 'timed_voucher_limit:' . $pppoeUser->id;
        if ($this->cacheHas($rateLimitKey)) {
            return response()->json(['success' => false, 'message' => 'A voucher purchase is already pending.'], 429);
        }

        try {
            $mpesaService = app(MpesaService::class);
            if ($tenantId) {
                $mpesaService->setTenantPaymentContext((string) $tenantId);
            }
            $stkResponse  = $mpesaService->initiateSTKPush(
                $validated['phone_number'],
                $option['price']
            );

            if (!($stkResponse['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $stkResponse['message'] ?? 'Failed to initiate payment.',
                ], 500);
            }

            $checkoutRequestId = $stkResponse['data']['CheckoutRequestID'];
            $merchantRequestId = $stkResponse['data']['MerchantRequestID'] ?? null;

            $voucher = PppoeTimedVoucher::create([
                'pppoe_user_id'  => $pppoeUser->id,
                'account_number' => $pppoeUser->account_number,
                'duration_label' => $option['label'],
                'duration_hours' => $option['hours'],
                'price'          => $option['price'],
                'status'         => 'pending_payment',
                'transaction_id' => $checkoutRequestId,
                'metadata'       => [
                    'package_id'     => $package->id,
                    'package_name'   => $package->name,
                    'download_speed' => $package->download_speed,
                    'upload_speed'   => $package->upload_speed,
                ],
            ]);

            $tenantId = $request->attributes->get('tenant_id');
            if ($tenantId) {
                MpesaTransactionMap::create([
                    'checkout_request_id' => $checkoutRequestId,
                    'merchant_request_id' => $merchantRequestId,
                    'tenant_id'           => $tenantId,
                    'payment_type'        => 'pppoe_timed_voucher',
                    'related_id'          => $voucher->id,
                ]);
            }

            $this->cachePut($rateLimitKey, true, now()->addMinutes(2));

            Log::info('PPPoE timed voucher STK push initiated', [
                'account_number' => $pppoeUser->account_number,
                'option'         => $option['label'],
                'amount'         => $option['price'],
                'transaction_id' => $checkoutRequestId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment request sent to your phone. Please enter your M-Pesa PIN.',
                'data'    => [
                    'voucher_id'     => $voucher->id,
                    'transaction_id' => $checkoutRequestId,
                    'duration_label' => $option['label'],
                    'amount'         => $option['price'],
                    'phone_number'   => $this->maskPhoneNumber($validated['phone_number']),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('PPPoE timed voucher purchase failed', [
                'account_number' => $pppoeUser->account_number,
                'error'          => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'An error occurred while processing your request.'], 500);
        }
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
            $authenticated = $this->authenticateWithPortalRadiusPassword($pppoeUser, $inputPassword);
            
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
    private function authenticateWithPortalRadiusPassword(PppoeUser $pppoeUser, string $password): bool
    {
        try {
            $tenant = $this->findTenantForPortalUser($pppoeUser);
            if (!$tenant) {
                return false;
            }

            $portalPassword = DB::transaction(function () use ($tenant, $pppoeUser) {
                DB::connection()->recordsHaveBeenModified();
                return $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser) {
                    return DB::table('radcheck')
                        ->where('username', $pppoeUser->username)
                        ->where('attribute', 'Portal-Password')
                        ->value('value');
                });
            });

            if (!$portalPassword) {
                \Log::debug('PPPoE portal: No Portal-Password found in RADIUS', [
                    'username' => $pppoeUser->username,
                ]);
                return false;
            }

            $isValid = hash_equals($portalPassword, $password);

            \Log::debug('PPPoE portal: Portal-Password verification', [
                'username' => $pppoeUser->username,
                'valid' => $isValid,
            ]);

            return $isValid;
        } catch (\Exception $e) {
            \Log::error('PPPoE portal: Portal-Password authentication error', [
                'username' => $pppoeUser->username,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function findTenantForPortalUser(PppoeUser $pppoeUser): ?Tenant
    {
        if (!empty($pppoeUser->tenant_id)) {
            $tenant = Tenant::query()
                ->whereKey((string) $pppoeUser->tenant_id)
                ->whereRaw('is_active = true')
                ->whereNotNull('schema_name')
                ->first(['id', 'schema_name', 'account_prefix', 'schema_created']);

            if ($tenant) {
                return $tenant;
            }
        }

        return $this->findTenantByAccountNumber((string) $pppoeUser->account_number);
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
        $this->bumpPortalCacheVersion($this->dashboardVersionKey($pppoeUser));
        $this->bumpPortalCacheVersion($this->paymentsVersionKey($pppoeUser));
        $this->cacheForget($this->radiusCacheKey($pppoeUser));

        $sessionVersionKey = 'pppoe_sessions_v:' . $pppoeUser->id;
        $this->cacheForever($sessionVersionKey, ((int) $this->cacheGet($sessionVersionKey, 0)) + 1);

        $this->warmDashboardCache($pppoeUser);
    }

    private function dashboardCacheKey(PppoeUser $pppoeUser): string
    {
        return 'pppoe_portal_dashboard:' . $pppoeUser->id . ':v' . $this->getPortalCacheVersion($this->dashboardVersionKey($pppoeUser));
    }

    private function dashboardLockKey(PppoeUser $pppoeUser): string
    {
        return 'pppoe_portal_dashboard_lock:' . $pppoeUser->id . ':v' . $this->getPortalCacheVersion($this->dashboardVersionKey($pppoeUser));
    }

    private function paymentsCacheKey(PppoeUser $pppoeUser): string
    {
        return 'pppoe_portal_payments:' . $pppoeUser->id . ':v' . $this->getPortalCacheVersion($this->paymentsVersionKey($pppoeUser));
    }

    private function radiusCacheKey(PppoeUser $pppoeUser): string
    {
        return 'pppoe_portal_radius:' . $pppoeUser->username;
    }

    private function dashboardVersionKey(PppoeUser $pppoeUser): string
    {
        return 'pppoe_portal_dashboard_version:' . $pppoeUser->id;
    }

    private function paymentsVersionKey(PppoeUser $pppoeUser): string
    {
        return 'pppoe_portal_payments_version:' . $pppoeUser->id;
    }


    private function getPortalCacheVersion(string $key): int
    {
        return (int) $this->cacheRememberForever($key, static fn (): int => 1);
    }

    private function bumpPortalCacheVersion(string $key): int
    {
        $nextVersion = $this->getPortalCacheVersion($key) + 1;
        $this->cacheForever($key, $nextVersion);

        return $nextVersion;
    }

    private function cacheGet(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $default;
        }
    }

    private function cachePut(string $key, mixed $value, mixed $ttl = null): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache put failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function cacheHas(string $key): bool
    {
        try {
            return Cache::has($key);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache has failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function cacheForget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache forget failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function cacheRemember(string $key, mixed $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache remember failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $callback();
        }
    }

    private function cacheRememberForever(string $key, callable $callback): mixed
    {
        try {
            return Cache::rememberForever($key, $callback);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache rememberForever failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $callback();
        }
    }

    private function cacheForever(string $key, mixed $value): void
    {
        try {
            Cache::forever($key, $value);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache forever failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function cacheIncrement(string $key, mixed $value = 1): mixed
    {
        try {
            return Cache::increment($key, $value);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache increment failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function cacheAdd(string $key, mixed $value, mixed $ttl = null): bool
    {
        try {
            return Cache::add($key, $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache add failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function cacheLock(string $key, int $seconds): mixed
    {
        try {
            return Cache::lock($key, $seconds);
        } catch (\Throwable $e) {
            Log::warning('PppoePortalController cache lock failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
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
        $cached = $this->cacheGet(self::RADIUS_STATUS_CACHE_KEY);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            // Use a fast query with short timeout
            DB::connection('radius')->selectOne('SELECT 1');
            $this->cachePut(self::RADIUS_STATUS_CACHE_KEY, true, self::RADIUS_STATUS_CACHE_TTL);
            return true;
        } catch (InvalidArgumentException $e) {
            if (!$this->radiusUnavailableLogged) {
                Log::warning('Radius connection not configured for PPPoE portal; returning degraded dashboard data');
                $this->radiusUnavailableLogged = true;
            }
            $this->cachePut(self::RADIUS_STATUS_CACHE_KEY, false, self::RADIUS_STATUS_CACHE_TTL);
            return false;
        } catch (\Throwable $e) {
            if (!$this->radiusUnavailableLogged) {
                Log::warning('Radius connection unavailable for PPPoE portal', [
                    'error' => $e->getMessage(),
                ]);
                $this->radiusUnavailableLogged = true;
            }
            $this->cachePut(self::RADIUS_STATUS_CACHE_KEY, false, self::RADIUS_STATUS_CACHE_TTL);
            return false;
        }
    }
}
