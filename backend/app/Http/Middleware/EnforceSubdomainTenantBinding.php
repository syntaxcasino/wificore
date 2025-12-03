<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce Subdomain-Tenant Binding
 * 
 * Ensures that authenticated users can ONLY access their tenant's subdomain.
 * Prevents cross-tenant access and data leakage.
 */
class EnforceSubdomainTenantBinding
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for unauthenticated requests
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        $host = $request->getHost();

        // Skip for localhost and IP addresses (development)
        if ($this->isLocalhost($host)) {
            return $next($request);
        }

        // System admins can access any subdomain
        if ($user->role === 'system_admin') {
            \Log::info('System admin access allowed', [
                'user_id' => $user->id,
                'username' => $user->username,
                'host' => $host,
            ]);
            return $next($request);
        }

        // Extract subdomain from host
        $subdomain = $this->extractSubdomain($host);

        // If no subdomain, this is main domain - only system admins allowed
        if (!$subdomain) {
            \Log::warning('Non-system-admin attempting to access main domain', [
                'user_id' => $user->id,
                'username' => $user->username,
                'role' => $user->role,
                'host' => $host,
            ]);

            Auth::logout();
            
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Please use your tenant subdomain.',
                'code' => 'SUBDOMAIN_REQUIRED',
            ], 403);
        }

        // Get user's tenant
        if (!$user->tenant_id) {
            \Log::error('User has no tenant assigned', [
                'user_id' => $user->id,
                'username' => $user->username,
            ]);

            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'User account is not associated with any tenant.',
                'code' => 'NO_TENANT_ASSIGNED',
            ], 403);
        }

        $tenant = $user->tenant;

        if (!$tenant) {
            \Log::error('User tenant not found', [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
            ]);

            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'Tenant not found.',
                'code' => 'TENANT_NOT_FOUND',
            ], 404);
        }

        // Check if tenant is active
        if (!$tenant->is_active) {
            \Log::warning('User attempting to access inactive tenant', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
            ]);

            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'This tenant account is inactive. Please contact support.',
                'code' => 'TENANT_INACTIVE',
            ], 403);
        }

        // Validate subdomain matches user's tenant
        $isValidSubdomain = $this->validateSubdomainForTenant($subdomain, $tenant);

        if (!$isValidSubdomain) {
            \Log::warning('Subdomain-tenant mismatch detected', [
                'user_id' => $user->id,
                'username' => $user->username,
                'user_tenant_id' => $tenant->id,
                'user_tenant_subdomain' => $tenant->subdomain,
                'user_tenant_custom_domain' => $tenant->custom_domain,
                'requested_subdomain' => $subdomain,
                'host' => $host,
            ]);

            // Log out the user to prevent session hijacking
            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'Access denied. You can only access your tenant subdomain.',
                'code' => 'SUBDOMAIN_MISMATCH',
                'details' => [
                    'your_subdomain' => $tenant->subdomain,
                    'requested_subdomain' => $subdomain,
                ],
            ], 403);
        }

        // All checks passed - allow request
        \Log::debug('Subdomain-tenant binding validated', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'subdomain' => $subdomain,
        ]);

        return $next($request);
    }

    /**
     * Check if host is localhost or IP address
     */
    private function isLocalhost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1']) ||
               filter_var($host, FILTER_VALIDATE_IP);
    }

    /**
     * Extract subdomain from host
     */
    private function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);
        
        // Need at least 3 parts for subdomain (subdomain.domain.tld)
        if (count($parts) < 3) {
            return null;
        }

        // Return first part as subdomain
        return $parts[0];
    }

    /**
     * Validate that subdomain matches tenant
     */
    private function validateSubdomainForTenant(string $subdomain, $tenant): bool
    {
        // Check if subdomain matches tenant's subdomain
        if ($tenant->subdomain === $subdomain) {
            return true;
        }

        // Check if subdomain matches tenant's custom domain
        if ($tenant->custom_domain && $tenant->custom_domain === $subdomain) {
            return true;
        }

        // Check if full host matches custom domain (for custom domains without subdomain)
        $fullHost = request()->getHost();
        if ($tenant->custom_domain && $tenant->custom_domain === $fullHost) {
            return true;
        }

        return false;
    }
}
