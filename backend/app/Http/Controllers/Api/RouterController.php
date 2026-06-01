<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteProvisioningServiceRouterTaskJob;
use App\Jobs\RouterProbingJob;
use App\Jobs\RouterProvisioningJob;
use App\Events\ProvisioningFailed;
use App\Events\RouterProvisioningProgress;
use App\Models\Router;
use App\Models\RouterConfig;
use App\Models\RouterTask;
use App\Models\RouterTenantMap;
use App\Models\Tenant;
use App\Services\MikrotikSnmpService;
use App\Services\RouterMetricsService;
use App\Services\TenantContext;
use App\Services\TenantMigrationManager;
use App\Services\VictoriaMetricsClient;
use App\Models\SystemLog;
use App\Services\AuditLogService;
use App\Services\ProvisioningServiceClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;

class RouterController extends Controller
{
    private const PROVISIONING_TASK_STALE_TIMEOUT_MINUTES = 8;

    public function index(Request $request)
    {
        try {
            $withLive = $request->boolean('with_live', false);
            $perPage = min((int) $request->input('per_page', 25), 100);
            $tenantId = (string) ($request->user()?->tenant_id ?? '');

            if ($tenantId === '') {
                return response()->json([
                    'error' => 'Tenant context not available',
                    'message' => 'Unable to determine tenant for router listing.',
                    'data' => [],
                ], 403);
            }

            $routers = Router::select(['id', 'name', 'ip_address', 'status', 'vpn_status', 'last_seen', 'model', 'os_version', 'location', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $basePayload = [
                'data' => $routers,
                'has_live_data' => false,
                'message' => 'Router information loaded successfully',
            ];

            if (!$withLive) {
                return response()->json($basePayload)
                    ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0');
            }

            /** @var \Illuminate\Pagination\LengthAwarePaginator $routers */
            $routers = $basePayload['data'];

            // For with_live=true, return basic data with loading indicators
            // The frontend can then call the live-data endpoint separately
            $routersWithLoadingState = $routers->getCollection()->map(function ($router) {
                $router->setAttribute('live_data', null);
                $router->setAttribute('live_status', 'loading');
                $router->setAttribute('live_error', null);
                $router->setAttribute('is_loading', true); // Explicit loading flag
                $router->setAttribute('loading_message', 'Fetching live data...');
                return $router;
            });

            return response()->json([
                'data' => $routersWithLoadingState,
                'has_live_data' => 'loading',
                'message' => 'Router information loaded. Live data is being fetched...',
                'live_data_endpoint' => '/api/routers/live-data',
                'loading_indicators' => [
                    'live_data' => 'Loading...',
                    'resources' => 'Loading...',
                    'interfaces' => 'Loading...',
                    'hotspots' => 'Loading...',
                    'radius_servers' => 'Loading...',
                    'active_connections' => 'Loading...'
                ],
                'loading_spinners' => [
                    'live_data' => 'spinner',
                    'resources' => 'spinner', 
                    'interfaces' => 'spinner',
                    'hotspots' => 'spinner',
                    'radius_servers' => 'spinner',
                    'active_connections' => 'spinner'
                ],
                'loading_classes' => [
                    'container' => 'loading-container',
                    'spinner' => 'loading-spinner',
                    'text' => 'loading-text',
                    'overlay' => 'loading-overlay'
                ]
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch routers: ' . $e->getMessage());
            return response()->json([
                'error' => 'Unable to load router information',
                'message' => 'There was a problem loading the router data. Please try again.',
                'data' => []
            ], 500);
        }
    }

    /**
     * Fetch live data for all routers (async endpoint)
     */
    public function getLiveData(
        Request $request,
        VictoriaMetricsClient $vm,
        TenantContext $tenantContext,
        RouterMetricsService $metricsService
    )
    {
        try {
            $tenantId = $tenantContext->getTenantId();
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Tenant context not set',
                    'data' => [],
                ], 403);
            }

            $routers = Router::select(['id', 'name', 'ip_address', 'status', 'vpn_ip', 'vpn_status', 'last_seen', 'model', 'os_version', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->get();

            $routerIds = $routers
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();

            $liveDataByRouter = count($routerIds) > 0
                ? $metricsService->getLatestRouterMetrics($vm, (string) $tenantId, $routerIds)
                : [];

            $routersWithLive = $routers->map(function ($router) use ($liveDataByRouter) {
                $routerId = (string) $router->id;
                $liveData = $liveDataByRouter[$routerId] ?? [];

                if (!empty($liveData)) {
                    $liveData['source'] = 'victoriametrics';
                }

                $router->setAttribute('live_data', $liveData);
                return $router;
            });

            return response()->json([
                'data' => $routersWithLive,
                'has_live_data' => true,
                'message' => 'Live data fetched successfully',
                'source' => 'victoriametrics',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch live router data: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch live data'], 500);
        }
    }

    private function shouldHideInterfaceForUi(array $iface): bool
    {
        $name = strtolower($iface['name'] ?? '');
        $type = strtolower($iface['type'] ?? '');
        $comment = strtolower($iface['comment'] ?? '');

        $excludedTypes = [
            'bridge', 'vlan', 'vrrp', 'vpls', 'ovpn-out', 'ovpn-in',
            'wireguard', 'wg', 'gre', 'ipip', 'eoip',
        ];

        if (in_array($type, $excludedTypes, true)) {
            return true;
        }

        // Loopback / system
        if (in_array($name, ['lo', 'loopback'], true)) {
            return true;
        }

        // WireGuard interfaces can appear with various naming conventions
        if (str_contains($name, 'wireguard') || str_starts_with($name, 'wg')) {
            return true;
        }

        // WAN / uplink ports (best-effort heuristics; can be refined with explicit router metadata)
        if (
            in_array($name, ['ether1', 'wan', 'uplink'], true) ||
            str_starts_with($name, 'wan') ||
            str_contains($comment, 'wan') ||
            str_contains($comment, 'uplink')
        ) {
            return true;
        }

        return false;
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'wan_interface' => 'nullable|string|max:64',
        ]);

        try {
            // Get tenant from TenantContext service (set by SetTenantContext middleware)
            $tenantContext = app(\App\Services\TenantContext::class);
            $tenant = $tenantContext->getTenant();
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant context not set. User must belong to a tenant to create routers.'
                ], 403);
            }

            $ipAddress = $this->generateUniqueIp();
            $username = 'traidnet_user';
            $password = Str::random(12);
            $port = 8728;
            $configToken = Str::uuid();
            $tokenCreatedAt = now();
            $ttlMinutes = (int) config('app.router_config_token_ttl_minutes', 60);
            $tokenExpiresAt = $ttlMinutes > 0 ? $tokenCreatedAt->copy()->addMinutes($ttlMinutes) : null;

            // VPN is now MANDATORY for all routers
            $router = Router::create([
                'name' => $request->name,
                'ip_address' => $ipAddress,
                'username' => $username,
                'password' => Crypt::encrypt($password),
                'port' => $port,
                'wan_interface' => $request->input('wan_interface'),
                'config_token' => $configToken,
                'config_token_created_at' => $tokenCreatedAt,
                'config_token_expires_at' => $tokenExpiresAt,
                'status' => 'pending',
                'vpn_enabled' => true, // Always enabled
                'vpn_status' => 'pending',
                'snmp_enabled' => true,
                'snmp_version' => '2c',
                'snmp_community' => config('telegraf.snmp_community', 'traidnet-monitor'),
            ]);

            $connectivityScript = $this->generateConnectivityScript($router);
            RouterConfig::create([
                'router_id' => $router->id,
                'config_type' => 'connectivity',
                'config_content' => $connectivityScript,
            ]);

            // Fire RouterCreated event
            event(new \App\Events\RouterCreated($router));

            // Create VPN configuration SYNCHRONOUSLY so we can return the script immediately
        $vpnService = app(\App\Services\VpnService::class);
        $vpnConfig = $vpnService->createVpnConfiguration(
            $tenant,
            $router
        );

        // Update router with VPN IP
        $router->update([
            'vpn_ip' => $vpnConfig->client_ip,
            'vpn_status' => 'pending',
        ]);

        // Use the authoritative script from VpnService
        $vpnScript = $vpnConfig->mikrotik_script;

        // Generate complete configuration script (basic setup + VPN + SNMP)
        // This is what will be in the .rsc file that MikroTik downloads
        $completeScript = $this->buildBootstrapCompleteScript($router, $vpnScript, true);

        // Store VPN script in router configs
        RouterConfig::create([
            'router_id' => $router->id,
            'config_type' => 'vpn',
            'config_content' => $vpnScript,
        ]);

        // Store complete script (this is what fetchConfig will return)
        RouterConfig::create([
            'router_id' => $router->id,
            'config_type' => 'complete',
            'config_content' => $completeScript,
        ]);

        Log::info('Router created with VPN configuration:', [
            'router_id' => $router->id,
            'name' => $router->name,
            'ip_address' => $router->ip_address,
            'vpn_ip' => $vpnConfig->client_ip,
            'username' => $router->username,
            'port' => $router->port,
        ]);

        AuditLogService::logRouterEvent(
            'router_created',
            (string) $router->id,
            'info',
            ['name' => $router->name, 'ip_address' => $router->ip_address, 'vpn_ip' => $vpnConfig->client_ip],
            "Router '{$router->name}' created with VPN configuration",
            (string) $router->tenant_id
        );

        // Bust tenant dashboard cache for router counts
        TenantDashboardController::bustEntityCache((string) $router->tenant_id, 'routers');
        TenantDashboardController::bustEntityCache((string) $router->tenant_id, 'dashboard');
        $this->bustRouterListCache((string) $router->tenant_id);

        // Generate sanitized script for UI display (hides secrets)
        $sanitizedScript = $this->generateSanitizedScript($router, $connectivityScript);

        return response()->json([
            'id' => $router->id,
            'name' => $router->name,
            'ip_address' => $router->ip_address,
            'config_token' => $router->config_token,
            'connectivity_script' => $connectivityScript, // Minimal fetch command only
            'sanitized_script' => $sanitizedScript, // Safe to display in UI
            'vpn_ip' => $vpnConfig->client_ip,
            'status' => $router->status,
            'model' => $router->model,
            'os_version' => $router->os_version,
            'last_seen' => $router->last_seen,
            'vpn_enabled' => true,
            'vpn_status' => $router->vpn_status,
        ], 201);
    } catch (\Exception $e) {
        Log::error('Failed to create router: ' . $e->getMessage(), [
            'name' => $request->name,
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => 'Failed to create router: ' . $e->getMessage()], 500);
    }
}

