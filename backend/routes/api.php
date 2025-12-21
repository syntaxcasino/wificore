<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\RouterController;
use App\Http\Controllers\Api\ProvisioningController;
use App\Http\Controllers\Api\RouterStatusController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\UnifiedAuthController;
use App\Http\Controllers\Api\TenantRegistrationController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\HotspotController;
use App\Http\Controllers\Api\RouterVpnController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\QueueStatsController;
use App\Http\Controllers\Api\CacheController;
use App\Http\Controllers\Api\MetricsController;
// NEW: Service Management & Access Point Controllers
use App\Http\Controllers\Api\RouterServiceController;
use App\Http\Controllers\Api\AccessPointController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\SystemAdminController;
use App\Http\Controllers\Api\TenantDashboardController;
use App\Http\Controllers\Api\PublicPackageController;
use App\Http\Controllers\Api\PublicTenantController;
use App\Http\Controllers\Api\EnvironmentHealthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Api\TodoController;
// HR Module Controllers
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\EmployeeController;
// Finance Module Controllers
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\RevenueController;
use App\Events\TestWebSocketEvent;

/*
|--------------------------------------------------------------------------
| API Routes - WiFi Hotspot Management System
|--------------------------------------------------------------------------
|
| Route Organization:
| 1. Public Routes (No authentication)
| 2. Shared Authenticated Routes (All users)
| 3. Admin-Only Routes (role:admin)
| 4. Hotspot User Routes (role:hotspot_user)
|
*/

// =============================================================================
// BROADCASTING AUTH - Sanctum-based authentication for WebSocket channels
// =============================================================================
Route::post('/broadcasting/auth', function (Request $request) {
    // Authenticate using Sanctum token from Authorization header
    $user = $request->user('sanctum');
    
    if (!$user) {
        \Log::warning('Broadcasting auth failed - no authenticated user', [
            'has_auth_header' => $request->hasHeader('Authorization'),
            'channel' => $request->input('channel_name'),
            'socket_id' => $request->input('socket_id'),
        ]);
        return response()->json(['message' => 'Unauthenticated'], 403);
    }
    
    \Log::info('Broadcasting auth successful', [
        'user_id' => $user->id,
        'channel' => $request->input('channel_name'),
        'socket_id' => $request->input('socket_id'),
    ]);
    
    // Set the authenticated user on the request for Broadcast::auth()
    // This ensures the channel authorization callbacks receive the correct user
    $request->setUserResolver(function () use ($user) {
        return $user;
    });
    
    // Use Laravel's broadcasting auth with the authenticated user
    return Broadcast::auth($request);
})->middleware('auth:sanctum');

// =============================================================================
// TEST ROUTES - For WebSocket testing
// =============================================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/test/websocket', function (\Illuminate\Http\Request $request) {
        $message = $request->input('message', 'Test WebSocket message');
        event(new TestWebSocketEvent($message));
        return response()->json(['status' => 'Event dispatched', 'message' => $message]);
    });
});

// =============================================================================
// PUBLIC ROUTES - No authentication required
// =============================================================================

// Public Packages - Tenant-specific packages for hotspot users
Route::get('/public/packages', [PublicPackageController::class, 'getPublicPackages'])
    ->name('api.public.packages');

Route::post('/public/set-tenant', [PublicPackageController::class, 'setTenantSession'])
    ->name('api.public.set-tenant');

// Public Tenant Routes - Subdomain-based tenant identification and packages
Route::get('/public/tenant/{subdomain}', [PublicTenantController::class, 'getTenantBySubdomain'])
    ->name('api.public.tenant.show');

Route::get('/public/tenant/{subdomain}/packages', [PublicTenantController::class, 'getPublicPackages'])
    ->name('api.public.tenant.packages');

Route::get('/public/tenant-by-domain', [PublicTenantController::class, 'getTenantByDomain'])
    ->name('api.public.tenant.by-domain');

Route::post('/public/subdomain/check', [PublicTenantController::class, 'checkSubdomainAvailability'])
    ->name('api.public.subdomain.check');

// Unified Login - Public route for authentication (system admin, tenant admin, hotspot users)
Route::post('/login', [UnifiedAuthController::class, 'login'])
    ->name('api.login');

// Tenant Registration - Public route for tenant and admin creation
Route::prefix('register')->group(function () {
    Route::post('/tenant', [TenantRegistrationController::class, 'register'])
        ->name('api.register.tenant');
    Route::get('/verify/{token}', [TenantRegistrationController::class, 'verifyEmail'])
        ->name('api.register.verify');
    Route::get('/status/{token}', [TenantRegistrationController::class, 'getStatus'])
        ->name('api.register.status');
    Route::post('/resend', [TenantRegistrationController::class, 'resendVerification'])
        ->name('api.register.resend');
});

