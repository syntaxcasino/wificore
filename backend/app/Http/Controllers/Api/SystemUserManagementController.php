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

class SystemUserManagementController extends Controller
{
    /**
     * Create a new system administrator
     * Only accessible by existing system administrators
     */
    public function createSystemAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username|regex:/^[a-z0-9_]+$/',
            'email' => 'required|email|max:255|unique:users,email',
            'phone_number' => 'nullable|string|max:20',
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

        // EVENT-BASED: Dispatch system admin creation job (async)
        $userData = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'role' => User::ROLE_SYSTEM_ADMIN,
        ];
        
        CreateUserJob::dispatch($userData, $request->password, null)
            ->onQueue('user-management');
        
        \Log::info('System admin creation job dispatched', [
            'requested_by' => $request->user()->id,
            'username' => $request->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'System administrator creation in progress',
            'data' => [
                'username' => $request->username,
                'status' => 'processing',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * List all system administrators
     */
    public function listSystemAdmins(Request $request)
    {
        $admins = User::where('role', User::ROLE_SYSTEM_ADMIN)
            ->select('id', 'name', 'username', 'email', 'phone_number', 'is_active', 'last_login_at', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $admins,
        ]);
    }

    /**
     * Update system administrator
     */
    public function updateSystemAdmin(Request $request, $id)
    {
        // Prevent deletion/deactivation of default admin
        if ($id === '00000000-0000-0000-0000-000000000001') {
            return response()->json([
                'success' => false,
                'message' => 'The default system administrator cannot be modified through this endpoint',
            ], 403);
        }

        $admin = User::where('role', User::ROLE_SYSTEM_ADMIN)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $id,
            'phone_number' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // EVENT-BASED: Dispatch system admin update job (async)
        $updateData = $request->only(['name', 'email', 'phone_number', 'is_active']);
        
        UpdateUserJob::dispatch($admin->id, $updateData)
            ->onQueue('user-management');
        
        \Log::info('System admin update job dispatched', [
            'updated_by' => $request->user()->id,
            'admin_id' => $admin->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'System administrator update in progress',
            'data' => [
                'admin_id' => $admin->id,
                'status' => 'processing',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * Delete system administrator
     */
    public function deleteSystemAdmin(Request $request, $id)
    {
        // Prevent deletion of default admin
        if ($id === '00000000-0000-0000-0000-000000000001') {
            return response()->json([
                'success' => false,
                'message' => 'The default system administrator cannot be deleted',
            ], 403);
        }

        // Prevent self-deletion
        if ($id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
            ], 403);
        }

        $admin = User::where('role', User::ROLE_SYSTEM_ADMIN)->findOrFail($id);

        // EVENT-BASED: Dispatch system admin deletion job (async)
        DeleteUserJob::dispatch($admin->id, $request->user()->username)
            ->onQueue('user-management');
        
        \Log::warning('System admin deletion job dispatched', [
            'deleted_by' => $request->user()->id,
            'admin_id' => $admin->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'System administrator deletion in progress',
            'data' => [
                'admin_id' => $admin->id,
                'status' => 'processing',
            ],
        ], 202); // 202 Accepted
    }
}
