<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RadiusService;
use App\Events\AccountSuspended;
use App\Jobs\TrackFailedLoginJob;
use App\Jobs\UpdateLoginStatsJob;
use App\Jobs\UpdatePasswordJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnifiedAuthController extends Controller
{
    protected $radiusService;

    public function __construct(RadiusService $radiusService)
    {
        $this->radiusService = $radiusService;
    }

    /**
     * Unified login for system admins, tenant admins, and hotspot users
     * Uses FreeRADIUS for authentication when applicable
     * Determines user type and returns appropriate dashboard route
     */
    public function login(Request $request)
    {
        \Log::info('=== LOGIN ATTEMPT ===', [
            'username' => $request->username,
            'has_password' => !empty($request->password),
            'ip' => $request->ip(),
            'all_data' => $request->all()
        ]);
        
        // Rate limiting
        $key = 'login:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            return response()->json([
                'success' => false,
                'message' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ], 429);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'username' => 'required|string', // Can be username or email
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find user by username or email
        $user = User::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        // Check if user exists
        if (!$user) {
            RateLimiter::hit($key, 60); // 60 seconds decay
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Check if user is active
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        // Check if account is suspended
        if ($user->suspended_until && now()->lessThan($user->suspended_until)) {
            $minutesRemaining = now()->diffInMinutes($user->suspended_until);
            
            \Log::warning('Login attempt on suspended account', [
                'username' => $user->username,
                'suspended_until' => $user->suspended_until,
                'reason' => $user->suspension_reason,
                'ip' => $request->ip()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => "Your account is temporarily suspended. Reason: {$user->suspension_reason}. Try again in {$minutesRemaining} minutes.",
                'suspended_until' => $user->suspended_until->toIso8601String(),
            ], 403);
        }

        // For tenant users, check if tenant is active
        if ($user->tenant_id) {
            $tenant = $user->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found. Please contact support.',
                ], 403);
            }

            if (!$tenant->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your organization account is inactive. Please contact support.',
                ], 403);
            }

            if ($tenant->isSuspended()) {
                return response()->json([
                    'success' => false,
                    'message' => "Your organization account has been suspended. Reason: {$tenant->suspension_reason}",
                ], 403);
            }
        }

        // AAA: Authenticate ALL users via FreeRADIUS
        \Log::info('Authenticating user via RADIUS (AAA)', [
            'username' => $user->username,
            'role' => $user->role
        ]);
        
        try {
            $authenticated = $this->radiusService->authenticate($user->username, $request->password);
            
            if ($authenticated) {
                \Log::info('RADIUS authentication successful (AAA)', [
                    'username' => $user->username,
                    'role' => $user->role
                ]);
            } else {
                \Log::warning('RADIUS authentication failed (AAA)', [
                    'username' => $user->username,
                    'role' => $user->role
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('RADIUS authentication error (AAA)', [
                'username' => $user->username,
                'role' => $user->role,
                'error' => $e->getMessage()
            ]);
            $authenticated = false;
        }
        
        // Check if authentication was successful
        if (!$authenticated) {
            RateLimiter::hit($key, 60);
            
            // EVENT-BASED: Dispatch job to track failed login
            TrackFailedLoginJob::dispatch($user->id, $request->ip())
                ->onQueue('auth-tracking');
            
            $remainingAttempts = 5 - $user->failed_login_attempts;
            return response()->json([
                'success' => false,
                'message' => "Invalid credentials. {$remainingAttempts} attempts remaining before account suspension.",
            ], 401);
        }

        // Clear rate limiter on successful login
        RateLimiter::clear($key);

        // EVENT-BASED: Dispatch job to update login stats
        UpdateLoginStatsJob::dispatch($user->id, $request->ip())
            ->onQueue('auth-tracking');

        // Create token with appropriate abilities based on role
        $abilities = $this->getTokenAbilities($user);
        $token = $user->createToken(
            'auth-token',
            $abilities,
            $request->remember ? now()->addDays(30) : now()->addHours(24)
        )->plainTextToken;

        // Determine dashboard route based on role
        $dashboardRoute = $this->getDashboardRoute($user);

        // Log successful login
        \Log::info('User logged in', [
            'user_id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'tenant_id' => $user->tenant_id,
                    'tenant' => $user->tenant ? [
                        'id' => $user->tenant->id,
                        'name' => $user->tenant->name,
                        'slug' => $user->tenant->slug,
                    ] : null,
                ],
                'token' => $token,
                'dashboard_route' => $dashboardRoute,
                'abilities' => $abilities,
            ],
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        \Log::info('User logged out', [
            'user_id' => $request->user()->id,
            'username' => $request->user()->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('tenant');

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'phone_number' => $user->phone_number,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at,
                'tenant_id' => $user->tenant_id,
                'tenant' => $user->tenant ? [
                    'id' => $user->tenant->id,
                    'name' => $user->tenant->name,
                    'slug' => $user->tenant->slug,
                    'is_active' => $user->tenant->is_active,
                    'trial_ends_at' => $user->tenant->trial_ends_at,
                ] : null,
            ],
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'different:current_password',
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

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect',
            ], 401);
        }

        // EVENT-BASED: Dispatch password update job (async)
        UpdatePasswordJob::dispatch($user->id, $request->new_password)
            ->onQueue('user-management');

        // Revoke all tokens except current
        $currentToken = $request->user()->currentAccessToken();
        $user->tokens()->where('id', '!=', $currentToken->id)->delete();

        \Log::info('Password change job dispatched', [
            'user_id' => $user->id,
            'username' => $user->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password change in progress. All other sessions have been logged out.',
        ], 202); // 202 Accepted
    }

    /**
     * Get token abilities based on user role
     */
    private function getTokenAbilities(User $user): array
    {
        return match($user->role) {
            User::ROLE_SYSTEM_ADMIN => [
                'system:read',
                'system:write',
                'system:delete',
                'tenants:manage',
                'users:manage',
                'health:view',
            ],
            User::ROLE_ADMIN => [
                'tenant:read',
                'tenant:write',
                'users:manage',
                'packages:manage',
                'routers:manage',
                'payments:view',
            ],
            User::ROLE_HOTSPOT_USER => [
                'profile:read',
                'profile:write',
                'subscription:view',
            ],
            default => ['profile:read'],
        };
    }

    /**
     * Get dashboard route based on user role
     */
    private function getDashboardRoute(User $user): string
    {
        return match($user->role) {
            User::ROLE_SYSTEM_ADMIN => '/system/dashboard',
            User::ROLE_ADMIN => '/dashboard',
            User::ROLE_HOTSPOT_USER => '/user/dashboard',
            default => '/dashboard',
        };
    }
}
