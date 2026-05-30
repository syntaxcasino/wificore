<?php

namespace App\Http\Controllers\Api;

use App\Events\PppoeUserCreated;
use App\Events\PppoeUserDeleted;
use App\Events\PppoeUserUpdated;
use App\Helpers\PackageExpiryHelper;
use App\Http\Controllers\Controller;
use App\Jobs\DisconnectPppoeSessionJob;
use App\Models\Package;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\MikroTik\BandwidthHelper;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Services\RadiusService;

/**
 * PPPoE User Controller
 * 
 * Manages PPPoE users within tenant schemas following schema-based multi-tenancy.
 * 
 * Architecture:
 * - PUBLIC SCHEMA: radius_user_schema_mapping (metadata for FreeRADIUS routing)
 * - TENANT SCHEMA: pppoe_users, radcheck, radreply (actual user data)
 * 
 * This controller ensures proper separation between metadata and tenant data.
 */
class PppoeUserController extends Controller
{
    protected TenantContext $tenantContext;
    private array $portalPasswordSupportCache = [];

    public function __construct(TenantContext $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }

    /**
     * Get tenant from authenticated user with validation
     */
    private function getAuthenticatedTenant(Request $request): ?Tenant
    {
        $tenantId = $request->user()->tenant_id;
        if (!$tenantId) {
            return null;
        }
        
        return Tenant::query()
            ->select(['id', 'schema_name', 'account_prefix', 'slug', 'name', 'is_active', 'schema_created'])
            ->find($tenantId);
    }

    /**
     * Check whether tenant schema includes portal_password column.
     * OPTIMIZED: Uses Redis cache for 1 hour to avoid expensive schema checks
     */
    private function tenantSupportsPortalPassword(Tenant $tenant): bool
    {
        $tenantId = (string) $tenant->id;

        // Check in-memory cache first (per-request)
        if (array_key_exists($tenantId, $this->portalPasswordSupportCache)) {
            return $this->portalPasswordSupportCache[$tenantId];
        }

        // Check Redis cache (cross-request, 1 hour TTL)
        $cacheKey = "tenant_portal_password_support:{$tenantId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            $this->portalPasswordSupportCache[$tenantId] = (bool) $cached;
            return (bool) $cached;
        }

        try {
            if ($this->tenantContext->hasTenant() && (string) $this->tenantContext->getTenantId() === $tenantId) {
                $supported = Schema::hasTable('pppoe_users') && Schema::hasColumn('pppoe_users', 'portal_password');
            } else {
                $supported = (bool) $this->tenantContext->runInTenantContext($tenant, function () {
                    return Schema::hasTable('pppoe_users') && Schema::hasColumn('pppoe_users', 'portal_password');
                });
            }

            // Cache in both memory and Redis
            $this->portalPasswordSupportCache[$tenantId] = (bool) $supported;
            Cache::put($cacheKey, (bool) $supported, now()->addHour());

            return (bool) $supported;
        } catch (\Throwable $e) {
            Log::warning('Unable to check portal_password column support', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            $this->portalPasswordSupportCache[$tenantId] = false;
            return false;
        }
    }

    /**
     * Disconnect PPPoE user sessions asynchronously.
     * Dispatches a job instead of blocking the HTTP response.
     */
    private function disconnectPppoeUserSessions(PppoeUser $pppoeUser, ?string $reason = null): void
    {
        if (empty($pppoeUser->router_id) || empty($pppoeUser->username)) {
            return;
        }

        // Dispatch async job for non-blocking session disconnect
        // This ensures the API response is fast while SSH operations happen in background
        DisconnectPppoeSessionJob::dispatch(
            (string) $pppoeUser->id,
            (string) $this->tenantContext->getTenantId(),
            $reason
        );

        Log::info('PPPoE session disconnect dispatched', [
            'pppoe_user_id' => (string) $pppoeUser->id,
            'username' => $pppoeUser->username,
            'reason' => $reason,
        ]);
    }

    private function bustUserCache(string $tenantId): void
    {
        Cache::forget("pppoe_users_list_{$tenantId}");
        Cache::forget("pppoe_live_sessions_{$tenantId}");
    }

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $cacheKey = "pppoe_users_list_{$tenantId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        try {
            $hasPppoeUsers = Cache::remember(
                "tenant_schema_has_pppoe_{$tenantId}",
                300,
                fn() => Schema::hasTable('pppoe_users')
            );

            if (!$hasPppoeUsers) {
                $emptyPaginator = new LengthAwarePaginator(
                    [],
                    0,
                    20,
                    1,
                    ['path' => $request->url(), 'query' => $request->query()]
                );

                return response()->json([
                    'success' => true,
                    'data' => $emptyPaginator,
                    'tenant_id' => $tenantId,
                ]);
            }

            // OPTIMIZED: Select specific columns + use selectRaw for computed fields
            // This avoids loading unnecessary data and the collection transform overhead
            $users = PppoeUser::query()
                ->select([
                    'id', 'username', 'account_number', 'customer_name', 'customer_email', 'customer_phone',
                    'package_id', 'router_id', 'expires_at', 'rate_limit', 'simultaneous_use',
                    'is_active', 'status', 'payment_status', 'balance', 'next_payment_due',
                    'amount_due', 'amount_paid', 'created_at', 'updated_at',
                ])
                ->selectRaw("
                    CASE 
                        WHEN expires_at IS NOT NULL THEN CAST(EXTRACT(DAY FROM (expires_at - NOW())) AS INTEGER)
                        ELSE NULL
                    END as days_to_expiry,
                    CASE 
                        WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN true
                        ELSE false
                    END as is_expired
                ")
                ->with([
                    'package:id,name,type,download_speed,upload_speed,duration,price',
                    'router:id,name',
                ])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            $payload = [
                'success'   => true,
                'data'      => $users,
                'tenant_id' => $tenantId,
            ];

            Cache::put($cacheKey, $payload, 30);

            return response()->json($payload);
        } catch (QueryException $e) {
            Log::error('Failed to fetch PPPoE users', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'sql_state' => $e->getCode(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch PPPoE users',
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available.',
            ], 500);
        }

