<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Jobs\CreateUserJob;
use App\Jobs\UpdateUserJob;
use App\Jobs\DeleteUserJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TenantUserManagementController extends Controller
{
    /**
     * Create a new user within the tenant
     * Only accessible by tenant administrators
     */
    public function createUser(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username|regex:/^[a-z0-9_]+$/',
            'email' => 'required|email|max:255|unique:users,email',
            'phone_number' => 'nullable|string|max:20',
            'role' => 'required|in:admin,hotspot_user',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // EVENT-BASED: Dispatch user creation job (async)
        $userData = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'role' => $request->role,
        ];
        
        CreateUserJob::dispatch($userData, $request->password, $tenantId)
            ->onQueue('user-management');
        
        \Log::info('User creation job dispatched', [
            'requested_by' => $request->user()->id,
            'tenant_id' => $tenantId,
            'username' => $request->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User creation in progress',
            'data' => [
                'username' => $request->username,
                'status' => 'processing',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * List all users in the tenant
     */
    public function listUsers(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $users = User::where('tenant_id', $tenantId)
            ->select('id', 'name', 'username', 'email', 'phone_number', 'role', 'is_active', 'last_login_at', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Update user in the tenant
     */
    public function updateUser(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $id,
            'phone_number' => 'nullable|string|max:20',
            'role' => 'sometimes|required|in:admin,hotspot_user',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // EVENT-BASED: Dispatch user update job (async)
        $updateData = $request->only(['name', 'email', 'phone_number', 'role', 'is_active']);
        
        UpdateUserJob::dispatch($user->id, $updateData)
            ->onQueue('user-management');
        
        \Log::info('User update job dispatched', [
            'updated_by' => $request->user()->id,
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User update in progress',
            'data' => [
                'user_id' => $user->id,
                'status' => 'processing',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * Delete user from the tenant
     */
    public function deleteUser(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        // Prevent self-deletion
        if ($id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
            ], 403);
        }

        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        // EVENT-BASED: Dispatch user deletion job (async)
        DeleteUserJob::dispatch($user->id, $request->user()->username)
            ->onQueue('user-management');
        
        \Log::warning('User deletion job dispatched', [
            'deleted_by' => $request->user()->id,
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User deletion in progress',
            'data' => [
                'user_id' => $user->id,
                'status' => 'processing',
            ],
        ], 202); // 202 Accepted
    }
}
