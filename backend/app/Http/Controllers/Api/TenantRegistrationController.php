<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\CreateTenantJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TenantRegistrationController extends Controller
{
    protected $radiusService;

    public function __construct(\App\Services\RadiusService $radiusService)
    {
        $this->radiusService = $radiusService;
    }
    /**
     * Register a new tenant with admin user
     * Public endpoint - no authentication required
     */
    public function register(Request $request)
    {
        // Validate registration data
        $validator = Validator::make($request->all(), [
            // Tenant information
            'tenant_name' => 'required|string|max:255',
            'tenant_email' => 'required|email|max:255|unique:tenants,email',
            'tenant_phone' => 'nullable|string|max:50',
            'tenant_address' => 'nullable|string|max:500',
            
            // Admin user information
            'admin_name' => 'required|string|max:255',
            'admin_username' => 'required|string|max:255|unique:users,username|regex:/^[a-z0-9_]+$/',
            'admin_email' => 'required|email|max:255|unique:users,email',
            'admin_phone' => 'nullable|string|max:20',
            'admin_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ],
            
            // Terms acceptance
            'accept_terms' => 'required|accepted',
        ], [
            'admin_username.regex' => 'Username must contain only lowercase letters, numbers, and underscores',
            'admin_password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Auto-generate slug from tenant name
        $slug = Str::slug($request->tenant_name);
        
        // Ensure slug uniqueness
        $counter = 1;
        $originalSlug = $slug;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        // EVENT-BASED: Dispatch tenant creation job (async)
        $tenantData = [
            'name' => $request->tenant_name,
            'slug' => $slug,
            'email' => $request->tenant_email,
            'phone' => $request->tenant_phone,
            'address' => $request->tenant_address,
        ];
        
        $adminData = [
            'name' => $request->admin_name,
            'username' => $request->admin_username,
            'email' => $request->admin_email,
            'phone' => $request->admin_phone,
        ];
        
        CreateTenantJob::dispatch($tenantData, $adminData, $request->admin_password)
            ->onQueue('tenant-management');
        
        \Log::info('Tenant registration job dispatched', [
            'tenant_slug' => $slug,
            'admin_username' => $request->admin_username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant registration in progress. You will be able to login shortly.',
            'data' => [
                'tenant_name' => $request->tenant_name,
                'tenant_slug' => $slug,
                'subdomain' => $slug . '.' . config('app.base_domain', 'yourdomain.com'),
                'admin_username' => $request->admin_username,
                'status' => 'processing',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * Check if tenant slug is available (alias for route)
     */
    public function checkSlug(Request $request)
    {
        return $this->checkSlugAvailability($request);
    }

    /**
     * Check if tenant slug is available
     */
    public function checkSlugAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|max:255|regex:/^[a-z0-9-]+$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Invalid slug format',
            ], 422);
        }

        $exists = Tenant::where('slug', $request->slug)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'message' => $exists ? 'Slug is already taken' : 'Slug is available',
        ]);
    }

    /**
     * Check if username is available (alias for route)
     */
    public function checkUsername(Request $request)
    {
        return $this->checkUsernameAvailability($request);
    }

    /**
     * Check if username is available
     */
    public function checkUsernameAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255|regex:/^[a-z0-9_]+$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Invalid username format',
            ], 422);
        }

        $exists = User::where('username', $request->username)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'message' => $exists ? 'Username is already taken' : 'Username is available',
        ]);
    }

    /**
     * Check if email is available (alias for route)
     */
    public function checkEmail(Request $request)
    {
        return $this->checkEmailAvailability($request);
    }

    /**
     * Check if email is available
     */
    public function checkEmailAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Invalid email format',
            ], 422);
        }

        $exists = User::where('email', $request->email)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'message' => $exists ? 'Email is already registered' : 'Email is available',
        ]);
    }
}