        // OPTIMIZED: Eager load relations with specific columns to prevent N+1
        $pppoeUser = PppoeUser::query()
            ->select([
                'id', 'username', 'account_number', 'customer_name', 'customer_email', 'customer_phone',
                'package_id', 'router_id', 'expires_at', 'rate_limit', 'simultaneous_use',
                'is_active', 'status', 'payment_status', 'balance', 'next_payment_due',
                'amount_due', 'amount_paid', 'created_at', 'updated_at',
            ])
            ->selectRaw("
                CASE 
                    WHEN expires_at IS NOT NULL THEN CAST(EXTRACT(DAY FROM (expires_at - NOW())) AS INTEGER)
                    ELSE NULL
                END as days_to_expiry,
                CASE 
                    WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN true
                    ELSE false
                END as is_expired
            ")
            ->with([
                'package:id,name,type,download_speed,upload_speed,duration,price',
                'router:id,name',
            ])
            ->find($id);

        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $pppoeUser,
        ]);
    }

    public function viewPassword(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'password', 'router_id'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        // Cleartext passwords are only stored when RADIUS_ALLOW_CLEARTEXT=true
        if (!env('RADIUS_ALLOW_CLEARTEXT', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Plaintext password retrieval is disabled. Use password reset to set a new password.',
            ], 403);
        }

        // Get plaintext password from radcheck table
        $password = $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser) {
            return DB::table('radcheck')
                ->where('username', $pppoeUser->username)
                ->where('attribute', 'Cleartext-Password')
                ->value('value');
        });

        if (!$password) {
            return response()->json([
                'success' => false,
                'message' => 'Password not available. User may need password reset.',
            ], 404);
        }

        Log::info('PPPoE password viewed', [
            'pppoe_user_id' => $id,
            'username' => $pppoeUser->username,
            'viewed_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $pppoeUser->username,
                'password' => $password,
                'has_portal_password' => !empty($pppoeUser->portal_password),
            ],
        ]);
    }

    public function viewPortalPassword(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'account_number', 'portal_password', 'password', 'router_id'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $supportsPortalPassword = $this->tenantSupportsPortalPassword($tenant);
        $hasPortalPassword = $supportsPortalPassword && !empty($pppoeUser->portal_password);

        // FIXED: Retrieve actual portal password from RADIUS instead of forcing reset
        $portalPassword = null;
        if ($hasPortalPassword) {
            try {
                $portalPassword = $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser) {
                    return DB::table('radcheck')
                        ->where('username', $pppoeUser->username)
                        ->where('attribute', 'Portal-Password')
                        ->value('value');
                });
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve portal password from RADIUS', [
                    'pppoe_user_id' => $id,
                    'username' => $pppoeUser->username,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('PPPoE portal password retrieved from RADIUS', [
            'pppoe_user_id' => $id,
            'username' => $pppoeUser->username,
            'viewed_by' => $request->user()->id,
            'has_portal_password' => $hasPortalPassword,
            'supports_portal_password' => $supportsPortalPassword,
            'retrieved_from_radius' => !empty($portalPassword),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $pppoeUser->username,
                'account_number' => $pppoeUser->account_number,
                'portal_password' => $portalPassword, // Actual password from RADIUS
                'has_portal_password' => $hasPortalPassword,
                'supports_portal_password' => $supportsPortalPassword,
                'portal_login_url' => '/portal/login',
                'message' => $supportsPortalPassword
                    ? ($portalPassword 
                        ? 'Portal password retrieved from RADIUS.' 
                        : 'Portal password not found in RADIUS. Please reset the portal password.')
                    : 'Portal password column is missing in this tenant schema. Run latest tenant migrations; portal login falls back to PPPoE password.',
            ],
        ]);
    }

    public function resetPassword(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'password', 'router_id'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $newPassword = Str::random(12);

        try {
            // STEP 1: Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id, (string) $pppoeUser->id);

            // STEP 2: Update password and RADIUS in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $newPassword, $tenant) {
                $pppoeUser->password = bcrypt($newPassword);
                $pppoeUser->save();

                $shortRouterId = substr(str_replace('-', '', (string) $pppoeUser->router_id), 0, 8);
                
                // CRITICAL: Get existing portal password from RADIUS to preserve it
                $existingPortalPassword = null;
                if ($pppoeUser->portal_password) {
                    // Try to get existing portal password from RADIUS
                    $radiusPortal = DB::table('radcheck')
                        ->where('username', $pppoeUser->username)
                        ->where('attribute', 'Portal-Password')
                        ->value('value');
                    $existingPortalPassword = $radiusPortal;
                }
                
                $this->syncRadiusCredentials(
                    (string) $pppoeUser->username,
                    $newPassword,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (string) $tenant->id,
                    $pppoeUser->router_id ? 'pppoe-pool-' . $shortRouterId : null,
                    $existingPortalPassword
                );

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

            // Force re-auth so new password is applied.
            $this->disconnectPppoeUserSessions($pppoeUser, 'Password reset - force re-auth');

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user password reset and RADIUS credentials re-synced',
                'data' => $pppoeUser,
                'generated_password' => $newPassword,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to reset PPPoE user password', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset PPPoE user password: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function resetPortalPassword(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'portal_password', 'password', 'router_id'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        if (!$this->tenantSupportsPortalPassword($tenant)) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant schema is missing portal password support. Run latest tenant migrations first.',
            ], 409);
        }

        // Generate a new random portal password
        $newPortalPassword = Str::random(8);

        try {
            // Update portal password in TENANT schema and sync to RADIUS
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $newPortalPassword) {
                // Store hashed portal password
                $pppoeUser->portal_password = bcrypt($newPortalPassword);
                $pppoeUser->save();
                
                // CRITICAL: Sync portal password to RADIUS for centralized authentication
                // Update the Portal-Password attribute in radcheck table
                DB::table('radcheck')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Portal-Password'],
                    ['op' => ':=', 'value' => $newPortalPassword]
                );
                
                Log::info('Portal password synced to RADIUS during reset', [
                    'username' => $pppoeUser->username,
                    'has_portal_password' => true,
                ]);

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

            Log::info('PPPoE portal password reset', [
                'pppoe_user_id' => $pppoeUser->id,
                'username' => $pppoeUser->username,
                'reset_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Portal password reset successfully',
                'data' => $pppoeUser,
                'portal_password' => $newPortalPassword,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to reset portal password', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset portal password: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            Log::error('PPPoE user creation failed - no tenant context', [
                'user_id' => $request->user()->id ?? null,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        $tenantId = $tenant->id;
        $tenantSchemaName = $tenant->schema_name;

        if (!$tenant->schema_created || !$tenantSchemaName) {
            Log::error('PPPoE user creation failed - tenant schema not initialized', [
                'tenant_id' => $tenantId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Tenant schema is not available. Please contact support.',
            ], 500);
        }

        // Cache schema checks per tenant — information_schema queries are slow and schema never changes mid-request
        $schemaCheck = \Illuminate\Support\Facades\Cache::remember(
            "tenant_schema_check_{$tenant->id}",
            300,
            function () {
                return [
                    'has_pppoe_users'  => Schema::hasTable('pppoe_users'),
                    'has_radcheck'     => Schema::hasTable('radcheck'),
                    'has_radreply'     => Schema::hasTable('radreply'),
                    'missing_columns'  => array_values(array_filter(
                        ['customer_name', 'customer_email', 'customer_phone'],
                        static fn(string $col): bool => !Schema::hasColumn('pppoe_users', $col)
                    )),
                ];
            }
        );

        if (!$schemaCheck['has_pppoe_users']) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE users are not initialized for this tenant. Please run tenant migrations.',
            ], 500);
        }

        if (!$schemaCheck['has_radcheck'] || !$schemaCheck['has_radreply']) {
            return response()->json([
                'success' => false,
                'message' => 'RADIUS tables are not initialized for this tenant. Please run tenant migrations.',
            ], 500);
        }

        $missingPppoeColumns = $schemaCheck['missing_columns'];

        if (!empty($missingPppoeColumns)) {
            Log::error('PPPoE user creation failed - tenant schema missing required columns', [
                'tenant_id' => $tenantId,
                'schema' => $tenantSchemaName,
                'missing_columns' => $missingPppoeColumns,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'PPPoE users schema is outdated for this tenant. Please run latest tenant migrations.',
                'missing_columns' => $missingPppoeColumns,
            ], 500);
        }

        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:64|regex:/^[a-z0-9_\.\-]+$/i',
            'package_id' => 'required|uuid',
            'router_id' => 'required|uuid',
            'simultaneous_use' => 'nullable|integer|min:1|max:50',
            'grace_period_days' => 'nullable|integer|min:0|max:365',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $username = strtolower(trim((string) $request->username));
        $plainPassword = Str::random(12);
        $portalPassword = Str::random(8); // Auto-generated portal password for customer self-service
        $supportsPortalPassword = $this->tenantSupportsPortalPassword($tenant);
        $simultaneousUse = (int) ($request->simultaneous_use ?? 1);
        $customerName = $request->filled('customer_name') ? (string) $request->customer_name : $username;
        $customerEmail = $request->filled('customer_email') ? (string) $request->customer_email : null;
        $customerPhone = $request->filled('customer_phone') ? (string) $request->customer_phone : null;

        // OPTIMIZED: Select only needed columns for package and router
        $package = Package::query()
            ->select(['id', 'name', 'download_speed', 'upload_speed', 'duration', 'price'])
            ->where('id', $request->package_id)
            ->where('type', 'pppoe')
            ->first();

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid package: must belong to tenant and be type pppoe',
            ], 422);
        }

        $router = Router::query()
            ->select(['id', 'name'])
            ->find($request->router_id);

        if (!$router) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        // expires_at is NOT set at creation — it is set when the first payment is recorded.
        // This ensures expiry is always anchored to the actual payment date + package duration.
        $expiresAt = null;
        $rateLimit = BandwidthHelper::formatMikrotikRateLimit((string) $package->download_speed, (string) $package->upload_speed);

        try {
            if (PppoeUser::where('username', $username)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username already exists',
                ], 422);
            }

            // STEP 1: Create schema mapping in PUBLIC schema (metadata for FreeRADIUS routing)
            // This is NOT tenant data - it's routing metadata that tells FreeRADIUS which schema to query
            $this->ensureRadiusSchemaMapping($username, $tenantSchemaName, (string) $tenantId);

            Log::info('Creating PPPoE user', [
                'username' => $username,
                'tenant_id' => $tenantId,
                'schema' => $tenantSchemaName,
            ]);

            // STEP 2: Create user and RADIUS entries in TENANT schema using TenantContext
            $pppoeUser = $this->tenantContext->runInTenantContext($tenant, function () use ($username, $plainPassword, $portalPassword, $supportsPortalPassword, $package, $router, $expiresAt, $rateLimit, $simultaneousUse, $customerName, $customerEmail, $customerPhone, $tenant) {
                $tenantPrefix = $tenant->account_prefix
                    ?? \App\Models\Tenant::generateAccountPrefix($tenant->slug ?? $tenant->name);
                $accountNumber = PppoeUser::generateAccountNumber($tenantPrefix, 'P');

                // next_payment_due = package duration from now (a billing prompt — not the actual expiry)
                $nextPaymentDue = PackageExpiryHelper::calculateExpiresAt($package, now());

                // STEP 3: Create PPPoE user in tenant schema
                // Default to ACTIVE so user can connect, but payment_status is UNPAID until paid
                $createPayload = [
                    'username' => $username,
                    'password' => bcrypt($plainPassword),
                    'account_number' => $accountNumber,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'package_id' => $package->id,
                    'router_id' => $router->id,
                    'expires_at' => $expiresAt,
                    'rate_limit' => $rateLimit,
                    'simultaneous_use' => $simultaneousUse,
                    'is_active' => true,
                    'status' => 'active',
                    'payment_status' => 'unpaid',
                    'next_payment_due' => $nextPaymentDue,
                    'amount_due' => $package->price ?? 0,
                    'in_grace_period' => false,
                ];

                if ($supportsPortalPassword) {
                    $createPayload['portal_password'] = bcrypt($portalPassword);
                }

                $pppoeUser = PppoeUser::create($createPayload);

                // STEP 4: Sync RADIUS credentials in tenant schema
                // Pool name matches ZeroConfigPPPoEGenerator formula: pppoe-pool-{short_router_id}
                $shortRouterId = substr(str_replace('-', '', (string) $router->id), 0, 8);
                $framedPool = 'pppoe-pool-' . $shortRouterId;
                // CRITICAL: Sync both PPPoE password and portal password to RADIUS for centralized authentication
                $this->syncRadiusCredentials($username, $plainPassword, $expiresAt, $rateLimit, $simultaneousUse, (string) $tenant->id, $framedPool, $supportsPortalPassword ? $portalPassword : null);

                $this->syncRadiusMetaForUser(
                    $pppoeUser->username,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (bool) $pppoeUser->is_active,
                    (string) $pppoeUser->status
                );

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);

                $pppoeUser->load([
                    'package:id,name,type,download_speed,upload_speed,duration',
                    'router:id,name',
                ]);

                return $pppoeUser;
            });

            event(new PppoeUserCreated($pppoeUser, (string) $tenantId));
            $this->bustUserCache((string) $tenantId);

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user created',
                'data' => $pppoeUser,
                'generated_password' => $plainPassword,
                'generated_portal_password' => $supportsPortalPassword ? $portalPassword : null,
                'portal_password_supported' => $supportsPortalPassword,
            ], 201);
        } catch (QueryException $e) {
            Log::error('Database error while creating PPPoE user', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'sql_state' => $e->getCode(),
                'username' => $username,
                'package_id' => $request->package_id,
                'router_id' => $request->router_id,
            ]);

            $message = 'Failed to create PPPoE user due to a database error.';
            if (str_contains($e->getMessage(), 'relation') && str_contains($e->getMessage(), 'does not exist')) {
                $message = 'PPPoE/RADIUS tables are missing for this tenant. Please run tenant migrations.';
            } elseif ((string) $e->getCode() === '42703' && str_contains($e->getMessage(), 'pppoe_users')) {
                $message = 'PPPoE users schema is outdated for this tenant (missing columns). Please run latest tenant migrations.';
            }

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 500);
        } catch (\Exception $e) {
            Log::error('Failed to create PPPoE user', [
                'error' => $e->getMessage(),
                'username' => $username,
                'package_id' => $request->package_id,
                'router_id' => $request->router_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create PPPoE user: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select([
                'id', 'username', 'account_number', 'customer_name', 'customer_email', 'customer_phone',
                'package_id', 'router_id', 'expires_at', 'rate_limit', 'simultaneous_use',
                'is_active', 'status', 'payment_status', 'balance', 'next_payment_due',
                'amount_due', 'amount_paid', 'created_at', 'updated_at',
            ])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'package_id' => 'sometimes|required|uuid',
            'router_id' => 'sometimes|required|uuid',
            'simultaneous_use' => 'sometimes|required|integer|min:1|max:50',
            'is_active' => 'sometimes|boolean',
            'status' => 'sometimes|required|string|in:active,inactive,blocked,expired,suspended',
            'customer_name' => 'sometimes|nullable|string|max:255',
            'customer_email' => 'sometimes|nullable|email|max:255',
            'customer_phone' => 'sometimes|nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $previousRateLimit  = $pppoeUser->rate_limit;
        $previousPackageId  = $pppoeUser->package_id;
        // OPTIMIZED: Select only package columns needed for pro-rated calculations
        $previousPackage    = $pppoeUser->package ?? Package::query()
            ->select(['id', 'name', 'download_speed', 'upload_speed', 'duration', 'price'])
            ->find($previousPackageId);

        $package = null;
        if ($request->has('package_id')) {
            $package = Package::query()
                ->select(['id', 'name', 'download_speed', 'upload_speed', 'duration', 'price'])
                ->where('id', $request->package_id)
                ->where('type', 'pppoe')
                ->first();

            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid package: must belong to tenant and be type pppoe',
                ], 422);
            }
        }

        if ($request->has('router_id')) {
            $router = Router::query()
                ->select(['id', 'name'])
                ->find($request->router_id);
            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router not found',
                ], 404);
            }
            $pppoeUser->router_id = $router->id;
        }

        if ($package) {
            $rateLimit = BandwidthHelper::formatMikrotikRateLimit((string) $package->download_speed, (string) $package->upload_speed);
            $pppoeUser->package_id = $package->id;
            $pppoeUser->rate_limit = $rateLimit;

            // Pro-rated expiry recalculation when package changes mid-subscription.
            // If the user has an active future expiry, convert unused credit to days on new package.
            // If no active subscription (expires_at is null or past), leave expires_at unchanged —
            // it will be set correctly when the next payment is recorded.
            $currentExpiry = $pppoeUser->expires_at;
            if ($currentExpiry && $currentExpiry->isFuture() && $previousPackage && (string) $previousPackage->id !== (string) $package->id) {
                $daysRemaining    = (int) now()->diffInDays($currentExpiry, false);
                $oldDurationDays  = PackageExpiryHelper::durationInDays($previousPackage);
                $newDurationDays  = PackageExpiryHelper::durationInDays($package);
                $oldPrice         = (float) ($previousPackage->price ?? 0);
                $newPrice         = (float) ($package->price ?? 0);

                if ($oldDurationDays > 0 && $newPrice > 0) {
                    $oldDailyRate  = $oldPrice / $oldDurationDays;
                    $unusedCredit  = max(0, $daysRemaining * $oldDailyRate);
                    $newDailyRate  = $newPrice / $newDurationDays;
                    $extraDays     = (int) floor($unusedCredit / $newDailyRate);
                    $pppoeUser->expires_at = now()->addDays(max(0, $extraDays));
                } else {
                    // Prices unknown — fall back to full new package duration from now
                    $pppoeUser->expires_at = PackageExpiryHelper::calculateExpiresAt($package, now());
                }
            }
            // If same package (other fields changed only) or no active sub, don't touch expires_at
        }

        if ($request->has('simultaneous_use')) {
            $pppoeUser->simultaneous_use = (int) $request->simultaneous_use;
        }

        if ($request->has('is_active')) {
            $pppoeUser->is_active = (bool) $request->is_active;
        }

        if ($request->has('status')) {
            $pppoeUser->status = (string) $request->status;
        }

        if ($request->has('customer_name')) {
            $pppoeUser->customer_name = $request->filled('customer_name') ? (string) $request->customer_name : null;
        }

        if ($request->has('customer_email')) {
            $pppoeUser->customer_email = $request->filled('customer_email') ? (string) $request->customer_email : null;
        }

        if ($request->has('customer_phone')) {
            $pppoeUser->customer_phone = $request->filled('customer_phone') ? (string) $request->customer_phone : null;
        }

        try {
            // STEP 1: Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id, (string) $pppoeUser->id);

            // STEP 2: Update user and RADIUS in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $tenant) {
                $now = now();
                $pppoeUser->save();

                // OPTIMIZED: Batch radreply inserts into single upsert
                DB::table('radreply')->upsert(
                    [
                        ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID', 'op' => ':=', 'value' => (string) $tenant->id, 'created_at' => $now, 'updated_at' => $now],
                        ['username' => $pppoeUser->username, 'attribute' => 'Service-Type', 'op' => ':=', 'value' => 'Framed-User', 'created_at' => $now, 'updated_at' => $now],
                    ],
                    ['username', 'attribute'],
                    ['op', 'value', 'updated_at']
                );

                $this->syncRadiusMetaForUser(
                    $pppoeUser->username,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (bool) $pppoeUser->is_active,
                    (string) $pppoeUser->status
                );

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));
            $this->bustUserCache((string) $tenant->id);

            $rateLimitChanged = (string) ($previousRateLimit ?? '') !== (string) ($pppoeUser->rate_limit ?? '');
            $packageChanged = (string) ($previousPackageId ?? '') !== (string) ($pppoeUser->package_id ?? '');
            $isNowBlockedOrInactive = !$pppoeUser->is_active || $pppoeUser->status === 'blocked' || $pppoeUser->status === 'expired' || $pppoeUser->status === 'inactive' || $pppoeUser->status === 'suspended';

            // IMPORTANT: MikroTik does not reliably apply new Mikrotik-Rate-Limit mid-session.
            // Force re-auth by disconnecting the active PPP session when rate limit/package changes,
            // or when the account is blocked/inactive/expired.
            if ($rateLimitChanged || $packageChanged || $isNowBlockedOrInactive) {
                $reason = null;
                if ($rateLimitChanged) $reason = 'Rate limit changed - force re-auth';
                elseif ($packageChanged) $reason = 'Package changed - force re-auth';
                elseif ($isNowBlockedOrInactive) $reason = 'Account status changed - force re-auth';
                $this->disconnectPppoeUserSessions($pppoeUser, $reason);
            }

            $pppoeUser->load([
                'package:id,name,type,download_speed,upload_speed,duration',
                'router:id,name',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user updated',
                'data' => $pppoeUser,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to update PPPoE user', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update PPPoE user: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function destroy(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            $pppoeUserId = (string) $pppoeUser->id;
            $username = (string) $pppoeUser->username;

            // Delete RADIUS entries and user in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $username) {
                // OPTIMIZED: Batch delete radcheck and radreply in single query each
                DB::table('radcheck')->where('username', $username)->delete();
                DB::table('radreply')->where('username', $username)->delete();

                // Clear package association so the package becomes deletable
                $pppoeUser->package_id = null;
                $pppoeUser->saveQuietly();

                $pppoeUser->delete();
            });

            // Remove schema mapping from PUBLIC schema (metadata cleanup)
            $this->removeRadiusSchemaMapping($username);

            event(new PppoeUserDeleted($pppoeUserId, $username, (string) $tenant->id));
            $this->bustUserCache((string) $tenant->id);

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user deleted',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to delete PPPoE user', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete PPPoE user: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function block(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'is_active', 'status', 'router_id'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id, (string) $pppoeUser->id);

            // Block user in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser) {
                $pppoeUser->is_active = false;
                $pppoeUser->status = 'blocked';
                $pppoeUser->save();

                // Block authentication WITHOUT deleting Cleartext-Password
                // This preserves password visibility while still rejecting auth.
                DB::table('radcheck')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Auth-Type'],
                    ['op' => ':=', 'value' => 'Reject']
                );
                
                // Remove rate limit reply attributes so blocked user has no service
                DB::table('radreply')->where('username', $pppoeUser->username)->delete();

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));
            $this->bustUserCache((string) $tenant->id);

            // Disconnect active session immediately so blocked users lose internet right away.
            $this->disconnectPppoeUserSessions($pppoeUser, 'User blocked - disconnect session');

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user blocked',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to block PPPoE user', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to block PPPoE user: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function unblock(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'is_active', 'status', 'suspended_at', 'suspension_reason', 'rate_limit', 'router_id', 'expires_at'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id, (string) $pppoeUser->id);

            // Unblock user in TENANT schema - fully restore RADIUS credentials
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $tenant) {
                $pppoeUser->is_active = true;
                // Reset status to active for blocked/inactive/suspended users
                if (in_array($pppoeUser->status, ['blocked', 'inactive', 'suspended'])) {
                    $pppoeUser->status = 'active';
                }
                // Clear suspension if any
                $pppoeUser->suspended_at = null;
                $pppoeUser->suspension_reason = null;
                $pppoeUser->save();

                // Block authentication WITHOUT deleting Cleartext-Password
                // This preserves password visibility while still rejecting auth.
                DB::table('radcheck')
                    ->where('username', $pppoeUser->username)
                    ->where('attribute', 'Auth-Type')
                    ->where('value', 'Reject')
                    ->delete();

                // OPTIMIZED: Batch all radreply attributes into single upsert
                $now = now();
                $radreplyValues = [
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID', 'op' => ':=', 'value' => (string) $tenant->id, 'created_at' => $now, 'updated_at' => $now],
                    ['username' => $pppoeUser->username, 'attribute' => 'Service-Type', 'op' => ':=', 'value' => 'Framed-User', 'created_at' => $now, 'updated_at' => $now],
                ];

                // Restore rate-limit attribute that was deleted during block
                if ($pppoeUser->rate_limit) {
                    $radreplyValues[] = ['username' => $pppoeUser->username, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => $pppoeUser->rate_limit, 'created_at' => $now, 'updated_at' => $now];
                }

                // Restore Framed-Pool so MikroTik assigns an IP (required: PPP profile has remote-address=none)
                if ($pppoeUser->router_id) {
                    $shortId = substr(str_replace('-', '', (string) $pppoeUser->router_id), 0, 8);
                    $radreplyValues[] = ['username' => $pppoeUser->username, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'pppoe-pool-' . $shortId, 'created_at' => $now, 'updated_at' => $now];
                }

                // Restore session timeout if expiry is set
                if ($pppoeUser->expires_at) {
                    $sessionTimeout = max(60, (int) $now->diffInSeconds($pppoeUser->expires_at, false));
                    $radreplyValues[] = ['username' => $pppoeUser->username, 'attribute' => 'Session-Timeout', 'op' => ':=', 'value' => (string) $sessionTimeout, 'created_at' => $now, 'updated_at' => $now];
                }

                // Bulk upsert all radreply values in single query
                if (!empty($radreplyValues)) {
                    DB::table('radreply')->upsert(
                        $radreplyValues,
                        ['username', 'attribute'],
                        ['op', 'value', 'updated_at']
                    );
                }

                // Update radcheck attributes
                $this->syncRadiusMetaForUser(
                    $pppoeUser->username,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (bool) $pppoeUser->is_active,
                    (string) $pppoeUser->status
                );

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));
            $this->bustUserCache((string) $tenant->id);

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user unblocked and activated',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to unblock PPPoE user', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unblock PPPoE user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate an inactive PPPoE user
     * This restores full RADIUS credentials and allows the user to connect
     */
    public function activate(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'is_active', 'status', 'suspended_at', 'suspension_reason', 'rate_limit', 'router_id', 'expires_at', 'simultaneous_use'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id, (string) $pppoeUser->id);

            // Activate user in TENANT schema
            $passwordRestored = $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $tenant) {
                $pppoeUser->is_active = true;
                $pppoeUser->status = 'active';
                $pppoeUser->suspended_at = null;
                $pppoeUser->suspension_reason = null;
                $pppoeUser->save();

                // Remove Auth-Type Reject to allow authentication
                DB::table('radcheck')
                    ->where('username', $pppoeUser->username)
                    ->where('attribute', 'Auth-Type')
                    ->where('value', 'Reject')
                    ->delete();

                // Check if Cleartext-Password exists - if not, password needs reset
                $hasPassword = DB::table('radcheck')
                    ->where('username', $pppoeUser->username)
                    ->where('attribute', 'Cleartext-Password')
                    ->exists();

                // OPTIMIZED: Batch all radreply attributes into single upsert
                $radreplyValues = [
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID', 'op' => ':=', 'value' => (string) $tenant->id, 'created_at' => now(), 'updated_at' => now()],
                    ['username' => $pppoeUser->username, 'attribute' => 'Service-Type', 'op' => ':=', 'value' => 'Framed-User', 'created_at' => now(), 'updated_at' => now()],
                ];

                // Restore rate-limit
                if ($pppoeUser->rate_limit) {
                    $radreplyValues[] = ['username' => $pppoeUser->username, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => $pppoeUser->rate_limit, 'created_at' => now(), 'updated_at' => now()];
                }

                // Restore Framed-Pool so MikroTik assigns an IP (required: PPP profile has remote-address=none)
                if ($pppoeUser->router_id) {
                    $shortId = substr(str_replace('-', '', (string) $pppoeUser->router_id), 0, 8);
                    $radreplyValues[] = ['username' => $pppoeUser->username, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'pppoe-pool-' . $shortId, 'created_at' => now(), 'updated_at' => now()];
                }

                // Restore session timeout
                if ($pppoeUser->expires_at) {
                    $sessionTimeout = max(60, (int) now()->diffInSeconds($pppoeUser->expires_at, false));
                    $radreplyValues[] = ['username' => $pppoeUser->username, 'attribute' => 'Session-Timeout', 'op' => ':=', 'value' => (string) $sessionTimeout, 'created_at' => now(), 'updated_at' => now()];
                }

                // Bulk upsert all radreply values in single query
                if (!empty($radreplyValues)) {
                    DB::table('radreply')->upsert(
                        $radreplyValues,
                        ['username', 'attribute'],
                        ['op', 'value', 'updated_at']
                    );
                }

                // Update radcheck meta
                $this->syncRadiusMetaForUser(
                    $pppoeUser->username,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    true,
                    'active'
                );

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);

                return $hasPassword;
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));
            $this->bustUserCache((string) $tenant->id);

            $message = 'PPPoE user activated successfully';
            if (!$passwordRestored) {
                $message .= '. Note: Password not found - user may need password reset.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'password_exists' => $passwordRestored,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to activate PPPoE user', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to activate PPPoE user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate a PPPoE user (set to inactive without blocking)
     * User will not be able to connect until reactivated
     */
    public function deactivate(Request $request, string $id)
    {
        $tenant = $this->getAuthenticatedTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context not available. Please contact support.',
            ], 500);
        }

        // OPTIMIZED: Select only needed columns
        $pppoeUser = PppoeUser::query()
            ->select(['id', 'username', 'is_active', 'status', 'router_id'])
            ->find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id, (string) $pppoeUser->id);

            // Deactivate user in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser) {
                $pppoeUser->is_active = false;
                $pppoeUser->status = 'inactive';
                $pppoeUser->save();

                // Set Auth-Type Reject to block authentication
                // Password is preserved for future reactivation
                DB::table('radcheck')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Auth-Type'],
                    ['op' => ':=', 'value' => 'Reject']
                );

                // OPTIMIZATION: Mark as synced so model event won't duplicate
                app()->instance("pppoe_mapping_synced_{$pppoeUser->id}", true);
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));
            $this->bustUserCache((string) $tenant->id);

            // Disconnect active session so user loses access immediately
            $this->disconnectPppoeUserSessions($pppoeUser, 'User deactivated - disconnect session');

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user deactivated',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to deactivate PPPoE user', [
                'error' => $e->getMessage(),
                'pppoe_user_id' => $pppoeUser->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate PPPoE user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync user credentials to RADIUS radcheck/radreply tables.
     * It operates on the tenant's radcheck and radreply tables, NOT public schema.
     * 
     * CRITICAL: Stores both PPPoE password and portal password for centralized authentication.
     * Portal password is stored as a separate attribute to allow portal-specific authentication.
     */
    private function syncRadiusCredentials(string $username, string $plainPassword, ?\DateTime $expiresAt, ?string $rateLimit, int $simultaneousUse, string $tenantId, ?string $framedPool = null, ?string $portalPassword = null): void
    {
        $ntPassword = $this->calculateNtPasswordHash($plainPassword);
        $now = now();

        // OPTIMIZED: Use UPSERT (INSERT ... ON CONFLICT) instead of DELETE+INSERT
        // This reduces queries and avoids race conditions
        $radcheckValues = [
            ['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $plainPassword, 'created_at' => $now, 'updated_at' => $now],
            ['username' => $username, 'attribute' => 'NT-Password', 'op' => ':=', 'value' => $ntPassword, 'created_at' => $now, 'updated_at' => $now],
            ['username' => $username, 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => (string) $simultaneousUse, 'created_at' => $now, 'updated_at' => $now],
        ];

        if ($portalPassword) {
            $radcheckValues[] = ['username' => $username, 'attribute' => 'Portal-Password', 'op' => ':=', 'value' => $portalPassword, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($expiresAt) {
            $radcheckValues[] = ['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expiresAt->format('F d Y H:i:s'), 'created_at' => $now, 'updated_at' => $now];
        }

        // Bulk UPSERT for radcheck - single query for all attributes
        DB::table('radcheck')->upsert(
            $radcheckValues,
            ['username', 'attribute'], // Unique key
            ['op', 'value', 'updated_at'] // Columns to update on conflict
        );

        // Clean up stale attributes that shouldn't exist anymore
        $keepAttributes = array_column($radcheckValues, 'attribute');
        DB::table('radcheck')
            ->where('username', $username)
            ->whereNotIn('attribute', $keepAttributes)
            ->delete();

        if ($portalPassword) {
            Log::info('Portal password synced to RADIUS', [
                'username' => $username,
                'has_portal_password' => true,
            ]);
        }

        // Build radreply values
        $replyValues = [
            ['username' => $username, 'attribute' => 'Tenant-ID', 'op' => ':=', 'value' => $tenantId, 'created_at' => $now, 'updated_at' => $now],
            ['username' => $username, 'attribute' => 'Service-Type', 'op' => ':=', 'value' => 'Framed-User', 'created_at' => $now, 'updated_at' => $now],
        ];

        if ($expiresAt) {
            $sessionTimeout = max(60, (int) $now->diffInSeconds($expiresAt, false));
            $replyValues[] = ['username' => $username, 'attribute' => 'Session-Timeout', 'op' => ':=', 'value' => (string) $sessionTimeout, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rateLimit) {
            $replyValues[] = ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => $rateLimit, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($framedPool) {
            $replyValues[] = ['username' => $username, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => $framedPool, 'created_at' => $now, 'updated_at' => $now];
        }

        // Bulk UPSERT for radreply
        if (!empty($replyValues)) {
            DB::table('radreply')->upsert(
                $replyValues,
                ['username', 'attribute'],
                ['op', 'value', 'updated_at']
            );

            // Clean up stale radreply attributes
            $keepReplyAttributes = array_column($replyValues, 'attribute');
            DB::table('radreply')
                ->where('username', $username)
                ->whereNotIn('attribute', $keepReplyAttributes)
                ->delete();
        }
    }

    private function calculateNtPasswordHash(string $plainPassword): string
    {
        if (function_exists('mb_convert_encoding')) {
            $utf16le = mb_convert_encoding($plainPassword, 'UTF-16LE', 'UTF-8');
        } else {
            $utf16le = iconv('UTF-8', 'UTF-16LE', $plainPassword);
        }
        return strtoupper(hash('md4', $utf16le));
    }

    private function ensureRadiusSchemaMapping(string $username, string $schemaName, string $tenantId, ?string $pppoeUserId = null): void
    {
        // Normalize to lowercase — get_user_schema() does LOWER() on lookup;
        // mixed-case stored values cause a lookup miss and auth failure.
        $username = strtolower(trim($username));

        // Use raw SQL with explicit schema to bypass any search_path issues
        // This ensures the mapping is created in public schema regardless of current search_path
        DB::statement("
            INSERT INTO public.radius_user_schema_mapping (username, pppoe_user_id, schema_name, tenant_id, user_role, is_active, created_at, updated_at)
            VALUES (?, ?::uuid, ?, ?::uuid, 'pppoe', true, NOW(), NOW())
            ON CONFLICT (username) DO UPDATE SET
                pppoe_user_id = EXCLUDED.pppoe_user_id,
                schema_name = EXCLUDED.schema_name,
                tenant_id = EXCLUDED.tenant_id,
                user_role = EXCLUDED.user_role,
                is_active = true,
                updated_at = NOW()
        ", [$username, $pppoeUserId, $schemaName, $tenantId]);
        
        Log::info('RADIUS schema mapping ensured', [
            'username' => $username,
            'schema_name' => $schemaName,
            'tenant_id' => $tenantId,
        ]);
    }

    private function removeRadiusSchemaMapping(string $username): void
    {
        // Use raw SQL with explicit schema to bypass any search_path issues
        DB::statement("DELETE FROM public.radius_user_schema_mapping WHERE username = ?", [$username]);
        
        Log::info('RADIUS schema mapping removed', ['username' => $username]);
    }

    /**
     * Sync RADIUS metadata (expiration, rate limits, status) in tenant schema
     * 
     * IMPORTANT: This method assumes TenantContext has already set the correct search_path.
     * It operates on the tenant's radcheck and radreply tables, NOT public schema.
     * 
     * OPTIMIZED: Uses batch upsert instead of multiple separate updateOrInsert calls
     */
    private function syncRadiusMetaForUser(string $username, $expiresAt, ?string $rateLimit, int $simultaneousUse, bool $isActive, string $status): void
    {
        $now = now();

        // Handle blocked/inactive status - add Auth-Type Reject
        if (!$isActive || $status === 'blocked' || $status === 'expired' || $status === 'inactive' || $status === 'suspended') {
            DB::table('radcheck')->upsert(
                [['username' => $username, 'attribute' => 'Auth-Type', 'op' => ':=', 'value' => 'Reject', 'created_at' => $now, 'updated_at' => $now]],
                ['username', 'attribute'],
                ['op', 'value', 'updated_at']
            );
            return;
        }

        // User is active - build batch upsert arrays
        $radcheckValues = [
            ['username' => $username, 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => (string) $simultaneousUse, 'created_at' => $now, 'updated_at' => $now],
        ];
        $radreplyValues = [];

        // Remove any existing Auth-Type Reject
        DB::table('radcheck')
            ->where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->delete();

        if ($expiresAt) {
            $radcheckValues[] = ['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expiresAt->format('F d Y H:i:s'), 'created_at' => $now, 'updated_at' => $now];
            $sessionTimeout = max(60, (int) $now->diffInSeconds($expiresAt, false));
            $radreplyValues[] = ['username' => $username, 'attribute' => 'Session-Timeout', 'op' => ':=', 'value' => (string) $sessionTimeout, 'created_at' => $now, 'updated_at' => $now];
        } else {
            // Remove stale Expiration entries — empty values cause FreeRADIUS to reject with epoch date
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Expiration')
                ->delete();
            DB::table('radreply')
                ->where('username', $username)
                ->where('attribute', 'Session-Timeout')
                ->delete();
        }

        if ($rateLimit) {
            $radreplyValues[] = ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => $rateLimit, 'created_at' => $now, 'updated_at' => $now];
        }

        // Batch upsert radcheck values (single query)
        if (!empty($radcheckValues)) {
            DB::table('radcheck')->upsert(
                $radcheckValues,
                ['username', 'attribute'],
                ['op', 'value', 'updated_at']
            );
        }

        // Batch upsert radreply values (single query)
        if (!empty($radreplyValues)) {
            DB::table('radreply')->upsert(
                $radreplyValues,
                ['username', 'attribute'],
                ['op', 'value', 'updated_at']
            );
        }
    }

}
