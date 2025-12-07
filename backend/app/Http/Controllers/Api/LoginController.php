<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RadiusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Authenticate user via RADIUS and return Sanctum token
     */
    public function login(Request $request, RadiusService $radius)
    {
        \Log::info('Login attempt started', ['username' => $request->username]);
        
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            \Log::info('Attempting RADIUS authentication', ['username' => $request->username]);
            
            // Authenticate against RADIUS server
            if ($radius->authenticate($request->username, $request->password)) {
                \Log::info('RADIUS authentication successful', ['username' => $request->username]);
                
                // SCHEMA-BASED MULTI-TENANCY: Look up tenant schema from mapping table
                $schemaMapping = \DB::table('radius_user_schema_mapping')
                    ->where('username', $request->username)
                    ->where('is_active', true)
                    ->first();
                
                if (!$schemaMapping) {
                    \Log::error('No schema mapping found for user', ['username' => $request->username]);
                    return response()->json([
                        'success' => false,
                        'message' => 'User account not properly configured. Please contact support.',
                    ], 403);
                }
                
                \Log::info('Schema mapping found', [
                    'username' => $request->username,
                    'schema' => $schemaMapping->schema_name,
                    'tenant_id' => $schemaMapping->tenant_id
                ]);
                
                // Find user by username and tenant_id (without tenant scope during login)
                $user = User::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                    ->where('username', $request->username)
                    ->where('tenant_id', $schemaMapping->tenant_id)
                    ->first();
                
                if (!$user) {
                    \Log::error('User not found in database', [
                        'username' => $request->username,
                        'tenant_id' => $schemaMapping->tenant_id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'User account not found. Please contact support.',
                    ], 404);
                }

                // Check if user is active
                if (!$user->is_active) {
                    \Log::warning('Login attempt by inactive user', ['username' => $request->username]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Your account has been deactivated. Please contact support.',
                    ], 403);
                }

                // Check if email is verified (skip for system admins and RADIUS auto-created users)
                if (!$user->hasVerifiedEmail() && $user->role !== User::ROLE_SYSTEM_ADMIN) {
                    \Log::warning('Login attempt by unverified user', ['username' => $request->username]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Please verify your email address before logging in.',
                        'requires_verification' => true,
                        'email' => $user->email,
                    ], 403);
                }

                // Update last login timestamp
                $user->updateLastLogin();

                // Create Sanctum token with abilities based on user role
                $abilities = $user->isAdmin() 
                    ? ['*'] // Admin gets all abilities
                    : ['read-packages', 'purchase-package', 'view-subscription']; // Hotspot user abilities

                $token = $user->createToken('auth-token', $abilities)->plainTextToken;

                // Get tenant information
                $tenant = \App\Models\Tenant::find($user->tenant_id);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'role' => $user->role,
                        'account_balance' => $user->account_balance,
                        'phone_number' => $user->phone_number,
                        'tenant_id' => $user->tenant_id,
                    ],
                    'tenant' => $tenant ? [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                        'schema_name' => $tenant->schema_name,
                    ] : null,
                ]);
            }
            
            \Log::warning('RADIUS authentication failed', ['username' => $request->username]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials - RADIUS authentication failed',
            ], 401);
            
        } catch (\Exception $e) {
            \Log::error('Login error', [
                'username' => $request->username,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Authentication service error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register new admin user
     * Creates user in database and RADIUS
     */
    public function register(Request $request, RadiusService $radius)
    {
        \Log::info('Registration attempt started', ['username' => $request->username]);
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone_number' => 'required|string|max:20|unique:users,phone_number',
        ]);

        try {
            \DB::beginTransaction();
            
            // Create user in database
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone_number' => $validated['phone_number'],
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]);
            
            // Create RADIUS account
            $radiusCreated = $radius->createUser(
                $validated['username'],
                $validated['password']
            );
            
            if (!$radiusCreated) {
                throw new \Exception('Failed to create RADIUS account');
            }
            
            \DB::commit();
            
            \Log::info('User registered successfully', [
                'user_id' => $user->id,
                'username' => $user->username,
            ]);
            
            // Send email verification
            $user->sendEmailVerificationNotification();
            
            \Log::info('Verification email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Account created successfully. Please check your email to verify your account.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'phone_number' => $user->phone_number,
                    'email_verified' => false,
                ],
                'requires_verification' => true,
            ], 201);
            
        } catch (\Exception $e) {
            \DB::rollBack();
            
            \Log::error('Registration error', [
                'username' => $request->username,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify email address
     */
    public function verifyEmail(Request $request)
    {
        $user = User::findOrFail($request->route('id'));

        // Check if hash matches
        if (!hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification link',
            ], 400);
        }

        // Check if already verified
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email already verified',
                'already_verified' => true,
            ]);
        }

        // Mark email as verified
        $user->markEmailAsVerified();

        \Log::info('Email verified successfully', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        // Generate token for auto-login
        $token = $user->createToken('auth-token', ['*'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully! You can now login.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified' => true,
            ],
        ]);
    }

    /**
     * Resend verification email
     */
    public function resendVerification(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified',
            ], 400);
        }

        // Resend verification email
        $user->sendEmailVerificationNotification();

        \Log::info('Verification email resent', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Verification email sent. Please check your inbox.',
        ]);
    }

    /**
     * Logout and revoke Sanctum token
     */
    public function logout(Request $request)
    {
        // Revoke the current user's token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
