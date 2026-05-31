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
        $lines = preg_split('/\r\n|\r|\n/', $script) ?: [];

        foreach ($lines as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_starts_with($line, '/')) {
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

            $interfaceRef = $params['interface'] ?? null;
            if (is_string($interfaceRef) && $interfaceRef !== '' && ! empty($knownInterfaces)) {
                if (! in_array($interfaceRef, $knownInterfaces, true)) {
                    $errors[] = "Line {$lineNumber}: Interface '{$interfaceRef}' does not exist on router interface inventory";
                }
            }

            if ($command === '/interface/bridge/port/add') {
                $bridge = $params['bridge'] ?? null;
                if (! is_string($bridge) || $bridge === '') {
                    $errors[] = "Line {$lineNumber}: bridge parameter is required for /interface/bridge/port/add";
                }
            }

            if (str_contains($line, '/system reset-configuration')) {
                $errors[] = "Line {$lineNumber}: Dangerous command is blocked: /system reset-configuration";
            }

            if (str_contains($line, '/queue simple add') && ! isset($params['target'])) {
                $warnings[] = "Line {$lineNumber}: Queue command missing target may not enforce expected limits";
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

    private function parseLine(string $line): array
    {
        $parts = preg_split('/\s+/', $line) ?: [];
        $command = (string) array_shift($parts);
        $params = [];

        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $key = ltrim(trim($key), '=');
            if ($key === '') {
                continue;
            }
            $params[$key] = trim($value, "\"'");
        }

        return [$command, $params];
    }
}