// Legacy hotspot user registration (kept for backward compatibility)
Route::post('/register', [LoginController::class, 'register'])
    ->name('api.register.legacy');


// Resend Verification Email
Route::post('/email/resend', [LoginController::class, 'resendVerification'])
    ->name('verification.resend');

// Packages - Public viewing for hotspot users (uses PublicPackageController for proper tenant detection)
// This route is for unauthenticated hotspot users, NOT for tenant dashboard
// Tenant dashboard should use /api/packages (authenticated route)
Route::get('/packages', [PublicPackageController::class, 'getPublicPackages'])
    ->name('api.public.packages.list');

// Payment Initiation - Public for hotspot users
Route::post('/payments/initiate', [PaymentController::class, 'initiateSTK'])
    ->name('api.payments.initiate');

// Payment Status Check - For auto-login
Route::get('/payments/{payment}/status', [PaymentController::class, 'checkStatus'])
    ->name('api.payments.status');

// M-Pesa Callback - Webhook endpoint
Route::post('/mpesa/callback', [PaymentController::class, 'callback'])
    ->name('api.mpesa.callback');

// Hotspot User Login - Public for hotspot users
Route::post('/hotspot/login', [HotspotController::class, 'login'])
    ->name('api.hotspot.login');

// Hotspot User Logout - Public
Route::post('/hotspot/logout', [HotspotController::class, 'logout'])
    ->name('api.hotspot.logout');

// Hotspot Session Check - Public
Route::post('/hotspot/check-session', [HotspotController::class, 'checkSession'])
    ->name('api.hotspot.check-session');

// Health Check - Public (for monitoring/uptime)
Route::get('/health/ping', [HealthController::class, 'ping'])
    ->name('api.health.ping');

// =============================================================================
// SHARED AUTHENTICATED ROUTES - All authenticated users
// =============================================================================

Route::middleware(['auth:sanctum', 'user.active', 'tenant.context'])->group(function () {
    
    // Logout - Unified for all user types
    Route::post('/logout', [UnifiedAuthController::class, 'logout'])
        ->name('api.logout');
    
    // Get Current User - Unified endpoint
    Route::get('/me', [UnifiedAuthController::class, 'me'])
        ->name('api.me');
    
    // User Profile (legacy endpoint)
    Route::get('/profile', function (Request $request) {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load('activeSubscription', 'tenant'),
        ]);
    })->name('api.profile');
    
    // Current Tenant Info
    Route::get('/tenant/current', [TenantController::class, 'current'])
        ->name('api.tenant.current');
});

// =============================================================================
// SYSTEM ADMIN ROUTES - Platform Administrator Only
// =============================================================================

Route::middleware(['auth:sanctum', 'system.admin'])->prefix('system')->name('api.system.')->group(function () {
    
    // -------------------------------------------------------------------------
    // Environment Health Monitoring (System Admin Only)
    // -------------------------------------------------------------------------
    Route::get('/health/status', [EnvironmentHealthController::class, 'getHealthStatus'])
        ->name('health.status');
    Route::get('/health/database', [EnvironmentHealthController::class, 'getDatabaseMetrics'])
        ->name('health.database');
    Route::get('/health/performance', [EnvironmentHealthController::class, 'getPerformanceMetrics'])
        ->name('health.performance');
    Route::get('/health/cache', [EnvironmentHealthController::class, 'getCacheStats'])
        ->name('health.cache');
    
    // -------------------------------------------------------------------------
    // System Administrator Management
    // -------------------------------------------------------------------------
    Route::prefix('admins')->name('admins.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SystemUserManagementController::class, 'listSystemAdmins'])
            ->name('list');
        Route::post('/', [\App\Http\Controllers\Api\SystemUserManagementController::class, 'createSystemAdmin'])
            ->name('create');
        Route::put('/{id}', [\App\Http\Controllers\Api\SystemUserManagementController::class, 'updateSystemAdmin'])
            ->name('update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\SystemUserManagementController::class, 'deleteSystemAdmin'])
            ->name('delete');
    });
    
    // -------------------------------------------------------------------------
    // Tenant Management (Full Access)
    // -------------------------------------------------------------------------
    Route::prefix('tenants')->name('tenants.')->group(function () {
        Route::get('/', [TenantController::class, 'index'])->name('index');
        Route::post('/', [TenantController::class, 'store'])->name('store');
        Route::get('/{tenant}', [TenantController::class, 'show'])->name('show');
        Route::put('/{tenant}', [TenantController::class, 'update'])->name('update');
        Route::delete('/{tenant}', [TenantController::class, 'destroy'])->name('destroy');
        Route::post('/{tenant}/suspend', [TenantController::class, 'suspend'])->name('suspend');
        Route::post('/{tenant}/activate', [TenantController::class, 'activate'])->name('activate');
    });
    
    // -------------------------------------------------------------------------
    // System Dashboard & Statistics
    // -------------------------------------------------------------------------
    Route::get('/dashboard', [SystemAdminController::class, 'getDashboardStats'])
        ->name('dashboard');
    Route::get('/dashboard/stats', [SystemAdminController::class, 'getDashboardStats'])
        ->name('dashboard.stats');
    
    // -------------------------------------------------------------------------
    // System Metrics & Monitoring
    // -------------------------------------------------------------------------
    Route::get('/health', [SystemAdminController::class, 'getSystemHealth'])
        ->name('health');
    Route::get('/metrics', [\App\Http\Controllers\Api\SystemMetricsController::class, 'getMetrics'])
        ->name('metrics');
    Route::get('/queue/stats', [\App\Http\Controllers\Api\SystemMetricsController::class, 'getQueueStats'])
        ->name('queue.stats');
    Route::get('/queue/historical', [\App\Http\Controllers\Api\SystemMetricsController::class, 'getHistoricalQueueMetrics'])
        ->name('queue.historical');
    Route::post('/queue/retry-failed', [\App\Http\Controllers\Api\SystemMetricsController::class, 'retryFailedJobs'])
        ->name('queue.retry-failed');
    
    // -------------------------------------------------------------------------
    // Activity Logs
    // -------------------------------------------------------------------------
    Route::get('/activity-logs', [SystemAdminController::class, 'getActivityLogs'])
        ->name('activity-logs');
});

