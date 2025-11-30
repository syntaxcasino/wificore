<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Package;
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
        
        // Get current user's tenant_id for proper tenant isolation
        $tenantId = $user->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Cache packages per tenant to prevent data leaks
        return Cache::remember("packages_list_tenant_{$tenantId}", 600, function () use ($tenantId) {
            return Package::where('tenant_id', $tenantId)
                ->with(['routers:id,name'])
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function show($id)
    {
        // Get tenant_id from authenticated user
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Find package and ensure it belongs to the current tenant
        $package = Package::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['routers:id,name'])
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

        // Get tenant_id from authenticated user
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        $package = Package::create([
            'tenant_id' => $tenantId,
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
            'validity' => $request->validity ?? $request->duration,
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
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // Load routers relationship
        $package->load('routers:id,name');

        // Clear cache for current tenant
        Cache::forget("packages_list_tenant_{$tenantId}");

        return response()->json($package, 201);
    }

    public function update(Request $request, $id)
    {
        // Get tenant_id from authenticated user
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Find package and ensure it belongs to the current tenant
        $package = Package::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

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
                        'tenant_id' => $tenantId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                $package->routers()->sync($syncData);
            }
        }

        // Load routers relationship
        $package->load('routers:id,name');

        // Clear cache for current tenant
        Cache::forget("packages_list_tenant_{$tenantId}");

        return response()->json($package, 200);
    }

    public function destroy($id)
    {
        // Get tenant_id from authenticated user
        $tenantId = auth()->user()->tenant_id;
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID is required'
            ], 403);
        }
        
        // Find package and ensure it belongs to the current tenant
        $package = Package::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        
        // Check if package has active payments or sessions
        $hasActivePayments = $package->payments()->where('status', 'completed')->exists();
        
        if ($hasActivePayments) {
            return response()->json([
                'error' => 'Cannot delete package with active payments. Please deactivate it instead.'
            ], 422);
        }

        $package->delete();

        // Clear cache for current tenant
        $tenantId = auth()->user()->tenant_id ?? 'system';
        Cache::forget("packages_list_tenant_{$tenantId}");

        return response()->json([
            'message' => 'Package deleted successfully'
        ], 200);
    }
}