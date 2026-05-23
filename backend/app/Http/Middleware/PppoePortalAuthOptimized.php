<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * OPTIMIZED PPPoE Portal Authentication Middleware
 * 
 * CRITICAL IMPROVEMENTS:
 * - Caches user lookups (5min TTL)
 * - Avoids tenant scanning with mapping-first strategy
 * - Lazy tenant context setting (only when needed)
 * - Single query user validation
 */
class PppoePortalAuthOptimized
{
    private const USER_CACHE_TTL = 300; // 5 minutes

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        // OPTIMIZATION: Check token-to-user cache first
        $tokenHash = md5($token);
        $cacheKey = 'portal_token_user:' . $tokenHash;
        $cachedUser = Cache::get($cacheKey);

        $pppoeUser = null;
        if ($cachedUser) {
            $pppoeUser = $this->loadUserFromCache($cachedUser);
        }

        // Cache miss: Validate token fully
        if (!$pppoeUser) {
            $pppoeUser = $this->validateTokenOptimized($token);
            
            if ($pppoeUser) {
                // Cache the user lookup
                $cacheData = [
                    'user_id' => $pppoeUser->id,
                    'tenant_id' => $pppoeUser->tenant_id ?? null,
                    'username' => $pppoeUser->username,
                    'account_number' => $pppoeUser->account_number,
                ];
                Cache::put($cacheKey, $cacheData, self::USER_CACHE_TTL);
            }
        }

        if (!$pppoeUser) {
            Log::warning('Invalid PPPoE portal token', [
                'ip' => $request->ip(),
                'token_preview' => substr($token, 0, 10) . '...',
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired token',
            ], 401);
        }

        // Attach PPPoE user to request
        $request->attributes->set('pppoe_user', $pppoeUser);

        $resolvedTenantId = $pppoeUser->tenant_id ?? $this->resolveTenantIdForUser($pppoeUser);
        $request->attributes->set('tenant_id', $resolvedTenantId);

        if ($resolvedTenantId) {
            try {
                // Wrap the entire downstream request in a DB::transaction() so that
                // SET LOCAL search_path persists across all queries under PgBouncer
                // transaction pooling (pool_mode=transaction). Without this the
                // search_path is lost between this middleware and the controller,
                // causing 42P01 on every tenant-schema table.
                return DB::transaction(function () use ($request, $next, $resolvedTenantId, $pppoeUser) {
                    DB::connection()->recordsHaveBeenModified();
                    try {
                        $this->tenantContext->setTenantById((string) $resolvedTenantId);
                    } catch (Throwable $e) {
                        Log::error('Failed to set tenant context for optimized PPPoE portal request', [
                            'tenant_id' => $resolvedTenantId,
                            'user_id' => $pppoeUser->id,
                            'error' => $e->getMessage(),
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to load account context',
                        ], 500);
                    }
                    try {
                        return $next($request);
                    } finally {
                        $this->tenantContext->clearTenant();
                    }
                });
            } catch (Throwable $e) {
                Log::error('Failed to process PPPoE portal request in tenant transaction', [
                    'tenant_id' => $resolvedTenantId,
                    'user_id' => $pppoeUser->id,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to load account context',
                ], 500);
            }
        }

