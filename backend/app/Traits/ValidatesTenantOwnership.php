<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Trait to validate tenant ownership and prevent IDOR vulnerabilities
 */
trait ValidatesTenantOwnership
{
    /**
     * Validate that a resource belongs to the authenticated user's tenant
     *
     * @param mixed $resource The resource to validate (must have tenant_id property)
     * @param string $errorMessage Custom error message
     * @return JsonResponse|null Returns error response if validation fails, null if passes
     */
    protected function validateTenantOwnership($resource, string $errorMessage = 'Unauthorized access to resource'): ?JsonResponse
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // System admins can access all resources
        if ($user->role === 'system_admin') {
            return null;
        }

        // Check if resource has tenant_id
        if (!isset($resource->tenant_id)) {
            // Resource is in tenant schema, already scoped by TenantScope
            return null;
        }

        // Validate tenant ownership
        if ($resource->tenant_id !== $user->tenant_id) {
            \Log::warning('IDOR attempt detected', [
                'user_id' => $user->id,
                'user_tenant_id' => $user->tenant_id,
                'resource_tenant_id' => $resource->tenant_id,
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? 'unknown',
                'ip' => request()->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
            ], 403);
        }

        return null;
    }

    /**
     * Validate that a user belongs to the authenticated user's tenant
     *
     * @param \App\Models\User $targetUser
     * @return JsonResponse|null
     */
    protected function validateUserTenantOwnership($targetUser): ?JsonResponse
    {
        return $this->validateTenantOwnership($targetUser, 'Cannot access user from different tenant');
    }

    /**
     * Validate that multiple resources belong to the authenticated user's tenant
     *
     * @param array $resources Array of resources to validate
     * @return JsonResponse|null
     */
    protected function validateMultipleTenantOwnership(array $resources): ?JsonResponse
    {
        foreach ($resources as $resource) {
            $result = $this->validateTenantOwnership($resource);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Ensure query is scoped to authenticated user's tenant
     * Use this for queries on public schema tables with tenant_id
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function scopeToUserTenant($query)
    {
        $user = Auth::user();
        
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        // System admins see all
        if ($user->role === 'system_admin') {
            return $query;
        }

        // Scope to user's tenant
        if ($user->tenant_id) {
            return $query->where('tenant_id', $user->tenant_id);
        }

        return $query;
    }

    /**
     * Validate that the authenticated user has permission to perform action
     *
     * @param string $action
     * @param mixed $resource
     * @return JsonResponse|null
     */
    protected function validatePermission(string $action, $resource = null): ?JsonResponse
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Check role-based permissions
        $allowedRoles = $this->getPermissionRoles($action);
        
        if (!in_array($user->role, $allowedRoles)) {
            \Log::warning('Unauthorized action attempt', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'action' => $action,
                'allowed_roles' => $allowedRoles,
                'ip' => request()->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions',
            ], 403);
        }

        // If resource provided, validate tenant ownership
        if ($resource) {
            return $this->validateTenantOwnership($resource);
        }

        return null;
    }

    /**
     * Get allowed roles for an action
     * Override this method in your controller to define custom permissions
     *
     * @param string $action
     * @return array
     */
    protected function getPermissionRoles(string $action): array
    {
        // Default: only admins and system admins
        return ['admin', 'system_admin'];
    }
}
