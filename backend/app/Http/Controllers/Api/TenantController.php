<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;

class TenantController extends Controller
{
    private function bustTenantCache(\App\Models\Tenant $tenant): void
    {
        Cache::forget("tenant:subdomain:{$tenant->subdomain}");
        Cache::forget("tenant:public:{$tenant->subdomain}");
        if ($tenant->custom_domain) {
            Cache::forget("tenant:domain:{$tenant->custom_domain}");
        }
        Cache::forget("tenant_{$tenant->id}_dashboard_stats");
    }

    /**
     * Display a listing of tenants (Super Admin only)
     */
    public function index(Request $request)
    {
        $query = Tenant::query();
        
        // Search
        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('slug', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }
        
        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }
        
        if ($request->has('suspended')) {
            if ($request->suspended) {
                $query->whereNotNull('suspended_at');
            } else {
                $query->whereNull('suspended_at');
            }
        }
        
        $tenants = $query->withCount(['users'])
            ->latest()
            ->paginate($request->per_page ?? 20);
        
        return response()->json([
            'success' => true,
            'tenants' => $tenants
        ]);
    }
    
    /**
     * Store a newly created tenant
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:tenants,slug',
            'custom_domain' => 'nullable|string|max:255|unique:tenants,custom_domain',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'settings' => 'nullable|array',
            'trial_ends_at' => 'nullable|date',
        ]);
        
        // Auto-generate slug if not provided
        if (!isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
            
            // Ensure uniqueness
            $counter = 1;
            $originalSlug = $validated['slug'];
            while (Tenant::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] = $originalSlug . '-' . $counter++;
            }
        }
        
        $tenant = Tenant::create($validated);

        // Schema setup is no longer auto-triggered by Tenant::created boot event.
        // Run it explicitly (DDL, outside any transaction).
        $migrationManager = app(\App\Services\TenantMigrationManager::class);
        if (!$migrationManager->setupTenantSchema($tenant)) {
            \Log::error('TenantController: Schema setup failed for tenant', ['tenant_id' => $tenant->id]);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Tenant created successfully',
            'tenant' => $tenant
        ], 201);
    }
    
    /**
     * Display the specified tenant
     */
    public function show(Tenant $tenant)
    {
        $tenant->loadCount(['users']);
        
        // Single query for user counts
        $userRow = \Illuminate\Support\Facades\DB::table('users')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total, COUNT(*) FILTER (WHERE is_active = true) as active')
            ->first();

        $stats = [
            'total_users'  => (int) ($userRow->total  ?? 0),
            'active_users' => (int) ($userRow->active ?? 0),
        ];

        // If tenant has a schema, get tenant-schema stats via context switch
        if ($tenant->schema_created && $tenant->schema_name) {
            try {
                $tenantContext = app(\App\Services\TenantContext::class);
                $tenantContext->runInTenantContext($tenant, function () use (&$stats) {
                    // Single query: router + package counts
                    $routerAgg = \App\Models\Router::selectRaw("
                        COUNT(*) as total,
                        COUNT(*) FILTER (WHERE status = 'online') as online
                    ")->first();
                    $packageAgg = \App\Models\Package::selectRaw("
                        COUNT(*) as total,
                        COUNT(*) FILTER (WHERE is_active = true) as active
                    ")->first();

                    // Single query: payment revenue
                    $revenueRow = \App\Models\Payment::where('status', 'completed')
                        ->selectRaw("
                            COALESCE(SUM(amount), 0) as total_revenue,
                            COALESCE(SUM(amount) FILTER (WHERE EXTRACT(MONTH FROM created_at) = ? AND EXTRACT(YEAR FROM created_at) = ?), 0) as monthly_revenue
                        ", [now()->month, now()->year])
                        ->first();

                    $stats['total_routers']   = (int)   ($routerAgg->total        ?? 0);
                    $stats['active_routers']  = (int)   ($routerAgg->online       ?? 0);
                    $stats['total_packages']  = (int)   ($packageAgg->total       ?? 0);
                    $stats['active_packages'] = (int)   ($packageAgg->active      ?? 0);
                    $stats['total_revenue']   = (float) ($revenueRow->total_revenue   ?? 0);
                    $stats['monthly_revenue'] = (float) ($revenueRow->monthly_revenue ?? 0);
                });
            } catch (\Exception $e) {
                \Log::warning('Failed to get tenant schema stats', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage()
                ]);
                $stats['total_routers'] = 0;
                $stats['active_routers'] = 0;
                $stats['total_packages'] = 0;
                $stats['active_packages'] = 0;
                $stats['total_revenue'] = 0;
                $stats['monthly_revenue'] = 0;
            }
        } else {
            $stats['total_routers'] = 0;
            $stats['active_routers'] = 0;
            $stats['total_packages'] = 0;
            $stats['active_packages'] = 0;
            $stats['total_revenue'] = 0;
            $stats['monthly_revenue'] = 0;
        }
        
        return response()->json([
            'success' => true,
            'tenant' => $tenant,
            'stats' => $stats
        ]);
    }
    
    /**
     * Update the specified tenant
     */
    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('tenants')->ignore($tenant->id)],
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('tenants', 'custom_domain')->ignore($tenant->id)],
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'address' => 'sometimes|nullable|string',
            'settings' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
            'trial_ends_at' => 'sometimes|nullable|date',
        ]);
        
        $tenant->update($validated);
        $this->bustTenantCache($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Tenant updated successfully',
            'tenant' => $tenant->fresh()
        ]);
    }
    
    /**
     * Suspend a tenant
     */
    public function suspend(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500'
        ]);
        
        $tenant->suspend($validated['reason'] ?? null);
        $this->bustTenantCache($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Tenant suspended successfully',
            'tenant' => $tenant->fresh()
        ]);
    }
    
    /**
     * Activate a tenant
     */
    public function activate(Tenant $tenant)
    {
        $tenant->activate();
        $this->bustTenantCache($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Tenant activated successfully',
            'tenant' => $tenant->fresh()
        ]);
    }
    
    /**
     * Remove the specified tenant (soft delete)
     */
    public function destroy(Tenant $tenant)
    {
        // Prevent deletion of default tenant
        if ($tenant->slug === 'default') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete default tenant'
            ], 403);
        }
        
        $tenant->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Tenant deleted successfully'
        ]);
    }
    
    /**
     * Get current user's tenant info
     */
    public function current(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant associated with user'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'tenant' => $tenant
        ]);
    }
}
