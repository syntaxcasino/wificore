<?php

namespace App\Services\MikroTik;

use App\Models\Router;

class RouterOsCapabilityRegistry
{
    public function buildFingerprint(array $liveData): array
    {
        $version = $this->extractString($liveData, ['version', 'os_version']);
        $architecture = $this->extractString($liveData, ['architecture', 'architecture_name']);
        $boardName = $this->extractString($liveData, ['board_name', 'model', 'board']);

        $versionInfo = $this->resolveProfile($version);

        return [
            'supported' => (bool) ($versionInfo['supported'] ?? false),
            'profile' => $versionInfo['profile'] ?? null,
            'version' => $versionInfo['normalized_version'] ?? $version,
            'major' => $versionInfo['major'] ?? null,
            'minor' => $versionInfo['minor'] ?? null,
            'architecture_name' => $architecture,
            'board_name' => $boardName,
            'vendor' => $this->extractString($liveData, ['vendor']) ?? 'mikrotik',
            'error' => $versionInfo['error'] ?? null,
        ];
    }

    public function buildRouterUpdatePayload(array $liveData, ?Router $router = null): array
    {
        $fingerprint = $this->buildFingerprint($liveData);

        $capabilities = [];
        if (($fingerprint['supported'] ?? false) && is_string($fingerprint['profile'] ?? null)) {
            $capabilities = $this->capabilitiesFor((string) $fingerprint['profile']);
        }

        $payload = [
            'vendor' => $fingerprint['vendor'] ?? ($router?->vendor ?? 'mikrotik'),
            'model' => $fingerprint['board_name'] ?? $router?->model,
            'os_version' => $fingerprint['version'] ?? $router?->os_version,
            'architecture_name' => $fingerprint['architecture_name'] ?? $router?->architecture_name,
            'board_name' => $fingerprint['board_name'] ?? $router?->board_name,
            'capabilities' => $capabilities ?: ($router?->capabilities ?? []),
        ];

        return array_filter($payload, static fn ($value) => $value !== null && $value !== []);
    }

    public function resolveProfile(?string $osVersion): array
    {
        if (! is_string($osVersion) || trim($osVersion) === '') {
            return [
                'supported' => false,
                'profile' => null,
                'major' => null,
                'minor' => null,
                'normalized_version' => null,
                'error' => 'RouterOS version is missing. Discover router interfaces/version before provisioning.',
            ];
        }

        $normalized = trim($osVersion);
        if (! preg_match('/^(\d+)\.(\d+)/', $normalized, $matches)) {
            return [
                'supported' => false,
                'profile' => null,
                'major' => null,
                'minor' => null,
                'normalized_version' => $normalized,
                'error' => 'RouterOS version format is unsupported: ' . $normalized,
            ];
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];

        if ($major < 7) {
            return [
                'supported' => false,
                'profile' => null,
                'major' => $major,
                'minor' => $minor,
                'normalized_version' => $normalized,
                'error' => 'RouterOS v' . $major . ' is unsupported for v7 provisioning pipeline.',
            ];
        }

        $profile = match (true) {
            $minor <= 8 => 'ros7_8',
            $minor <= 15 => 'ros7_15',
            default => 'ros7_18',
        };

        return [
            'supported' => true,
            'profile' => $profile,
            'major' => $major,
            'minor' => $minor,
            'normalized_version' => $normalized,
            'error' => null,
        ];
    }

