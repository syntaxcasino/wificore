<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Package;
use App\Events\PackageCreated;
use App\Events\PackageUpdated;
use App\Events\PackageDeleted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class PackageController extends Controller
{
    public function index()
    {
        // Ensure user is authenticated
        $user = auth()->user();
        
        if (!$user) {
            return response()->json([
                'error' => 'Authentication required'
            ], 401);
        }
        
        $tenantId = $user->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Optimized cache with shorter TTL for real-time updates
        return Cache::remember("packages_list_tenant_{$tenantId}", 15, function () {
            return Package::select([
                'id', 'name', 'description', 'type', 'price', 'duration', 'validity',
                'speed', 'download_speed', 'upload_speed', 'data_limit',
                'devices', 'users_count', 'status', 'is_active',
                'hide_from_client', 'enable_burst', 'enable_schedule',
                'scheduled_activation_time', 'scheduled_deactivation_time', 'is_global', 'is_public',
                'created_at', 'updated_at'
            ])
            ->orderBy('created_at', 'desc')
            ->get();
        });
    }

    public function show($id)
    {
        // Optimized query with specific column selection
        $package = Package::select([
            'id', 'name', 'description', 'type', 'price', 'duration', 
            'upload_speed', 'download_speed', 'speed', 'devices', 'data_limit', 
            'validity', 'enable_burst', 'enable_schedule', 'scheduled_activation_time',
            'scheduled_deactivation_time', 'hide_from_client', 'is_global', 
            'status', 'is_active', 'is_public', 'users_count', 'created_at', 'updated_at'
        ])
        ->where('id', $id)
        ->with(['routers:id,name']) // Only select needed columns
        ->firstOrFail();
        
        return response()->json($package, 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:hotspot,pppoe',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'required|string|max:50',
            'upload_speed' => 'required|string|max:50',
            'download_speed' => 'required|string|max:50',
            'speed' => 'nullable|string|max:50',
            'price' => 'required|numeric|min:0',
            'devices' => 'required|integer|min:1',
            'data_limit' => 'nullable|string|max:50',
            'validity' => 'nullable|string|max:50',
            'enable_burst' => 'boolean',
            'enable_schedule' => 'boolean',
            'scheduled_activation_time' => 'nullable|date|after:now',
            'hide_from_client' => 'boolean',
            'is_global' => 'boolean',
            'router_ids' => 'nullable|array',
            'router_ids.*' => 'exists:routers,id',
            'status' => 'nullable|string|in:active,inactive',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get tenant_id for cache key and event broadcasting
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Schema isolation handles tenancy - no tenant_id needed
        $package = Package::create([
            'type' => $request->type,
            'name' => $request->name,
            'description' => $request->description,
            'duration' => $request->duration,
            'upload_speed' => $request->upload_speed,
            'download_speed' => $request->download_speed,
            'speed' => $request->speed ?? $request->download_speed,
            'price' => $request->price,
            'devices' => $request->devices,
            'data_limit' => $request->data_limit,
            'validity' => !empty($request->validity) ? $request->validity : $request->duration,
            'enable_burst' => $request->enable_burst ?? false,
            'enable_schedule' => $request->enable_schedule ?? false,
            'scheduled_activation_time' => $request->scheduled_activation_time,
            'hide_from_client' => $request->hide_from_client ?? false,
            'is_global' => $request->is_global ?? true,
            'status' => $request->status ?? 'active',
            'is_active' => $request->is_active ?? true,
            'users_count' => 0,
        ]);

        // Attach routers if not global and router_ids provided
        if (!$package->is_global && $request->has('router_ids') && is_array($request->router_ids)) {
            $package->routers()->attach($request->router_ids, [
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // Load routers relationship
        $package->load('routers:id,name');

        // Clear cache for current tenant - comprehensive cache busting
        $this->bustPackageCache((string) $tenantId);
        
        // Broadcast event for real-time updates
        event(new PackageCreated($package->toArray(), (string) $tenantId));

        return response()->json([
            'success' => true,
            'data' => $package,
            'message' => 'Package created successfully',
            'status' => 'completed',
        ], 201);
    }

    public function update(Request $request, $id)
    {
        // Get tenant_id for cache key and event broadcasting
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Schema isolation ensures only tenant's packages are visible
        $package = Package::where('id', $id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|required|string|in:hotspot,pppoe',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'sometimes|required|string|max:50',
            'upload_speed' => 'sometimes|required|string|max:50',
            'download_speed' => 'sometimes|required|string|max:50',
            'speed' => 'nullable|string|max:50',
            'price' => 'sometimes|required|numeric|min:0',
            'devices' => 'sometimes|required|integer|min:1',
            'data_limit' => 'nullable|string|max:50',
            'validity' => 'nullable|string|max:50',
            'enable_burst' => 'boolean',
            'enable_schedule' => 'boolean',
            'scheduled_activation_time' => 'nullable|date',
            'hide_from_client' => 'boolean',
            'is_global' => 'boolean',
            'router_ids' => 'nullable|array',
            'router_ids.*' => 'exists:routers,id',
            'status' => 'nullable|string|in:active,inactive',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = [];
        
        if ($request->has('type')) $updateData['type'] = $request->type;
        if ($request->has('name')) $updateData['name'] = $request->name;
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('duration')) $updateData['duration'] = $request->duration;
        if ($request->has('upload_speed')) $updateData['upload_speed'] = $request->upload_speed;
        if ($request->has('download_speed')) $updateData['download_speed'] = $request->download_speed;
        if ($request->has('speed')) $updateData['speed'] = $request->speed;
        if ($request->has('price')) $updateData['price'] = $request->price;
        if ($request->has('devices')) $updateData['devices'] = $request->devices;
        if ($request->has('data_limit')) $updateData['data_limit'] = $request->data_limit;
        if ($request->has('validity')) $updateData['validity'] = $request->validity;
        if ($request->has('enable_burst')) $updateData['enable_burst'] = $request->enable_burst;
        if ($request->has('enable_schedule')) $updateData['enable_schedule'] = $request->enable_schedule;
        if ($request->has('scheduled_activation_time')) $updateData['scheduled_activation_time'] = $request->scheduled_activation_time;
        if ($request->has('hide_from_client')) $updateData['hide_from_client'] = $request->hide_from_client;
        if ($request->has('is_global')) $updateData['is_global'] = $request->is_global;
        if ($request->has('status')) $updateData['status'] = $request->status;
        if ($request->has('is_active')) $updateData['is_active'] = $request->is_active;

        $package->update($updateData);

        // Update router assignments if provided
        if ($request->has('router_ids')) {
            if ($package->is_global) {
                // If package is global, remove all router assignments
                $package->routers()->detach();
            } else {
                // Sync router assignments
                $syncData = [];
                foreach ($request->router_ids as $routerId) {
                    $syncData[$routerId] = [
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                $package->routers()->sync($syncData);
            }
        }

        // Load routers relationship
        $package->load('routers:id,name');

        // Clear cache for current tenant - comprehensive cache busting
        $this->bustPackageCache((string) $tenantId);

        event(new PackageUpdated($package->toArray(), (string) $tenantId));

        return response()->json([
            'success' => true,
            'data' => $package,
            'message' => 'Package updated successfully',
            'status' => 'completed',
        ], 200);
    }

    public function destroy($id)
    {
        // Get tenant_id for cache key and event broadcasting
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Schema isolation ensures only tenant's packages are visible
        $package = Package::where('id', $id)->firstOrFail();
        
        // Check if package has active payments or sessions
        $hasActivePayments = $package->payments()->where('status', 'completed')->exists();
        
        if ($hasActivePayments) {
            return response()->json([
                'error' => 'Cannot delete package with active payments. Please deactivate it instead.'
            ], 422);
        }

        $packageName = $package->name;
        $packageId = $package->id;
        $package->delete();

        // Clear cache for current tenant - comprehensive cache busting
        $this->bustPackageCache((string) $tenantId);

        event(new PackageDeleted((string) $packageId, $packageName, (string) $tenantId));

        return response()->json([
            'success' => true,
            'message' => 'Package deleted successfully',
            'status' => 'completed',
        ], 200);
    }

    /**
     * Comprehensive cache busting for packages to prevent stale data
     */
    private function bustPackageCache(string $tenantId): void
    {
        Cache::forget("packages_list_tenant_{$tenantId}");

        $packages = Package::select('id')->get();
        foreach ($packages as $package) {
            Cache::forget("package_{$package->id}_tenant_{$tenantId}");
        }

        TenantDashboardController::bustEntityCache($tenantId, 'packages');
        TenantDashboardController::bustDashboardCache($tenantId);

        Cache::forget("vouchers_list_tenant_{$tenantId}");
        Cache::forget("voucher_stats_tenant_{$tenantId}");
        Cache::forget("pppoe_portal_plans:{$tenantId}");
        Cache::forget("pppoe_portal_timed_voucher_options:{$tenantId}");
        Cache::tags(["router_packages_{$tenantId}"])->flush();
    }
}