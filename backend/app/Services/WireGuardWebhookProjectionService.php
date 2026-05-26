<?php

namespace App\Services;

use App\Events\RouterStatusUpdated;
use App\Jobs\DiscoverRouterInterfacesJob;
use App\Models\RouterTenantMap;
use App\Models\Tenant;
use App\Models\WireguardPeer;
use App\Services\CacheInvalidationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class WireGuardWebhookProjectionService
{
    private array $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function process(array $eventData): void
    {
        $eventType = $eventData['event_type'] ?? 'handshake';

        match ($eventType) {
            'handshake' => $this->processHandshake($eventData),
            'expired' => $this->processExpired($eventData),
            'batch' => $this->processBatch($eventData),
            default => Log::warning('Unknown webhook event type', ['event_type' => $eventType]),
        };
    }

    private function processHandshake(array $eventData): void
    {
        $publicKey = (string) $eventData['public_key'];
        $map = RouterTenantMap::findByVpnPublicKey($publicKey);
        if (! $map) {
            return;
        }

        $tenantId = $map['tenant_id'];
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->schema_created) {
            return;
        }

        $this->tenantContext->runInTenantContext($tenant, function () use ($eventData, $tenantId, $publicKey) {
            $peer = WireguardPeer::where('public_key', $publicKey)->first();
            if (! $peer || ! $peer->router) {
                return;
            }

            $handshakeAt = Carbon::createFromTimestamp((int) $eventData['latest_handshake']);
            $peer->update([
                'last_handshake' => $handshakeAt,
                'endpoint' => $eventData['endpoint'] ?? null,
                'transfer_rx' => (int) ($eventData['transfer_rx'] ?? $peer->transfer_rx ?? 0),
                'transfer_tx' => (int) ($eventData['transfer_tx'] ?? $peer->transfer_tx ?? 0),
            ]);

            $router = $peer->router;
            $previousStatus = $router->status;
            $previousVpnStatus = $router->vpn_status;
            $inProvisioning = in_array($router->status, $this->provisioningStatuses, true);

            $router->update([
                'status' => 'online',
                'provisioning_stage' => $inProvisioning ? 'completed' : $router->provisioning_stage,
                'vpn_status' => 'active',
                'vpn_last_handshake' => $handshakeAt,
                'last_seen' => now(),
                'last_checked' => now(),
            ]);

            $this->broadcastStatusUpdate($router->toArray(), $tenantId, $previousStatus, $previousVpnStatus);
            $this->dispatchDiscoveryIfNeeded($tenantId, (string) $router->id, $router->name, $inProvisioning);
        });
    }

    private function processExpired(array $eventData): void
    {
        $publicKey = (string) $eventData['public_key'];
        $map = RouterTenantMap::findByVpnPublicKey($publicKey);
        if (! $map) {
            return;
        }

        $tenantId = $map['tenant_id'];
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->schema_created) {
            return;
        }

        $this->tenantContext->runInTenantContext($tenant, function () use ($tenantId, $publicKey) {
            $peer = WireguardPeer::where('public_key', $publicKey)->first();
            if (! $peer || ! $peer->router) {
                return;
            }

            $peer->update(['last_handshake' => null]);
            $router = $peer->router;
            $previousStatus = $router->status;
            $previousVpnStatus = $router->vpn_status;
            $inProvisioning = in_array($router->status, $this->provisioningStatuses, true);
            $router->update([
                'status' => $inProvisioning ? $router->status : 'offline',
                'vpn_status' => 'inactive',
                'vpn_last_handshake' => null,
                'last_checked' => now(),
            ]);

            $this->broadcastStatusUpdate($router->toArray(), $tenantId, $previousStatus, $previousVpnStatus);
        });
    }

    private function processBatch(array $eventData): void
    {
        $byTenant = [];
        foreach (($eventData['peers'] ?? []) as $peerData) {
            if (empty($peerData['latest_handshake'])) {
                continue;
            }
            $map = RouterTenantMap::findByVpnPublicKey($peerData['public_key']);
            if (! $map) {
                continue;
            }
            $byTenant[$map['tenant_id']][] = $peerData;
        }

        foreach ($byTenant as $tenantId => $tenantPeers) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant || ! $tenant->schema_created) {
                continue;
            }

            $this->tenantContext->runInTenantContext($tenant, function () use ($tenantPeers, $tenantId) {
                $payload = [];
                foreach ($tenantPeers as $peerData) {
                    $peer = WireguardPeer::where('public_key', $peerData['public_key'])->first();
                    if (! $peer || ! $peer->router) {
                        continue;
                    }

                    $handshakeAt = Carbon::createFromTimestamp((int) $peerData['latest_handshake']);
                    if ($peer->last_handshake && $peer->last_handshake->gte($handshakeAt)) {
                        continue;
                    }

                    $peer->update([
                        'last_handshake' => $handshakeAt,
                        'endpoint' => $peerData['endpoint'] ?? null,
                    ]);

                    $router = $peer->router;
                    $inProvisioning = in_array($router->status, $this->provisioningStatuses, true);
                    $router->update([
                        'status' => 'online',
                        'provisioning_stage' => $inProvisioning ? 'completed' : $router->provisioning_stage,
                        'vpn_status' => 'active',
                        'vpn_last_handshake' => $handshakeAt,
                        'last_seen' => now(),
                        'last_checked' => now(),
                    ]);

                    CacheInvalidationService::invalidateRouterCache($tenantId, (string) $router->id);
                    $this->dispatchDiscoveryIfNeeded($tenantId, (string) $router->id, $router->name, $inProvisioning);

                    $payload[] = [
                        'id' => $router->id,
                        'name' => $router->name,
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'status' => 'online',
                        'vpn_status' => 'active',
                        'last_seen' => $router->last_seen,
                        'last_checked' => $router->last_checked,
                        'tenant_id' => $tenantId,
                        'vpn_last_handshake' => $router->vpn_last_handshake,
                    ];
                }

                if ($payload !== []) {
                    broadcast(new RouterStatusUpdated($payload, $tenantId))->toOthers();
                    Redis::publish('router:status:changed', json_encode([
                        'tenant_id' => $tenantId,
                        'routers' => $payload,
                        'timestamp' => now()->toIso8601String(),
                    ]));
                }
            });
        }
    }

    private function broadcastStatusUpdate(array $router, string $tenantId, string $previousStatus, string $previousVpnStatus): void
    {
        CacheInvalidationService::invalidateRouterCache($tenantId, (string) $router['id']);

        $payload = [[
            'id' => $router['id'],
            'name' => $router['name'],
            'ip_address' => $router['ip_address'],
            'vpn_ip' => $router['vpn_ip'],
            'status' => $router['status'],
            'previous_status' => $previousStatus,
            'vpn_status' => $router['vpn_status'],
            'previous_vpn_status' => $previousVpnStatus,
            'last_seen' => $router['last_seen'] ?? null,
            'last_checked' => $router['last_checked'] ?? now(),
            'tenant_id' => $tenantId,
            'vpn_last_handshake' => $router['vpn_last_handshake'] ?? null,
        ]];

        broadcast(new RouterStatusUpdated($payload, $tenantId))->toOthers();
        Redis::publish('router:status:changed', json_encode([
            'tenant_id' => $tenantId,
            'routers' => $payload,
            'timestamp' => now()->toIso8601String(),
        ]));
    }

    private function dispatchDiscoveryIfNeeded(string $tenantId, string $routerId, string $routerName, bool $inProvisioning): void
    {
        if (! $inProvisioning) {
            return;
        }

        $discoveryKey = 'discovery_dispatch_' . $routerId;
        if (! Cache::has($discoveryKey)) {
            Cache::put($discoveryKey, true, 30);
            dispatch(new DiscoverRouterInterfacesJob($tenantId, $routerId))->onQueue('router-provisioning');

            Log::info('WireGuard webhook triggered discovery for provisioning router', [
                'router_id' => $routerId,
                'router_name' => $routerName,
                'tenant_id' => $tenantId,
            ]);
        }
    }
}
