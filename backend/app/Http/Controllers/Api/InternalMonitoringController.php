<?php

namespace App\Http\Controllers\Api;

use App\Events\RouterStatusUpdated;
use App\Events\RouterLiveDataUpdated;
use App\Events\RouterMetricsUpdated;
use App\Events\VpnConnectivityChecking;
use App\Events\VpnConnectivityFailed;
use App\Events\VpnConnectivityVerified;
use App\Http\Controllers\Controller;
use App\Jobs\DiscoverRouterInterfacesJob;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnConfiguration;
use App\Models\WireguardPeer;
use App\Services\CacheInvalidationService;
use App\Services\TenantContext;
use App\Services\TenantMigrationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InternalMonitoringController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function updateVpnStatus(Request $request, string $tenantId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:completed,failed',
            'result' => 'nullable|array',
            'result.routers' => 'nullable|array',
            'error' => 'nullable|string|max:2000',
        ]);

        if ($validated['status'] === 'failed') {
            Log::warning('Monitoring callback reported VPN refresh failure', [
                'tenant_id' => $tenantId,
                'error' => $validated['error'] ?? null,
            ]);

            return response()->json(['success' => true]);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (! $tenant) {
            return response()->json(['success' => true]);
        }

        $routerUpdates = [];
        DB::transaction(function () use ($tenant, $validated, &$routerUpdates, $tenantId) {
            $this->tenantContext->runInTenantContext($tenant, function () use ($validated, &$routerUpdates, $tenantId) {
                foreach (($validated['result']['routers'] ?? []) as $entry) {
                    $router = Router::find($entry['router_id'] ?? null);
                    if (! $router) {
                        continue;
                    }

                    $handshakeAt = $this->parseNullableTimestamp($entry['vpn_last_handshake'] ?? null);
                    $vpnConfig = ! empty($entry['public_key'])
                        ? VpnConfiguration::where('client_public_key', $entry['public_key'])->first()
                        : null;

                    if ($vpnConfig) {
                        $vpnConfig->update([
                            'last_handshake_at' => $handshakeAt,
                            'rx_bytes' => (int) ($entry['transfer_rx'] ?? 0),
                            'tx_bytes' => (int) ($entry['transfer_tx'] ?? 0),
                            'status' => (string) ($entry['vpn_config_status'] ?? 'disconnected'),
                        ]);
                    }

                    if (! empty($entry['public_key'])) {
                        WireguardPeer::updateOrCreate(
                            ['public_key' => $entry['public_key']],
                            [
                                'router_id' => $router->id,
                                'peer_name' => $router->name,
                                'endpoint' => $entry['endpoint'] ?? null,
                                'allowed_ips' => $entry['allowed_ips'] ?? null,
                                'transfer_rx' => (int) ($entry['transfer_rx'] ?? 0),
                                'transfer_tx' => (int) ($entry['transfer_tx'] ?? 0),
                                'last_handshake' => $handshakeAt,
                            ]
                        );
                    }

                    $routerUpdates[] = $this->applyOperationalRouterUpdate($router, $entry, $handshakeAt, $tenantId);
                }
            });
        });

        $this->broadcastRouterUpdates($tenantId, $routerUpdates);

        return response()->json([
            'success' => true,
            'updated_router_count' => count($routerUpdates),
        ]);
    }

    public function updateRouterStatus(Request $request, string $tenantId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:completed,failed',
            'result' => 'nullable|array',
            'result.routers' => 'nullable|array',
            'error' => 'nullable|string|max:2000',
        ]);

        if ($validated['status'] === 'failed') {
            Log::warning('Monitoring callback reported router status refresh failure', [
                'tenant_id' => $tenantId,
                'error' => $validated['error'] ?? null,
            ]);

            return response()->json(['success' => true]);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (! $tenant) {
            return response()->json(['success' => true]);
        }

        $routerUpdates = [];
        $discoveryRouterIds = [];

        DB::transaction(function () use ($tenant, $validated, &$routerUpdates, &$discoveryRouterIds, $tenantId) {
            $this->tenantContext->runInTenantContext($tenant, function () use ($validated, &$routerUpdates, &$discoveryRouterIds, $tenantId) {
                foreach (($validated['result']['routers'] ?? []) as $entry) {
                    $router = Router::find($entry['router_id'] ?? null);
                    if (! $router) {
                        continue;
                    }

                    $phase = (string) ($entry['phase'] ?? 'operational');
                    if ($phase === 'provisioning' && ! empty($entry['discovery_required'])) {
                        $routerUpdates[] = $this->applyProvisioningRouterUpdate($router, $entry, $tenantId, $discoveryRouterIds);
                        continue;
                    }

                    $handshakeAt = $this->parseNullableTimestamp($entry['vpn_last_handshake'] ?? null);
                    $routerUpdates[] = $this->applyOperationalRouterUpdate($router, $entry, $handshakeAt, $tenantId);
                }
            });
        });

        foreach ($discoveryRouterIds as $routerId) {
            $key = 'discovery_dispatch_' . $routerId;
            if (! Cache::has($key)) {
                Cache::put($key, true, 30);
                dispatch(new DiscoverRouterInterfacesJob($tenantId, $routerId))->onQueue('router-provisioning');
            }
        }

        $this->broadcastRouterUpdates($tenantId, $routerUpdates);

        return response()->json([
            'success' => true,
            'updated_router_count' => count($routerUpdates),
            'discovery_dispatch_count' => count($discoveryRouterIds),
        ]);
    }

    public function updateLiveData(Request $request, string $tenantId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:completed,failed',
            'result' => 'nullable|array',
            'result.routers' => 'nullable|array',
            'error' => 'nullable|string|max:2000',
        ]);

        if ($validated['status'] === 'failed') {
            Log::warning('Monitoring callback reported live data refresh failure', [
                'tenant_id' => $tenantId,
                'error' => $validated['error'] ?? null,
            ]);

            return response()->json(['success' => true]);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (! $tenant) {
            return response()->json(['success' => true]);
        }

        $updatedRouterCount = 0;
        $this->tenantContext->runInTenantContext($tenant, function () use ($validated, $tenantId, &$updatedRouterCount) {
            foreach (($validated['result']['routers'] ?? []) as $entry) {
                $router = Router::find($entry['router_id'] ?? null);
                $liveData = is_array($entry['data'] ?? null) ? $entry['data'] : null;
                if (! $router || ! $liveData) {
                    continue;
                }

                Cache::put('router_live_data_' . $router->id, $liveData, now()->addSeconds(30));
                $router->update(['last_checked' => now()]);

                broadcast(new RouterLiveDataUpdated($tenantId, (string) $router->id, $liveData))->toOthers();
                $updatedRouterCount++;
            }
        });

        return response()->json([
            'success' => true,
            'updated_router_count' => $updatedRouterCount,
        ]);
    }

    public function updateRouterMetrics(Request $request, string $tenantId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:completed,failed',
            'result' => 'nullable|array',
            'result.ranges' => 'nullable|array',
            'result.ranges.*.time_range' => 'required_with:result.ranges|string|max:32',
            'result.ranges.*.routers' => 'nullable|array',
            'result.ranges.*.routers.*.router_id' => 'required_with:result.ranges.*.routers|string',
            'error' => 'nullable|string|max:2000',
        ]);

        if ($validated['status'] === 'failed') {
            Log::warning('Monitoring callback reported router metrics computation failure', [
                'tenant_id' => $tenantId,
                'error' => $validated['error'] ?? null,
            ]);

            return response()->json(['success' => true]);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (! $tenant) {
            return response()->json(['success' => true]);
        }

        $updatedSeriesCount = 0;
        $this->tenantContext->runInTenantContext($tenant, function () use ($validated, $tenantId, &$updatedSeriesCount) {
            foreach (($validated['result']['ranges'] ?? []) as $rangeEntry) {
                $timeRange = (string) ($rangeEntry['time_range'] ?? '');
                if ($timeRange === '') {
                    continue;
                }

                foreach (($rangeEntry['routers'] ?? []) as $routerEntry) {
                    $router = Router::find($routerEntry['router_id'] ?? null);
                    if (! $router) {
                        continue;
                    }

                    $routerId = (string) $router->id;
                    $traffic = is_array($routerEntry['traffic'] ?? null) ? $routerEntry['traffic'] : [];
                    $resources = is_array($routerEntry['resources'] ?? null) ? $routerEntry['resources'] : [];

                    if ($traffic !== []) {
                        Cache::put("router_metrics_{$tenantId}_{$routerId}_traffic_{$timeRange}", $traffic, now()->addSeconds(60));
                        broadcast(new RouterMetricsUpdated($tenantId, $routerId, $traffic, 'traffic', $timeRange))->toOthers();
                        $updatedSeriesCount++;
                    }

                    if ($resources !== []) {
                        Cache::put("router_metrics_{$tenantId}_{$routerId}_resources_{$timeRange}", $resources, now()->addSeconds(60));
                        broadcast(new RouterMetricsUpdated($tenantId, $routerId, $resources, 'resources', $timeRange))->toOthers();
                        $updatedSeriesCount++;
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'updated_series_count' => $updatedSeriesCount,
        ]);
    }

    public function updateVpnVerification(Request $request, string $tenantId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:running,completed,failed',
            'progress' => 'nullable|integer|min:0|max:100',
            'result' => 'nullable|array',
            'message' => 'nullable|string|max:1000',
            'error' => 'nullable|string|max:2000',
        ]);

        $tenant = $this->resolveTenant($tenantId);
        if (! $tenant) {
            return response()->json(['success' => true]);
        }

        $result = is_array($validated['result'] ?? null) ? $validated['result'] : [];
        $routerId = (string) ($result['router_id'] ?? '');
        $vpnConfigId = (int) ($result['vpn_config_id'] ?? 0);
        $clientIp = (string) ($result['client_ip'] ?? '');

        if ($validated['status'] === 'running') {
            broadcast(new VpnConnectivityChecking(
                $tenantId,
                $routerId,
                $vpnConfigId,
                $clientIp,
                (int) ($result['attempt'] ?? 1),
                (int) ($result['max_attempts'] ?? 1),
            ));

            return response()->json(['success' => true]);
        }

        $migrationManager = app(TenantMigrationManager::class);
        if ($migrationManager->hasPendingMigrations($tenant)) {
            $migrationManager->runMigrationsForTenant($tenant);
        }

        $this->tenantContext->runInTenantContext($tenant, function () use ($validated, $tenantId, $routerId, $vpnConfigId, $clientIp, $result) {
            $vpnConfig = $this->resolveVpnConfigurationForVerification($routerId, $vpnConfigId, $clientIp);
            $router = $routerId !== '' ? Router::find($routerId) : null;

            if ($validated['status'] === 'completed') {
                if ($vpnConfig) {
                    $vpnConfig->update([
                        'status' => 'connected',
                        'last_handshake_at' => now(),
                    ]);
                } else {
                    Log::warning('VPN verification completed without a resolvable VPN configuration', [
                        'tenant_id' => $tenantId,
                        'router_id' => $routerId,
                        'vpn_config_id' => $vpnConfigId,
                        'client_ip' => $clientIp,
                    ]);
                }

                if ($router) {
                    $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];
                    $inProvisioning = in_array($router->status, $provisioningStatuses, true);
                    $now = now();

                    if ($inProvisioning) {
                        $router->update([
                            'status' => $router->status === 'pending' ? 'provisioning' : $router->status,
                            'provisioning_stage' => $router->provisioning_stage ?? 'vpn_verified',
                            'last_seen' => $now,
                            'last_checked' => $now,
                        ]);
                    } else {
                        $router->update([
                            'status' => 'online',
                            'vpn_status' => 'active',
                            'last_seen' => $now,
                            'last_checked' => $now,
                            'vpn_last_handshake' => $now,
                        ]);
                    }

                    $key = 'discovery_dispatch_' . $router->id;
                    if (! Cache::has($key)) {
                        Cache::put($key, true, 30);
                        dispatch(new DiscoverRouterInterfacesJob($tenantId, (string) $router->id))->onQueue('router-provisioning');
                    }
                }

                broadcast(new VpnConnectivityVerified(
                    $tenantId,
                    $routerId,
                    (int) ($vpnConfig?->id ?? $vpnConfigId),
                    (string) ($vpnConfig?->client_ip ?? $clientIp),
                    (float) ($result['latency_ms'] ?? 0),
                    0,
                    (int) ($result['attempts'] ?? 1),
                ));

                return;
            }

            if ($vpnConfig) {
                $vpnConfig->update(['status' => 'disconnected']);
            }

            if ($router) {
                $router->update([
                    'status' => 'failed',
                    'provisioning_stage' => 'verify_connectivity_failed',
                    'vpn_status' => 'inactive',
                    'last_checked' => now(),
                ]);
            }

            broadcast(new VpnConnectivityFailed(
                $tenantId,
                $routerId,
                (int) ($vpnConfig?->id ?? $vpnConfigId),
                (string) ($vpnConfig?->client_ip ?? $clientIp),
                (string) ($validated['error'] ?? 'VPN connectivity timeout'),
                (int) ($result['attempts'] ?? 1),
            ));
        });

        return response()->json(['success' => true]);
    }

    private function resolveVpnConfigurationForVerification(string $routerId, int $vpnConfigId, string $clientIp): ?VpnConfiguration
    {
        try {
            if ($vpnConfigId > 0) {
                $vpnConfig = VpnConfiguration::find($vpnConfigId);
                if ($vpnConfig) {
                    return $vpnConfig;
                }
            }

            $query = VpnConfiguration::query();

            if ($routerId !== '') {
                $query->where('router_id', $routerId);
            }

            if ($clientIp !== '') {
                $query->when($routerId !== '', function ($builder) use ($clientIp) {
                    $builder->orWhere('client_ip', $clientIp);
                }, function ($builder) use ($clientIp) {
                    $builder->where('client_ip', $clientIp);
                });
            }

            if ($routerId === '' && $clientIp === '') {
                return null;
            }

            return $query->latest('id')->first();
        } catch (\Throwable $e) {
            Log::warning('Unable to resolve VPN configuration during verification', [
                'router_id' => $routerId,
                'vpn_config_id' => $vpnConfigId,
                'client_ip' => $clientIp,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveTenant(string $tenantId): ?Tenant
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->schema_created || ! $tenant->schema_name) {
            Log::warning('Monitoring callback skipped because tenant schema is unavailable', [
                'tenant_id' => $tenantId,
            ]);

            return null;
        }

        return $tenant;
    }

    private function applyOperationalRouterUpdate(Router $router, array $entry, ?Carbon $handshakeAt, string $tenantId): array
    {
        $previousStatus = $router->status;
        $previousVpnStatus = $router->vpn_status;

        $router->update([
            'status' => (string) ($entry['status'] ?? 'offline'),
            'vpn_status' => (string) ($entry['vpn_status'] ?? 'inactive'),
            'vpn_last_handshake' => $handshakeAt,
            'last_checked' => now(),
            'last_seen' => ($entry['status'] ?? null) === 'online' ? now() : $router->last_seen,
        ]);

        if ($previousStatus !== $router->status || $previousVpnStatus !== $router->vpn_status) {
            CacheInvalidationService::invalidateRouterCache($tenantId, (string) $router->id);
        }

        return $this->buildRouterPayload($router, $tenantId);
    }

    private function applyProvisioningRouterUpdate(Router $router, array $entry, string $tenantId, array &$discoveryRouterIds): array
    {
        $previousStatus = $router->status;
        $status = (string) ($entry['status'] ?? $router->status);
        $provisioningStage = (string) ($entry['provisioning_stage'] ?? $router->provisioning_stage ?? 'ping_verified');

        $router->update([
            'status' => $status,
            'provisioning_stage' => $provisioningStage,
            'last_seen' => now(),
            'last_checked' => now(),
        ]);

        if ($previousStatus !== $router->status) {
            CacheInvalidationService::invalidateRouterCache($tenantId, (string) $router->id);
        }

        $discoveryRouterIds[] = (string) $router->id;

        return $this->buildRouterPayload($router, $tenantId);
    }

    private function buildRouterPayload(Router $router, string $tenantId): array
    {
        return [
            'id' => $router->id,
            'name' => $router->name,
            'ip_address' => $router->ip_address,
            'vpn_ip' => $router->vpn_ip,
            'status' => $router->status,
            'vpn_status' => $router->vpn_status,
            'vpn_last_handshake' => $router->vpn_last_handshake,
            'last_checked' => $router->last_checked,
            'last_seen' => $router->last_seen,
            'model' => $router->model,
            'os_version' => $router->os_version,
            'tenant_id' => $tenantId,
        ];
    }

    private function broadcastRouterUpdates(string $tenantId, array $routerUpdates): void
    {
        if ($routerUpdates !== []) {
            broadcast(new RouterStatusUpdated($routerUpdates, $tenantId))->toOthers();
        }
    }

    private function parseNullableTimestamp(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value);
    }
}