// =============================================================================
// ADMIN-ONLY ROUTES - Requires role:admin (Tenant Administrators)
// =============================================================================

Route::middleware(['auth:sanctum', 'role:admin', 'user.active', 'tenant.context'])->group(function () {
    
    // -------------------------------------------------------------------------
    // Tenant User Management
    // -------------------------------------------------------------------------
    Route::prefix('users')->name('api.users.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\TenantUserManagementController::class, 'listUsers'])
            ->name('list');
        Route::post('/', [\App\Http\Controllers\Api\TenantUserManagementController::class, 'createUser'])
            ->name('create');
        Route::put('/{id}', [\App\Http\Controllers\Api\TenantUserManagementController::class, 'updateUser'])
            ->name('update');
        Route::delete('/{id}', [\App\Http\Controllers\Api\TenantUserManagementController::class, 'deleteUser'])
            ->name('delete');
    });
    
    // -------------------------------------------------------------------------
    // Dashboard Statistics
    // -------------------------------------------------------------------------
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats'])
        ->name('api.dashboard.stats');
    Route::post('/dashboard/refresh', [DashboardController::class, 'refreshStats'])
        ->name('api.dashboard.refresh');
    
    // -------------------------------------------------------------------------
    // Todo Management
    // -------------------------------------------------------------------------
    Route::prefix('todos')->name('api.todos.')->group(function () {
        Route::get('/', [TodoController::class, 'index'])->name('index');
        Route::post('/', [TodoController::class, 'store'])->name('store');
        Route::get('/statistics', [TodoController::class, 'statistics'])->name('statistics');
        Route::get('/{id}', [TodoController::class, 'show'])->name('show');
        Route::put('/{id}', [TodoController::class, 'update'])->name('update');
        Route::delete('/{id}', [TodoController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/complete', [TodoController::class, 'markAsCompleted'])->name('complete');
        Route::post('/{id}/assign', [TodoController::class, 'assign'])->name('assign');
        Route::get('/{id}/activities', [TodoController::class, 'activities'])->name('activities');
    });
    
    // -------------------------------------------------------------------------
    // HR Module - Departments
    // -------------------------------------------------------------------------
    Route::prefix('departments')->name('api.departments.')->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])->name('index');
        Route::post('/', [DepartmentController::class, 'store'])->name('store');
        Route::get('/statistics', [DepartmentController::class, 'statistics'])->name('statistics');
        Route::get('/{id}', [DepartmentController::class, 'show'])->name('show');
        Route::put('/{id}', [DepartmentController::class, 'update'])->name('update');
        Route::delete('/{id}', [DepartmentController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/approve', [DepartmentController::class, 'approve'])->name('approve');
    });
    
    // -------------------------------------------------------------------------
    // HR Module - Positions
    // -------------------------------------------------------------------------
    Route::prefix('positions')->name('api.positions.')->group(function () {
        Route::get('/', [PositionController::class, 'index'])->name('index');
        Route::post('/', [PositionController::class, 'store'])->name('store');
        Route::get('/statistics', [PositionController::class, 'statistics'])->name('statistics');
        Route::get('/{id}', [PositionController::class, 'show'])->name('show');
        Route::put('/{id}', [PositionController::class, 'update'])->name('update');
        Route::delete('/{id}', [PositionController::class, 'destroy'])->name('destroy');
    });
    
    // -------------------------------------------------------------------------
    // HR Module - Employees
    // -------------------------------------------------------------------------
    Route::prefix('employees')->name('api.employees.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::get('/statistics', [EmployeeController::class, 'statistics'])->name('statistics');
        Route::get('/{id}', [EmployeeController::class, 'show'])->name('show');
        Route::put('/{id}', [EmployeeController::class, 'update'])->name('update');
        Route::delete('/{id}', [EmployeeController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/terminate', [EmployeeController::class, 'terminate'])->name('terminate');
    });
    
    // -------------------------------------------------------------------------
    // Finance Module - Expenses
    // -------------------------------------------------------------------------
    Route::prefix('expenses')->name('api.expenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');
        Route::get('/statistics', [ExpenseController::class, 'statistics'])->name('statistics');
        Route::get('/{id}', [ExpenseController::class, 'show'])->name('show');
        Route::put('/{id}', [ExpenseController::class, 'update'])->name('update');
        Route::delete('/{id}', [ExpenseController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/approve', [ExpenseController::class, 'approve'])->name('approve');
        Route::post('/{id}/reject', [ExpenseController::class, 'reject'])->name('reject');
        Route::post('/{id}/mark-as-paid', [ExpenseController::class, 'markAsPaid'])->name('mark-as-paid');
    });
    
    // -------------------------------------------------------------------------
    // Finance Module - Revenues
    // -------------------------------------------------------------------------
    Route::prefix('revenues')->name('api.revenues.')->group(function () {
        Route::get('/', [RevenueController::class, 'index'])->name('index');
        Route::post('/', [RevenueController::class, 'store'])->name('store');
        Route::get('/statistics', [RevenueController::class, 'statistics'])->name('statistics');
        Route::get('/{id}', [RevenueController::class, 'show'])->name('show');
        Route::put('/{id}', [RevenueController::class, 'update'])->name('update');
        Route::delete('/{id}', [RevenueController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/confirm', [RevenueController::class, 'confirm'])->name('confirm');
        Route::post('/{id}/cancel', [RevenueController::class, 'cancel'])->name('cancel');
    });
    
    // -------------------------------------------------------------------------
    // Health Check - Admin Only
    // -------------------------------------------------------------------------
    Route::prefix('health')->name('api.health.')->group(function () {
        Route::get('/', [HealthController::class, 'index'])->name('index');
        Route::get('/routers', [HealthController::class, 'routers'])->name('routers');
        Route::get('/database', [HealthController::class, 'database'])->name('database');
        Route::get('/security', [HealthController::class, 'security'])->name('security');
    });
    
    // Queue Statistics - Admin Only
    // -------------------------------------------------------------------------
    Route::prefix('queue')->name('api.queue.')->group(function () {
        Route::get('/stats', [QueueStatsController::class, 'index'])->name('stats');
        Route::post('/increment-processed', [QueueStatsController::class, 'incrementProcessed'])->name('increment');
    });
    
    // -------------------------------------------------------------------------
    // Cache Management - Admin Only
    // -------------------------------------------------------------------------
    Route::prefix('cache')->name('api.cache.')->group(function () {
        Route::get('/stats', [CacheController::class, 'stats'])->name('stats');
        Route::post('/warmup', [CacheController::class, 'warmup'])->name('warmup');
        Route::post('/clear', [CacheController::class, 'clear'])->name('clear');
        Route::post('/clear-pattern', [CacheController::class, 'clearPattern'])->name('clear-pattern');
    });
    
    // -------------------------------------------------------------------------
    // Performance Metrics - Admin Only
    // -------------------------------------------------------------------------
    Route::prefix('metrics')->name('api.metrics.')->group(function () {
        Route::get('/', [MetricsController::class, 'index'])->name('index');
        Route::get('/layout', [MetricsController::class, 'layout'])->name('layout');
        Route::get('/tps', [MetricsController::class, 'tps'])->name('tps');
        Route::get('/ops', [MetricsController::class, 'ops'])->name('ops');
        Route::get('/historical', [MetricsController::class, 'historical'])->name('historical');
        Route::get('/summary', [MetricsController::class, 'summary'])->name('summary');
    });
    
    // -------------------------------------------------------------------------
    // Package Management
    // -------------------------------------------------------------------------
    Route::get('/packages', [PackageController::class, 'index'])
        ->name('api.packages.index');
    Route::get('/packages/{package}', [PackageController::class, 'show'])
        ->name('api.packages.show');
    Route::post('/packages', [PackageController::class, 'store'])
        ->name('api.packages.store');
    Route::put('/packages/{package}', [PackageController::class, 'update'])
        ->name('api.packages.update');
    Route::delete('/packages/{package}', [PackageController::class, 'destroy'])
        ->name('api.packages.destroy');
    
    // -------------------------------------------------------------------------
    // System Logs
    // -------------------------------------------------------------------------
    Route::get('/logs', [LogController::class, 'index'])
        ->name('api.logs.index');
    
    // -------------------------------------------------------------------------
    // Router Management
    // -------------------------------------------------------------------------
    Route::prefix('routers')->name('api.routers.')->group(function () {
        // CRUD Operations
        Route::get('/', [RouterController::class, 'index'])->name('index');
        Route::post('/', [RouterController::class, 'store'])->name('store');
        Route::get('/{router}', [RouterController::class, 'show'])->name('show');
        Route::put('/{router}', [RouterController::class, 'update'])->name('update');
        Route::delete('/{router}', [RouterController::class, 'destroy'])->name('destroy');
        
        // Router Status & Details
        Route::get('/{router}/status', [RouterController::class, 'status'])->name('status');
        Route::get('/{router}/details', [RouterController::class, 'getRouterDetails'])->name('details');
        
        // Router Analytics & Revenue
        Route::get('/{router}/analytics', [\App\Http\Controllers\Api\RouterAnalyticsController::class, 'getRouterDetails'])->name('analytics');
        Route::get('/revenue/all', [\App\Http\Controllers\Api\RouterAnalyticsController::class, 'getRouterRevenue'])->name('revenue.all');
        Route::post('/revenue/compare', [\App\Http\Controllers\Api\RouterAnalyticsController::class, 'compareRouters'])->name('revenue.compare');
        
        // Configuration Management
        Route::post('/{router}/configure', [RouterController::class, 'configure'])->name('configure');
        Route::post('/{router}/apply-configs', [RouterController::class, 'applyConfigs'])->name('apply-configs');
        
        // Connectivity & Verification
        Route::get('/{router}/verify-connectivity', [RouterController::class, 'verifyConnectivity'])->name('verify-connectivity');
        
        // Firmware Management
        Route::post('/{router}/update-firmware', [RouterController::class, 'updateFirmware'])->name('update-firmware');

        // =========================================================================
        // MULTI-STAGE ROUTER PROVISIONING ROUTES
        // =========================================================================

        // Stage 1: Create router with initial config (auto-starts probing)
        Route::post('/create-with-config', [RouterController::class, 'createRouterWithConfig'])->name('create-with-config');

        // Stage 2: Start router probing (optional manual trigger)
        Route::post('/{router}/start-probing', [RouterController::class, 'startRouterProbing'])->name('start-probing');

        // Stage 3: Get router interfaces once connected
        Route::get('/{router}/interfaces', [RouterController::class, 'getRouterInterfaces'])->name('interfaces');

        // Stage 4: Generate service configuration
        Route::post('/{router}/generate-service-config', [RouterController::class, 'generateServiceConfig'])->name('generate-service-config');

        // Stage 5: Deploy service configuration
        Route::post('/{router}/deploy-service-config', [RouterController::class, 'deployServiceConfig'])->name('deploy-service-config');

        // Get provisioning status
        Route::get('/{router}/provisioning-status', [RouterController::class, 'getProvisioningStatus'])->name('provisioning-status');

        // Reset provisioning for reprovisioning
        Route::post('/{router}/reset-provisioning', [RouterController::class, 'resetProvisioning'])->name('reset-provisioning');

        // =========================================================================
        // NEW: SERVICE MANAGEMENT ROUTES
        // =========================================================================
        
        // Service Management
        Route::get('/{router}/services', [RouterServiceController::class, 'index'])->name('services.index');
        Route::post('/{router}/services', [RouterServiceController::class, 'store'])->name('services.store');
        Route::get('/{router}/services/{service}', [RouterServiceController::class, 'show'])->name('services.show');
        Route::put('/{router}/services/{service}', [RouterServiceController::class, 'update'])->name('services.update');
        Route::delete('/{router}/services/{service}', [RouterServiceController::class, 'destroy'])->name('services.destroy');
    });
    
    // -------------------------------------------------------------------------
    // VPN Configuration Management (WireGuard/IPsec)
    // -------------------------------------------------------------------------
    Route::prefix('vpn')->name('api.vpn.')->group(function () {
        // List all VPN configurations for tenant
        Route::get('/', [\App\Http\Controllers\Api\VpnConfigurationController::class, 'index'])->name('index');
        
        // Create new VPN configuration
        Route::post('/', [\App\Http\Controllers\Api\VpnConfigurationController::class, 'store'])->name('store');
        
        // Get specific VPN configuration
        Route::get('/{id}', [\App\Http\Controllers\Api\VpnConfigurationController::class, 'show'])->name('show');
        
        // Download configuration scripts
        Route::get('/{id}/download/mikrotik', [\App\Http\Controllers\Api\VpnConfigurationController::class, 'downloadMikrotikScript'])->name('download.mikrotik');
        Route::get('/{id}/download/linux', [\App\Http\Controllers\Api\VpnConfigurationController::class, 'downloadLinuxConfig'])->name('download.linux');
        
        // Delete VPN configuration
        Route::delete('/{id}', [\App\Http\Controllers\Api\VpnConfigurationController::class, 'destroy'])->name('destroy');
        
        // Get tenant subnet information
        Route::get('/subnet/info', [\App\Http\Controllers\Api\VpnConfigurationController::class, 'getSubnetInfo'])->name('subnet.info');
    });
    
    // -------------------------------------------------------------------------
    // Router Service Management (continued from routers group)
    // -------------------------------------------------------------------------
    Route::prefix('routers/{router}')->name('api.routers.')->group(function () {
        // Service Actions
        Route::post('/services/{service}/start', [RouterServiceController::class, 'start'])->name('services.start');
        Route::post('/services/{service}/stop', [RouterServiceController::class, 'stop'])->name('services.stop');
        Route::post('/services/{service}/restart', [RouterServiceController::class, 'restart'])->name('services.restart');
        Route::post('/services/sync', [RouterServiceController::class, 'sync'])->name('services.sync');
        
        // Interface Management
        Route::get('/interfaces/available', [RouterServiceController::class, 'interfaces'])->name('interfaces.available');

        // =========================================================================
        // NEW: ACCESS POINT MANAGEMENT ROUTES
        // =========================================================================
        
        // Access Point Management
        Route::get('/access-points', [AccessPointController::class, 'index'])->name('access-points.index');
        Route::post('/access-points', [AccessPointController::class, 'store'])->name('access-points.store');
        Route::post('/access-points/discover', [AccessPointController::class, 'discover'])->name('access-points.discover');
    });

    // -------------------------------------------------------------------------
    // Router Provisioning
    // -------------------------------------------------------------------------
    Route::prefix('provisioning')->name('api.provisioning.')->group(function () {
        Route::post('/configs', [ProvisioningController::class, 'saveConfigs'])->name('save-configs');
        Route::get('/configs', [ProvisioningController::class, 'getConfigs'])->name('get-configs');
        Route::post('/interfaces', [ProvisioningController::class, 'fetchInterfaces'])->name('fetch-interfaces');
        Route::post('/apply', [ProvisioningController::class, 'applyConfigs'])->name('apply');
    });
    
    Route::apiResource('router-configs', ProvisioningController::class)
        ->only(['index', 'store', 'update'])
        ->names('api.router-configs');

    // -------------------------------------------------------------------------
    // Router Status Monitoring
    // -------------------------------------------------------------------------
    Route::get('/router-status', [RouterStatusController::class, 'getStatus'])
        ->name('api.router-status');

    // =========================================================================
    // NEW: STANDALONE ACCESS POINT ROUTES
    // =========================================================================
    Route::prefix('access-points')->name('api.access-points.')->group(function () {
        // Get specific access point
        Route::get('/{accessPoint}', [AccessPointController::class, 'show'])->name('show');
        Route::put('/{accessPoint}', [AccessPointController::class, 'update'])->name('update');
        Route::delete('/{accessPoint}', [AccessPointController::class, 'destroy'])->name('destroy');
        
        // Access point actions
        Route::get('/{accessPoint}/sessions', [AccessPointController::class, 'sessions'])->name('sessions');
        Route::get('/{accessPoint}/statistics', [AccessPointController::class, 'statistics'])->name('statistics');
        Route::post('/{accessPoint}/sync', [AccessPointController::class, 'sync'])->name('sync');
    });
    
    // -------------------------------------------------------------------------
    // User Management
    // -------------------------------------------------------------------------
    Route::prefix('users')->name('api.users.')->group(function () {
        // List all users
        Route::get('/', function (Request $request) {
            $users = \App\Models\User::with('activeSubscription')
                ->when($request->role, fn($q, $role) => $q->where('role', $role))
                ->when($request->search, fn($q, $search) => 
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone_number', 'like', "%{$search}%")
                )
                ->latest()
                ->paginate($request->per_page ?? 20);
            
            return response()->json(['success' => true, 'users' => $users]);
        })->name('index');
        
        // Get specific user
        Route::get('/{user}', function (\App\Models\User $user) {
            return response()->json([
                'success' => true,
                'user' => $user->load(['activeSubscription', 'subscriptions', 'payments'])
            ]);
        })->name('show');
        
        // Deactivate user
        Route::put('/{user}/deactivate', function (\App\Models\User $user) {
            $user->is_active = false;
            $user->save();
            $user->tokens()->delete(); // Revoke all tokens
            
            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully'
            ]);
        })->name('deactivate');
        
        // Activate user
        Route::put('/{user}/activate', function (\App\Models\User $user) {
            $user->is_active = true;
            $user->save();
            
            return response()->json([
                'success' => true,
                'message' => 'User activated successfully'
            ]);
        })->name('activate');
        
        // Update user balance
        Route::post('/{user}/balance', function (Request $request, \App\Models\User $user) {
            $request->validate([
                'amount' => 'required|numeric|min:0',
                'action' => 'required|in:add,deduct,set',
            ]);
            
            switch ($request->action) {
                case 'add':
                    $user->addBalance($request->amount);
                    break;
                case 'deduct':
                    $user->deductBalance($request->amount);
                    break;
                case 'set':
                    $user->account_balance = $request->amount;
                    $user->save();
                    break;
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Balance updated successfully',
                'new_balance' => $user->fresh()->account_balance
            ]);
        })->name('balance');
    });
    
    // -------------------------------------------------------------------------
    // Payment Management
    // -------------------------------------------------------------------------
    Route::prefix('payments')->name('api.payments.')->group(function () {
        // List all payments
        Route::get('/', function (Request $request) {
            $payments = \App\Models\Payment::with(['user', 'package'])
                ->when($request->status, fn($q, $status) => $q->where('status', $status))
                ->when($request->payment_method, fn($q, $method) => $q->where('payment_method', $method))
                ->when($request->search, fn($q, $search) => 
                    $q->where('phone_number', 'like', "%{$search}%")
                      ->orWhere('transaction_id', 'like', "%{$search}%")
                )
                ->latest()
                ->paginate($request->per_page ?? 20);
            
            return response()->json(['success' => true, 'payments' => $payments]);
        })->name('admin-index');
        
        // Get specific payment
        Route::get('/{payment}', function (\App\Models\Payment $payment) {
            return response()->json([
                'success' => true,
                'payment' => $payment->load(['user', 'package', 'router'])
            ]);
        })->name('show');
        
        // Payment statistics
        Route::get('/stats/summary', function (Request $request) {
            $stats = [
                'total_payments' => \App\Models\Payment::count(),
                'completed_payments' => \App\Models\Payment::where('status', 'completed')->count(),
                'pending_payments' => \App\Models\Payment::where('status', 'pending')->count(),
                'failed_payments' => \App\Models\Payment::where('status', 'failed')->count(),
                'total_revenue' => \App\Models\Payment::where('status', 'completed')->sum('amount'),
                'today_revenue' => \App\Models\Payment::where('status', 'completed')
                    ->whereDate('created_at', today())->sum('amount'),
                'this_month_revenue' => \App\Models\Payment::where('status', 'completed')
                    ->whereMonth('created_at', now()->month)->sum('amount'),
            ];
            
            return response()->json(['success' => true, 'stats' => $stats]);
        })->name('stats');
    });
    
    // -------------------------------------------------------------------------
    // Subscription Management
    // -------------------------------------------------------------------------
    Route::prefix('subscriptions')->name('api.subscriptions.')->group(function () {
        // List all subscriptions
        Route::get('/', function (Request $request) {
            $subscriptions = \App\Models\UserSubscription::with(['user', 'package', 'payment'])
                ->when($request->status, fn($q, $status) => $q->where('status', $status))
                ->when($request->search, fn($q, $search) => 
                    $q->whereHas('user', fn($q) => 
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('phone_number', 'like', "%{$search}%")
                    )
                )
                ->latest()
                ->paginate($request->per_page ?? 20);
            
            return response()->json(['success' => true, 'subscriptions' => $subscriptions]);
        })->name('admin-index');
        
        // Get specific subscription
        Route::get('/{subscription}', function (\App\Models\UserSubscription $subscription) {
            return response()->json([
                'success' => true,
                'subscription' => $subscription->load(['user', 'package', 'payment'])
            ]);
        })->name('show');
        
        // Suspend subscription
        Route::post('/{subscription}/suspend', function (\App\Models\UserSubscription $subscription) {
            $subscription->suspend();
            
            return response()->json([
                'success' => true,
                'message' => 'Subscription suspended successfully'
            ]);
        })->name('suspend');
        
        // Activate subscription
        Route::post('/{subscription}/activate', function (\App\Models\UserSubscription $subscription) {
            $subscription->activate();
            
            return response()->json([
                'success' => true,
                'message' => 'Subscription activated successfully'
            ]);
        })->name('activate');
        
        // Subscription statistics
        Route::get('/stats/summary', function () {
            $stats = [
                'total_subscriptions' => \App\Models\UserSubscription::count(),
                'active_subscriptions' => \App\Models\UserSubscription::where('status', 'active')->count(),
                'expired_subscriptions' => \App\Models\UserSubscription::where('status', 'expired')->count(),
                'suspended_subscriptions' => \App\Models\UserSubscription::where('status', 'suspended')->count(),
            ];
            
            return response()->json(['success' => true, 'stats' => $stats]);
        })->name('stats');
    });
});

