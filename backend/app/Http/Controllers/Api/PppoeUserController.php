<?php

namespace App\Http\Controllers\Api;

use App\Events\PppoeUserCreated;
use App\Events\PppoeUserDeleted;
use App\Events\PppoeUserUpdated;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PppoeUser;
use App\Models\Router;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PppoeUserController extends Controller
{
    private function quoteSchemaName(string $schemaName): string
    {
        if (!preg_match('/^ts_[a-z0-9]+$/', $schemaName)) {
            throw new \InvalidArgumentException('Invalid tenant schema name');
        }

        return '"' . str_replace('"', '""', $schemaName) . '"';
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
        $tenantId = $request->user()->tenant_id;

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $tenantSchemaName = (string) DB::table('public.tenants')
            ->where('id', $tenantId)
            ->value('schema_name');

        if ($tenantSchemaName === '') {
            Log::error('Failed to determine tenant schema for PPPoE password reset', [
                'tenant_id' => $tenantId,
                'pppoe_user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Tenant schema is not available. Please contact support.',
            ], 500);
        }

        $newPassword = Str::random(12);

        try {
            DB::transaction(function () use ($pppoeUser, $newPassword, $tenantSchemaName, $tenantId) {
                DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($tenantSchemaName) . ', public');

                $pppoeUser->password = bcrypt($newPassword);
                $pppoeUser->save();

                $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenantSchemaName, (string) $tenantId);
                $this->syncRadiusForUser(
                    (string) $pppoeUser->username,
                    $newPassword,
                    $pppoeUser->expires_at,
                    $pppoeUser->rate_limit,
                    (int) $pppoeUser->simultaneous_use,
                    $tenantSchemaName,
                    (string) $tenantId
                );
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenantId));

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
        $tenantId = $request->user()->tenant_id;

        $tenantSchemaName = (string) DB::table('public.tenants')
            ->where('id', $tenantId)
            ->value('schema_name');

        if ($tenantSchemaName === '') {
            Log::error('Failed to determine tenant schema for PPPoE user creation', [
                'tenant_id' => $tenantId,
                'user_id' => $request->user()->id ?? null,
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

            $pppoeUser = DB::transaction(function () use ($username, $plainPassword, $package, $router, $expiresAt, $rateLimit, $simultaneousUse, $tenantId, $tenantSchemaName) {
                DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($tenantSchemaName) . ', public');

                $pppoeUser = PppoeUser::create([
                    'username' => $username,
                    'password' => bcrypt($plainPassword),
                    'package_id' => $package->id,
                    'router_id' => $router->id,
                    'expires_at' => $expiresAt,
                    'rate_limit' => $rateLimit,
                    'simultaneous_use' => $simultaneousUse,
                    'is_active' => true,
                    'status' => 'active',
                ]);

                $this->ensureRadiusSchemaMapping($username, $tenantSchemaName, (string) $tenantId);
                $this->syncRadiusForUser($username, $plainPassword, $expiresAt, $rateLimit, $simultaneousUse, $tenantSchemaName, (string) $tenantId);

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
        $tenantId = $request->user()->tenant_id;

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $tenantSchemaName = (string) DB::table('public.tenants')
            ->where('id', $tenantId)
            ->value('schema_name');

        if ($tenantSchemaName === '') {
            Log::error('Failed to determine tenant schema for PPPoE user update', [
                'tenant_id' => $tenantId,
                'pppoe_user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Tenant schema is not available. Please contact support.',
            ], 500);
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
                ->where('tenant_id', $tenantId)
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
            DB::transaction(function () use ($pppoeUser, $tenantSchemaName, $tenantId) {
                DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($tenantSchemaName) . ', public');

                $pppoeUser->save();

                $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenantSchemaName, (string) $tenantId);
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID'],
                    ['op' => ':=', 'value' => (string) $tenantId, 'updated_at' => now(), 'created_at' => now()]
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

            event(new PppoeUserUpdated($pppoeUser, (string) $tenantId));

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
        $tenantId = $request->user()->tenant_id;

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $tenantSchemaName = (string) DB::table('public.tenants')
            ->where('id', $tenantId)
            ->value('schema_name');

        if ($tenantSchemaName === '') {
            Log::error('Failed to determine tenant schema for PPPoE user delete', [
                'tenant_id' => $tenantId,
                'pppoe_user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Tenant schema is not available. Please contact support.',
            ], 500);
        }

        try {
            $pppoeUserId = (string) $pppoeUser->id;
            $username = (string) $pppoeUser->username;

            DB::transaction(function () use ($pppoeUser, $tenantSchemaName) {
                DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($tenantSchemaName) . ', public');
                DB::table('radcheck')->where('username', $pppoeUser->username)->delete();
                DB::table('radreply')->where('username', $pppoeUser->username)->delete();
                $this->removeRadiusSchemaMapping((string) $pppoeUser->username);
                $pppoeUser->delete();
            });

            event(new PppoeUserDeleted($pppoeUserId, $username, (string) $tenantId));

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
        $tenantId = $request->user()->tenant_id;

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $tenantSchemaName = (string) DB::table('public.tenants')
            ->where('id', $tenantId)
            ->value('schema_name');

        if ($tenantSchemaName === '') {
            Log::error('Failed to determine tenant schema for PPPoE user block', [
                'tenant_id' => $tenantId,
                'pppoe_user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Tenant schema is not available. Please contact support.',
            ], 500);
        }

        try {
            DB::transaction(function () use ($pppoeUser, $tenantSchemaName) {
                DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($tenantSchemaName) . ', public');
                $pppoeUser->is_active = false;
                $pppoeUser->status = 'blocked';
                $pppoeUser->save();

                DB::table('radcheck')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Auth-Type'],
                    ['op' => ':=', 'value' => 'Reject', 'updated_at' => now(), 'created_at' => now()]
                );
            });

            event(new PppoeUserUpdated($pppoeUser, (string) $tenantId));

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
        $tenantId = $request->user()->tenant_id;

        $pppoeUser = PppoeUser::find($id);
        if (!$pppoeUser) {
            return response()->json([
                'success' => false,
                'message' => 'PPPoE user not found',
            ], 404);
        }

        $tenantSchemaName = (string) DB::table('public.tenants')
            ->where('id', $tenantId)
            ->value('schema_name');

        if ($tenantSchemaName === '') {
            Log::error('Failed to determine tenant schema for PPPoE user unblock', [
                'tenant_id' => $tenantId,
                'pppoe_user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Tenant schema is not available. Please contact support.',
            ], 500);
        }

        try {
            DB::transaction(function () use ($pppoeUser, $tenantSchemaName, $tenantId) {
                DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($tenantSchemaName) . ', public');
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

                $this->ensureRadiusSchemaMapping($pppoeUser->username, $tenantSchemaName, (string) $tenantId);
                DB::table('radreply')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Tenant-ID'],
                    ['op' => ':=', 'value' => (string) $tenantId, 'updated_at' => now(), 'created_at' => now()]
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

            event(new PppoeUserUpdated($pppoeUser, (string) $tenantId));

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

    private function syncRadiusForUser(string $username, string $plainPassword, $expiresAt, ?string $rateLimit, int $simultaneousUse, string $tenantSchemaName, string $tenantId): void
    {
        DB::statement('SET LOCAL search_path TO ' . $this->quoteSchemaName($tenantSchemaName) . ', public');

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
                'value' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : '',
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
        DB::table('public.radius_user_schema_mapping')->updateOrInsert(
            ['username' => $username],
            [
                'schema_name' => $schemaName,
                'tenant_id' => $tenantId,
                'user_role' => 'pppoe',
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function removeRadiusSchemaMapping(string $username): void
    {
        DB::table('public.radius_user_schema_mapping')
            ->where('username', $username)
            ->delete();
    }

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
                ['op' => ':=', 'value' => $expiresAt->format('Y-m-d H:i:s'), 'updated_at' => now(), 'created_at' => now()]
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
