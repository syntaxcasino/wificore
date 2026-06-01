<?php

namespace App\Services\MikroTik;

use App\Models\Router;

class RouterOsV7ProvisioningValidator
{
    public function __construct(
        private readonly RouterOsCapabilityRegistry $capabilityRegistry,
    ) {
    }

    public function validateScript(Router $router, string $script): array
    {
        $errors = [];
        $warnings = [];

        $versionInfo = $this->capabilityRegistry->resolveProfile($router->os_version);
        if (! ($versionInfo['supported'] ?? false)) {
            return [
                'valid' => false,
                'errors' => [$versionInfo['error'] ?? 'Unsupported RouterOS version.'],
                'warnings' => [],
                'metadata' => $versionInfo,
            ];
        }

        $capabilities = $this->capabilityRegistry->capabilitiesFor((string) $versionInfo['profile']);
        $allowedCommands = array_flip($capabilities['allowed_commands']);
        $requiredParams = $capabilities['required_params'];
        $unsupportedParams = $capabilities['unsupported_params'];

        $knownInterfaces = $this->extractKnownInterfaces($router);
        $virtualInterfaces = [];
        $lines = preg_split('/\r\n|\r|\n/', $script) ?: [];

        foreach ($lines as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line = $this->extractExecutableCommand($line);
            if ($line === null) {
                continue;
            }

            [$command, $params] = $this->parseLine($line);

            if (! isset($allowedCommands[$command])) {
                $errors[] = "Line {$lineNumber}: Unsupported command for {$versionInfo['profile']}: {$command}";
                continue;
            }

            foreach ($requiredParams[$command] ?? [] as $param) {
                if (! array_key_exists($param, $params)) {
                    $errors[] = "Line {$lineNumber}: Missing required parameter '{$param}' for {$command}";
                }
            }

            foreach ($unsupportedParams[$command] ?? [] as $param) {
                if (array_key_exists($param, $params)) {
                    $errors[] = "Line {$lineNumber}: Parameter '{$param}' is not supported for {$versionInfo['profile']} in {$command}";
                }
            }

            $availableInterfaces = array_values(array_unique(array_merge($knownInterfaces, $virtualInterfaces)));
            $interfaceRef = $params['interface'] ?? null;
            if (is_string($interfaceRef) && $interfaceRef !== '') {
                if (! in_array($interfaceRef, $availableInterfaces, true)) {
                    $errors[] = "Line {$lineNumber}: Interface '{$interfaceRef}' does not exist on router interface inventory";
                }
            }

            switch ($command) {
                case '/interface/bridge/add':
                case '/interface/vlan/add':
                case '/interface/wireguard/add':
                    if (! isset($params['name']) || ! is_string($params['name']) || trim($params['name']) === '') {
                        $errors[] = "Line {$lineNumber}: name parameter is required for {$command}";
                    } elseif (is_string($params['name'])) {
                        $virtualInterfaces[] = trim($params['name']);
                    }
                    break;
                case '/interface/bridge/port/add':
                    $bridge = $params['bridge'] ?? null;
                    if (! is_string($bridge) || $bridge === '') {
                        $errors[] = "Line {$lineNumber}: bridge parameter is required for /interface/bridge/port/add";
                        break;
                    }

                    if (! in_array($bridge, $availableInterfaces, true)) {
                        $errors[] = "Line {$lineNumber}: Bridge '{$bridge}' does not exist on router interface inventory";
                    }
                    break;
                case '/ip/pool/add':
                    if (! isset($params['name']) || ! is_string($params['name']) || trim($params['name']) === '') {
                        $errors[] = "Line {$lineNumber}: name parameter is required for /ip/pool/add";
                    }
                    if (! isset($params['ranges']) || ! is_string($params['ranges']) || trim($params['ranges']) === '') {
                        $errors[] = "Line {$lineNumber}: ranges parameter is required for /ip/pool/add";
                    }
                    break;
                case '/ppp/profile/add':
                    if (! isset($params['name']) || ! is_string($params['name']) || trim($params['name']) === '') {
                        $errors[] = "Line {$lineNumber}: name parameter is required for /ppp/profile/add";
                    }
                    break;
                case '/interface/pppoe-server/server/add':
                    if (! isset($params['interface']) || ! is_string($params['interface']) || trim($params['interface']) === '') {
                        $errors[] = "Line {$lineNumber}: interface parameter is required for /interface/pppoe-server/server/add";
                    }
                    if (! isset($params['service-name']) || ! is_string($params['service-name']) || trim($params['service-name']) === '') {
                        $errors[] = "Line {$lineNumber}: service-name parameter is required for /interface/pppoe-server/server/add";
                    }
                    break;
                case '/queue/simple/add':
                    if (! isset($params['target']) || ! is_string($params['target']) || trim($params['target']) === '') {
                        $warnings[] = "Line {$lineNumber}: Queue command missing target may not enforce expected limits";
                    }
                    if (! isset($params['max-limit'])) {
                        $warnings[] = "Line {$lineNumber}: Queue command missing max-limit may not enforce expected throughput";
                    }
                    break;
                case '/ip/firewall/filter/add':
                case '/ip/firewall/nat/add':
                    if (! isset($params['chain']) || ! is_string($params['chain']) || trim($params['chain']) === '') {
                        $errors[] = "Line {$lineNumber}: chain parameter is required for {$command}";
                    }
                    if (! isset($params['action']) || ! is_string($params['action']) || trim($params['action']) === '') {
                        $errors[] = "Line {$lineNumber}: action parameter is required for {$command}";
                    }
                    break;
                case '/system/ntp/client/set':
                    if (! isset($params['servers']) || ! is_string($params['servers']) || trim($params['servers']) === '') {
                        $errors[] = "Line {$lineNumber}: servers parameter is required for /system/ntp/client/set";
                    }
                    break;
            }

            if (str_contains($line, '/system reset-configuration')) {
                $errors[] = "Line {$lineNumber}: Dangerous command is blocked: /system reset-configuration";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'metadata' => [
                'profile' => $versionInfo['profile'],
                'version' => $versionInfo['normalized_version'],
                'known_interfaces' => $knownInterfaces,
            ],
        ];
    }

    private function extractKnownInterfaces(Router $router): array
    {
        $source = $router->interface_list;
        if (! is_array($source)) {
            return [];
        }

        $names = [];
        foreach ($source as $entry) {
            if (is_string($entry)) {
                $names[] = trim($entry);
                continue;
            }
            if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                $names[] = trim($entry['name']);
            }
        }

        return array_values(array_filter(array_unique($names), static fn ($v) => $v !== ''));
    }

