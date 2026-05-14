<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PPPoE Portal Authentication Middleware
 * 
 * Validates PPPoE portal tokens and loads the PPPoE user
 * into the request context. Designed to be tenant-agnostic.
 */
class PppoePortalAuth
{
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

        // Validate token and find PPPoE user
        $pppoeUser = $this->validateToken($token);

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

        // Attach PPPoE user to request for downstream use
        $request->attributes->set('pppoe_user', $pppoeUser);
        
        // Set tenant context for proper data isolation
        $resolvedTenantId = $this->resolveTenantIdForUser($pppoeUser);
        $request->attributes->set('tenant_id', $resolvedTenantId);
        if ($resolvedTenantId) {
            try {
                $this->tenantContext->setTenantById($resolvedTenantId);
            } catch (\Throwable $e) {
                Log::error('Failed to set tenant context for PPPoE portal request', [
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
     * Extract token from request header or query parameter
     */
    private function extractToken(Request $request): ?string
    {
        // Check Authorization header first
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback to query parameter (for SSE/EventSource)
        return $request->query('portal_token');
    }

    /**
     * Validate portal token and return PPPoE user if valid
     */
    private function validateToken(string $token): ?PppoeUser
    {
        // Token format v2: base64(user_id|tenant_id|timestamp|signature)
        // Legacy v1:       base64(user_id|timestamp|signature)
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
        if ($isV2 && (string) $tenantId !== '') {
            $pppoeUser = $this->findPppoeUserByTenantAndId((string) $tenantId, (string) $userId);
        }
        if (!$pppoeUser) {
            $pppoeUser = $this->findPppoeUserByIdViaMapping((string) $userId);
        }
        if (!$pppoeUser) {
            $pppoeUser = $this->findPppoeUserByIdAcrossTenants((string) $userId);
        }

        if (!$pppoeUser) {
            return null;
        }

        // Backward-compatible secret for tenants that may not yet have portal_password populated.
        $secret = $pppoeUser->portal_password ?: $pppoeUser->password ?: $pppoeUser->account_number;
        if (!$secret) {
            return null;
        }

        // Verify signature
        $payload = $isV2
            ? ($userId . '|' . (string) $tenantId . '|' . $timestamp)
            : ($userId . '|' . $timestamp);
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $pppoeUser;
    }

    private function findPppoeUserByTenantAndId(string $tenantId, string $userId): ?PppoeUser
    {
        if ($tenantId === '') {
            return null;
        }

        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('is_active', true)
            ->first();

        if (!$tenant || !$tenant->schema_name) {
            return null;
        }

        return DB::transaction(function () use ($tenant, $userId) {
            DB::connection()->recordsHaveBeenModified();
            return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                $user = PppoeUser::query()->find($userId);
                if ($user) {
                    $user->setAttribute('tenant_id', (string) $tenant->id);
                }

                return $user;
            });
        });
    }

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
            ->where(function ($query) use ($mapping) {
                if (!empty($mapping->tenant_id)) {
                    $query->whereKey((string) $mapping->tenant_id);
                }

                if (!empty($mapping->schema_name)) {
                    $query->orWhere('schema_name', (string) $mapping->schema_name);
                }
            })
            ->first(['id', 'schema_name']);

        if (!$tenant || !$tenant->schema_name) {
            return null;
        }

        return DB::transaction(function () use ($tenant, $userId) {
            DB::connection()->recordsHaveBeenModified();
            return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                $user = PppoeUser::query()->find($userId);
                if ($user) {
                    $user->setAttribute('tenant_id', (string) $tenant->id);
                }

                return $user;
            });
        });
    }

    private function findPppoeUserByIdAcrossTenants(string $userId): ?PppoeUser
    {
        $tenants = Tenant::query()
            ->where('is_active', true)
            ->whereNotNull('schema_name')
            ->get(['id', 'schema_name']);

        foreach ($tenants as $tenant) {
            $user = DB::transaction(function () use ($tenant, $userId) {
                DB::connection()->recordsHaveBeenModified();
                return $this->tenantContext->runInTenantContext($tenant, function () use ($tenant, $userId) {
                    $user = PppoeUser::query()->find($userId);
                    if ($user) {
                        $user->setAttribute('tenant_id', (string) $tenant->id);
                    }

                    return $user;
                });
            });

            if ($user) {
                Log::warning('PPPoE portal token fell back to legacy tenant scan', [
                    'user_id' => $userId,
                    'tenant_id' => $tenant->id,
                ]);
                return $user;
            }
        }

        return null;
    }

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
            ->first(['id', 'account_prefix']);

        return $tenant ? (string) $tenant->id : null;
    }
}
