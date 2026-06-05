<?php

namespace App\Http\Controllers\Api;

use App\Support\SafeRelativeTime;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterTenantMap;
use App\Models\Payment;
use App\Models\HotspotUser;
use App\Models\Tenant;
use App\Jobs\CreateHotspotUserJob;
use App\Services\TenantContext;
use App\Events\HotspotUserLoginAttempted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Controller for the tenant-branded Captive Portal.
 * 
 * This handles:
 * - Public package listing (no auth required)
 * - Hotspot user login validation
 * - Payment initiation for packages
 * - Voucher redemption
 * 
 * All endpoints are tenant-scoped via the router_id parameter.
 */
class CaptivePortalController extends Controller
{
    private TenantContext $tenantContext;

    public function __construct(TenantContext $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }

    /**
     * Get captive portal configuration and available packages.
     * 
     * PUBLIC endpoint - no authentication required.
     * Scoped to tenant via router_id from the MikroTik captive portal.
     */
    public function getPortalConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'router_id' => 'required|string',
            'mac' => 'nullable|string',
            'ip' => 'nullable|ip',
            'link_login' => 'nullable|url',
            'link_orig' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Find tenant via router_tenant_map (public schema lookup)
            $tenantId = RouterTenantMap::findTenantByRouterId($request->router_id);
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router not found',
                ], 404);
            }

            $tenant = Tenant::find($tenantId);
            if (!$tenant || !$tenant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 503);
            }

            $this->tenantContext->setTenant($tenant);

            // Now query router from tenant schema
            $router = Router::find($request->router_id);
            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Router not found',
                ], 404);
            }

            // Get available packages for this router
            $packages = Package::where('is_active', true)
                ->where('is_public', true)
                ->where('type', 'hotspot')
                ->where(function ($query) use ($router) {
                    $query->where('is_global', true)
                        ->orWhereHas('routers', function ($q) use ($router) {
                            $q->where('router_id', $router->id);
                        });
                })
                ->orderBy('price', 'asc')
                ->get([
                    'id', 'name', 'description', 'price', 'duration',
                    'upload_speed', 'download_speed', 'speed', 'data_limit',
                ]);

            // Get branding config
            $branding = $this->getTenantBranding($tenant);

            return response()->json([
                'success' => true,
                'data' => [
                    'tenant' => [
                        'name' => $tenant->company_name ?? $tenant->name,
                        'logo' => $branding['logo'],
                        'primary_color' => $branding['primary_color'],
                        'support_phone' => $branding['support_phone'],
                        'support_email' => $branding['support_email'],
                    ],
                    'router' => [
                        'id' => $router->id,
                        'name' => $router->name,
                        'location' => $router->location,
                    ],
                    'packages' => $packages,
                    'payment_methods' => $this->getPaymentMethods($tenant),
                    'client' => [
                        'mac' => $request->mac,
                        'ip' => $request->ip,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Captive portal config error', [
                'router_id' => $request->router_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load portal configuration',
            ], 500);
        }
    }

    /**
     * Validate Hotspot user login credentials.
     * 
     * Called by MikroTik RADIUS or direct login.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'router_id' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'mac' => 'nullable|string',
            'ip' => 'nullable|ip',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 422);
        }

        try {
            // Find tenant via router_tenant_map, then set context
            $tenantId = RouterTenantMap::findTenantByRouterId($request->router_id);
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 404);
            }

            $tenant = Tenant::find($tenantId);
            if (!$tenant || !$tenant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 503);
            }

            $this->tenantContext->setTenant($tenant);

            $router = Router::find($request->router_id);
            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 404);
            }

            // Find user
            // CRITICAL FIX: Grouped where prevents matching wrong user
            $user = HotspotUser::where(function ($query) use ($request) {
                $query->where('username', $request->username)
                    ->orWhere('voucher_code', $request->username);
            })->first();

            if (!$user) {
                $this->logLoginAttempt($tenant->id, $request->username, false, 'User not found', $request);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid username or password',
                ], 401);
            }

            // Verify password (check against radcheck or stored hash)
            if (!$this->verifyPassword($user, $request->password)) {
                $this->logLoginAttempt($tenant->id, $request->username, false, 'Invalid password', $request);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid username or password',
                ], 401);
            }

            // Check subscription status
            if (!$user->has_active_subscription) {
                $this->logLoginAttempt($tenant->id, $request->username, false, 'No active subscription', $request);
                return response()->json([
                    'success' => false,
                    'message' => 'Your subscription has expired. Please purchase a new package.',
                    'code' => 'SUBSCRIPTION_EXPIRED',
                ], 403);
            }

            // Check expiration
            if ($user->subscription_expires_at && $user->subscription_expires_at->isPast()) {
                $this->logLoginAttempt($tenant->id, $request->username, false, 'Subscription expired', $request);
                return response()->json([
                    'success' => false,
                    'message' => 'Your subscription has expired. Please purchase a new package.',
                    'code' => 'SUBSCRIPTION_EXPIRED',
                ], 403);
            }

            // Success
            $this->logLoginAttempt($tenant->id, $request->username, true, null, $request, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'username' => $user->username,
                    'package' => $user->package_name,
                    'expires_at' => $user->subscription_expires_at?->toIso8601String(),
                    'time_remaining' => $user->subscription_expires_at
                        ? SafeRelativeTime::until($user->subscription_expires_at)
                        : 'Unlimited',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Captive portal login error', [
                'username' => $request->username,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Initiate payment for a package.
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'router_id' => 'required|string',
            'package_id' => 'required|string',
            'phone' => 'required|string',
            'payment_method' => 'required|string|in:mpesa,voucher',
            'voucher_code' => 'required_if:payment_method,voucher|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Find tenant via router_tenant_map, then set context
            $tenantId = RouterTenantMap::findTenantByRouterId($request->router_id);
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 404);
            }

            $tenant = Tenant::find($tenantId);
            if (!$tenant || !$tenant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 503);
            }

            $this->tenantContext->setTenant($tenant);

            $router = Router::find($request->router_id);
            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 404);
            }

            // Get package
            $package = Package::where('id', $request->package_id)
                ->where('is_active', true)
                ->where('type', 'hotspot')
                ->first();

            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Package not found or not available',
                ], 404);
            }

            if ($request->payment_method === 'voucher') {
                return $this->redeemVoucher($request, $tenant, $router, $package);
            }

            // Create pending payment
            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'package_id' => $package->id,
                'amount' => $package->price,
                'phone' => $this->normalizePhone($request->phone),
                'payment_method' => $request->payment_method,
                'status' => 'pending',
                'reference' => 'HS' . strtoupper(uniqid()),
                'metadata' => [
                    'mac' => $request->mac,
                    'ip' => $request->ip,
                    'service_type' => 'hotspot',
                ],
            ]);

            // Trigger MPesa STK push (via existing payment service)
            // This will be handled by existing PaymentController logic

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated. Please complete the payment on your phone.',
                'data' => [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'amount' => $package->price,
                    'phone' => $payment->phone,
                    'status' => 'pending',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Payment initiation error', [
                'router_id' => $request->router_id,
                'package_id' => $request->package_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate payment',
            ], 500);
        }
    }

    /**
     * Check payment status.
     */
    public function checkPaymentStatus(Request $request, string $paymentId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'router_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request',
            ], 422);
        }

        try {
            // Find tenant via router_tenant_map, then set context
            $tenantId = RouterTenantMap::findTenantByRouterId($request->router_id);
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 404);
            }

            $tenant = Tenant::find($tenantId);
            if (!$tenant || !$tenant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 503);
            }

            $this->tenantContext->setTenant($tenant);

            $router = Router::find($request->router_id);
            if (!$router) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not available',
                ], 404);
            }

            $payment = Payment::where('id', $paymentId)
                ->where('router_id', $router->id)
                ->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found',
                ], 404);
            }

            $response = [
                'success' => true,
                'data' => [
                    'status' => $payment->status,
                    'reference' => $payment->reference,
                ],
            ];

            // If completed, include credentials
            if ($payment->status === 'completed' && $payment->hotspot_user_id) {
                $user = HotspotUser::find($payment->hotspot_user_id);
                if ($user) {
                    $response['data']['credentials'] = [
                        'username' => $user->username,
                        'expires_at' => $user->subscription_expires_at?->toIso8601String(),
                    ];
                }
            }

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status',
            ], 500);
        }
    }

    /**
     * Redeem a voucher code.
     */
    private function redeemVoucher(Request $request, Tenant $tenant, Router $router, Package $package): JsonResponse
    {
        $voucherCode = strtoupper(trim($request->voucher_code));

        // Find unused voucher
        $user = HotspotUser::where('voucher_code', $voucherCode)
            ->where('status', 'inactive')
            ->where('has_active_subscription', false)
            ->whereNull('first_used_at')
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or already used voucher code',
            ], 400);
        }

        // Activate the voucher
        DB::beginTransaction();
        try {
            $user->update([
                'status' => 'active',
                'has_active_subscription' => true,
                'subscription_starts_at' => now(),
                'subscription_expires_at' => $this->calculateExpiry($user->package),
                'first_used_at' => now(),
                'phone' => $this->normalizePhone($request->phone),
            ]);

            // Update RADIUS - remove block
            DB::table('radcheck')
                ->where('username', $user->username)
                ->where('attribute', 'Auth-Type')
                ->where('value', 'Reject')
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voucher redeemed successfully',
                'data' => [
                    'username' => $user->username,
                    'package' => $user->package_name,
                    'expires_at' => $user->subscription_expires_at?->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Voucher redemption error', [
                'voucher' => $voucherCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to redeem voucher',
            ], 500);
        }
    }

    private function getTenantBranding(Tenant $tenant): array
    {
        return [
            'logo' => $tenant->logo_url ?? null,
            'primary_color' => $tenant->primary_color ?? '#3B82F6',
            'support_phone' => $tenant->support_phone ?? null,
            'support_email' => $tenant->support_email ?? $tenant->email,
        ];
    }

    private function getPaymentMethods(Tenant $tenant): array
    {
        $methods = ['voucher'];
        
        // Check if MPesa is configured
        if ($tenant->mpesa_shortcode || config('services.mpesa.shortcode')) {
            $methods[] = 'mpesa';
        }
        
        return $methods;
    }

    private function verifyPassword(HotspotUser $user, string $password): bool
    {
        // Check radcheck table
        $storedPassword = DB::table('radcheck')
            ->where('username', $user->username)
            ->where('attribute', 'Cleartext-Password')
            ->value('value');

        if ($storedPassword && $storedPassword === $password) {
            return true;
        }

        // Check stored credential
        if ($user->credential) {
            return $user->credential->password === $password;
        }

        return false;
    }

    private function logLoginAttempt(
        string $tenantId,
        string $username,
        bool $success,
        ?string $reason,
        Request $request,
        ?string $userId = null
    ): void {
        broadcast(new HotspotUserLoginAttempted(
            $tenantId,
            $username,
            $success,
            $userId,
            $reason,
            $request->ip,
            $request->mac
        ))->toOthers();
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }
        if (!str_starts_with($phone, '254')) {
            $phone = '254' . $phone;
        }
        
        return $phone;
    }

    private function calculateExpiry(?Package $package): ?\Carbon\Carbon
    {
        if (!$package || !$package->duration) {
            return null;
        }
        
        $duration = strtolower(trim($package->duration));
        
        if (preg_match('/^(\d+)\s*h/i', $duration, $m)) {
            return now()->addHours((int) $m[1]);
        }
        if (preg_match('/^(\d+)\s*d/i', $duration, $m)) {
            return now()->addDays((int) $m[1]);
        }
        if (preg_match('/^(\d+)\s*w/i', $duration, $m)) {
            return now()->addWeeks((int) $m[1]);
        }
        if (preg_match('/^(\d+)\s*mo/i', $duration, $m)) {
            return now()->addMonths((int) $m[1]);
        }
        
        if (is_numeric($package->duration)) {
            return now()->addHours((int) $package->duration);
        }
        
        return now()->addDay();
    }
}
