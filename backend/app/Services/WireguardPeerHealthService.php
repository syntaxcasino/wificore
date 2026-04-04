<?php

namespace App\Services;

use App\Scopes\TenantScope;
use App\Models\TenantVpnTunnel;
use App\Models\VpnConfiguration;
use App\Models\WireguardPeer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WireguardPeerHealthService
{
    public function refreshPeerStats(string $tenantId): array
    {
        $tunnels = TenantVpnTunnel::where('tenant_id', $tenantId)->get();
        if ($tunnels->isEmpty()) {
            Log::info('No VPN tunnels found for tenant during peer refresh', [
                'tenant_id' => $tenantId,
            ]);
            return [];
        }

        $updatedRouters = [];
        $seenPeerPublicKeys = []; // Track peers found in dump
        $hasWireGuard = $this->isWireGuardInstalled();
        $controllerUrl = config('services.wireguard.controller_url');
        $apiKey = config('services.wireguard.api_key');

        if (!$hasWireGuard && (empty($controllerUrl) || empty($apiKey))) {
            Log::warning('WireGuard dump unavailable (no wg binary or controller config)', [
                'tenant_id' => $tenantId,
                'controller_url' => $controllerUrl,
                'api_key_set' => !empty($apiKey),
            ]);
            return [];
        }

        foreach ($tunnels as $tunnel) {
            $output = $this->fetchDumpOutput(
                $tunnel,
                $hasWireGuard,
                $controllerUrl,
                $apiKey,
                $tenantId
            );

            // If dump failed/empty, treat as no peers (routers offline) - don't skip
            // This ensures peers from failed dumps get marked as offline properly
            if (empty($output)) {
                Log::warning('WireGuard dump returned empty/failed - treating peers as offline', [
                    'tenant_id' => $tenantId,
                    'interface' => $tunnel->interface_name,
                ]);
                // Continue to next tunnel - peers from this tunnel won't be in seenPeerPublicKeys
                // and will be properly marked as offline by clearMissingPeerHandshakes
                continue;
            }

            foreach ($this->parseDump($output) as $peer) {
                $seenPeerPublicKeys[] = $peer['public_key']; // Track seen peer
                
                // Use absolute time difference to handle clock skew (router clock ahead/behind server)
                $handshakeAge = $peer['latest_handshake'] ? abs(now()->diffInSeconds($peer['latest_handshake'], false)) : null;
                Log::debug('Processing peer from dump', [
                    'public_key' => substr($peer['public_key'], 0, 20) . '...',
                    'handshake_at' => $peer['latest_handshake']?->toIso8601String(),
                    'handshake_age_seconds' => $handshakeAge,
                    'endpoint' => $peer['endpoint'],
                ]);
                
                $vpnConfig = VpnConfiguration::where('client_public_key', $peer['public_key'])->first();

                if (!$vpnConfig) {
                    Log::info('WireGuard peer not mapped to VPN configuration', [
                        'tenant_id' => $tenantId,
                        'public_key' => $peer['public_key'],
                        'interface' => $tunnel->interface_name,
                    ]);
                    continue;
                }

                $router = $vpnConfig->router;
                $handshakeAt = $peer['latest_handshake'];
                $vpnConfigStatus = $this->resolveVpnConfigStatus($handshakeAt, $vpnConfig->status);
                $routerVpnStatus = $this->resolveRouterVpnStatus($handshakeAt);

                WireguardPeer::updateOrCreate(
                    ['public_key' => $peer['public_key']],
                    [
                        'router_id' => $vpnConfig->router_id,
                        'peer_name' => $router?->name,
                        'endpoint' => $peer['endpoint'],
                        'allowed_ips' => $peer['allowed_ips'],
                        'transfer_rx' => $peer['transfer_rx'],
                        'transfer_tx' => $peer['transfer_tx'],
                        'last_handshake' => $handshakeAt,
                    ]
                );

                $vpnConfig->update([
                    'last_handshake_at' => $handshakeAt,
                    'rx_bytes' => $peer['transfer_rx'],
                    'tx_bytes' => $peer['transfer_tx'],
                    'status' => $vpnConfigStatus,
                ]);

                if ($router) {
                    $router->update([
                        'vpn_status' => $routerVpnStatus,
                        'vpn_last_handshake' => $handshakeAt,
                    ]);

                    $updatedRouters[$router->id] = array_merge([
                        'id' => $router->id,
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'name' => $router->name,
                        'status' => $router->status,
                        'last_checked' => $router->last_checked,
                        'model' => $router->model,
                        'os_version' => $router->os_version,
                        'last_seen' => $router->last_seen,
                        'vpn_status' => $routerVpnStatus,
                        'vpn_last_handshake' => $handshakeAt,
                    ], $this->buildHandshakeTimezonePayload($handshakeAt));
                }
            }
        }

        // Clear last_handshake for peers not seen in dump (routers that went offline)
        // Also clear for peers with stale handshakes (older than threshold)
        Log::debug('About to clear handshakes', [
            'seen_count' => count($seenPeerPublicKeys),
            'updated_routers_before' => count($updatedRouters),
        ]);
        $this->clearMissingPeerHandshakes($tenantId, $seenPeerPublicKeys, $updatedRouters);
        $this->clearStalePeerHandshakes($tenantId, $seenPeerPublicKeys, $updatedRouters);
        
        Log::debug('Completed refreshPeerStats', [
            'tenant_id' => $tenantId,
            'seen_peers' => count($seenPeerPublicKeys),
            'updated_routers' => count($updatedRouters),
        ]);

        return array_values($updatedRouters);
    }

    /**
     * Clear last_handshake for peers not present in WireGuard dump.
     * These are routers that have gone offline.
     */
    private function clearMissingPeerHandshakes(string $tenantId, array $seenPublicKeys, array &$updatedRouters): void
    {
        // If dump was valid but empty (no peers), ALL routers are offline - clear all handshakes for this tenant
        // Otherwise, clear only peers not seen in the dump
        if (empty($seenPublicKeys)) {
            // In tenant context, schema isolation handles tenancy - routers table doesn't have tenant_id
            // Must use withoutGlobalScope to prevent tenant_id filter on both wireguard_peers and routers
            $missingPeers = WireguardPeer::withoutGlobalScope(TenantScope::class)
                ->whereHas('router', function ($query) {
                    $query->withoutGlobalScope(TenantScope::class);
                })
                ->get();

            Log::info('WireGuard dump empty - marking all peers offline', [
                'tenant_id' => $tenantId,
                'peer_count' => $missingPeers->count(),
            ]);
        } else {
            // In tenant context, schema isolation handles tenancy - routers table doesn't have tenant_id
            // Must use withoutGlobalScope to prevent tenant_id filter on both wireguard_peers and routers
            $missingPeers = WireguardPeer::withoutGlobalScope(TenantScope::class)
                ->whereNotIn('public_key', $seenPublicKeys)
                ->whereHas('router', function ($query) {
                    $query->withoutGlobalScope(TenantScope::class);
                })
                ->get();
        }

        foreach ($missingPeers as $peer) {
            $router = $peer->router;

            // Only clear if it had a handshake before (was online)
            if ($peer->last_handshake !== null) {
                $peer->update(['last_handshake' => null]);

                if ($router) {
                    $router->update([
                        'vpn_status' => 'inactive',
                        'vpn_last_handshake' => null,
                    ]);

                    $updatedRouters[$router->id] = array_merge([
                        'id' => $router->id,
                        'ip_address' => $router->ip_address,
                        'vpn_ip' => $router->vpn_ip,
                        'name' => $router->name,
                        'status' => $router->status,
                        'last_checked' => $router->last_checked,
                        'model' => $router->model,
                        'os_version' => $router->os_version,
                        'last_seen' => $router->last_seen,
                        'vpn_status' => 'inactive',
                        'vpn_last_handshake' => null,
                    ], $this->buildHandshakeTimezonePayload(null));

                    Log::info('Cleared handshake for offline peer', [
                        'tenant_id' => $tenantId,
                        'router_id' => $router->id,
                        'peer_public_key' => substr($peer->public_key, 0, 20) . '...',
                    ]);
                }
            }
        }
    }

    /**
     * Clear last_handshake for peers present in dump but with stale handshakes.
     * WireGuard keeps peers in dump even when offline, but with old timestamps.
     */
    private function clearStalePeerHandshakes(string $tenantId, array $seenPublicKeys, array &$updatedRouters): void
    {
        if (empty($seenPublicKeys)) {
            Log::debug('clearStalePeerHandshakes: seenPublicKeys empty, returning');
            return;
        }

        $threshold = (int) config('vpn.monitoring.inactive_threshold', 180);
        // Use UTC for database comparison to avoid timezone issues
        $staleThreshold = now()->utc()->subSeconds($threshold);
        
        Log::debug('clearStalePeerHandshakes: checking for stale peers', [
            'tenant_id' => $tenantId,
            'seen_count' => count($seenPublicKeys),
            'threshold' => $threshold,
            'stale_threshold' => $staleThreshold->toIso8601String(),
        ]);

        // Find peers that are in dump but have stale handshakes
        // In tenant context, schema isolation handles tenancy - routers table doesn't have tenant_id
        // Must use withoutGlobalScope to prevent tenant_id filter on both wireguard_peers and routers
        $stalePeers = WireguardPeer::withoutGlobalScope(TenantScope::class)
            ->whereIn('public_key', $seenPublicKeys)
            ->whereNotNull('last_handshake')
            ->where('last_handshake', '<', $staleThreshold)
            ->whereHas('router', function ($query) {
                $query->withoutGlobalScope(TenantScope::class);
            })
            ->get();
        
        Log::debug('clearStalePeerHandshakes: found stale peers', [
            'count' => $stalePeers->count(),
            'peer_ids' => $stalePeers->pluck('id')->toArray(),
        ]);

        foreach ($stalePeers as $peer) {
            $router = $peer->router;
            // Use absolute difference to handle clock skew (router clock ahead/behind server)
            $staleAge = abs(now()->diffInSeconds($peer->last_handshake, false));

            $peer->update(['last_handshake' => null]);

            if ($router) {
                $router->update([
                    'vpn_status' => 'inactive',
                    'vpn_last_handshake' => null,
                ]);

                $updatedRouters[$router->id] = array_merge([
                    'id' => $router->id,
                    'ip_address' => $router->ip_address,
                    'vpn_ip' => $router->vpn_ip,
                    'name' => $router->name,
                    'status' => $router->status,
                    'last_checked' => $router->last_checked,
                    'model' => $router->model,
                    'os_version' => $router->os_version,
                    'last_seen' => $router->last_seen,
                    'vpn_status' => 'inactive',
                    'vpn_last_handshake' => null,
                ], $this->buildHandshakeTimezonePayload(null));

                Log::info('Cleared stale handshake for offline peer', [
                    'tenant_id' => $tenantId,
                    'router_id' => $router->id,
                    'peer_public_key' => substr($peer->public_key, 0, 20) . '...',
                    'handshake_age_seconds' => $staleAge,
                    'handshake_time' => $peer->last_handshake?->toIso8601String(),
                    'threshold' => $threshold,
                    'stale_threshold' => $staleThreshold->toIso8601String(),
                ]);
            }
        }
    }

    private function fetchDumpOutput(
        TenantVpnTunnel $tunnel,
        bool $hasWireGuard,
        ?string $controllerUrl,
        ?string $apiKey,
        string $tenantId
    ): ?string {
        $localOutput = null;

        if ($hasWireGuard) {
            $localOutput = shell_exec("wg show {$tunnel->interface_name} dump 2>&1");
            if ($this->isDumpOutputUsable($localOutput)) {
                return $localOutput;
            }
        }

        if (empty($controllerUrl) || empty($apiKey)) {
            return $localOutput;
        }

        if ($localOutput !== null) {
            Log::info('Falling back to WireGuard controller dump', [
                'tenant_id' => $tenantId,
                'interface' => $tunnel->interface_name,
            ]);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->get(rtrim($controllerUrl, '/') . '/vpn/dump/' . $tunnel->interface_name);

            if ($response->failed()) {
                Log::warning('WireGuard controller dump request failed', [
                    'tenant_id' => $tenantId,
                    'interface' => $tunnel->interface_name,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $dump = $response->json('dump');

            return is_string($dump) ? $dump : null;
        } catch (\Exception $e) {
            Log::error('WireGuard controller dump request error', [
                'tenant_id' => $tenantId,
                'interface' => $tunnel->interface_name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isDumpOutputUsable(?string $output): bool
    {
        if (!$output) {
            return false;
        }

        $trimmed = trim($output);
        if ($trimmed === '') {
            return false;
        }

        $lower = strtolower($trimmed);
        $errorNeedles = [
            'unable to access interface',
            'no such device',
            'operation not permitted',
            'permission denied',
            'wg: ',
        ];

        foreach ($errorNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }

        return str_contains($trimmed, "\t");
    }

    private function parseDump(string $output): array
    {
        $lines = explode("\n", trim($output));
        $peers = [];

        foreach ($lines as $line) {
            $parts = explode("\t", $line);

            if (count($parts) < 8) {
                continue;
            }

            $handshake = (int) ($parts[4] ?? 0);
            $handshakeAt = $handshake > 0 ? Carbon::createFromTimestamp($handshake) : null;

            $peers[] = [
                'public_key' => $parts[0],
                'endpoint' => $parts[2] ?? null,
                'allowed_ips' => $parts[3] ?? null,
                'latest_handshake' => $handshakeAt,
                'transfer_rx' => (int) ($parts[5] ?? 0),
                'transfer_tx' => (int) ($parts[6] ?? 0),
            ];
        }

        return $peers;
    }

    private function buildHandshakeTimezonePayload($handshakeAt): array
    {
        $handshake = null;

        if ($handshakeAt instanceof Carbon) {
            $handshake = $handshakeAt;
        } elseif (!empty($handshakeAt)) {
            $handshake = Carbon::parse($handshakeAt);
        }

        $utc = $handshake?->copy()->timezone('UTC')->toIso8601String();
        $eat = $handshake?->copy()->timezone('Africa/Nairobi')->toIso8601String();

        return [
            'vpn_last_handshake_utc' => $utc,
            'vpn_last_handshake_eat' => $eat,
            'vpn_last_handshake_timezones' => [
                'UTC' => $utc,
                'Africa/Nairobi' => $eat,
            ],
        ];
    }

    private function resolveVpnConfigStatus(?Carbon $handshakeAt, ?string $currentStatus): string
    {
        if (!$handshakeAt) {
            return $currentStatus === 'pending' ? 'pending' : 'disconnected';
        }

        $threshold = (int) config('vpn.monitoring.inactive_threshold', 180);
        // Use absolute difference to handle clock skew (router clock ahead/behind server)
        $age = abs(now()->diffInSeconds($handshakeAt, false));

        return $age <= $threshold ? 'connected' : 'disconnected';
    }

    private function resolveRouterVpnStatus(?Carbon $handshakeAt): string
    {
        if (!$handshakeAt) {
            return 'inactive';
        }

        $threshold = (int) config('vpn.monitoring.inactive_threshold', 180);
        // Use absolute difference to handle clock skew (router clock ahead/behind server)
        return abs(now()->diffInSeconds($handshakeAt, false)) <= $threshold ? 'active' : 'inactive';
    }

    private function isWireGuardInstalled(): bool
    {
        return !empty(shell_exec('which wg'));
    }
}