// =============================================================================
// HOTSPOT USER ROUTES - Requires role:hotspot_user
// =============================================================================

Route::middleware(['auth:sanctum', 'role:hotspot_user', 'user.active', 'tenant.context'])->group(function () {
    
    // -------------------------------------------------------------------------
    // Package Purchase
    // -------------------------------------------------------------------------
    Route::post('/purchase', [PurchaseController::class, 'purchaseWithBalance'])
        ->name('api.purchase');
    
    // -------------------------------------------------------------------------
    // Subscription Management
    // -------------------------------------------------------------------------
    Route::get('/my-subscription', [PurchaseController::class, 'getMySubscription'])
        ->name('api.my-subscription');
    
    Route::get('/my-history', [PurchaseController::class, 'getMyHistory'])
        ->name('api.my-history');
    
    // -------------------------------------------------------------------------
    // Usage Statistics
    // -------------------------------------------------------------------------
    Route::get('/my-usage', [PurchaseController::class, 'getMyUsage'])
        ->name('api.my-usage');
    
    // -------------------------------------------------------------------------
    // Account Balance
    // -------------------------------------------------------------------------
    Route::post('/account/topup', [PurchaseController::class, 'topUpBalance'])
        ->name('api.account.topup');
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// =============================================================================
// ROUTER VPN MANAGEMENT ROUTES
// =============================================================================
Route::middleware('auth:sanctum')->prefix('routers/{router}')->group(function () {
    // Create VPN configuration
    Route::post('/vpn', [RouterVpnController::class, 'createVpnConfig'])
        ->name('api.routers.vpn.create');
    
    // Get MikroTik configuration script
    Route::get('/vpn/script', [RouterVpnController::class, 'getMikroTikScript'])
        ->name('api.routers.vpn.script');
    
    // Download MikroTik configuration script
    Route::get('/vpn/script/download', [RouterVpnController::class, 'downloadScript'])
        ->name('api.routers.vpn.script.download');
    
    // Get VPN status
    Route::get('/vpn/status', [RouterVpnController::class, 'getVpnStatus'])
        ->name('api.routers.vpn.status');
    
    // Delete VPN configuration
    Route::delete('/vpn', [RouterVpnController::class, 'deleteVpnConfig'])
        ->name('api.routers.vpn.delete');
    
    // Regenerate RADIUS secret
    Route::post('/vpn/regenerate-secret', [RouterVpnController::class, 'regenerateRadiusSecret'])
        ->name('api.routers.vpn.regenerate-secret');
});

// =============================================================================
// DUPLICATE SYSTEM ADMIN ROUTES REMOVED
// All system admin routes are now in the main system admin group above (line ~191)
// =============================================================================

// =============================================================================
// TENANT ROUTES - Tenant-specific data (auto-filtered by TenantScope)
// =============================================================================
Route::middleware(['auth:sanctum', 'role:admin,tenant'])->prefix('tenant')->group(function () {
    // Dashboard & Statistics
    Route::get('/dashboard', [TenantDashboardController::class, 'index'])
        ->name('api.tenant.dashboard');
    
    // Users
    Route::get('/users', [TenantDashboardController::class, 'getUsers'])
        ->name('api.tenant.users');
    
    // Packages
    Route::get('/packages', [TenantDashboardController::class, 'getPackages'])
        ->name('api.tenant.packages');
    
    // Routers
    Route::get('/routers', [TenantDashboardController::class, 'getRouters'])
        ->name('api.tenant.routers');
    
    // Payments
    Route::get('/payments', [TenantDashboardController::class, 'getPayments'])
        ->name('api.tenant.payments');
    
    // Active Sessions
    Route::get('/sessions', [TenantDashboardController::class, 'getSessions'])
        ->name('api.tenant.sessions');
});
