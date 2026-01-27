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

        // Extract subdomain for tenant identification
        $host = $request->getHost();
        $subdomain = null;
        $tenant = null;
        
        if (!$this->isLocalhost($host)) {
            $subdomain = $this->extractSubdomain($host);
            
            if ($subdomain && !$this->isReservedSubdomain($subdomain)) {
                // Find tenant by subdomain
                $tenant = \App\Models\Tenant::where('subdomain', $subdomain)
                    ->orWhere('custom_domain', $host)
                    ->first();
                    
                \Log::info('Tenant identified from subdomain', [
                    'subdomain' => $subdomain,
                    'tenant_id' => $tenant?->id,
                    'tenant_schema' => $tenant?->schema_name
                ]);
            }
        }
        
        // Find user by username or email (without tenant scope for now)
        $user = User::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->where('username', $request->username)
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
        // Allow login without subdomain, but will redirect to subdomain after auth
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

            // Check if user is logging in from correct subdomain
            $host = $request->getHost();
            $needsRedirect = false;
            
            if (!$this->isLocalhost($host)) {
                $subdomain = $this->extractSubdomain($host);
                
                if (!$this->validateSubdomainForTenant($subdomain, $tenant)) {
                    // Not on correct subdomain, but allow login and signal redirect needed
                    $needsRedirect = true;
                    
                    \Log::info('Tenant login from non-subdomain, will redirect after auth', [
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'user_tenant_subdomain' => $tenant->subdomain,
                        'requested_subdomain' => $subdomain,
                        'host' => $host,
                    ]);
                }
            }
        }

        // System admins can login from any subdomain (including tenant subdomains)
        // This allows sysadmin to manage the platform from any entry point
        if ($user->role === 'system_admin') {
            \Log::info('System admin login allowed from any subdomain', [
                'user_id' => $user->id,
                'username' => $user->username,
                'host' => $request->getHost(),
            ]);
        }

        // SCHEMA-BASED MULTI-TENANCY: Validate schema mapping for tenant users
        if ($user->tenant_id) {
            $schemaMapping = DB::table('public.radius_user_schema_mapping')
                ->where('username', $user->username)
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->first();
            
            if (!$schemaMapping) {
                \Log::error('No schema mapping found for tenant user', [
                    'username' => $user->username,
                    'tenant_id' => $user->tenant_id
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'User account not properly configured. Please contact support.',
                    'code' => 'SCHEMA_MAPPING_MISSING'
                ], 403);
            }
            
            // Validate schema name matches tenant
            if ($schemaMapping->schema_name !== $user->tenant->schema_name) {
                \Log::error('Schema mapping mismatch', [
                    'username' => $user->username,
                    'expected_schema' => $user->tenant->schema_name,
                    'mapped_schema' => $schemaMapping->schema_name
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Account configuration error. Please contact support.',
                    'code' => 'SCHEMA_MISMATCH'
                ], 403);
            }
            
            \Log::info('Schema mapping validated', [
                'username' => $user->username,
                'schema' => $schemaMapping->schema_name,
                'tenant_id' => $user->tenant_id
            ]);
        }
        
        // AAA: Authenticate ALL users via FreeRADIUS
        // PostgreSQL functions automatically determine correct tenant schema
        \Log::info('Authenticating user via RADIUS (AAA)', [
            'username' => $user->username,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id
        ]);
        
        try {
            // RADIUS service uses PostgreSQL functions for automatic schema lookup
            // No need to pass schema - high performance without connection state changes
            $authenticated = $this->radiusService->authenticate(
                $user->username, 
                $request->password
            );
            
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

        // Determine if redirect to subdomain is needed
        $redirectSubdomain = null;
        if ($user->role === User::ROLE_ADMIN && $user->tenant_id) {
            // Tenant admin should be redirected to their subdomain
            $redirectSubdomain = $user->tenant->slug . '.wificore.traidsolutions.com';
        }
        // System admin: no redirect (stays on main domain)

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
                        'schema_name' => $user->tenant->schema_name,
                    ] : null,
                ],
                'token' => $token,
                'dashboard_route' => $dashboardRoute,
                'abilities' => $abilities,
                'redirect_subdomain' => $redirectSubdomain,
            ],
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        // Revoke current token (if present)
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        \Log::info('User logged out', [
            'user_id' => $request->user()?->id,
            'username' => $request->user()?->username,
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
                new \App\Rules\StrongPassword(),
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

    /**
     * Check if host is localhost, IP address, or development environment (ngrok)
     */
    private function isLocalhost(string $host): bool
    {
        // Check for localhost and IP addresses
        if (in_array($host, ['localhost', '127.0.0.1', '::1']) || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        
        // Check for ngrok domains (development tunneling)
        if (str_contains($host, 'ngrok') || str_contains($host, 'ngrok-free.dev')) {
            return true;
        }
        
        // Check if APP_ENV is local or development
        if (in_array(config('app.env'), ['local', 'development'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Extract subdomain from host
     */
    private function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);
        
        // Need at least 3 parts for subdomain (subdomain.domain.tld)
        if (count($parts) < 3) {
            return null;
        }

        // Return first part as subdomain
        return $parts[0];
    }

    /**
     * Validate that subdomain matches tenant
     */
    private function validateSubdomainForTenant(?string $subdomain, $tenant): bool
    {
        if (!$subdomain) {
            return false;
        }

        // Check if subdomain matches tenant's subdomain
        if ($tenant->subdomain === $subdomain) {
            return true;
        }

        // Check if subdomain matches tenant's custom domain
        if ($tenant->custom_domain && $tenant->custom_domain === $subdomain) {
            return true;
        }

        // Check if full host matches custom domain (for custom domains without subdomain)
        $fullHost = request()->getHost();
        if ($tenant->custom_domain && $tenant->custom_domain === $fullHost) {
            return true;
        }

        return false;
    }
    
    /**
     * Check if subdomain is reserved
     */
    private function isReservedSubdomain(string $subdomain): bool
    {
        $reserved = [
            'www', 'api', 'admin', 'app', 'mail', 'ftp', 'smtp',
            'pop', 'imap', 'webmail', 'cpanel', 'whm', 'ns1', 'ns2',
            'system', 'test', 'dev', 'staging', 'demo',
        ];
        
        return in_array(strtolower($subdomain), $reserved);
    }
}