    public function capabilitiesFor(string $profile): array
    {
        $commonAllowedCommands = [
            '/log',
            '/system/identity/set',
            '/system/logging/add',
            '/system/logging/remove',
            '/system/logging/action/add',
            '/system/logging/action/remove',
            '/system/clock/set',
            '/system/ntp/server/set',
            '/system/script/add',
            '/file/set',
            '/system/script/remove',
            '/system/scheduler/add',
            '/system/scheduler/remove',
            '/tool/netwatch/add',
            '/tool/netwatch/remove',
            '/snmp/community/add',
            '/snmp/community/remove',
            '/snmp/set',
            '/ip/service/enable',
            '/ip/service/set',
            '/ip/service/disable',
            '/interface/list/add',
            '/interface/list/member/add',
            '/interface/list/member/remove',
            '/interface/ethernet/set',
            '/interface/bridge/remove',
            '/interface/bridge/add',
            '/interface/bridge/port/add',
            '/interface/bridge/port/remove',
            '/interface/bridge/port/find',
            '/interface/bridge/set',
            '/ip/dhcp-client/add',
            '/ip/dhcp-client/set',
            '/ip/pool/add',
            '/ip/pool/remove',
            '/ip/pool/set',
            '/ip/address/add',
            '/ip/dhcp-server/network/add',
            '/ip/dhcp-server/network/remove',
            '/ip/dhcp-server/add',
            '/ip/dhcp-server/remove',
            '/ip/address/remove',
            '/ppp/profile/add',
            '/ppp/profile/remove',
            '/ppp/profile/set',
            '/ppp/secret/remove',
            '/ppp/aaa/set',
            '/interface/pppoe-server/server/add',
            '/interface/pppoe-server/server/remove',
            '/interface/pppoe-server/server/set',
            '/radius/add',
            '/radius/remove',
            '/radius/set',
            '/radius/incoming/set',
            '/ip/firewall/filter/add',
            '/ip/firewall/filter/remove',
            '/ip/firewall/filter/set',
            '/ip/traffic-flow/set',
            '/ip/traffic-flow/target/add',
            '/ip/traffic-flow/target/remove',
            '/ip/firewall/nat/add',
            '/ip/firewall/nat/remove',
            '/ip/firewall/nat/set',
            '/ip/firewall/mangle/add',
            '/ip/firewall/mangle/remove',
            '/ip/firewall/mangle/set',
            '/ip/route/add',
            '/ip/route/remove',
            '/ip/route/set',
            '/routing/table/add',
            '/routing/table/remove',
            '/routing/table/set',
            '/ip/firewall/connection/tracking/set',
            '/queue/simple/add',
            '/queue/simple/remove',
            '/queue/tree/add',
            '/queue/tree/remove',
            '/queue/type/add',
            '/queue/type/remove',
            '/system/ntp/client/set',
            '/ip/dns/set',
            '/ip/hotspot/profile/add',
            '/ip/hotspot/profile/remove',
            '/ip/hotspot/profile/set',
            '/ip/hotspot/add',
            '/ip/hotspot/remove',
            '/ip/hotspot/user/profile/add',
            '/ip/hotspot/user/profile/remove',
            '/ip/hotspot/user/profile/set',
            '/ip/hotspot/user/remove',
            '/ip/hotspot/walled-garden/add',
            '/ip/hotspot/walled-garden/remove',
            '/ip/hotspot/walled-garden/ip/add',
            '/ip/hotspot/walled-garden/ip/remove',
        ];

        $wireguard = [
            '/interface/wireguard/add',
            '/interface/wireguard/peers/add',
            '/ip/address/add',
        ];

        $allowed = array_values(array_unique(array_merge($commonAllowedCommands, $wireguard)));

        $requiredParams = [
            '/interface/bridge/add' => ['name'],
            '/interface/bridge/port/add' => ['bridge', 'interface'],
            '/interface/list/add' => ['name'],
            '/interface/list/member/add' => ['list', 'interface'],
            '/ip/dhcp-client/add' => ['interface'],
            '/ip/pool/add' => ['name', 'ranges'],
            '/ip/pool/set' => ['numbers', 'ranges'],
            '/ip/address/add' => ['address', 'interface'],
            '/ip/dhcp-server/network/add' => ['address', 'gateway'],
            '/ip/dhcp-server/add' => ['name', 'interface', 'address-pool'],
            '/ppp/profile/add' => ['name'],
            '/ppp/profile/set' => ['numbers'],
            '/ppp/aaa/set' => [],
            '/interface/pppoe-server/server/add' => ['interface', 'service-name'],
            '/interface/pppoe-server/server/set' => ['numbers'],
            '/interface/wireguard/add' => ['name'],
            '/interface/wireguard/peers/add' => ['interface', 'public-key'],
            '/system/identity/set' => ['name'],
            '/system/ntp/client/set' => [],
            '/ip/firewall/mangle/add' => ['chain', 'action'],
            '/ip/route/add' => ['dst-address', 'gateway'],
            '/routing/table/add' => ['name'],
            '/ip/hotspot/profile/add' => ['name', 'hotspot-address'],
            '/ip/hotspot/add' => ['name', 'interface', 'profile', 'address-pool'],
            '/ip/hotspot/walled-garden/add' => ['dst-host'],
            '/queue/simple/add' => ['name'],
        ];

        $unsupportedParamsByProfile = [
            'ros7_8' => [
                '/system/ntp/client/set' => ['servers'],
            ],
            'ros7_15' => [],
            'ros7_18' => [],
        ];

        return [
            'profile' => $profile,
            'allowed_commands' => $allowed,
            'required_params' => $requiredParams,
            'unsupported_params' => $unsupportedParamsByProfile[$profile] ?? [],
        ];
    }

    private function extractString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            return $value;
        }

        return null;
    }
}
