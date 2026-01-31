<?php

namespace App\Http\Controllers\Api;

use App\Events\PppoeUserCreated;
use App\Events\PppoeUserDeleted;
use App\Events\PppoeUserUpdated;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\Tenant;
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
                    'package:id,name,type,download_speed,upload_speed,duration',
                    'router:id,name',
                ])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

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

                $this->syncRadiusCredentials(
                    (string) $pppoeUser->username,
                    $newPassword,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    (string) $tenant->id
                );
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

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

        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:64|regex:/^[a-z0-9_\.-]+$/i',
            'package_id' => 'required|uuid',
            'router_id' => 'required|uuid',
            'simultaneous_use' => 'nullable|integer|min:1|max:50',
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

        $package = Package::where('id', $request->package_id)
            ->where('tenant_id', $tenantId)
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

        $expiresAt = $this->calculateExpiresAtFromPackage($package, now());
        $rateLimit = $this->formatMikrotikRateLimit((string) $package->download_speed, (string) $package->upload_speed);

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
            $pppoeUser = $this->tenantContext->runInTenantContext($tenant, function () use ($username, $plainPassword, $package, $router, $expiresAt, $rateLimit, $simultaneousUse, $tenant) {
                $tenantPrefix = substr($tenant->name, 0, 1);
                $accountNumber = PppoeUser::generateAccountNumber($tenantPrefix);

                // Calculate payment due date (30 days from package duration)
                $nextPaymentDue = $expiresAt ? clone $expiresAt : now()->addDays(30);

                // STEP 3: Create PPPoE user in tenant schema
                $pppoeUser = PppoeUser::create([
                    'username' => $username,
                    'password' => bcrypt($plainPassword),
                    'account_number' => $accountNumber,
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
                $this->syncRadiusCredentials($username, $plainPassword, $expiresAt, $rateLimit, $simultaneousUse, (string) $tenant->id);

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
            'status' => 'sometimes|required|string|in:active,inactive,blocked,expired',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $package = null;
        if ($request->has('package_id')) {
            $package = Package::where('id', $request->package_id)
                ->where('tenant_id', $tenant->id)
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
            $expiresAt = $this->calculateExpiresAtFromPackage($package, now());
            $rateLimit = $this->formatMikrotikRateLimit((string) $package->download_speed, (string) $package->upload_speed);

            $pppoeUser->package_id = $package->id;
            $pppoeUser->expires_at = $expiresAt;
            $pppoeUser->rate_limit = $rateLimit;
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

        try {
            // STEP 1: Ensure schema mapping in PUBLIC schema (metadata)
            $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenant->schema_name, (string) $tenant->id);

            // STEP 2: Update user and RADIUS in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $tenant) {
                $pppoeUser->save();

                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID'],
                    ['op' => ':=', 'value' => (string) $tenant->id, 'updated_at' => now(), 'created_at' => now()]
                );
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Service-Type'],
                    ['op' => ':=', 'value' => 'Framed-User', 'updated_at' => now(), 'created_at' => now()]
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

                DB::table('radcheck')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Auth-Type'],
                    ['op' => ':=', 'value' => 'Reject', 'updated_at' => now(), 'created_at' => now()]
                );
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenant->id));

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

            // Unblock user in TENANT schema
            $this->tenantContext->runInTenantContext($tenant, function () use ($pppoeUser, $tenant) {
                $pppoeUser->is_active = true;
                if ($pppoeUser->status === 'blocked') {
                    $pppoeUser->status = 'active';
                }
                $pppoeUser->save();

                DB::table('radcheck')
                    ->where('username', $pppoeUser->username)
                    ->where('attribute', 'Auth-Type')
                    ->where('value', 'Reject')
                    ->delete();

                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID'],
                    ['op' => ':=', 'value' => (string) $tenant->id, 'updated_at' => now(), 'created_at' => now()]
                );
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Service-Type'],
                    ['op' => ':=', 'value' => 'Framed-User', 'updated_at' => now(), 'created_at' => now()]
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

            return response()->json([
                'success' => true,
                'message' => 'PPPoE user unblocked',
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
     * Sync RADIUS credentials in tenant schema
     * 
     * IMPORTANT: This method assumes TenantContext has already set the correct search_path.
     * It operates on the tenant's radcheck and radreply tables, NOT public schema.
     */
    private function syncRadiusCredentials(string $username, string $plainPassword, $expiresAt, ?string $rateLimit, int $simultaneousUse, string $tenantId): void
    {
        $ntPassword = $this->calculateNtPasswordHash($plainPassword);

        DB::table('radcheck')->where('username', $username)->delete();
        DB::table('radreply')->where('username', $username)->delete();

        DB::table('radcheck')->insert([
            [
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $plainPassword,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => $username,
                'attribute' => 'NT-Password',
                'op' => ':=',
                'value' => $ntPassword,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => $username,
                'attribute' => 'Expiration',
                'op' => ':=',
                'value' => $expiresAt ? $expiresAt->timestamp : '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => $username,
                'attribute' => 'Simultaneous-Use',
                'op' => ':=',
                'value' => (string) $simultaneousUse,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $replyRows = [];

        $replyRows[] = [
            'username' => $username,
            'attribute' => 'Tenant-ID',
            'op' => ':=',
            'value' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $replyRows[] = [
            'username' => $username,
            'attribute' => 'Service-Type',
            'op' => ':=',
            'value' => 'Framed-User',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($expiresAt) {
            $sessionTimeout = max(60, (int) now()->diffInSeconds($expiresAt, false));
            $replyRows[] = [
                'username' => $username,
                'attribute' => 'Session-Timeout',
                'op' => ':=',
                'value' => (string) $sessionTimeout,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rateLimit) {
            $replyRows[] = [
                'username' => $username,
                'attribute' => 'Mikrotik-Rate-Limit',
                'op' => ':=',
                'value' => $rateLimit,
                'created_at' => now(),
                'updated_at' => now(),
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
        return hash('md4', $utf16le);
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
        if (!$isActive || $status === 'blocked') {
            DB::table('radcheck')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Auth-Type'],
                ['op' => ':=', 'value' => 'Reject', 'updated_at' => now(), 'created_at' => now()]
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
                ['op' => ':=', 'value' => $expiresAt->timestamp, 'updated_at' => now(), 'created_at' => now()]
            );

            $sessionTimeout = max(60, (int) now()->diffInSeconds($expiresAt, false));
            DB::table('radreply')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Session-Timeout'],
                ['op' => ':=', 'value' => (string) $sessionTimeout, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        DB::table('radcheck')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Simultaneous-Use'],
            ['op' => ':=', 'value' => (string) $simultaneousUse, 'updated_at' => now(), 'created_at' => now()]
        );

        if ($rateLimit) {
            DB::table('radreply')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit'],
                ['op' => ':=', 'value' => $rateLimit, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    private function calculateExpiresAtFromPackage(Package $package, Carbon $baseTime): Carbon
    {
        $validity = trim((string) ($package->validity ?: $package->duration));
        if ($validity === '') {
            return $baseTime->copy()->addHour();
        }

        if (!preg_match('/^\s*(\d+)\s*(minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)\s*$/i', $validity, $matches)) {
            return $baseTime->copy()->addHour();
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        if ($value <= 0) {
            return $baseTime->copy()->addHour();
        }

        return match ($unit) {
            'minute', 'minutes' => $baseTime->copy()->addMinutes($value),
            'hour', 'hours' => $baseTime->copy()->addHours($value),
            'day', 'days' => $baseTime->copy()->addDays($value),
            'week', 'weeks' => $baseTime->copy()->addWeeks($value),
            'month', 'months' => $baseTime->copy()->addMonths($value),
            'year', 'years' => $baseTime->copy()->addYears($value),
            default => $baseTime->copy()->addHour(),
        };
    }

    private function normalizeSpeed(string $speed): ?string
    {
        $speed = trim($speed);
        if ($speed === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*([kKmMgG])$/', $speed, $m)) {
            return $m[1] . strtoupper($m[2]);
        }

        if (preg_match('/^(\d+)\s*Mbps$/i', $speed, $m)) {
            return $m[1] . 'M';
        }

        if (preg_match('/^(\d+)\s*Kbps$/i', $speed, $m)) {
            return $m[1] . 'K';
        }

        if (preg_match('/^(\d+)\s*Gbps$/i', $speed, $m)) {
            return $m[1] . 'G';
        }

        return $speed;
    }

    private function formatMikrotikRateLimit(string $download, string $upload): ?string
    {
        $down = $this->normalizeSpeed($download);
        $up = $this->normalizeSpeed($upload);

        if (!$down && !$up) {
            return null;
        }

        return ($down ?: '0') . '/' . ($up ?: '0');
    }
}
