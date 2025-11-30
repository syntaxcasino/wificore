<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicTenantController extends Controller
{
    /**
     * Get tenant information by subdomain
     * Public endpoint - no authentication required
     */
    public function getTenantBySubdomain(Request $request, string $subdomain)
    {
        // Cache tenant info for 1 hour
        $tenant = Cache::remember("tenant:public:{$subdomain}", 3600, function () use ($subdomain) {
            return Tenant::where('subdomain', $subdomain)
                ->orWhere('custom_domain', $subdomain)
                ->first();
        });

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        if (!$tenant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This service is currently unavailable',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'subdomain' => $tenant->subdomain,
                'custom_domain' => $tenant->custom_domain,
                'branding' => $tenant->branding,
                'public_packages_enabled' => $tenant->public_packages_enabled,
                'public_registration_enabled' => $tenant->public_registration_enabled,
            ],
        ]);
    }

    /**
     * Get public packages for a tenant
     * Public endpoint - no authentication required
     */
    public function getPublicPackages(Request $request, string $subdomain)
    {
        // Find tenant
        $tenant = Tenant::where('subdomain', $subdomain)
            ->orWhere('custom_domain', $subdomain)
            ->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        if (!$tenant->is_active || !$tenant->public_packages_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Public packages are not available',
            ], 403);
        }

        // Get active packages for this tenant
        $packages = Package::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('is_public', true) // Only show public packages
            ->select([
                'id',
                'name',
                'description',
                'price',
                'duration_hours',
                'data_limit_bytes',
                'speed_limit_mbps',
                'type',
                'features',
            ])
            ->orderBy('price', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'tenant' => [
                    'name' => $tenant->name,
                    'branding' => $tenant->branding,
                ],
                'packages' => $packages,
            ],
        ]);
    }

    /**
     * Get tenant by custom domain
     */
    public function getTenantByDomain(Request $request)
    {
        $host = $request->getHost();
        
        $tenant = Cache::remember("tenant:domain:{$host}", 3600, function () use ($host) {
            return Tenant::where('custom_domain', $host)
                ->orWhere('subdomain', $host)
                ->first();
        });

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found for this domain',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'subdomain' => $tenant->subdomain,
                'custom_domain' => $tenant->custom_domain,
                'branding' => $tenant->branding,
            ],
        ]);
    }

    /**
     * Check if subdomain is available
     */
    public function checkSubdomainAvailability(Request $request)
    {
        $subdomain = $request->input('subdomain');

        if (!$subdomain) {
            return response()->json([
                'success' => false,
                'message' => 'Subdomain is required',
            ], 422);
        }

        // Validate subdomain format
        if (!preg_match('/^[a-z0-9-]+$/', $subdomain)) {
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Invalid subdomain format. Use only lowercase letters, numbers, and hyphens.',
            ], 422);
        }

        // Check if reserved
        $reserved = ['www', 'api', 'admin', 'app', 'mail', 'system', 'test', 'dev', 'staging', 'demo'];
        if (in_array(strtolower($subdomain), $reserved)) {
            return response()->json([
                'success' => true,
                'available' => false,
                'message' => 'This subdomain is reserved',
            ]);
        }

        // Check if exists
        $exists = Tenant::where('subdomain', $subdomain)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'message' => $exists ? 'Subdomain is already taken' : 'Subdomain is available',
        ]);
    }
}
