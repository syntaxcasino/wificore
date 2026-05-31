<?php

namespace App\Services\MikroTik;

class RouterOsCapabilityRegistry
{
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

        // Initial supported profile set requested for rollout.
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
            '/interface/bridge/add',
            '/interface/bridge/port/add',
            '/interface/bridge/set',
            '/ip/pool/add',
            '/ip/address/add',
            '/ip/dhcp-server/network/add',
            '/ppp/profile/add',
            '/ppp/profile/set',
            '/interface/pppoe-server/server/add',
            '/interface/pppoe-server/server/set',
            '/ip/firewall/filter/add',
            '/ip/firewall/nat/add',
            '/queue/simple/add',
            '/system/ntp/client/set',
            '/radius/add',
            '/ip/dns/set',
            '/ip/hotspot/profile/add',
            '/ip/hotspot/add',
            '/ip/hotspot/user/profile/add',
            '/ip/hotspot/walled-garden/add',
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
            '/ip/pool/add' => ['name', 'ranges'],
            '/ppp/profile/add' => ['name'],
            '/interface/pppoe-server/server/add' => ['interface', 'service-name'],
            '/interface/wireguard/add' => ['name'],
            '/interface/wireguard/peers/add' => ['interface', 'public-key'],
            '/system/ntp/client/set' => [],
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
}