    private function extractExecutableCommand(string $line): ?string
    {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/log')) {
            return null;
        }

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        if (str_contains($trimmed, 'do={') || str_starts_with($trimmed, ':do')) {
            $body = $trimmed;
            $firstBrace = strpos($body, '{');
            if ($firstBrace !== false) {
                $body = substr($body, $firstBrace + 1);
            }

            $boundary = null;
            foreach (['} else={', '} on-error={', '} on-error=', '}'] as $marker) {
                $markerPos = strpos($body, $marker);
                if ($markerPos === false) {
                    continue;
                }
                $boundary = $boundary === null ? $markerPos : min($boundary, $markerPos);
            }

            if ($boundary !== null) {
                $body = substr($body, 0, $boundary);
            }

            $body = trim($body);
            if ($body === '' || str_starts_with($body, ':error') || str_starts_with($body, ':log') || str_starts_with($body, '/log')) {
                return null;
            }

            $slashPos = strpos($body, '/');
            if ($slashPos !== false) {
                $body = substr($body, $slashPos);
            }

            $body = trim($body);
            if ($body === '' || str_starts_with($body, ':error') || str_starts_with($body, ':log') || str_starts_with($body, '/log')) {
                return null;
            }

            $body = preg_split('/\s*;\s*/', $body, 2)[0] ?? $body;
            $body = trim($body);
            return $body === '' ? null : $body;
        }

        return null;
    }

    private function parseLine(string $line): array
    {
        $parts = preg_split('/\s+/', $line) ?: [];
        $commandParts = [];
        $params = [];
        $numbersBuffer = null;
        $collectingCommand = true;

        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '') {
                continue;
            }

            if ($collectingCommand) {
                $lastCommandToken = strtolower((string) end($commandParts));
                $selectorContext = in_array($lastCommandToken, ['add', 'remove', 'set', 'enable', 'disable', 'find', 'print'], true);

                if (str_starts_with($token, '[') || ($selectorContext && ! str_contains($token, '=') && ! str_starts_with($token, '/'))) {
                    $collectingCommand = false;
                    $numbersBuffer = $token;
                    if (str_contains($token, ']')) {
                        $clean = trim($numbersBuffer);
                        $clean = trim($clean, '[]');
                        if ($clean !== '') {
                            $params['numbers'] = $clean;
                        }
                        $numbersBuffer = null;
                    }
                    continue;
                }

                if (str_contains($token, '=')) {
                    $collectingCommand = false;
                } else {
                    $commandParts[] = $token;
                    continue;
                }
            }

            if ($numbersBuffer !== null) {
                $numbersBuffer .= ' ' . $token;
                if (str_contains($token, ']')) {
                    $clean = trim($numbersBuffer);
                    $clean = trim($clean, '[]');
                    if ($clean !== '') {
                        $params['numbers'] = $clean;
                    }
                    $numbersBuffer = null;
                }
                continue;
            }

            if (str_starts_with($token, '[')) {
                $numbersBuffer = $token;
                if (str_contains($token, ']')) {
                    $clean = trim($numbersBuffer);
                    $clean = trim($clean, '[]');
                    if ($clean !== '') {
                        $params['numbers'] = $clean;
                    }
                    $numbersBuffer = null;
                }
                continue;
            }

            if (! str_contains($token, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $token, 2);
            $key = ltrim(trim($key), '=');
            if ($key === '') {
                continue;
            }
            $params[$key] = rtrim(trim($value, "\"'"), ']');
        }

        $normalizedParts = [];
        foreach ($commandParts as $index => $part) {
            $clean = trim($part);
            if ($clean === '') {
                continue;
            }
            $clean = trim($clean, '[]{}');
            if ($clean === '') {
                continue;
            }
            if ($index === 0) {
                $clean = ltrim($clean, '/');
            }
            $normalizedParts[] = $clean;
        }

        return ['/' . implode('/', $normalizedParts), $params];
    }
}