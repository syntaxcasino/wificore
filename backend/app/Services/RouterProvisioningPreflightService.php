<?php

namespace App\Services;

use App\Models\Router;
use App\Services\MikroTik\RouterOsCapabilityRegistry;

class RouterProvisioningPreflightService
{
    private readonly RouterOsCapabilityRegistry $capabilityRegistry;

    public function __construct(?RouterOsCapabilityRegistry $capabilityRegistry = null)
    {
        $this->capabilityRegistry = $capabilityRegistry ?? new RouterOsCapabilityRegistry();
    }

    public function preflight(Router $router, array $payload, array $liveInterfaces = []): array
    {
        $availableInterfaces = $this->normalizeInterfaceNames(array_merge(
            $liveInterfaces,
            $this->normalizeInterfaceNames($router->interface_list ?? []),
        ));

        $requestedHotspot = $this->normalizeInterfaceNames($payload['hotspot_interfaces'] ?? []);
        $requestedPppoe = $this->normalizeInterfaceNames($payload['pppoe_interfaces'] ?? []);
        $requestedBridge = $this->normalizeInterfaceNames($payload['bridge_interface'] ?? null);
        $requestedWireguard = $this->normalizeInterfaceNames($payload['wireguard_interface'] ?? null);
        $requestedLan = $this->normalizeInterfaceNames($payload['lan_interface'] ?? null);
        $requestedRequired = $this->normalizeInterfaceNames($payload['required_interfaces'] ?? []);
        $requestedAll = array_values(array_unique(array_filter(array_merge(
            $requestedHotspot,
            $requestedPppoe,
            $requestedBridge,
            $requestedWireguard,
            $requestedLan,
            $requestedRequired,
        ))));

        $errors = [];
        $warnings = [];

        $versionInfo = $this->capabilityRegistry->resolveProfile($router->os_version);
        if (! ($versionInfo['supported'] ?? false)) {
            $errors[] = $versionInfo['error'] ?? 'RouterOS version is missing or unsupported.';
        }

        if ($this->isEnabled($payload, 'enable_hotspot') && $requestedHotspot === []) {
            $errors[] = 'Hotspot provisioning requested but no hotspot interfaces were provided.';
        }

        if ($this->isEnabled($payload, 'enable_pppoe') && $requestedPppoe === []) {
            $errors[] = 'PPPoE provisioning requested but no PPPoE interfaces were provided.';
        }

        if ($requestedAll !== []) {
            if ($availableInterfaces === []) {
                $errors[] = 'Unable to verify requested interfaces because the router interface inventory is empty.';
            } else {
                $missing = array_values(array_diff($requestedAll, $availableInterfaces));
                if ($missing !== []) {
                    $errors[] = 'Requested interface(s) not found on router: ' . implode(', ', $missing);
                }
            }
        }

        $wanInterface = trim((string) ($router->wan_interface ?? ''));
        if ($wanInterface !== '') {
            if ($requestedAll !== [] && in_array($wanInterface, $requestedAll, true)) {
                $errors[] = 'Requested provisioning interfaces cannot reuse the configured WAN interface: ' . $wanInterface;
            }

            if ($availableInterfaces !== [] && ! in_array($wanInterface, $availableInterfaces, true)) {
                $errors[] = 'Configured WAN interface is missing from router inventory: ' . $wanInterface;
            } elseif ($availableInterfaces === []) {
                $warnings[] = 'WAN interface could not be verified because live inventory is unavailable.';
            }
        }

        if ($availableInterfaces === []) {
            $warnings[] = 'Router interface inventory is unavailable; preflight validation is using request payload only.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'requested_interfaces' => $requestedAll,
            'requested_hotspot_interfaces' => $requestedHotspot,
            'requested_pppoe_interfaces' => $requestedPppoe,
            'requested_bridge_interfaces' => $requestedBridge,
            'requested_wireguard_interfaces' => $requestedWireguard,
            'requested_lan_interfaces' => $requestedLan,
            'requested_explicit_interfaces' => $requestedRequired,
            'available_interfaces' => $availableInterfaces,
            'missing_interfaces' => $requestedAll === [] ? [] : array_values(array_diff($requestedAll, $availableInterfaces)),
            'metadata' => [
                'router_id' => (string) $router->id,
                'router_name' => $router->name,
                'wan_interface' => $wanInterface !== '' ? $wanInterface : null,
                'router_os_version' => $router->os_version,
                'router_os_profile' => $versionInfo['profile'] ?? null,
                'router_os_supported' => (bool) ($versionInfo['supported'] ?? false),
                'enable_hotspot' => $this->isEnabled($payload, 'enable_hotspot'),
                'enable_pppoe' => $this->isEnabled($payload, 'enable_pppoe'),
                'inventory_source' => $availableInterfaces === [] ? 'request_payload' : 'live_or_cached',
            ],
        ];
    }

    private function normalizeInterfaceNames(array|string|null $interfaces): array
    {
        if ($interfaces === null) {
            return [];
        }

        $values = is_array($interfaces) ? $interfaces : [$interfaces];
        $names = [];

        foreach ($values as $entry) {
            if (is_string($entry)) {
                $decoded = json_decode($entry, true);
                if (is_array($decoded)) {
                    $names = array_merge($names, $this->normalizeInterfaceNames($decoded));
                    continue;
                }

                foreach (preg_split('/\s*,\s*/', $entry) ?: [] as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $names[] = $part;
                    }
                }
                continue;
            }

            if (is_array($entry)) {
                if (isset($entry['name']) && is_string($entry['name'])) {
                    $names[] = trim($entry['name']);
                    continue;
                }
                if (isset($entry['interface']) && is_string($entry['interface'])) {
                    $names[] = trim($entry['interface']);
                    continue;
                }
            }
        }

        return array_values(array_filter(array_unique($names), static fn ($value) => $value !== ''));
    }

    private function isEnabled(array $payload, string $key): bool
    {
        return filter_var($payload[$key] ?? false, FILTER_VALIDATE_BOOL);
    }
}
