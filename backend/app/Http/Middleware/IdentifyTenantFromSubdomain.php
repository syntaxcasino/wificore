<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenantFromSubdomain
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        
        // Skip for localhost and IP addresses (development)
        if ($this->isLocalhost($host)) {
            return $next($request);
        }

        // Extract subdomain
        $subdomain = $this->extractSubdomain($host);
        
        // Skip if no subdomain or if it's a reserved subdomain
        if (!$subdomain || $this->isReservedSubdomain($subdomain)) {
            return $next($request);
        }

        // Find tenant by subdomain (with caching)
        $tenant = Cache::remember("tenant:subdomain:{$subdomain}", 3600, function () use ($subdomain) {
            return Tenant::where('subdomain', $subdomain)
                ->orWhere('custom_domain', $subdomain)
                ->first();
        });

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found for this subdomain',
                'subdomain' => $subdomain,
            ], 404);
        }

        // Check if tenant is active
        if (!$tenant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This tenant account is inactive',
            ], 403);
        }

        // Store tenant in request
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('tenant_id', $tenant->id);
        $request->attributes->set('subdomain', $subdomain);

        // Add tenant context to logs
        \Log::withContext([
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
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
     * Check if subdomain is reserved
     */
    private function isReservedSubdomain(string $subdomain): bool
    {
        $reserved = [
            'www',
            'api',
            'admin',
            'app',
            'mail',
            'ftp',
            'smtp',
            'pop',
            'imap',
            'webmail',
            'cpanel',
            'whm',
            'ns1',
            'ns2',
            'system',
            'test',
            'dev',
            'staging',
            'demo',
        ];

        return in_array(strtolower($subdomain), $reserved);
    }
}
