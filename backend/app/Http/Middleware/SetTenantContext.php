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
                $this->tenantContext->clearTenant();
                return $next($request);
            }
            
            // Regular users: set tenant context
            if ($user->tenant_id) {
                $tenant = Tenant::find($user->tenant_id);
                
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
                    $this->tenantContext->setTenant($tenant);
                    
                    // Store tenant in request for easy access
                    $request->merge(['tenant' => $tenant]);
                    $request->attributes->set('tenant', $tenant);
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
            $this->tenantContext->clearTenant();
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
        $this->tenantContext->clearTenant();
    }
}
