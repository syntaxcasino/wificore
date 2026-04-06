<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Models\Router;
use App\Models\WireguardPeer;
use App\Services\CacheInvalidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $eventData;
    public $tries = 3;
    public $timeout = 30;
    private array $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];

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
     * Process single peer handshake event
     */
    private function processHandshake(): void
    {
        $publicKey = $this->eventData['public_key'];
        $handshakeTimestamp = $this->eventData['latest_handshake'];
        $endpoint = $this->eventData['endpoint'] ?? null;

        // Find peer by public key
        $peer = WireguardPeer::where('public_key', $publicKey)->first();

        if (!$peer) {
            Log::debug('Peer not found for handshake', [
                'public_key' => substr($publicKey, 0, 20) . '...',
            ]);
            return;
        }

        // Update peer handshake
        $handshakeAt = Carbon::createFromTimestamp($handshakeTimestamp);
        $peer->update([
            'last_handshake' => $handshakeAt,
            'endpoint' => $endpoint,
        ]);

        // Update associated router
        $router = $peer->router;
        if (!$router) {
            Log::debug('No router associated with peer', [
                'peer_id' => $peer->id,
            ]);
            return;
        }

        // Only update if router is currently offline or needs status refresh
        $previousStatus = $router->status;
        $previousVpnStatus = $router->vpn_status;

        $now = now();
        $inProvisioning = in_array($router->status, $this->provisioningStatuses, true);

        $router->update([
            'status' => $inProvisioning ? ($router->status === 'pending' ? 'provisioning' : $router->status) : 'online',
            'provisioning_stage' => $inProvisioning ? ($router->provisioning_stage ?? 'handshake_detected') : $router->provisioning_stage,
            'vpn_status' => $inProvisioning ? $router->vpn_status : 'active',
            'vpn_last_handshake' => $handshakeAt,
            'last_seen' => $now,
            'last_checked' => $now,
        ]);

        // Broadcast status update immediately
        $this->broadcastStatusUpdate($router, $previousStatus, $previousVpnStatus);

        Log::info('Router marked online via WireGuard handshake event', [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'previous_status' => $previousStatus,
            'handshake_age_seconds' => abs(now()->diffInSeconds($handshakeAt, false)),
        ]);
    }

    /**
     * Process peer expired event
     */
    private function processExpired(): void
    {
        $publicKey = $this->eventData['public_key'];

        $peer = WireguardPeer::where('public_key', $publicKey)->first();

        if (!$peer) {
            return;
        }

        // Clear handshake
        $peer->update(['last_handshake' => null]);

        $router = $peer->router;
        if (!$router) {
            return;
        }

        $previousStatus = $router->status;
        $previousVpnStatus = $router->vpn_status;
        $inProvisioning = in_array($router->status, $this->provisioningStatuses, true);

        // Mark router offline only if operational
        $router->update([
            'status' => $inProvisioning ? $router->status : 'offline',
            'provisioning_stage' => $inProvisioning ? $router->provisioning_stage : $router->provisioning_stage,
            'vpn_status' => 'inactive',
            'vpn_last_handshake' => null,
            'last_checked' => now(),
        ]);

        $this->broadcastStatusUpdate($router, $previousStatus, $previousVpnStatus);

        Log::info('Router marked offline via WireGuard expired event', [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'previous_status' => $previousStatus,
        ]);
    }

    /**
     * Process batch peer update
     */
    private function processBatch(): void
    {
        $peers = $this->eventData['peers'] ?? [];
        $updatedRouters = [];

        foreach ($peers as $peerData) {
            if (empty($peerData['latest_handshake'])) {
                continue;
            }

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
            if (!$router || isset($updatedRouters[$router->id])) {
                continue;
            }

            $previousStatus = $router->status;
            $inProvisioning = in_array($router->status, $this->provisioningStatuses, true);
            $now = now();
            
            $router->update([
                'status' => $inProvisioning ? ($router->status === 'pending' ? 'provisioning' : $router->status) : 'online',
                'provisioning_stage' => $inProvisioning ? ($router->provisioning_stage ?? 'handshake_detected') : $router->provisioning_stage,
                'vpn_status' => $inProvisioning ? $router->vpn_status : 'active',
                'vpn_last_handshake' => $handshakeAt,
                'last_seen' => $now,
                'last_checked' => $now,
            ]);

            $updatedRouters[$router->id] = [
                'router' => $router,
                'previous_status' => $previousStatus,
            ];
        }

        // Broadcast batch update
        if (!empty($updatedRouters)) {
            $payload = [];
            $firstTenantId = null;
            foreach ($updatedRouters as $data) {
                $router = $data['router'];
                if ($firstTenantId === null) {
                    $firstTenantId = (string) $router->tenant_id;
                }
                $payload[] = [
                    'id' => $router->id,
                    'name' => $router->name,
                    'ip_address' => $router->ip_address,
                    'vpn_ip' => $router->vpn_ip,
                    'status' => 'online',
                    'previous_status' => $data['previous_status'],
                    'vpn_status' => 'active',
                    'last_seen' => $router->last_seen,
                    'tenant_id' => (string) $router->tenant_id,
                ];
                CacheInvalidationService::invalidateRouterCache((string) $router->tenant_id, (string) $router->id);
            }

            broadcast(new RouterStatusUpdated($payload, $firstTenantId))->toOthers();

            Log::info('Batch updated routers from WireGuard peers', [
                'count' => count($updatedRouters),
            ]);
        }
    }

    /**
     * Broadcast status update event
     */
    private function broadcastStatusUpdate(Router $router, string $previousStatus, string $previousVpnStatus): void
    {
        try {
            CacheInvalidationService::invalidateRouterCache(
                (string) $router->tenant_id, 
                (string) $router->id
            );

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
                    'tenant_id' => (string) $router->tenant_id,
                    'vpn_last_handshake' => $router->vpn_last_handshake,
                ]
            ];

            broadcast(new RouterStatusUpdated($payload, (string) $router->tenant_id))->toOthers();

            // Also publish to Redis
            Redis::publish('router:status:changed', json_encode([
                'tenant_id' => $router->tenant_id,
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
