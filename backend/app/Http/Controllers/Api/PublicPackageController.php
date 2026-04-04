<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterTenantMap;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicPackageController extends Controller
{
    /**
     * Get public packages for a specific tenant
     * Identifies tenant and router from request, returns global + router-specific packages
     */
    public function getPublicPackages(Request $request)
    {
        $tenantId = $this->identifyTenant($request);
        $routerId = $this->identifyRouter($request);
        
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to identify tenant. Please connect to a hotspot network.'
            ], 400);
        }

        // Set tenant context so Package queries hit the correct tenant schema
        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->is_active || !$tenant->schema_created) {
            return response()->json([
                'success' => false,
                'message' => 'Service not available'
            ], 503);
        }

        $tenantContext = app(TenantContext::class);
        $tenantContext->setTenant($tenant);

        // Cache key specific to this tenant and router
        $cacheKey = "public_packages_tenant_{$tenantId}_router_" . ($routerId ?? 'all');

        $packages = Cache::remember($cacheKey, 30, function () use ($routerId) {
            // Package is now in tenant schema - no tenant_id filter needed
            $query = Package::where('type', 'hotspot')
                ->where('is_active', true)
                ->where('hide_from_client', false);

            if ($routerId) {
                $query->where(function($q) use ($routerId) {
                    $q->where('is_global', true)
                      ->orWhereHas('routers', function($rq) use ($routerId) {
                          $rq->where('router_id', $routerId);
                      });
                });
            } else {
                $query->where('is_global', true);
            }

            return $query->select('id', 'name', 'description', 'price', 'duration', 'speed', 'data_limit', 'validity', 'is_global')
                ->orderBy('price', 'asc')
                ->get();
        });

        return response()->json([
            'success' => true,
            'tenant_id' => $tenantId,
            'router_id' => $routerId,
            'packages' => $packages
        ]);
    }

    /**
     * Identify router from request
     * Returns router ID if found, null otherwise
     */
    private function identifyRouter(Request $request)
    {
        // Method 1: From query parameter (for testing/direct access)
        if ($request->has('router_id')) {
            return $request->input('router_id');
        }

        // Method 2: From router IP via router_tenant_map (public schema lookup)
        $clientIp = $this->getClientIp($request);
        $map = RouterTenantMap::where('ip_address', $clientIp)
            ->orWhere('ip_address', $request->ip())
            ->first();
        if ($map) {
            return $map->router_id;
        }

        // Method 3: Check gateway IP
        $gatewayIp = $this->detectGatewayIp($request);
        if ($gatewayIp) {
            $map = RouterTenantMap::where('ip_address', $gatewayIp)->first();
            if ($map) {
                return $map->router_id;
            }
        }

        // Method 4: From session (if router previously identified)
        if ($request->hasSession() && $request->session()->has('router_id')) {
            return $request->session()->get('router_id');
        }

        return null;
    }

    /**
     * Identify tenant from request
     * Methods:
     * 1. Router IP from X-Forwarded-For or client IP
     * 2. Subdomain (e.g., tenant-a.hotspot.com)
     * 3. Query parameter (?tenant_id=xxx)
     */
    private function identifyTenant(Request $request)
    {
        // Method 1: From query parameter (for testing/direct access)
        if ($request->has('tenant_id')) {
            return $request->input('tenant_id');
        }

        // Method 2: From subdomain
        $host = $request->getHost();
        $subdomain = $this->extractSubdomain($host);
        
        if ($subdomain) {
            $tenant = Tenant::where('slug', $subdomain)->first();
            if ($tenant) {
                return $tenant->id;
            }
        }

        // Method 3: From router IP via router_tenant_map (public schema lookup)
        $clientIp = $this->getClientIp($request);
        $tenantId = RouterTenantMap::where('ip_address', $clientIp)
            ->orWhere('ip_address', $request->ip())
            ->value('tenant_id');
        if ($tenantId) {
            return $tenantId;
        }

        // Method 4: Check gateway IP
        $gatewayIp = $this->detectGatewayIp($request);
        if ($gatewayIp) {
            $tenantId = RouterTenantMap::where('ip_address', $gatewayIp)->value('tenant_id');
            if ($tenantId) {
                return $tenantId;
            }
        }

        // Method 5: From session (if user previously accessed)
        if ($request->hasSession() && $request->session()->has('tenant_id')) {
            return $request->session()->get('tenant_id');
        }

        return null;
    }

    /**
     * Extract subdomain from host
     */
    private function extractSubdomain($host)
    {
        $parts = explode('.', $host);
        
        // If we have at least 3 parts (subdomain.domain.tld)
        if (count($parts) >= 3) {
            return $parts[0];
        }
        
        return null;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request)
    {
        // Check for proxy headers
        if ($request->header('X-Forwarded-For')) {
            $ips = explode(',', $request->header('X-Forwarded-For'));
            return trim($ips[0]);
        }
        
        if ($request->header('X-Real-IP')) {
            return $request->header('X-Real-IP');
        }
        
        return $request->ip();
    }

    /**
     * Detect gateway IP from request headers or network info
     */
    private function detectGatewayIp(Request $request)
    {
        // MikroTik hotspot often sends gateway IP in custom headers
        if ($request->header('X-Gateway-IP')) {
            return $request->header('X-Gateway-IP');
        }
        
        // Check for common hotspot headers
        if ($request->header('X-Router-IP')) {
            return $request->header('X-Router-IP');
        }
        
        return null;
    }

    /**
     * Store tenant ID in session for future requests
     */
    public function setTenantSession(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id'
        ]);

        $request->session()->put('tenant_id', $validated['tenant_id']);

        return response()->json([
            'success' => true,
            'message' => 'Tenant session set successfully'
        ]);
    }
}
