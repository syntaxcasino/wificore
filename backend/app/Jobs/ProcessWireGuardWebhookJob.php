<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Models\Router;
use App\Models\RouterTenantMap;
use App\Models\Tenant;
use App\Models\WireguardPeer;
use App\Services\CacheInvalidationService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Carbon;

/**
 * Process WireGuard Webhook Job
 *
 * Processes peer activity events from WireGuard webhook endpoint.
 * Updates router status immediately when handshakes occur.
 */
class ProcessWireGuardWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public array $eventData;
    public $tries = 3;
    public $timeout = 30;
    private array $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];
    private array $completedProvisioningStages = ['completed', 'interfaces_discovered', 'config_applied', 'connectivity_verified'];

    public function __construct(array $eventData)
    {
        $this->eventData = $eventData;
        $this->onQueue('router-monitoring');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $eventType = $this->eventData['event_type'] ?? 'handshake';

        Log::debug('Processing WireGuard webhook', [
            'event_type' => $eventType,
            'public_key' => isset($this->eventData['public_key']) 
                ? substr($this->eventData['public_key'], 0, 20) . '...' 
                : null,
        ]);

        try {
            switch ($eventType) {
                case 'handshake':
                    $this->processHandshake();
                    break;
                case 'expired':
                    $this->processExpired();
                    break;
                case 'batch':
                    $this->processBatch();
                    break;
                default:
                    Log::warning('Unknown webhook event type', ['event_type' => $eventType]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process WireGuard webhook', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Process single peer handshake event.
     *
     * Resolves tenant via RouterTenantMap (public schema), then switches to the
     * correct tenant schema before touching wireguard_peers / routers.
     */
    private function processHandshake(): void
    {
        $publicKey = $this->eventData['public_key'];
        $handshakeTimestamp = $this->eventData['latest_handshake'];
        $endpoint = $this->eventData['endpoint'] ?? null;

        // --- Cross-tenant resolution via public-schema map ---
        $map = RouterTenantMap::findByVpnPublicKey($publicKey);

        if (!$map) {
            Log::debug('WireGuard handshake: no router_tenant_map entry for public key', [
                'public_key' => substr($publicKey, 0, 20) . '...',
            ]);
            return;
        }

        $tenantId = $map['tenant_id'];
        $tenant = Tenant::find($tenantId);

        if (!$tenant || !$tenant->schema_created) {
            Log::warning('WireGuard handshake: tenant not found or schema not ready', [
                'tenant_id' => $tenantId,
            ]);
            return;
        }

        // --- All DB work runs inside the correct tenant schema ---
        $this->tenantId = $tenantId;
        $this->executeInTenantContext(function () use ($publicKey, $handshakeTimestamp, $endpoint, $tenantId) {
            $peer = WireguardPeer::where('public_key', $publicKey)->first();

            if (!$peer) {
                Log::debug('WireGuard handshake: peer not found in tenant schema', [
                    'public_key' => substr($publicKey, 0, 20) . '...',
                    'tenant_id' => $tenantId,
                ]);
                return;
            }

            $handshakeAt = Carbon::createFromTimestamp($handshakeTimestamp);
            $peer->update([
                'last_handshake' => $handshakeAt,
                'endpoint' => $endpoint,
            ]);

            $router = $peer->router;
            if (!$router) {
                return;
            }

            $previousStatus = $router->status;
            $previousVpnStatus = $router->vpn_status;
            $now = now();
            $inProvisioning = in_array($router->status, $this->provisioningStatuses, true)
                || (! in_array($router->provisioning_stage, $this->completedProvisioningStages, true) && $router->provisioning_stage !== null);

            // On first handshake a router always becomes online regardless of provisioning state.
            // The WireGuard tunnel is live — that IS the proof of connectivity.
            $router->update([
                'status' => 'online',
                'provisioning_stage' => $inProvisioning ? 'completed' : $router->provisioning_stage,
                'vpn_status' => 'active',
                'vpn_last_handshake' => $handshakeAt,
                'last_seen' => $now,
                'last_checked' => $now,
            ]);

            $this->broadcastStatusUpdate($router, $previousStatus, $previousVpnStatus, $tenantId);

            // If the router was in a provisioning state, trigger interface discovery now.
            // This is the earliest possible moment to run discovery after the WireGuard
            // tunnel is confirmed live. Uses a dedup cache to avoid duplicate dispatches.
            if ($inProvisioning) {
                $discoveryKey = 'discovery_dispatch_' . $router->id;
                if (!Cache::has($discoveryKey)) {
                    Cache::put($discoveryKey, true, 30);
                    dispatch(new DiscoverRouterInterfacesJob($tenantId, $router->id))
                        ->onQueue('router-provisioning');

                    Log::info('WireGuard handshake triggered discovery for provisioning router', [
                        'router_id' => $router->id,
                        'router_name' => $router->name,
                        'tenant_id' => $tenantId,
                    ]);
                }
            }

            Log::info('Router marked online via WireGuard handshake', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'previous_status' => $previousStatus,
                'handshake_age_seconds' => abs(now()->diffInSeconds($handshakeAt, false)),
                'tenant_id' => $tenantId,
            ]);
        });
    }

    /**
     * Process peer expired event.
     *
     * Resolves tenant via RouterTenantMap then switches to the correct schema.
     */
    private function processExpired(): void
    {
        $publicKey = $this->eventData['public_key'];

        $map = RouterTenantMap::findByVpnPublicKey($publicKey);

        if (!$map) {
            Log::debug('WireGuard expired: no router_tenant_map entry for public key', [
                'public_key' => substr($publicKey, 0, 20) . '...',
            ]);
            return;
        }

        $tenantId = $map['tenant_id'];
        $tenant = Tenant::find($tenantId);

        if (!$tenant || !$tenant->schema_created) {
            return;
        }

        $this->tenantId = $tenantId;
        $this->executeInTenantContext(function () use ($publicKey, $tenantId) {
            $peer = WireguardPeer::where('public_key', $publicKey)->first();

            if (!$peer) {
                return;
            }

            $peer->update(['last_handshake' => null]);

            $router = $peer->router;
            if (!$router) {
                return;
            }

            $previousStatus = $router->status;
            $previousVpnStatus = $router->vpn_status;
            $inProvisioning = in_array($router->status, $this->provisioningStatuses, true)
                || (! in_array($router->provisioning_stage, $this->completedProvisioningStages, true) && $router->provisioning_stage !== null);

            // Mark router offline only if operational; leave provisioning routers alone
            $router->update([
                'status' => $inProvisioning ? $router->status : 'offline',
                'vpn_status' => 'inactive',
                'vpn_last_handshake' => null,
                'last_checked' => now(),
            ]);

            $this->broadcastStatusUpdate($router, $previousStatus, $previousVpnStatus, $tenantId);

            Log::info('Router marked offline via WireGuard expired event', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'previous_status' => $previousStatus,
                'tenant_id' => $tenantId,
            ]);
        });
    }

    /**
     * Process batch peer update.
     *
     * Groups peers by tenant (resolved via RouterTenantMap), then processes
     * each tenant group within the correct schema context.
     */
    private function processBatch(): void
    {
        $peers = $this->eventData['peers'] ?? [];

        // Group peers by tenant using the public-schema map — avoids cross-schema queries
        $byTenant = [];
        foreach ($peers as $peerData) {
            if (empty($peerData['latest_handshake'])) {
                continue;
            }
            $map = RouterTenantMap::findByVpnPublicKey($peerData['public_key']);
            if (!$map) {
                continue;
            }
            $byTenant[$map['tenant_id']][] = $peerData;
        }

        foreach ($byTenant as $tenantId => $tenantPeers) {
            $tenant = Tenant::find($tenantId);
            if (!$tenant || !$tenant->schema_created) {
                continue;
            }

            $this->tenantId = $tenantId;
            $this->executeInTenantContext(function () use ($tenantPeers, $tenantId) {
                $payload = [];

                foreach ($tenantPeers as $peerData) {
                    $peer = WireguardPeer::where('public_key', $peerData['public_key'])->first();
                    if (!$peer) {
                        continue;
                    }

                    $handshakeAt = Carbon::createFromTimestamp($peerData['latest_handshake']);

                    // Only update if handshake is newer
                    if ($peer->last_handshake && $peer->last_handshake->gte($handshakeAt)) {
                        continue;
                    }

                    $peer->update([
                        'last_handshake' => $handshakeAt,
                        'endpoint' => $peerData['endpoint'] ?? null,
                    ]);

                    $router = $peer->router;
                    if (!$router) {
                        continue;
                    }

                    $previousStatus = $router->status;
                    $inProvisioning = in_array($router->status, $this->provisioningStatuses, true)
                        || (! in_array($router->provisioning_stage, $this->completedProvisioningStages, true) && $router->provisioning_stage !== null);
                    $now = now();

                    // WireGuard handshake = tunnel live = router online, always
                    $router->update([
                        'status' => 'online',
                        'provisioning_stage' => $inProvisioning ? 'completed' : $router->provisioning_stage,
                        'vpn_status' => 'active',
                        'vpn_last_handshake' => $handshakeAt,
                        'last_seen' => $now,
                        'last_checked' => $now,
                    ]);

                    CacheInvalidationService::invalidateRouterCache($tenantId, (string) $router->id);

                    if ($inProvisioning) {
                        $discoveryKey = 'discovery_dispatch_' . $router->id;
                        if (!Cache::has($discoveryKey)) {
                            Cache::put($discoveryKey, true, 30);
                            dispatch(new DiscoverRouterInterfacesJob($tenantId, $router->id))
                                ->onQueue('router-provisioning');
                        }
                    }

                    $payload[] = [
                        'id' => $router->id,
                        'name' => $router->name,
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'status' => 'online',
                        'previous_status' => $previousStatus,
                        'vpn_status' => 'active',
                        'last_seen' => $router->last_seen,
                        'tenant_id' => $tenantId,
                    ];
                }

                if (!empty($payload)) {
                    try {
                        broadcast(new RouterStatusUpdated($payload, $tenantId))->toOthers();
                    } catch (\Exception $e) {
                        Log::warning('WireGuard batch: failed to broadcast', ['error' => $e->getMessage()]);
                    }

                    Log::info('WireGuard batch: updated routers for tenant', [
                        'tenant_id' => $tenantId,
                        'count' => count($payload),
                    ]);
                }
            });
        }
    }

    /**
     * Broadcast status update event.
     *
     * @param string $tenantId Passed explicitly — avoids the extra DB lookup
     *                         that $router->tenant_id triggers via RouterTenantMap.
     */
    private function broadcastStatusUpdate(Router $router, string $previousStatus, string $previousVpnStatus, string $tenantId): void
    {
        try {
            CacheInvalidationService::invalidateRouterCache($tenantId, (string) $router->id);

            $payload = [
                [
                    'id' => $router->id,
                    'name' => $router->name,
                    'ip_address' => $router->ip_address,
                    'vpn_ip' => $router->vpn_ip,
                    'status' => $router->status,
                    'previous_status' => $previousStatus,
                    'vpn_status' => $router->vpn_status,
                    'previous_vpn_status' => $previousVpnStatus,
                    'last_seen' => $router->last_seen,
                    'last_checked' => now(),
                    'tenant_id' => $tenantId,
                    'vpn_last_handshake' => $router->vpn_last_handshake,
                ]
            ];

            broadcast(new RouterStatusUpdated($payload, $tenantId))->toOthers();

            Redis::publish('router:status:changed', json_encode([
                'tenant_id' => $tenantId,
                'routers' => $payload,
                'timestamp' => now()->toIso8601String(),
            ]));
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast status update', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
