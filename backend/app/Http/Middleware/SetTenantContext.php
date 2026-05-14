<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Log;

class SetTenantContext
{
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
            
            // Regular users: set tenant context
            if ($user->tenant_id) {
                $tenant = Tenant::select([
                        'id', 'name', 'schema_name', 'schema_created',
                        'is_active', 'suspended_at', 'suspension_reason', 'suspended_until',
                    ])->find($user->tenant_id);
                
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
                
                // Set tenant context (will set PostgreSQL search_path if schema exists)
                try {
                    // Store tenant in request for easy access
                    $request->merge(['tenant' => $tenant]);
                    $request->attributes->set('tenant', $tenant);

                    // Never hold a DB transaction for long-lived stream/SSE requests.
                    // Keeping a transaction open on Octane workers can exhaust pooled
                    // connections and cause intermittent 500s across unrelated endpoints.
                    if ($this->isStreamingRequest($request)) {
                        $this->tenantContext->setTenant($tenant);
                        return $next($request);
                    }

                    // Wrap the entire request in a DB::transaction() so PgBouncer
                    // holds a single backend PostgreSQL connection for all statements.
                    // SET LOCAL search_path is transaction-scoped: it is applied once
                    // inside setTenant() below and persists for every query in the
                    // controller as long as PgBouncer does not return the connection
                    // to the pool. Without a transaction, PgBouncer transaction pooling
                    // releases the backend between each statement, losing search_path.
                    return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $tenant, $next) {
                        // Force sticky-write mode so all SELECT queries in this request
                        // use the write PDO (which holds the open transaction).
                        // Without this, Eloquent routes SELECTs to the read PDO which
                        // has no transaction and loses SET LOCAL search_path immediately.
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
}
