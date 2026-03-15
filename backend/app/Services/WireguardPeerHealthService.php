<?php

namespace App\Services;

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

            if (empty($output)) {
                Log::warning('WireGuard dump returned empty output', [
                    'tenant_id' => $tenantId,
                    'interface' => $tunnel->interface_name,
                ]);
                continue;
            }

            foreach ($this->parseDump($output) as $peer) {
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

        return array_values($updatedRouters);
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

        $threshold = (int) config('vpn.monitoring.inactive_threshold', 120);
        $age = now()->diffInSeconds($handshakeAt);

        return $age <= $threshold ? 'connected' : 'disconnected';
    }

    private function resolveRouterVpnStatus(?Carbon $handshakeAt): string
    {
        if (!$handshakeAt) {
            return 'inactive';
        }

        $threshold = (int) config('vpn.monitoring.inactive_threshold', 120);

        return now()->diffInSeconds($handshakeAt) <= $threshold ? 'active' : 'inactive';
    }

    private function isWireGuardInstalled(): bool
    {
        return !empty(shell_exec('which wg'));
    }
}
