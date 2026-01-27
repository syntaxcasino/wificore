<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Package;
use App\Models\Router;
use App\Models\Payment;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TenantDashboardController extends Controller
{
    /**
     * Get tenant dashboard statistics
     * SECURITY: Only returns data for authenticated user's tenant
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        // Verify user belongs to a tenant
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any tenant'
            ], 403);
        }

        // Cache key specific to this tenant
        $cacheKey = "tenant_{$tenantId}_dashboard_stats";

        $stats = Cache::remember($cacheKey, 60, function () use ($tenantId) {
            return [
                'users' => [
                    'total' => User::where('tenant_id', $tenantId)->count(),
                    'active' => User::where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->count(),
                    'online' => UserSession::where('tenant_id', $tenantId)
                        ->where('status', 'active')
                        ->distinct('user_id')
                        ->count('user_id'),
                ],
                'packages' => Package::where('tenant_id', $tenantId)
                    ->select('id', 'name', 'price', 'type', 'is_active')
                    ->get(),
                'routers' => Router::where('tenant_id', $tenantId)
                    ->select('id', 'name', 'ip_address', 'status', 'last_seen')
                    ->get(),
                'revenue' => [
                    'total' => Payment::where('tenant_id', $tenantId)
                        ->where('status', 'completed')
                        ->sum('amount'),
                    'monthly' => Payment::where('tenant_id', $tenantId)
                        ->where('status', 'completed')
                        ->whereMonth('created_at', now()->month)
                        ->sum('amount'),
                    'today' => Payment::where('tenant_id', $tenantId)
                        ->where('status', 'completed')
                        ->whereDate('created_at', now())
                        ->sum('amount'),
                ],
                'sessions' => [
                    'active' => UserSession::where('tenant_id', $tenantId)
                        ->where('status', 'active')
                        ->count(),
                    'today' => UserSession::where('tenant_id', $tenantId)
                        ->whereDate('created_at', now())
                        ->count(),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get tenant users
     * SECURITY: Only returns users for authenticated user's tenant
     */
    public function getUsers(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Global scope automatically filters by tenant_id
        $users = User::select('id', 'name', 'email', 'username', 'role', 'is_active', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Get tenant packages
     * SECURITY: Only returns packages for authenticated user's tenant
     */
    public function getPackages(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Global scope automatically filters by tenant_id
        $packages = Package::select('id', 'name', 'description', 'price', 'type', 'duration', 'is_active')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $packages
        ]);
    }

    /**
     * Get tenant routers
     * SECURITY: Only returns routers for authenticated user's tenant
     */
    public function getRouters(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Global scope automatically filters by tenant_id
        $routers = Router::select('id', 'name', 'ip_address', 'model', 'status', 'last_seen')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $routers
        ]);
    }

    /**
     * Get tenant payments/revenue
     * SECURITY: Only returns payments for authenticated user's tenant
     */
    public function getPayments(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $payments = Payment::where('tenant_id', $tenantId)
            ->with(['user:id,name,email', 'package:id,name'])
            ->select('id', 'user_id', 'package_id', 'amount', 'status', 'payment_method', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Get tenant active sessions
     * SECURITY: Only returns sessions for authenticated user's tenant
     */
    public function getSessions(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $sessions = UserSession::where('tenant_id', $tenantId)
            ->with(['user:id,name,username', 'router:id,name'])
            ->where('status', 'active')
            ->select('id', 'user_id', 'router_id', 'ip_address', 'mac_address', 'upload_bytes', 'download_bytes', 'started_at')
            ->orderBy('started_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $sessions
        ]);
    }
}
