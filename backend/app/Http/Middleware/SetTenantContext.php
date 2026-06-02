<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SetTenantContext
{
    private const TENANT_CACHE_TTL_SECONDS = 30;

    /**
     * TenantContext service
     */
    protected TenantContext $tenantContext;
    
    public function __construct(TenantContext $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }
    
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if ($request->user()) {
            $user = $request->user();
            
            // System admins use public schema (no tenant context)
            if (in_array($user->role, config('multitenancy.system_admin_roles', ['system_admin']))) {
                $this->safeClearTenantContext($request, 'system_admin');
                return $next($request);
            }
            
            // Fail closed: authenticated non-system users must have tenant context.
            if (!$user->tenant_id) {
                Log::critical("Authenticated user missing tenant context", [
                    "user_id" => $user->id,
                    "role" => $user->role,
                    "path" => $request->path(),
                    "method" => $request->method(),
                ]);

                return response()->json([
                    "success" => false,
                    "message" => "Tenant context is required for this account"
                ], 403);
            }

            // Regular users: set tenant context
            if ($user->tenant_id) {
                $tenant = $this->resolveTenant((string) $user->tenant_id);
                
                if (!$tenant) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tenant not found'
                    ], 404);
                }
                
                // Verify tenant is active
                if (!$tenant->isActive()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tenant account is suspended or inactive',
                        'suspended_at' => $tenant->suspended_at,
                        'suspension_reason' => $tenant->suspension_reason,
                    ], 403);
                }

                // Verify tenant schema is fully created
                if (!$tenant->schema_created) {
                    Log::warning('Tenant schema not created, rejecting request', [
                        'tenant_id' => $tenant->id,
                        'schema_name' => $tenant->schema_name,
                        'user_id' => $user->id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Tenant workspace is being set up. Please try again shortly.',
                        'code' => 'TENANT_SCHEMA_NOT_READY',
                    ], 503);
                }

                // Set tenant context (will set PostgreSQL search_path if schema exists)
                try {
                    // Store tenant in request for easy access
                    $request->merge(['tenant' => $tenant]);
                    $request->attributes->set('tenant', $tenant);

                    // Streaming requests should never hold a request-wide transaction.
                    if ($this->isStreamingRequest($request)) {
                        $this->tenantContext->setTenant($tenant);
                        \Illuminate\Support\Facades\DB::connection()->recordsHaveBeenModified();
                        return $next($request);
                    }

                    // PgBouncer write connection runs in transaction pooling mode.
                    // SET LOCAL search_path only persists within an explicit transaction;
                    // outside a transaction PgBouncer may route the next statement to a
                    // different backend connection that still has search_path=public.
                    // recordsHaveBeenModified() forces sticky-write so all SELECTs inside
                    // the transaction use the same write PDO (not the read replica).
                    return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $tenant, $next) {
                        \Illuminate\Support\Facades\DB::connection()->recordsHaveBeenModified();
                        $this->tenantContext->setTenant($tenant);
                        return $next($request);
                    });
                } catch (\Exception $e) {
                    Log::error('Failed to set tenant context', [
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to set tenant context'
                    ], 500);
                }
            }
        } else {
            // No user: use public schema
            $this->safeClearTenantContext($request, 'guest');
        }
        
        return $next($request);
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    public function terminate(Request $request, Response $response): void
    {
        // Clear tenant context after request completes
        $this->safeClearTenantContext($request, 'terminate');
    }

    private function isStreamingRequest(Request $request): bool
    {
        return $request->is('api/sse/*')
            || str_contains((string) $request->headers->get('accept'), 'text/event-stream');
    }

    private function safeClearTenantContext(Request $request, string $stage): void
    {
        try {
            $this->tenantContext->clearTenant();
        } catch (\Throwable $e) {
            Log::warning('Failed to clear tenant context', [
                'stage' => $stage,
                'path' => $request->path(),
                'method' => $request->method(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveTenant(string $tenantId): ?Tenant
    {
        return Cache::remember(
            "tenant_context:{$tenantId}",
            self::TENANT_CACHE_TTL_SECONDS,
            fn () => Tenant::select([
                'id',
                'name',
                'schema_name',
                'schema_created',
                'is_active',
                'suspended_at',
                'suspension_reason',
            ])->find($tenantId)
        );
    }
}
