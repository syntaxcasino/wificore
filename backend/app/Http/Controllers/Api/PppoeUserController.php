<?php

namespace App\Http\Controllers\Api;

use App\Events\PppoeUserCreated;
use App\Events\PppoeUserDeleted;
use App\Events\PppoeUserUpdated;
use App\Helpers\PackageExpiryHelper;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\MikroTik\BandwidthHelper;
use App\Services\MikroTik\SshExecutor;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        
        return Tenant::find($tenantId);
    }

    private function disconnectPppoeUserSessions(PppoeUser $pppoeUser): void
    {
        try {
            if (empty($pppoeUser->router_id) || empty($pppoeUser->username)) {
                return;
            }

            $router = Router::find($pppoeUser->router_id);
            if (!$router) {
                return;
            }

            $username = (string) $pppoeUser->username;

            $ssh = new SshExecutor($router, 5);
            $ssh->connect();

            // PPPoE active sessions typically use the PPP secret name as the active session name.
            // Use both selectors (name/user) for compatibility across RouterOS versions.
            $ssh->exec(sprintf('/ppp active remove [find name="%s"]', addslashes($username)));
            $ssh->exec(sprintf('/ppp active remove [find user="%s"]', addslashes($username)));
            $ssh->disconnect();

            Log::info('PPPoE active sessions disconnected (best-effort)', [
                'pppoe_user_id' => (string) $pppoeUser->id,
                'username' => $username,
                'router_id' => (string) $router->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to disconnect PPPoE active sessions (best-effort)', [
                'pppoe_user_id' => (string) ($pppoeUser->id ?? ''),
                'username' => (string) ($pppoeUser->username ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        try {
            if (!Schema::hasTable('pppoe_users')) {
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

            $users = PppoeUser::query()
                ->with([
                    'package:id,name,type,download_speed,upload_speed,duration,price',
                    'router:id,name',
                ])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            // Add computed fields for each user
            $users->getCollection()->transform(function ($user) {
                $user->days_to_expiry = $user->expires_at ? (int) now()->diffInDays($user->expires_at, false) : null;
                $user->is_expired = $user->expires_at && $user->expires_at->isPast();
                return $user;
            });

            return response()->json([
                'success' => true,
                'data' => $users,
                'tenant_id' => $tenantId,
            ]);
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

        $pppoeUser = PppoeUser::with([
            'package:id,name,type,download_speed,upload_speed,duration,price',
            'router:id,name',
        ])->find($id);

        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        // Add computed fields
        $pppoeUser->days_to_expiry = $pppoeUser->expires_at ? (int) now()->diffInDays($pppoeUser->expires_at, false) : null;
        $pppoeUser->is_expired = $pppoeUser->expires_at && $pppoeUser->expires_at->isPast();

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

        $pppoeUser = PppoeUser::find($id);
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

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $newPassword = Str::random(12);

        try {
            // STEP 1: Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id);

            // STEP 2: Update password and RADIUS in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $newPassword, $tenant) {
                $pppoeUser->password = bcrypt($newPassword);
                $pppoeUser->save();

                $shortRouterId = substr(str_replace('-', '', (string) $pppoeUser->router_id), 0, 8);
                $this->syncRadiusCredentials(
                    (string) $pppoeUser->username,
                    $newPassword,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (string) $tenant->id,
                    $pppoeUser->router_id ? 'pppoe-pool-' . $shortRouterId : null
                );
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

            // Force re-auth so new password is applied.
            $this->disconnectPppoeUserSessions($pppoeUser);

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

        if (!Schema::hasTable('pppoe_users')) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE users are not initialized for this tenant. Please run tenant migrations.',
            ], 500);
        }

        if (!Schema::hasTable('radcheck') || !Schema::hasTable('radreply')) {
            return response()->json([
                'success' => false,
                'message' => 'RADIUS tables are not initialized for this tenant. Please run tenant migrations.',
            ], 500);
        }

        $requiredPppoeColumns = ['customer_name', 'customer_email', 'customer_phone'];
        $missingPppoeColumns = array_values(array_filter(
            $requiredPppoeColumns,
            static fn (string $column): bool => !Schema::hasColumn('pppoe_users', $column)
        ));

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

        $username = (string) $request->username;
        $plainPassword = Str::random(12);
        $simultaneousUse = (int) ($request->simultaneous_use ?? 1);
        $customerName = $request->filled('customer_name') ? (string) $request->customer_name : $username;
        $customerEmail = $request->filled('customer_email') ? (string) $request->customer_email : null;
        $customerPhone = $request->filled('customer_phone') ? (string) $request->customer_phone : null;

        $package = Package::where('id', $request->package_id)
            ->where('type', 'pppoe')
            ->first();

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid package: must belong to tenant and be type pppoe',
            ], 422);
        }

        $router = Router::find($request->router_id);
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
            $pppoeUser = $this->tenantContext->runInTenantContext($tenant, function () use ($username, $plainPassword, $package, $router, $expiresAt, $rateLimit, $simultaneousUse, $customerName, $customerEmail, $customerPhone, $tenant) {
                $tenantPrefix = $tenant->account_prefix
                    ?? \App\Models\Tenant::generateAccountPrefix($tenant->slug ?? $tenant->name);
                $accountNumber = PppoeUser::generateAccountNumber($tenantPrefix, 'P');

                // next_payment_due = package duration from now (a billing prompt — not the actual expiry)
                $nextPaymentDue = PackageExpiryHelper::calculateExpiresAt($package, now());

                // STEP 3: Create PPPoE user in tenant schema
                // Default to ACTIVE so user can connect, but payment_status is UNPAID until paid
                $pppoeUser = PppoeUser::create([
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
                ]);

                // STEP 4: Sync RADIUS credentials in tenant schema
                // Pool name matches ZeroConfigPPPoEGenerator formula: pppoe-pool-{short_router_id}
                $shortRouterId = substr(str_replace('-', '', (string) $router->id), 0, 8);
                $framedPool = 'pppoe-pool-' . $shortRouterId;
                $this->syncRadiusCredentials($username, $plainPassword, $expiresAt, $rateLimit, $simultaneousUse, (string) $tenant->id, $framedPool);

                $this->syncRadiusMetaForUser(
                    $pppoeUser->username,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (bool) $pppoeUser->is_active,
                    (string) $pppoeUser->status
                );

                $pppoeUser->load([
                    'package:id,name,type,download_speed,upload_speed,duration',
                    'router:id,name',
                ]);

                return $pppoeUser;
            });

            event(new PppoeUserCreated($pppoeUser, (string) $tenantId));

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user created',
                'data' => $pppoeUser,
                'generated_password' => $plainPassword,
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

        $pppoeUser = PppoeUser::find($id);
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
        $previousPackage    = $pppoeUser->package ?? Package::find($previousPackageId);

        $package = null;
        if ($request->has('package_id')) {
            $package = Package::where('id', $request->package_id)
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
            $router = Router::find($request->router_id);
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
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id);

            // STEP 2: Update user and RADIUS in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $tenant) {
                $pppoeUser->save();

                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID'],
                    ['op' => ':=', 'value' => (string) $tenant->id]
                );
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Service-Type'],
                    ['op' => ':=', 'value' => 'Framed-User']
                );

                $this->syncRadiusMetaForUser(
                    $pppoeUser->username,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (bool) $pppoeUser->is_active,
                    (string) $pppoeUser->status
                );
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

            $rateLimitChanged = (string) ($previousRateLimit ?? '') !== (string) ($pppoeUser->rate_limit ?? '');
            $packageChanged = (string) ($previousPackageId ?? '') !== (string) ($pppoeUser->package_id ?? '');
            $isNowBlockedOrInactive = !$pppoeUser->is_active || $pppoeUser->status === 'blocked' || $pppoeUser->status === 'expired' || $pppoeUser->status === 'inactive' || $pppoeUser->status === 'suspended';

            // IMPORTANT: MikroTik does not reliably apply new Mikrotik-Rate-Limit mid-session.
            // Force re-auth by disconnecting the active PPP session when rate limit/package changes,
            // or when the account is blocked/inactive/expired.
            if ($rateLimitChanged || $packageChanged || $isNowBlockedOrInactive) {
                $this->disconnectPppoeUserSessions($pppoeUser);
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

        $pppoeUser = PppoeUser::find($id);
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
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser) {
                DB::table('radcheck')->where('username', $pppoeUser->username)->delete();
                DB::table('radreply')->where('username', $pppoeUser->username)->delete();
                $pppoeUser->delete();
            });

            // Remove schema mapping from PUBLIC schema (metadata cleanup)
            $this->removeRadiusSchemaMapping($username);

            event(new PppoeUserDeleted($pppoeUserId, $username, (string) $tenant->id));

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

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id);

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
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

            // Disconnect active session immediately so blocked users lose internet right away.
            $this->disconnectPppoeUserSessions($pppoeUser);

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

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id);

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

                // Fully restore radreply attributes including rate-limit
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID'],
                    ['op' => ':=', 'value' => (string) $tenant->id]
                );
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Service-Type'],
                    ['op' => ':=', 'value' => 'Framed-User']
                );

                // CRITICAL: Restore rate-limit attribute that was deleted during block
                if ($pppoeUser->rate_limit) {
                    DB::table('radreply')->updateOrInsert(
                        ['username' => $pppoeUser->username, 'attribute' => 'Mikrotik-Rate-Limit'],
                        ['op' => ':=', 'value' => $pppoeUser->rate_limit]
                    );
                }

                // Restore Framed-Pool so MikroTik assigns an IP (required: PPP profile has remote-address=none)
                if ($pppoeUser->router_id) {
                    $shortId = substr(str_replace('-', '', (string) $pppoeUser->router_id), 0, 8);
                    DB::table('radreply')->updateOrInsert(
                        ['username' => $pppoeUser->username, 'attribute' => 'Framed-Pool'],
                        ['op' => ':=', 'value' => 'pppoe-pool-' . $shortId]
                    );
                }

                // Restore session timeout if expiry is set
                if ($pppoeUser->expires_at) {
                    $sessionTimeout = max(60, (int) now()->diffInSeconds($pppoeUser->expires_at, false));
                    DB::table('radreply')->updateOrInsert(
                        ['username' => $pppoeUser->username, 'attribute' => 'Session-Timeout'],
                        ['op' => ':=', 'value' => (string) $sessionTimeout]
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
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

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

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id);

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

                // Restore radreply attributes
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID'],
                    ['op' => ':=', 'value' => (string) $tenant->id]
                );
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Service-Type'],
                    ['op' => ':=', 'value' => 'Framed-User']
                );

                // Restore rate-limit
                if ($pppoeUser->rate_limit) {
                    DB::table('radreply')->updateOrInsert(
                        ['username' => $pppoeUser->username, 'attribute' => 'Mikrotik-Rate-Limit'],
                        ['op' => ':=', 'value' => $pppoeUser->rate_limit]
                    );
                }

                // Restore Framed-Pool so MikroTik assigns an IP (required: PPP profile has remote-address=none)
                if ($pppoeUser->router_id) {
                    $shortId = substr(str_replace('-', '', (string) $pppoeUser->router_id), 0, 8);
                    DB::table('radreply')->updateOrInsert(
                        ['username' => $pppoeUser->username, 'attribute' => 'Framed-Pool'],
                        ['op' => ':=', 'value' => 'pppoe-pool-' . $shortId]
                    );
                }

                // Restore session timeout
                if ($pppoeUser->expires_at) {
                    $sessionTimeout = max(60, (int) now()->diffInSeconds($pppoeUser->expires_at, false));
                    DB::table('radreply')->updateOrInsert(
                        ['username' => $pppoeUser->username, 'attribute' => 'Session-Timeout'],
                        ['op' => ':=', 'value' => (string) $sessionTimeout]
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

                return $hasPassword;
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

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

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        try {
            // Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id);

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
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

            // Disconnect active session so user loses access immediately
            $this->disconnectPppoeUserSessions($pppoeUser);

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
     * Sync RADIUS credentials in tenant schema
     * 
     * IMPORTANT: This method assumes TenantContext has already set the correct search_path.
     * It operates on the tenant's radcheck and radreply tables, NOT public schema.
     */
    private function syncRadiusCredentials(string $username, string $plainPassword, $expiresAt, ?string $rateLimit, int $simultaneousUse, string $tenantId, ?string $framedPool = null): void
    {
        $ntPassword = $this->calculateNtPasswordHash($plainPassword);

        DB::table('radcheck')->where('username', $username)->delete();
        DB::table('radreply')->where('username', $username)->delete();

        $radcheckRows = [
            [
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $plainPassword,
            ],
            [
                'username' => $username,
                'attribute' => 'NT-Password',
                'op' => ':=',
                'value' => $ntPassword,
            ],
            [
                'username' => $username,
                'attribute' => 'Simultaneous-Use',
                'op' => ':=',
                'value' => (string) $simultaneousUse,
            ],
        ];

        if ($expiresAt) {
            $radcheckRows[] = [
                'username' => $username,
                'attribute' => 'Expiration',
                'op' => ':=',
                'value' => $expiresAt->format('F d Y H:i:s'),
            ];
        }

        DB::table('radcheck')->insert($radcheckRows);

        $replyRows = [];

        $replyRows[] = [
            'username' => $username,
            'attribute' => 'Tenant-ID',
            'op' => ':=',
            'value' => $tenantId,
        ];

        $replyRows[] = [
            'username' => $username,
            'attribute' => 'Service-Type',
            'op' => ':=',
            'value' => 'Framed-User',
        ];

        if ($expiresAt) {
            $sessionTimeout = max(60, (int) now()->diffInSeconds($expiresAt, false));
            $replyRows[] = [
                'username' => $username,
                'attribute' => 'Session-Timeout',
                'op' => ':=',
                'value' => (string) $sessionTimeout,
            ];
        }

        if ($rateLimit) {
            $replyRows[] = [
                'username' => $username,
                'attribute' => 'Mikrotik-Rate-Limit',
                'op' => ':=',
                'value' => $rateLimit,
            ];
        }

        // Framed-Pool: tells MikroTik which IP pool to assign from.
        // Required because PPP profile uses remote-address=none (RADIUS-only, no local fallback).
        if ($framedPool) {
            $replyRows[] = [
                'username' => $username,
                'attribute' => 'Framed-Pool',
                'op' => ':=',
                'value' => $framedPool,
            ];
        }

        if (!empty($replyRows)) {
            DB::table('radreply')->insert($replyRows);
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

    private function ensureRadiusSchemaMapping(string $username, string $schemaName, string $tenantId): void
    {
        // Use raw SQL with explicit schema to bypass any search_path issues
        // This ensures the mapping is created in public schema regardless of current search_path
        DB::statement("
            INSERT INTO public.radius_user_schema_mapping (username, schema_name, tenant_id, user_role, is_active, created_at, updated_at)
            VALUES (?, ?, ?::uuid, 'pppoe', true, NOW(), NOW())
            ON CONFLICT (username) DO UPDATE SET
                schema_name = EXCLUDED.schema_name,
                tenant_id = EXCLUDED.tenant_id,
                user_role = EXCLUDED.user_role,
                is_active = true,
                updated_at = NOW()
        ", [$username, $schemaName, $tenantId]);
        
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
     */
    private function syncRadiusMetaForUser(string $username, $expiresAt, ?string $rateLimit, int $simultaneousUse, bool $isActive, string $status): void
    {
        if (!$isActive || $status === 'blocked' || $status === 'expired' || $status === 'inactive' || $status === 'suspended') {
            DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Auth-Type'],
                ['op' => ':=', 'value' => 'Reject']
            );
            return;
        }

        DB::table('radcheck')
            ->where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->delete();

        if ($expiresAt) {
            DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Expiration'],
                ['op' => ':=', 'value' => $expiresAt->format('F d Y H:i:s')]
            );

            $sessionTimeout = max(60, (int) now()->diffInSeconds($expiresAt, false));
            DB::table('radreply')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Session-Timeout'],
                ['op' => ':=', 'value' => (string) $sessionTimeout]
            );
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

        DB::table('radcheck')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Simultaneous-Use'],
            ['op' => ':=', 'value' => (string) $simultaneousUse]
        );

        if ($rateLimit) {
            DB::table('radreply')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit'],
                ['op' => ':=', 'value' => $rateLimit]
            );
        }
    }

}