    public function createRouterWithConfig(Request $request)
    {
        $response = $this->store($request);

        if (!method_exists($response, 'getStatusCode') || $response->getStatusCode() >= 400) {
            return $response;
        }

        $payload = $response->getData(true);
        $routerId = $payload['id'] ?? null;

        if (!$routerId) {
            return $response;
        }

        $router = Router::find($routerId);
        if (!$router) {
            return $response;
        }

        $dispatchResult = $this->dispatchRouterProbing($router);
        $payload['probing_started'] = $dispatchResult['success'];
        if (!$dispatchResult['success']) {
            $payload['probing_error'] = $dispatchResult['message'];
        }

        return response()->json($payload, $response->getStatusCode());
    }

    public function startRouterProbing(Router $router)
    {
        try {
            if (in_array($router->status, ['online', 'connected', 'active'], true)) {
                return response()->json([
                    'success' => true,
                    'router_id' => $router->id,
                    'status' => $router->status,
                    'message' => 'Router is already online',
                ]);
            }

            if (in_array($router->status, ['failed', 'connection_failed'], true)) {
                $router->update(['status' => 'pending']);
            }

            $dispatchResult = $this->dispatchRouterProbing($router);
            if (!$dispatchResult['success']) {
                return response()->json([
                    'success' => false,
                    'router_id' => $router->id,
                    'message' => $dispatchResult['message'],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'router_id' => $router->id,
                'status' => $router->status,
                'message' => 'Router probing started',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to start router probing', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'router_id' => $router->id,
                'message' => 'Failed to start probing: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function resetProvisioning(Request $request, Router $router)
    {
        try {
            $startProbing = $request->boolean('start_probing', true);

            $router->update([
                'status' => 'pending',
                'provisioning_stage' => 'pending',
            ]);

            Cache::forget("vpn_check_pending_{$router->id}");
            Cache::forget("discovery_dispatch_{$router->id}");
            Cache::forget("router_discovery_lock_{$router->id}");

            $dispatchResult = ['success' => false, 'message' => 'Probing not requested'];
            if ($startProbing) {
                $dispatchResult = $this->dispatchRouterProbing($router);
            }

            return response()->json([
                'success' => true,
                'router_id' => $router->id,
                'status' => $router->status,
                'vpn_status' => $router->vpn_status,
                'message' => $startProbing
                    ? ($dispatchResult['success']
                        ? 'Provisioning state reset and probing restarted'
                        : 'Provisioning state reset, but probing could not be started')
                    : 'Provisioning state reset successfully',
                'probing_started' => $startProbing ? $dispatchResult['success'] : false,
                'probing_error' => $startProbing && !$dispatchResult['success']
                    ? $dispatchResult['message']
                    : null,
            ], $startProbing && !$dispatchResult['success'] ? 202 : 200);
        } catch (\Exception $e) {
            Log::error('Failed to reset router provisioning state', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'router_id' => $router->id,
                'message' => 'Failed to reset provisioning: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function dispatchRouterProbing(Router $router): array
    {
        $tenantId = auth()->user()?->tenant_id
            ?? app(TenantContext::class)->getTenantId()
            ?? RouterTenantMap::findTenantByRouterId($router->id);

        if (!$tenantId) {
            return [
                'success' => false,
                'message' => 'Tenant context not available for probing dispatch',
            ];
        }

        RouterProbingJob::dispatch((string) $router->id, (string) $tenantId)
            ->onQueue('router-monitoring');

        Log::info('Router probing dispatched', [
            'router_id' => $router->id,
            'tenant_id' => $tenantId,
        ]);

        return [
            'success' => true,
            'message' => 'Router probing dispatched',
        ];
    }

    public function update(Request $request, Router $router)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            'config_token' => 'nullable|string|max:255',
            'wan_interface' => 'nullable|string|max:64',
        ]);

        try {
            $updateData = [
                'name' => $request->name,
                'ip_address' => $request->ip_address ?? $router->ip_address,
                'wan_interface' => $request->input('wan_interface', $router->wan_interface),
            ];

            if ($request->filled('config_token')) {
                $ttlMinutes = (int) config('app.router_config_token_ttl_minutes', 60);
                $updateData['config_token'] = $request->config_token;
                $updateData['config_token_created_at'] = now();
                $updateData['config_token_expires_at'] = $ttlMinutes > 0
                    ? now()->addMinutes($ttlMinutes)
                    : null;
            }

            $router->update($updateData);

            // Broadcast router updated event
            $tenantId = $router->tenant_id ?? null;
            if ($tenantId) {
                event(new \App\Events\RouterUpdated($router->toArray(), (string) $tenantId));
                \App\Services\CacheInvalidationService::invalidateRouterCache((string) $tenantId, (string) $router->id);
                Cache::forget("router_revenue_tenant_{$tenantId}");
                Cache::forget("router_details_{$router->id}");
                // Bust tenant dashboard cache for router counts
                TenantDashboardController::bustEntityCache((string) $tenantId, 'routers');
                TenantDashboardController::bustEntityCache((string) $tenantId, 'dashboard');
                $this->bustRouterListCache((string) $tenantId);
            }

            AuditLogService::logRouterEvent(
                'router_updated',
                (string) $router->id,
                'info',
                ['name' => $router->name, 'ip_address' => $router->ip_address],
                "Router '{$router->name}' settings updated",
                (string) $router->tenant_id
            );

            Log::info('Router updated successfully:', [
                'router_id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
            ]);

            return response()->json($router);
        } catch (\Exception $e) {
            Log::error('Failed to update router: ' . $e->getMessage(), [
                'router_id' => $router->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to update router: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Router $router)
    {
        try {
            $routerId = (string) $router->id;
            $routerName = $router->name;
            AuditLogService::logRouterEvent(
                'router_deleted',
                $routerId,
                'warning',
                ['name' => $routerName],
                "Router '{$routerName}' deleted",
                (string) $router->tenant_id
            );
            $tenantId = $router->tenant_id ?? null;
            $router->delete();
            if ($tenantId) {
                \App\Services\CacheInvalidationService::invalidateRouterCache((string) $tenantId, $routerId);
                Cache::forget("router_revenue_tenant_{$tenantId}");
                Cache::forget("router_details_{$routerId}");
                // Bust tenant dashboard cache for router counts
                TenantDashboardController::bustEntityCache((string) $tenantId, 'routers');
                TenantDashboardController::bustEntityCache((string) $tenantId, 'dashboard');
                $this->bustRouterListCache((string) $tenantId);
            }
            Log::info('Router deleted successfully:', ['router_id' => $routerId]);
            return response()->json(['message' => 'Router deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete router: ' . $e->getMessage(), [
                'router_id' => $router->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to delete router: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get router status
     * 
     * @param Router $router
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(Router $router)
    {
        try {
            // Return the current router status from database
            return response()->json([
                'success' => true,
                'status' => $router->status ?? 'offline',
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'ip_address' => $router->ip_address,
                    'status' => $router->status ?? 'offline',
                    'last_checked' => $router->last_checked,
                    'last_seen' => $router->last_seen,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get router status', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'status' => 'offline',
                'error' => 'Failed to get router status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed router information
     * 
     * @param Router $router
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRouterDetails(Request $request, Router $router)
    {
        try {
            // Refresh router model to get latest VPN status and handshake
            $router->refresh();

            $withLive = $request->boolean('with_live', false);
            
            // Load relationships quickly from database
            $services = $router->load('services')->services;
            $accessPoints = $router->load('accessPoints')->accessPoints;

            if (!$withLive) {
                return response()->json([
                    'success' => true,
                    'has_live_data' => false,
                    'message' => 'Router details loaded successfully',
                    'router' => [
                        'id' => $router->id,
                        'name' => $router->name,
                        'ip_address' => $router->ip_address,
                        'port' => $router->port,
                        'username' => $router->username,
                        'location' => $router->location,
                        'status' => $router->status,
                        'model' => $router->model,
                        'os_version' => $router->os_version,
                        'serial_number' => $router->serial_number,
                        'firmware' => $router->firmware,
                        'last_seen' => $router->last_seen,
                        'wan_interface' => $router->wan_interface,
                        'vpn_status' => $router->vpn_status,
                        'vpn_last_handshake' => $router->vpn_last_handshake,
                        'vpn_last_handshake_utc' => $router->vpn_last_handshake_utc,
                        'vpn_last_handshake_eat' => $router->vpn_last_handshake_eat,
                        'vpn_last_handshake_timezones' => $router->vpn_last_handshake_timezones,
                    ],
                    // Empty live data sections - will be populated when with_live=1
                    'resources' => [],
                    'interfaces' => [],
                    'hotspots' => [],
                    'radius_servers' => [],
                    'active_connections' => 0,
                    // Additional metadata from database
                    'services' => $services,
                    'access_points' => $accessPoints,
                ]);
            }

            // For with_live=true, return basic details with loading indicators
            // The frontend can then call the live-data endpoint separately
            return response()->json([
                'success' => true,
                'has_live_data' => 'loading',
                'message' => 'Router details loaded. Live data is being fetched...',
                'live_data_endpoint' => "/api/routers/{$router->id}/live-data",
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'ip_address' => $router->ip_address,
                    'port' => $router->port,
                    'username' => $router->username,
                    'location' => $router->location,
                    'status' => $router->status,
                    'model' => $router->model,
                    'os_version' => $router->os_version,
                    'serial_number' => $router->serial_number,
                    'firmware' => $router->firmware,
                    'last_seen' => $router->last_seen,
                    'wan_interface' => $router->wan_interface,
                    'vpn_status' => $router->vpn_status,
                    'vpn_last_handshake' => $router->vpn_last_handshake,
                    'vpn_last_handshake_utc' => $router->vpn_last_handshake_utc,
                    'vpn_last_handshake_eat' => $router->vpn_last_handshake_eat,
                    'vpn_last_handshake_timezones' => $router->vpn_last_handshake_timezones,
                ],
                // Loading state for live data sections
                'resources' => null,
                'interfaces' => null,
                'hotspots' => null,
                'radius_servers' => null,
                'active_connections' => null,
                // Additional metadata from database
                'services' => $services,
                'access_points' => $accessPoints,
                // Explicit loading indicators
                'loading_indicators' => [
                    'live_data' => 'Loading...',
                    'resources' => 'Loading...',
                    'interfaces' => 'Loading...',
                    'hotspots' => 'Loading...',
                    'radius_servers' => 'Loading...',
                    'active_connections' => 'Loading...'
                ],
                'loading_spinners' => [
                    'live_data' => 'spinner',
                    'resources' => 'spinner', 
                    'interfaces' => 'spinner',
                    'hotspots' => 'spinner',
                    'radius_servers' => 'spinner',
                    'active_connections' => 'spinner'
                ],
                'loading_classes' => [
                    'container' => 'loading-container',
                    'spinner' => 'loading-spinner',
                    'text' => 'loading-text',
                    'overlay' => 'loading-overlay'
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch router details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Unable to load router details',
                'message' => 'There was a problem loading the router information. Please try again.',
                'router' => null
            ], 500);
        }
    }

    /**
     * Get live data for a specific router
     * 
     * @param Router $router
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRouterLiveData(
        Router $router,
        VictoriaMetricsClient $vm,
        TenantContext $tenantContext,
        RouterMetricsService $metricsService
    )
    {
        try {
            $tenantId = $tenantContext->getTenantId();
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Tenant context not set',
                ], 403);
            }

            $routerId = (string) $router->id;
            $liveData = $metricsService->getLatestRouterMetrics($vm, (string) $tenantId, [$routerId]);
            $live = $liveData[$routerId] ?? [];
            $hasLive = !empty($live);

            if ($hasLive) {
                $live['source'] = $live['source'] ?? 'victoriametrics';
            }

            // Prefer the richer active_connections metric when available, but
            // keep hotspot_active as a fallback for backwards compatibility.
            $activeConnections = $live['active_connections']
                ?? $live['pppoe_sessions']
                ?? $live['hotspot_active']
                ?? 0;

            // If SNMP/VictoriaMetrics returned 0, fall back to counting open radacct
            // sessions — this is the authoritative source from RADIUS accounting.
            if ($activeConnections === 0 && Schema::hasTable('radacct')) {
                $nasIps = array_filter([
                    $router->vpn_ip ?? null,
                    $router->ip_address ?? null,
                ]);
                if (!empty($nasIps)) {
                    $radacctCount = DB::table('radacct')
                        ->whereNull('acctstoptime')
                        ->whereIn('nasipaddress', $nasIps)
                        ->count();
                    if ($radacctCount > 0) {
                        $activeConnections = $radacctCount;
                        $live['pppoe_sessions'] = $radacctCount;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'has_live_data' => $hasLive,
                'message' => $hasLive ? 'Live metrics fetched successfully' : 'No live metrics available yet',
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'ip_address' => $router->ip_address,
                    'status' => $router->status,
                    'model' => $router->model,
                    'os_version' => $router->os_version,
                    'last_seen' => $router->last_seen,
                    'vpn_status' => $router->vpn_status,
                    'vpn_last_handshake' => $router->vpn_last_handshake,
                    'vpn_last_handshake_utc' => $router->vpn_last_handshake_utc,
                    'vpn_last_handshake_eat' => $router->vpn_last_handshake_eat,
                    'vpn_last_handshake_timezones' => $router->vpn_last_handshake_timezones,
                ],
                // Live data sections
                'resources' => $live,
                'interfaces' => [],
                'hotspots' => [],
                'radius_servers' => [],
                'active_connections' => $activeConnections,
                'is_stale' => !$hasLive,
            ]);
            
        } catch (\Exception $e) {
            Log::warning('Could not fetch VictoriaMetrics live data', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'ip_address' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            
            // Return 200 with success: false for offline routers (not a server error)
            return response()->json([
                'success' => false,
                'has_live_data' => false,
                'error' => 'Metrics unavailable',
                'message' => 'Could not fetch live metrics from VictoriaMetrics.',
                'router' => [
                    'id' => $router->id,
                    'name' => $router->name,
                    'ip_address' => $router->ip_address,
                    'status' => 'offline',
                    'wan_interface' => $router->wan_interface,
                    'vpn_status' => $router->vpn_status,
                    'vpn_last_handshake' => $router->vpn_last_handshake,
                    'vpn_last_handshake_utc' => $router->vpn_last_handshake_utc,
                    'vpn_last_handshake_eat' => $router->vpn_last_handshake_eat,
                    'vpn_last_handshake_timezones' => $router->vpn_last_handshake_timezones,
                ],
                'resources' => [],
                'interfaces' => [],
                'hotspots' => [],
                'radius_servers' => [],
                'active_connections' => 0,
            ]);
        }
    }

    /**
     * Get router interfaces
     * 
     * @param Router $router
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRouterInterfaces(Router $router)
    {
        try {
            $sshService = app(\App\Services\MikrotikSshService::class);

            $result = $sshService->fetchInterfaces($router, false);
            $interfaces = $result['interfaces'] ?? [];

            $formattedInterfaces = array_map(function ($iface) {
                return [
                    'name' => $iface['name'] ?? 'Unknown',
                    'type' => $iface['type'] ?? 'Unknown',
                    'running' => ($iface['running'] ?? 'false') === 'true',
                    'disabled' => ($iface['disabled'] ?? 'false') === 'true',
                    'mtu' => $iface['mtu'] ?? 'N/A',
                    'comment' => $iface['comment'] ?? '',
                ];
            }, $interfaces);

            $formattedInterfaces = array_values(array_filter($formattedInterfaces, function ($iface) {
                return !$this->shouldHideInterfaceForUi($iface);
            }));

            Log::info('Fetched router interfaces', [
                'router_id' => $router->id,
                'interface_count' => count($formattedInterfaces),
            ]);

            return response()->json([
                'success' => true,
                'interfaces' => $formattedInterfaces,
                'count' => count($formattedInterfaces),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch router interfaces', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch router interfaces: ' . $e->getMessage(),
                'interfaces' => [],
            ], 500);
        }
    }

    /**
     * Generate service configuration (Hotspot/PPPoE)
     * 
     * @param Request $request
     * @param Router $router
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateServiceConfig(Request $request, Router $router)
    {
        try {
            $validated = $request->validate([
                'enable_hotspot' => 'boolean',
                'enable_pppoe' => 'boolean',
                'hotspot_interfaces' => 'array',
                'hotspot_interfaces.*' => 'string',
                'pppoe_interfaces' => 'array',
                'pppoe_interfaces.*' => 'string',
                'portal_title' => 'nullable|string',
                'login_method' => 'nullable|string',
                'pppoe_service_name' => 'nullable|string',
                'pppoe_ip_pool' => 'nullable|string',
            ]);

            Log::info('Generating service configuration', [
                'router_id' => $router->id,
                'enable_hotspot' => $validated['enable_hotspot'] ?? false,
                'enable_pppoe' => $validated['enable_pppoe'] ?? false,
            ]);

            // Use the ConfigurationService to generate the script
            $configService = app(\App\Services\MikroTik\ConfigurationService::class);
            $result = $configService->generateServiceConfig($router, $validated);

            // Save the generated script to router_configs table
            if (!empty($result['service_script'])) {
                RouterConfig::updateOrCreate(
                    [
                        'router_id' => $router->id,
                        'config_type' => 'service',
                    ],
                    [
                        'config_content' => $result['service_script'],
                    ]
                );

                AuditLogService::logRouterEvent(
                    'service_config_generated',
                    (string) $router->id,
                    'info',
                    ['service_type' => $validated['service_type'] ?? 'unknown'],
                    "Service configuration generated for router '{$router->name}'",
                    (string) $router->tenant_id
                );

                Log::info('Service configuration saved', [
                    'router_id' => $router->id,
                    'script_length' => strlen($result['service_script']),
                ]);
            }

            return response()->json([
                'success' => true,
                'service_script' => $result['service_script'] ?? '',
                'message' => 'Service configuration generated successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed: ' . json_encode($e->errors()),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to generate service configuration', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate configuration: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deploy service configuration to router
     * 
     * @param Request $request
     * @param Router $router
     * @return \Illuminate\Http\JsonResponse
     */
    public function deployServiceConfig(Request $request, Router $router)
    {
        try {
            $validated = $request->validate([
                'service_type' => 'required|string|in:hotspot,pppoe',
                'commands' => 'nullable|array',
            ]);

            Log::info('Queueing service configuration deployment', [
                'router_id' => $router->id,
                'service_type' => $validated['service_type'],
                'command_count' => count($validated['commands'] ?? []),
            ]);

            $task = $this->createRouterTask($router, RouterTask::TYPE_DEPLOY_SERVICE_CONFIG, [
                'service_type' => $validated['service_type'],
                'enable_hotspot' => $validated['service_type'] === 'hotspot',
                'enable_pppoe' => $validated['service_type'] === 'pppoe',
                'commands' => $validated['commands'] ?? [],
            ]);

            $this->dispatchRouterTask($task);

            AuditLogService::logRouterEvent(
                'provisioning_started',
                (string) $router->id,
                'info',
                ['service_type' => $validated['service_type'] ?? 'unknown', 'tenant_id' => $task->tenant_id, 'task_id' => $task->id],
                "Provisioning queued for router '{$router->name}'",
                (string) $task->tenant_id
            );

            return $this->routerTaskAcceptedResponse($task, 'Deployment task accepted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed: ' . json_encode($e->errors()),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to queue service configuration deployment', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to queue deployment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get router provisioning status
     * 
     * @param Router $router
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProvisioningStatus(Router $router)
    {
        try {
            $tenantId = (string) ($router->tenant_id ?? auth()->user()?->tenant_id ?? '');

            $task = $tenantId !== ''
                ? RouterTask::forRouter($tenantId, (string) $router->id)
                    ->whereIn('type', [
                        RouterTask::TYPE_DEPLOY_SERVICE_CONFIG,
                        RouterTask::TYPE_APPLY_SERVICE_CONFIGS,
                        RouterTask::TYPE_VERIFY_CONNECTIVITY,
                        RouterTask::TYPE_DISCOVER_INTERFACES,
                    ])
                    ->latest('created_at')
                    ->first()
                : null;

            if ($task) {
                $task = $this->failStaleProvisioningTaskIfNeeded($router, $task);

                $responsePayload = [
                    'success' => true,
                    'status' => $task->status,
                    'router_status' => $router->status,
                    'router_id' => $router->id,
                    'task_id' => $task->id,
                    'task_type' => $task->type,
                    'progress' => $task->progress,
                    'message' => $task->message,
                    'error' => $task->error_message,
                    'result' => $task->result_payload,
                    'started_at' => $task->started_at,
                    'completed_at' => $task->completed_at,
                ];

                if ($task->type === RouterTask::TYPE_DEPLOY_SERVICE_CONFIG) {
                    $workflow = app(ProvisioningServiceClient::class)->getWorkflowStatus((string) $task->id);
                    if (is_array($workflow)) {
                        $task = $this->syncTaskFromWorkflowSnapshot($router, $task, $workflow);

                        $responsePayload['status'] = $workflow['status'] ?? $responsePayload['status'];
                        $responsePayload['progress'] = $workflow['progress'] ?? $responsePayload['progress'];
                        $responsePayload['message'] = $workflow['message'] ?? $responsePayload['message'];
                        $responsePayload['error'] = $workflow['error'] ?? $responsePayload['error'];
                        $responsePayload['result'] = $workflow['result'] ?? $responsePayload['result'];
                        $responsePayload['started_at'] = $workflow['started_at'] ?? $responsePayload['started_at'];
                        $responsePayload['completed_at'] = $workflow['completed_at'] ?? $responsePayload['completed_at'];
                        $responsePayload['authoritative_source'] = 'provisioning_service';
                        $responsePayload['workflow_stage'] = $workflow['stage'] ?? null;
                        $responsePayload['workflow_idempotency_key'] = $workflow['idempotency_key'] ?? (string) $task->id;
                    }
                }

                return response()->json($responsePayload);
            }

            $status = $router->status;
            $provisioningStatus = match($status) {
                'active', 'online' => 'completed',
                'deploying', 'provisioning', 'verifying' => 'running',
                'failed', 'connection_failed' => 'failed',
                default => 'pending',
            };

            $activeWorkflow = app(ProvisioningServiceClient::class)->getActiveWorkflow((string) $router->id);
            if (is_array($activeWorkflow)) {
                return response()->json([
                    'success' => true,
                    'status' => $activeWorkflow['status'] ?? $provisioningStatus,
                    'router_status' => $status,
                    'router_id' => $router->id,
                    'task_id' => null,
                    'task_type' => RouterTask::TYPE_DEPLOY_SERVICE_CONFIG,
                    'progress' => $activeWorkflow['progress'] ?? null,
                    'message' => $activeWorkflow['message'] ?? null,
                    'error' => $activeWorkflow['error'] ?? null,
                    'result' => $activeWorkflow['result'] ?? null,
                    'started_at' => $activeWorkflow['started_at'] ?? null,
                    'completed_at' => $activeWorkflow['completed_at'] ?? null,
                    'authoritative_source' => 'provisioning_service',
                    'workflow_stage' => $activeWorkflow['stage'] ?? null,
                    'workflow_idempotency_key' => $activeWorkflow['idempotency_key'] ?? null,
                ]);
            }

            return response()->json([
                'success' => true,
                'status' => $provisioningStatus,
                'router_status' => $status,
                'router_id' => $router->id,
                'task_id' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get provisioning status', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'unknown',
                'error' => 'Failed to get provisioning status: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function failStaleProvisioningTaskIfNeeded(Router $router, RouterTask $task): RouterTask
    {
        if ($task->status !== RouterTask::STATUS_RUNNING || !$task->updated_at) {
            return $task;
        }

        $staleAt = now()->subMinutes(self::PROVISIONING_TASK_STALE_TIMEOUT_MINUTES);
        if ($task->updated_at->greaterThan($staleAt)) {
            return $task;
        }

        $error = sprintf(
            'Provisioning timeout: no callback update for %d minutes. Please retry provisioning.',
            self::PROVISIONING_TASK_STALE_TIMEOUT_MINUTES
        );

        $task->markFailed($error, (int) $task->progress, $task->message ?: 'Provisioning stalled waiting for callback');
        $router->update([
            'status' => 'failed',
            'provisioning_stage' => 'failed',
            'last_checked' => now(),
        ]);

        Log::error('Provisioning task marked failed after callback timeout', [
            'task_id' => $task->id,
            'router_id' => $router->id,
            'tenant_id' => $task->tenant_id,
            'last_task_update_at' => optional($task->updated_at)->toIso8601String(),
        ]);

        broadcast(new RouterProvisioningProgress(
            (string) $router->id,
            'failed',
            (float) ($task->progress ?: 40),
            $error,
            ['task_id' => $task->id, 'tenant_id' => $task->tenant_id, 'reason' => 'callback_timeout']
        ));

        broadcast(new ProvisioningFailed(
            (string) $router->id,
            'failed',
            $error,
            ['task_id' => $task->id, 'tenant_id' => $task->tenant_id, 'reason' => 'callback_timeout']
        ));

        return $task->fresh() ?? $task;
    }

    private function syncTaskFromWorkflowSnapshot(Router $router, RouterTask $task, array $workflow): RouterTask
    {
        $workflowStatus = (string) ($workflow['status'] ?? '');
        if ($workflowStatus === '') {
            return $task;
        }

        if ($workflowStatus === RouterTask::STATUS_FAILED && $task->status !== RouterTask::STATUS_FAILED) {
            $error = (string) ($workflow['error'] ?? $workflow['message'] ?? 'Provisioning failed');
            $task->markFailed(
                $error,
                (int) ($workflow['progress'] ?? $task->progress ?? 0),
                (string) ($workflow['message'] ?? $task->message ?? 'Provisioning failed'),
                (array) ($workflow['result'] ?? [])
            );

            $router->update([
                'status' => 'failed',
                'provisioning_stage' => 'failed',
                'last_checked' => now(),
            ]);

            Log::error('Provisioning workflow reported terminal failure', [
                'task_id' => $task->id,
                'router_id' => $router->id,
                'tenant_id' => $task->tenant_id,
                'workflow_stage' => $workflow['stage'] ?? null,
                'error' => $error,
            ]);
        }

        return $task->fresh() ?? $task;
    }

    public function verifyConnectivity(Router $router)
    {
        Log::info('Queueing connectivity verification for router', [
            'router_id' => $router->id,
            'ip_address' => $router->ip_address,
            'username' => $router->username,
            'port' => $router->port,
        ]);

        try {
            $task = $this->createRouterTask($router, RouterTask::TYPE_VERIFY_CONNECTIVITY);
            $this->dispatchRouterTask($task);

            return $this->routerTaskAcceptedResponse($task, 'Connectivity verification task accepted');
        } catch (\Exception $e) {
            $errorMessage = match (true) {
                strpos($e->getMessage(), 'decrypt') !== false => 'Failed to decrypt password. Check OpenSSL configuration and database integrity.',
                default => 'Failed to queue connectivity verification: ' . $e->getMessage(),
            };

            Log::error('Failed to queue connectivity verification', [
                'router_id' => $router->id,
                'ip_address' => $router->ip_address,
                'username' => $router->username,
                'port' => $router->port,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
            ], 500);
        }
    }

    public function generateConfigs(Request $request, Router $router)
    {
        $request->validate([
            'hotspot_interfaces' => 'nullable|array',
            'hotspot_interfaces.*' => 'string',
            'pppoe_interfaces' => 'nullable|array',
            'pppoe_interfaces.*' => 'string',
            'enable_hotspot' => 'boolean',
            'enable_pppoe' => 'boolean',
        ]);

        try {
            $interfaceAssignments = $request->input('interface_assignments', []);
            $interfaceServices = $request->input('interface_services', []);
            $configurations = $request->input('configurations', []);

            Log::info('generateConfigs called', [
                'router_id' => $router->id,
                'interface_assignments' => $interfaceAssignments,
                'interface_services' => $interfaceServices,
                'hotspot_interfaces' => $request->hotspot_interfaces,
                'enable_hotspot' => $request->boolean('enable_hotspot'),
            ]);

            if ($request->boolean('enable_hotspot') && is_array($request->hotspot_interfaces)) {
                foreach ($request->hotspot_interfaces as $iface) {
                    if (!in_array($iface, $interfaceAssignments)) {
                        $interfaceAssignments[] = $iface;
                    }
                    $interfaceServices[$iface] = 'hotspot';
                    $configurations[$iface] = $configurations[$iface] ?? [
                        'hotspot_profile' => "hotspot-profile-$iface",
                        'ip_pool' => "192.168.88.10-192.168.88.100",
                    ];
                }
            }

            if ($request->boolean('enable_pppoe') && is_array($request->pppoe_interfaces)) {
                foreach ($request->pppoe_interfaces as $iface) {
                    if (!in_array($iface, $interfaceAssignments)) {
                        $interfaceAssignments[] = $iface;
                    }
                    $interfaceServices[$iface] = 'pppoe';
                    $configurations[$iface] = $configurations[$iface] ?? [
                        'pppoe_service' => $request->pppoe_service_name ?: 'pppoe-service',
                        'ip_pool' => $request->pppoe_ip_pool ?: '192.168.89.10-192.168.89.100',
                    ];
                }
            }

            $serviceScript = $this->generateServiceScript($router, $interfaceAssignments, $interfaceServices, $configurations);

            RouterConfig::create([
                'router_id' => $router->id,
                'config_type' => 'service',
                'config_content' => $serviceScript,
            ]);

            Log::info('Service configuration generated for router:', [
                'router_id' => $router->id,
                'interface_assignments' => $interfaceAssignments,
            ]);

            return response()->json(['service_script' => $serviceScript]);

        } catch (\Exception $e) {
            Log::error('Failed to generate service configuration: ' . $e->getMessage(), [
                'router_id' => $router->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to generate service configuration: ' . $e->getMessage()], 500);
        }
    }

    private function generateUniqueIp()
    {
        // Generate a placeholder IP - will be replaced with VPN IP after VPN config creation
        // This is just for initial router creation, actual management happens via VPN
        return '0.0.0.0/32';
    }

    /**
     * Fetch router configuration using config token (public endpoint)
     * Returns complete configuration script as plain text for /tool fetch
     * 
     * CRITICAL: This is a public endpoint that must work without authentication
     * We need to find the tenant from the router's config_token and set schema context
     */
    public function fetchConfig($configToken)
    {
        $tenantContext = app(TenantContext::class);
        $router = null;
        $foundTenant = null;

        $migrationManager = app(TenantMigrationManager::class);

        try {
            $mappedTenantId = RouterTenantMap::findTenantByConfigToken($configToken);
            if ($mappedTenantId) {
                $mappedTenant = Tenant::find($mappedTenantId);
                if ($mappedTenant && $mappedTenant->schema_created && $mappedTenant->schema_name) {
                    if ($migrationManager->hasPendingMigrations($mappedTenant)) {
                        $migrationManager->runMigrationsForTenant($mappedTenant);
                    }
                    $router = DB::transaction(function () use ($tenantContext, $mappedTenant, $configToken) {
                        DB::connection()->recordsHaveBeenModified();
                        $tenantContext->setTenant($mappedTenant);
                        return Router::where('config_token', $configToken)->first();
                    });
                    if ($router) {
                        $foundTenant = $mappedTenant;
                    }
                }
            }

            if (!$router) {
                // OPTIMIZATION: Check if we've recently searched for this token and failed
                // This prevents repeated full-tenant scans for invalid tokens
                $notFoundCacheKey = "router_token_notfound:{$configToken}";
                if (Cache::has($notFoundCacheKey)) {
                    Log::debug('Router config token recently not found, skipping tenant scan', [
                        'config_token' => $configToken,
                    ]);
                    return response('# ERROR: Configuration not found. Please verify your config token.', 404)
                        ->header('Content-Type', 'text/plain; charset=utf-8');
                }

                // CRITICAL: Router table is in tenant schema, but we don't know which tenant yet
                // Fallback: search across all tenant schemas using write connection only
                // OPTIMIZATION: Only search tenants that have been active recently (last 30 days)
                $tenants = Tenant::whereRaw('is_active = true')
                    ->where(function($q) {
                        $q->whereNull('last_active_at')
                          ->orWhere('last_active_at', '>=', now()->subDays(30));
                    })
                    ->limit(50) // Limit to prevent scanning hundreds of tenants
                    ->get();

                $searchedCount = 0;
                foreach ($tenants as $tenant) {
                    if (!$tenant->schema_created || !$tenant->schema_name) {
                        continue;
                    }

                    $searchedCount++;

                    try {
                        if ($migrationManager->hasPendingMigrations($tenant)) {
                            $migrationManager->runMigrationsForTenant($tenant);
                        }
                        $found = DB::transaction(function () use ($tenantContext, $tenant, $configToken) {
                            DB::connection()->recordsHaveBeenModified();
                            $tenantContext->setTenant($tenant);
                            return Router::where('config_token', $configToken)->first();
                        });

                        if ($found) {
                            $router      = $found;
                            $foundTenant = $tenant;
                            Log::info('Router found in tenant schema', [
                                'tenant_id'    => $tenant->id,
                                'tenant_slug'  => $tenant->slug,
                                'schema_name'  => $tenant->schema_name,
                                'router_id'    => $router->id,
                                'config_token' => $configToken,
                            ]);
                            break;
                        }
                    } catch (\Exception $e) {
                        // Schema might not exist or have issues, continue to next tenant
                        Log::debug('Could not search tenant schema', [
                            'tenant_id'   => $tenant->id,
                            'schema_name' => $tenant->schema_name,
                            'error'       => $e->getMessage(),
                        ]);
                        continue;
                    }
                }

                if (!$router || !$foundTenant) {
                    // Cache the "not found" result for 5 minutes to prevent repeated scans
                    Cache::put($notFoundCacheKey, true, now()->addMinutes(5));

                    Log::warning('Router not found with config token', [
                        'config_token'     => $configToken,
                        'tenants_searched' => $searchedCount,
                        'total_tenants'    => $tenants->count(),
                    ]);
                }
            }

            if ($router && $foundTenant) {
                RouterTenantMap::registerRouter(
                    $router->id,
                    $foundTenant->id,
                    $router->ip_address,
                    $router->vpn_ip,
                    $router->config_token
                );
            }

            if (!$router || !$foundTenant) {
                return response('# ERROR: Configuration not found. Please verify your config token.', 404)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            }

            $ttlMinutes = (int) config('app.router_config_token_ttl_minutes', 60);
            if ($ttlMinutes > 0 && !$router->config_token_created_at && !$router->config_token_expires_at) {
                $router->forceFill([
                    'config_token_created_at' => now(),
                    'config_token_expires_at' => now()->addMinutes($ttlMinutes),
                ])->save();
                $router->refresh();
            }

            if ($router->isConfigTokenExpired()) {
                Log::warning('Router config token expired', [
                    'router_id' => $router->id,
                    'tenant_id' => $foundTenant->id,
                ]);

                return response('# ERROR: Configuration token expired. Please regenerate the bootstrap token.', 410)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            }

            $completeScript = DB::transaction(function () use ($tenantContext, $foundTenant, $router) {
                DB::connection()->recordsHaveBeenModified();
                $tenantContext->setTenant($foundTenant);

                // Get VPN configuration
                $vpnConfig = $router->vpnConfiguration;

                if (!$vpnConfig) {
                    throw new \RuntimeException('VPN configuration not found');
                }

                // Always regenerate the latest complete configuration script
                $vpnService    = app(\App\Services\VpnService::class);
                $vpnScript     = $vpnService->generateMikroTikScript($vpnConfig);

                $completeScript = $this->buildBootstrapCompleteScript($router, $vpnScript, false);

                // Persist the latest generated script in the tenant DB
                RouterConfig::updateOrCreate(
                    [
                        'router_id'   => $router->id,
                        'config_type' => 'complete',
                    ],
                    [
                        'config_content' => $completeScript,
                    ]
                );

                return $completeScript;
            });

            Log::info('Router configuration fetched successfully', [
                'tenant_id'    => $foundTenant->id,
                'router_id'    => $router->id,
                'router_name'  => $router->name,
                'config_token' => $configToken,
            ]);

            // Return as .rsc file for MikroTik /tool fetch
            // CRITICAL: MikroTik requires Content-Disposition for dst-path to work
            return response($completeScript, 200)
                ->header('Content-Type', 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="config.rsc"')
                ->header('Content-Length', (string) strlen($completeScript))
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
                
        } catch (\Exception $e) {
            Log::error('Failed to fetch router config', [
                'config_token' => $configToken,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response('# ERROR: Configuration not found', 404)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        } finally {
            $tenantContext->clearTenant();
        }
    }

    /**
     * Generate sanitized script for UI display (hides secrets)
     */
    private function generateSanitizedScript(Router $router, string $connectivityScript): string
    {
        return $connectivityScript;
    }

    private function buildBootstrapCompleteScript(Router $router, string $vpnScript, bool $includeSnmp): string
    {
        $decryptedPassword = Crypt::decrypt($router->password);
        $managementSubnet = config('vpn.subnet.base', '10.0.0.0/8');
        $apiPort = $router->api_port ?? 8729;
        $snmpCommunity = config('telegraf.snmp_community', 'traidnet-monitor');
        $snmpSubnet = '10.8.0.1/32';

        $snmpLines = $includeSnmp
            ? "/snmp set enabled=yes contact=\"Network Admin\" location=\"Managed by WifiCore\"\n"
                . ":do { /snmp community remove [find name=\"{$snmpCommunity}\"] } on-error={}\n"
                . "/snmp community add name=\"{$snmpCommunity}\" addresses={$snmpSubnet} security=none read-access=yes write-access=no\n"
                . "/snmp set trap-community=\"{$snmpCommunity}\" trap-version=2"
            : '';

        $script = <<<EOT
:do { /ip service set api disabled=no port={$router->port} address={$managementSubnet} } on-error={ /log info "API service not available" }
:do { /ip service set rest-api disabled=no port={$apiPort} address={$managementSubnet} } on-error={ /log info "REST API service not available" }
:do { /ip service set api-ssl disabled=no address={$managementSubnet} } on-error={ /log info "API SSL service not available" }
:do { /ip service set ssh disabled=no port=22 address={$managementSubnet} } on-error={ /log info "SSH service not available" }
:do { /user add name={$router->username} password="$decryptedPassword" group=full } on-error={ /log info "User already exists or creation failed" }
/system identity set name="{$router->name}"
/system note set note="Managed by Traidnet Solution LTD"
{$snmpLines}
{$vpnScript}

EOT;

        return rtrim($script) . "\n";
    }

    private function generateConnectivityScript(Router $router)
    {
        $fetchUrl = config('app.url') . '/api/routers/' . $router->config_token . '/fetch-config';
        
        return "/tool fetch mode=https url=\"{$fetchUrl}\" dst-path=config.rsc keep-result=yes check-certificate=no; :delay 5s; /import config.rsc";
    }

    private function generateServiceScript(Router $router, array $interfaceAssignments, array $interfaceServices, array $configurations): string
{
    Log::info('Starting generateServiceScript', [
        'interface_assignments' => $interfaceAssignments,
        'interface_services' => $interfaceServices,
        'configurations' => $configurations,
    ]);

    $startTime = microtime(true);
    $wanInterface = $router->wan_interface ?: 'ether1';
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $wanInterface)) {
        $wanInterface = 'ether1';
    }

    $scriptLines = [
        '# Generated by Traidnet Solution LTD',
        '# Common Configuration',
        '/interface list',
        'add name=LAN',
        'add name=WAN',
        '/interface list member',
        "add list=WAN interface={$wanInterface}",
    ];

    // Collect hotspot interfaces
    $hotspotInterfaces = array_values(array_filter($interfaceAssignments, 
        fn($iface) => isset($interfaceServices[$iface]) && $interfaceServices[$iface] === 'hotspot'
    ));

    if (!empty($hotspotInterfaces)) {
        $scriptLines[] = '';
        $scriptLines[] = '# Hotspot Configuration';
        $scriptLines[] = ':if ([:len [/system package find name=hotspot]] > 0) do={';
        $scriptLines[] = '  :log info "Hotspot package found, configuring..."';
        $bridgeName = 'br-hotspot';

        // Bridge setup
        $scriptLines[] = '/interface bridge';
        $scriptLines[] = "add name=$bridgeName";
        $scriptLines[] = '/interface bridge port';
        foreach ($hotspotInterfaces as $iface) {
            if (preg_match('/^[a-zA-Z0-9\-_]+$/', $iface)) {
                $scriptLines[] = "add bridge=$bridgeName interface=$iface";
            } else {
                Log::warning('Invalid interface name skipped', ['interface' => $iface]);
            }
        }

        // Network parameters
        $firstIface = $hotspotInterfaces[0];
        $ipPool = $configurations[$firstIface]['ip_pool'] ?? $this->generateRandomPool('192.168');
        $network = $this->getNetworkFromPool($ipPool);
        $gateway = $this->getGatewayFromNetwork($network);

        // IP and DHCP with MAC binding
        $scriptLines[] = '/ip pool';
        $scriptLines[] = "add name=pool-hotspot ranges=$ipPool";
        $scriptLines[] = '/ip address';
        $scriptLines[] = "add address=$gateway/24 interface=$bridgeName";
        
        // Enable DHCP with MAC-based IP assignment
        $scriptLines[] = '/ip dhcp-server';
        $scriptLines[] = "add name=dhcp-hotspot address-pool=pool-hotspot interface=$bridgeName disabled=no lease-time=30m";
        $scriptLines[] = '/ip dhcp-server network';
        $scriptLines[] = "add address=$network dns-server=8.8.8.8,8.8.4.4 gateway=$gateway";
        $scriptLines[] = '/ip dhcp-server lease';
        $scriptLines[] = "add address=$gateway mac-address=00:00:00:00:00:00 comment=gateway-reservation disabled=yes";

        // Hotspot Profile
        $profileName = 'hs-prof';
        $scriptLines[] = '/ip hotspot profile';
        $scriptLines[] = ':do { remove [find name="' . $profileName . '"] } on-error={}';
        $scriptLines[] = "add name=\"$profileName\"";
        $scriptLines[] = ':delay 500ms';
        $scriptLines[] = ':local hp [/ip hotspot profile find name="' . $profileName . '"]; :if ([:len $hp] > 0) do={ /ip hotspot profile set $hp hotspot-address="' . $gateway . '" login-by=http-chap,mac-cookie rate-limit=10M/10M }';
        //$scriptLines[] = "/ip hotspot profile set $profileName idle-timeout=30m";

        // Hotspot Server (explicitly enabled)
        $serverName = 'hs-hotspot';
        $scriptLines[] = '/ip hotspot';
        $scriptLines[] = ':do { remove [find name="' . $serverName . '"] } on-error={}';
        $scriptLines[] = "add name=$serverName interface=$bridgeName profile=$profileName address-pool=pool-hotspot disabled=no";

        // User Profile with MAC cookie
        $userProfileName = 'hs-user';
        $scriptLines[] = '/ip hotspot user profile';
        $scriptLines[] = ':do { remove [find name="' . $userProfileName . '"] } on-error={}';
        $scriptLines[] = "add name=$userProfileName add-mac-cookie=yes rate-limit=10M/10M";

        // Add to LAN list
        $scriptLines[] = '/interface list member';
        $scriptLines[] = "add list=LAN interface=$bridgeName";
        $scriptLines[] = '} on-error={ /log info "Hotspot package not available, skipping hotspot configuration" }';
    }

    // Firewall Configuration
    $scriptLines[] = '';
    $scriptLines[] = '# Firewall Configuration';
    $scriptLines[] = '/ip firewall nat';
    $scriptLines[] = 'add chain=srcnat out-interface-list=WAN action=masquerade';
    $scriptLines[] = '/ip firewall filter';
    $scriptLines[] = 'add chain=forward action=accept connection-state=established,related';
    $scriptLines[] = 'add chain=forward action=drop connection-state=invalid';
    $scriptLines[] = 'add chain=forward action=drop connection-state=new connection-nat-state=!dstnat in-interface-list=LAN out-interface-list=WAN';

    if (!empty($hotspotInterfaces)) {
        $scriptLines[] = "# Hotspot Firewall Rules for $bridgeName";
        $scriptLines[] = '/ip firewall filter';
        $scriptLines[] = "add chain=input in-interface=$bridgeName protocol=tcp dst-port=80 action=accept comment=\"Allow HTTP to hotspot\"";
        $scriptLines[] = "add chain=input in-interface=$bridgeName protocol=tcp dst-port=443 action=accept comment=\"Allow HTTPS to hotspot\"";
        $scriptLines[] = "add chain=input in-interface=$bridgeName protocol=udp dst-port=53 action=accept comment=\"Allow DNS to hotspot\"";
    }

    $endTime = microtime(true);
    Log::info('generateServiceScript completed', [
        'execution_time' => $endTime - $startTime,
        'script_lines_count' => count($scriptLines),
    ]);

    return implode("\n", $scriptLines);
}
    private function generateRandomPool(string $prefix): string
    {
        $thirdOctet = rand(10, 250);
        $start = rand(10, 100);
        $end = $start + 50;
        return "$prefix.$thirdOctet.$start-$prefix.$thirdOctet.$end";
    }

    private function getNetworkFromPool(string $pool): string
    {
        $parts = explode('-', $pool);
        $ipParts = explode('.', $parts[0]);
        return "{$ipParts[0]}.{$ipParts[1]}.{$ipParts[2]}.0/24";
    }

    private function getGatewayFromNetwork(string $network): string
    {
        $parts = explode('.', $network);
        return "{$parts[0]}.{$parts[1]}.{$parts[2]}.1";
    }

    private function validateRouterOsScript($script)
    {
        $lines = explode("\n", $script);
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (substr_count($line, '"') % 2 !== 0) {
                Log::error('Invalid script: Unclosed quotes detected', [
                    'line_number' => $index + 1,
                    'line_content' => $line,
                ]);
                return false;
            }
        }
        return true;
    }

    public function applyConfigs(Request $request, $routerId)
    {
        $router = Router::findOrFail($routerId);

        $routerConfig = RouterConfig::where('router_id', $routerId)
            ->where('config_type', 'service')
            ->first();

        if (!$routerConfig || empty(trim($routerConfig->config_content))) {
            Log::error('No valid service configuration found in database for router', [
                'router_id' => $routerId,
                'router_name' => $router->name,
            ]);
            return response()->json(['error' => 'No valid service configuration found in database'], 400);
        }

        $serviceScript = trim($routerConfig->config_content);
        if (!$this->validateRouterOsScript($serviceScript)) {
            Log::error('Service script validation failed', [
                'router_id' => $routerId,
                'router_name' => $router->name,
            ]);
            return response()->json(['error' => 'Invalid RouterOS script syntax in configuration'], 400);
        }

        try {
            $task = $this->createRouterTask($router, RouterTask::TYPE_APPLY_SERVICE_CONFIGS);
            $this->dispatchRouterTask($task);

            return $this->routerTaskAcceptedResponse($task, 'Configuration apply task accepted');
        } catch (\Exception $e) {
            Log::error('Failed to queue configuration apply for router', [
                'router_id' => $routerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to queue configuration apply: ' . $e->getMessage()], 500);
        }
    }

    public function discoverInterfaces(Router $router)
    {
        try {
            $task = $this->createRouterTask($router, RouterTask::TYPE_DISCOVER_INTERFACES);
            $this->dispatchRouterTask($task);

            return $this->routerTaskAcceptedResponse($task, 'Interface discovery task accepted');
        } catch (\Exception $e) {
            Log::error('Failed to queue router interface discovery', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to queue interface discovery: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function createTask(Request $request, Router $router)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:' . implode(',', [
                RouterTask::TYPE_DEPLOY_SERVICE_CONFIG,
                RouterTask::TYPE_APPLY_SERVICE_CONFIGS,
                RouterTask::TYPE_VERIFY_CONNECTIVITY,
                RouterTask::TYPE_DISCOVER_INTERFACES,
            ]),
            'payload' => 'nullable|array',
        ]);

        $payload = $validated['payload'] ?? [];
        if ($validated['type'] === RouterTask::TYPE_DEPLOY_SERVICE_CONFIG) {
            validator($payload, [
                'service_type' => 'required|string|in:hotspot,pppoe',
                'commands' => 'nullable|array',
            ])->validate();
            $payload['enable_hotspot'] = ($payload['service_type'] ?? null) === 'hotspot';
            $payload['enable_pppoe'] = ($payload['service_type'] ?? null) === 'pppoe';
        }

        if ($validated['type'] === RouterTask::TYPE_APPLY_SERVICE_CONFIGS) {
            $routerConfig = RouterConfig::where('router_id', $router->id)
                ->where('config_type', 'service')
                ->first();
            if (!$routerConfig || empty(trim($routerConfig->config_content))) {
                return response()->json(['success' => false, 'error' => 'No valid service configuration found in database'], 400);
            }
            if (!$this->validateRouterOsScript(trim($routerConfig->config_content))) {
                return response()->json(['success' => false, 'error' => 'Invalid RouterOS script syntax in configuration'], 400);
            }
        }

        $task = $this->createRouterTask($router, $validated['type'], $payload);
        $this->dispatchRouterTask($task);

        return $this->routerTaskAcceptedResponse($task, 'Router task accepted');
    }

    public function getTaskStatus(Router $router, string $taskId)
    {
        $tenantId = (string) ($router->tenant_id ?? auth()->user()?->tenant_id ?? '');

        $task = RouterTask::forRouter($tenantId, (string) $router->id)
            ->whereKey($taskId)
            ->first();

        if (!$task) {
            return response()->json([
                'success' => false,
                'error' => 'Router task not found',
            ], 404);
        }

        $task = $this->failStaleProvisioningTaskIfNeeded($router, $task);

        $taskPayload = [
            'id' => $task->id,
            'type' => $task->type,
            'status' => $task->status,
            'progress' => $task->progress,
            'message' => $task->message,
            'error' => $task->error_message,
            'result' => $task->result_payload,
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
            'created_at' => $task->created_at,
            'router_id' => $task->router_id,
        ];

        if ($task->type === RouterTask::TYPE_DEPLOY_SERVICE_CONFIG) {
            $workflow = app(ProvisioningServiceClient::class)->getWorkflowStatus((string) $task->id);
            if (is_array($workflow)) {
                $task = $this->syncTaskFromWorkflowSnapshot($router, $task, $workflow);

                $taskPayload['status'] = $workflow['status'] ?? $taskPayload['status'];
                $taskPayload['progress'] = $workflow['progress'] ?? $taskPayload['progress'];
                $taskPayload['message'] = $workflow['message'] ?? $taskPayload['message'];
                $taskPayload['error'] = $workflow['error'] ?? $taskPayload['error'];
                $taskPayload['result'] = $workflow['result'] ?? $taskPayload['result'];
                $taskPayload['started_at'] = $workflow['started_at'] ?? $taskPayload['started_at'];
                $taskPayload['completed_at'] = $workflow['completed_at'] ?? $taskPayload['completed_at'];
                $taskPayload['authoritative_source'] = 'provisioning_service';
                $taskPayload['workflow_stage'] = $workflow['stage'] ?? null;
                $taskPayload['workflow_idempotency_key'] = $workflow['idempotency_key'] ?? (string) $task->id;
            }
        }

        return response()->json([
            'success' => true,
            'task' => $taskPayload,
        ]);
    }

    private function createRouterTask(Router $router, string $type, array $payload = []): RouterTask
    {
        $tenantId = (string) ($router->tenant_id ?? auth()->user()?->tenant_id ?? '');
        if ($tenantId === '') {
            throw new \RuntimeException('Tenant context not available for router task');
        }

        return RouterTask::create([
            'tenant_id' => $tenantId,
            'router_id' => (string) $router->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'status' => RouterTask::STATUS_QUEUED,
            'progress' => 0,
            'message' => 'Task accepted and queued',
            'request_payload' => $payload,
        ]);
    }

    private function dispatchRouterTask(RouterTask $task): void
    {
        switch ($task->type) {
            case RouterTask::TYPE_DEPLOY_SERVICE_CONFIG:
                RouterProvisioningJob::dispatch($task->router_id, $task->tenant_id, $task->request_payload ?? [], $task->id);
                return;

            case RouterTask::TYPE_APPLY_SERVICE_CONFIGS:
            case RouterTask::TYPE_VERIFY_CONNECTIVITY:
            case RouterTask::TYPE_DISCOVER_INTERFACES:
                ExecuteProvisioningServiceRouterTaskJob::dispatch($task->id, $task->tenant_id, $task->router_id);
                return;
        }

        throw new \InvalidArgumentException('Unsupported router task type: ' . $task->type);
    }

    private function routerTaskAcceptedResponse(RouterTask $task, string $message)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'task' => [
                'id' => $task->id,
                'type' => $task->type,
                'status' => $task->status,
                'progress' => $task->progress,
                'router_id' => $task->router_id,
                'created_at' => $task->created_at,
                'status_url' => route('api.routers.tasks.show', ['router' => $task->router_id, 'task' => $task->id]),
            ],
        ], 202);
    }

    private function discoverRouterIp($routerName)
    {
        try {
            $mdnsService = '_mikrotik-api._tcp.local';
            $output = [];
            $returnVar = 0;
            exec("avahi-browse -t -r -p $mdnsService 2>/dev/null | grep '=;.*;IPv4;.*$routerName.*'", $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                foreach ($output as $line) {
                    $parts = explode(';', $line);
                    if (count($parts) >= 8 && filter_var($parts[7], FILTER_VALIDATE_IP)) {
                        Log::info('Router discovered via mDNS:', [
                            'router_name' => $routerName,
                            'ip' => $parts[7],
                        ]);
                        return $parts[7];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('mDNS discovery failed:', [
                'router_name' => $routerName,
                'error' => $e->getMessage(),
            ]);
        }

        $subnet = '192.168.56.0/24';
        try {
            $ipList = $this->scanSubnetForRouter($subnet, $routerName);
            if (!empty($ipList)) {
                Log::info('Router discovered via subnet scan:', [
                    'router_name' => $routerName,
                    'ip' => $ipList[0],
                ]);
                return $ipList[0];
            }
        } catch (\Exception $e) {
            Log::warning('Subnet scan failed:', [
                'router_name' => $routerName,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function scanSubnetForRouter($subnet, $routerName)
    {
        $ipList = [];
        $baseIp = preg_replace('/\.\d+\/\d+$/', '.1', $subnet);
        $provisioningClient = app(ProvisioningServiceClient::class);
        $tenantId = (string) ($this->router->tenant_id ?? 'system');

        for ($i = 1; $i <= 254; $i++) {
            $ip = str_replace('.1', '.' . $i, $baseIp);

            try {
                $results = $provisioningClient->executeCommandsWithConnection(
                    'router-scan:' . $ip,
                    [
                        'ip_address' => $ip,
                        'vpn_ip' => null,
                        'username' => $this->router->username,
                        'password' => Crypt::decrypt($this->router->password),
                        'ssh_port' => (int) ($this->router->port ?? 22),
                    ],
                    ['/system identity print'],
                    $tenantId,
                );

                $identity = (string) ($results[0]['output'] ?? '');
                if (preg_match('/name:\s*(.+)/', $identity, $match) && trim($match[1]) === $routerName) {
                    $ipList[] = $ip;
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $ipList;
    }

    /**
     * Get device events for a specific router
     */
    public function getRouterEvents(Router $router, Request $request)
    {
        try {
            $perPage = min((int) $request->input('per_page', 25), 100);
            $level = $request->input('level');
            $days = (int) $request->input('days', 30);

            $baseQuery = SystemLog::withoutGlobalScopes()
                ->where('entity_type', 'router')
                ->where('entity_id', (string) $router->id)
                ->where('tenant_id', (string) $router->tenant_id)
                ->where('created_at', '>=', now()->subDays($days));

            $query = (clone $baseQuery)->with('user:id,name,email');

            if ($level && in_array($level, ['info', 'warning', 'error', 'critical'])) {
                $query->where('level', $level);
            }

            $events = $query->orderByDesc('created_at')->paginate($perPage);

            $counts = (clone $baseQuery)
                ->selectRaw("level, COUNT(*) as count")
                ->groupBy('level')
                ->pluck('count', 'level');

            $last24hCount = SystemLog::withoutGlobalScopes()
                ->where('entity_type', 'router')
                ->where('entity_id', (string) $router->id)
                ->where('tenant_id', (string) $router->tenant_id)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            return response()->json([
                'success' => true,
                'events' => $events,
                'summary' => [
                    'total' => $counts->sum(),
                    'critical' => $counts->get('critical', 0),
                    'error' => $counts->get('error', 0),
                    'warning' => $counts->get('warning', 0),
                    'info' => $counts->get('info', 0),
                    'last_24h' => $last24hCount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch router events', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'Failed to fetch events'], 500);
        }
    }

    private function routerListCacheKey(string $tenantId, int $perPage): string
    {
        return "tenant_router_index:{$tenantId}:pp{$perPage}";
    }

    private function bustRouterListCache(string $tenantId): void
    {
        foreach ([15, 20, 25, 50, 100] as $perPage) {
            Cache::forget($this->routerListCacheKey($tenantId, $perPage));
        }
    }
}
