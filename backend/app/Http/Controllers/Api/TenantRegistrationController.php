<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\CreateTenantJob;
use App\Jobs\SendTenantVerificationEmailJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
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
     * New flow: Only company details required, auto-generate credentials
     * Public endpoint - no authentication required
     */
    public function register(Request $request)
    {
        // Validate registration data - only company details
        $validator = Validator::make($request->all(), [
            // Company/Tenant information
            'company_name' => 'required|string|max:255',
            'company_email' => 'required|email|max:255|unique:tenants,email',
            'company_phone' => 'required|string|max:50',
            'company_address' => 'required|string|max:500',
            
            // Terms acceptance
            'accept_terms' => 'required|accepted',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Auto-generate slug from company name (without hyphens for username)
        $slug = Str::slug($request->company_name);
        
        // Ensure slug uniqueness
        $counter = 1;
        $originalSlug = $slug;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }
        
        // Generate username from slug (remove hyphens)
        $username = str_replace('-', '', $slug);
        
        // Ensure username uniqueness
        $usernameCounter = 1;
        $originalUsername = $username;
        while (User::where('username', $username)->exists()) {
            $username = $originalUsername . $usernameCounter++;
        }
        
        // Generate secure random password
        $password = $this->generateSecurePassword();

        // Create tenant immediately (synchronous for email verification)
        try {
            DB::beginTransaction();
            
            $tenantData = [
                'name' => $request->company_name,
                'slug' => $slug,
                'email' => $request->company_email,
                'phone' => $request->company_phone,
                'address' => $request->company_address,
            ];
            
            $adminData = [
                'name' => $request->company_name . ' Admin',
                'username' => $username,
                'email' => $request->company_email,
                'phone' => $request->company_phone,
            ];
            
            // Create tenant record
            $tenant = Tenant::create([
                'name' => $tenantData['name'],
                'slug' => $tenantData['slug'],
                'subdomain' => $tenantData['slug'],
                'email' => $tenantData['email'],
                'phone' => $tenantData['phone'],
                'address' => $tenantData['address'],
                'is_active' => false, // Inactive until email verified
                'trial_ends_at' => now()->addDays(30),
                'public_packages_enabled' => true,
                'public_registration_enabled' => true,
                'settings' => [
                    'timezone' => 'Africa/Nairobi',
                    'currency' => 'KES',
                    'max_routers' => 5,
                    'max_users' => 100,
                ],
                'branding' => [
                    'company_name' => $tenantData['name'],
                    'support_email' => $tenantData['email'],
                    'support_phone' => $tenantData['phone'],
                ],
            ]);
            
            // Store credentials temporarily for email sending after verification
            $tenant->update([
                'settings' => array_merge($tenant->settings, [
                    'pending_username' => $username,
                    'pending_password' => $password,
                ])
            ]);
            
            DB::commit();
            
            // Dispatch job to send verification email (async)
            SendTenantVerificationEmailJob::dispatch(
                $tenant->id,
                $slug,
                $request->company_name
            )->onQueue('emails');
            
            Log::info('Tenant registration initiated - verification email job dispatched', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $slug,
                'email' => $request->company_email,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tenant registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Registration successful! Please check your email to verify your account.',
            'data' => [
                'tenant_id' => $tenant->id,
                'company_name' => $request->company_name,
                'tenant_slug' => $slug,
                'subdomain' => $slug . '.wificore.traidsolutions.com',
                'email' => $request->company_email,
                'status' => 'pending_verification',
                'step' => 'email_verification',
            ],
        ], 200);
    }
    
    /**
     * Generate secure random password
     */
    private function generateSecurePassword(): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $numbers = '23456789';
        $special = '@#$%&*';
        
        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        
        // Add 4 more random characters
        $allChars = $uppercase . $lowercase . $numbers . $special;
        for ($i = 0; $i < 4; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        // Shuffle the password
        return str_shuffle($password);
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