        return $next($request);
    }

    /**
     * Load user from cached data (fast path)
     */
    private function loadUserFromCache(array $cachedData): ?PppoeUser
    {
        $userId = $cachedData['user_id'] ?? null;
        $tenantId = $cachedData['tenant_id'] ?? null;

        if (!$userId) {
            return null;
        }

        // Fast path: If we have tenant ID, direct load with eager loading
        if ($tenantId) {
            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->where('is_active', true)
                ->first(['id', 'schema_name', 'schema_created']);

            if ($tenant) {
                try {
                    return DB::transaction(function () use ($tenant, $userId) {
                        DB::connection()->recordsHaveBeenModified();
                        return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                            // OPTIMIZATION: Eager load package to prevent N+1 in controller
                            $user = PppoeUser::query()->with('package:id,name,download_speed,upload_speed,price')->find($userId);
                            if ($user) {
                                $user->setAttribute('tenant_id', (string) $tenant->id);
                            }
                            return $user;
                        });
                    });
                } catch (\Throwable $e) {
                    Log::debug('Failed to load cached user', ['error' => $e->getMessage()]);
                    return null;
                }
            }
        }

        // Try loading via mapping
        return $this->findPppoeUserByIdViaMapping((string) $userId);
    }

    /**
     * OPTIMIZED: Token validation with mapping-first strategy
     */
    private function validateTokenOptimized(string $token): ?PppoeUser
    {
        $decoded = base64_decode($token, true);
        if (!$decoded) {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 3 && count($parts) !== 4) {
            return null;
        }

        $isV2 = count($parts) === 4;
        $userId = $parts[0] ?? null;
        $tenantId = $isV2 ? ($parts[1] ?? null) : null;
        $timestamp = $isV2 ? ($parts[2] ?? null) : ($parts[1] ?? null);
        $signature = $isV2 ? ($parts[3] ?? null) : ($parts[2] ?? null);

        if (!$userId || !$timestamp || !$signature) {
            return null;
        }

        // Check token expiration (24 hours)
        if (time() - (int)$timestamp > 86400) {
            return null;
        }

        $pppoeUser = null;

        // Prefer the tenant embedded in v2 tokens before consulting mapping fallback.
        if ($isV2 && $tenantId) {
            $pppoeUser = $this->findPppoeUserByTenantAndId((string) $tenantId, (string) $userId);
        }

        if (!$pppoeUser) {
            $pppoeUser = $this->findPppoeUserByIdViaMapping((string) $userId);
        }

        // Last resort: Legacy scan (should rarely happen)
        if (!$pppoeUser) {
            Log::warning('PPPoE portal token fell back to legacy tenant scan', ['user_id' => $userId]);
            $pppoeUser = $this->findPppoeUserByIdAcrossTenantsOptimized((string) $userId);
        }

        if (!$pppoeUser) {
            return null;
        }

        // Verify signature
        $secret = $pppoeUser->portal_password ?: $pppoeUser->password ?: $pppoeUser->account_number;
        if (!$secret) {
            return null;
        }

        $payload = $isV2
            ? ($userId . '|' . (string) $tenantId . '|' . $timestamp)
            : ($userId . '|' . $timestamp);
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $pppoeUser;
    }

    /**
     * Extract token from request
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return $request->query('portal_token');
    }

    /**
     * OPTIMIZED: Find user by tenant ID (direct lookup)
     */
    private function findPppoeUserByTenantAndId(string $tenantId, string $userId): ?PppoeUser
    {
        if ($tenantId === '') {
            return null;
        }

        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('is_active', true)
            ->where('schema_created', true)
            ->first(['id', 'schema_name', 'schema_created']);

        if (!$tenant || !$tenant->schema_name) {
            return null;
        }

        try {
            return DB::transaction(function () use ($tenant, $userId) {
                DB::connection()->recordsHaveBeenModified();
                return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                    // OPTIMIZATION: Eager load package to prevent N+1 in controller
                    $user = PppoeUser::query()->with('package:id,name,download_speed,upload_speed,price')->find($userId);
                    if ($user) {
                        $user->setAttribute('tenant_id', (string) $tenant->id);
                    }
                    return $user;
                });
            });
        } catch (\Throwable $e) {
            Log::debug('Failed to find user by tenant', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * OPTIMIZED: Find user via mapping table (fastest path)
     */
    private function findPppoeUserByIdViaMapping(string $userId): ?PppoeUser
    {
        $mapping = DB::table('public.radius_user_schema_mapping')
            ->where('pppoe_user_id', $userId)
            ->where('is_active', true)
            ->first(['tenant_id', 'schema_name']);

        if (!$mapping) {
            return null;
        }

        $tenant = Tenant::query()
            ->where('is_active', true)
            ->where('schema_created', true)
            ->where(function ($query) use ($mapping) {
                if (!empty($mapping->tenant_id)) {
                    $query->whereKey((string) $mapping->tenant_id);
                }
                if (!empty($mapping->schema_name)) {
                    $query->orWhere('schema_name', (string) $mapping->schema_name);
                }
            })
            ->first(['id', 'schema_name', 'schema_created']);

        if (!$tenant || !$tenant->schema_name) {
            return null;
        }

        try {
            return DB::transaction(function () use ($tenant, $userId) {
                DB::connection()->recordsHaveBeenModified();
                return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                    // OPTIMIZATION: Eager load package to prevent N+1 in controller
                    $user = PppoeUser::query()->with('package:id,name,download_speed,upload_speed,price')->find($userId);
                    if ($user) {
                        $user->setAttribute('tenant_id', (string) $tenant->id);
                    }
                    return $user;
                });
            });
        } catch (\Throwable $e) {
            Log::debug('Failed to find user via mapping', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * OPTIMIZED: Find user across tenants with early termination and limit
     */
    private function findPppoeUserByIdAcrossTenantsOptimized(string $userId): ?PppoeUser
    {
        // Get only active tenants with schemas, limited to recent ones first
        $tenants = Tenant::query()
            ->where('is_active', true)
            ->whereNotNull('schema_name')
            ->where('schema_created', true)
            ->orderBy('updated_at', 'desc') // Recent activity first
            ->limit(50) // Don't scan unlimited tenants
            ->get(['id', 'schema_name', 'schema_created']);

        foreach ($tenants as $tenant) {
            try {
                $user = DB::transaction(function () use ($tenant, $userId) {
                    DB::connection()->recordsHaveBeenModified();
                    return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                        // OPTIMIZATION: Eager load package to prevent N+1 in controller
                        $user = PppoeUser::query()->with('package:id,name,download_speed,upload_speed,price')->find($userId);
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
                continue; // Try next tenant
            }
        }

        return null;
    }

    /**
     * Resolve tenant ID for user
     */
    private function resolveTenantIdForUser(PppoeUser $pppoeUser): ?string
    {
        if (!empty($pppoeUser->tenant_id)) {
            return (string) $pppoeUser->tenant_id;
        }

        $normalized = strtoupper(trim((string) ($pppoeUser->account_number ?? '')));
        if ($normalized === '') {
            return null;
        }

        $tenant = Tenant::query()
            ->where('is_active', true)
            ->whereNotNull('schema_name')
            ->whereNotNull('account_prefix')
            ->whereRaw('? LIKE UPPER(account_prefix) || \'%\'', [$normalized])
            ->orderByRaw('LENGTH(account_prefix) DESC')
            ->first(['id']);

        return $tenant ? (string) $tenant->id : null;
    }
}
