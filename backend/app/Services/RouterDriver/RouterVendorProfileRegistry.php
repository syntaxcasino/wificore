<?php

namespace App\Services\RouterDriver;

use App\Models\Router;

class RouterVendorProfileRegistry
{
    public function resolve(?string $vendor = null, ?string $model = null): array
    {
        $profiles = $this->profiles();
        $explicitVendor = $this->normalizeKey($vendor);
        $normalizedModel = $this->normalizeModel($model);

        if ($explicitVendor !== null && isset($profiles[$explicitVendor])) {
            return $this->buildProfilePayload($explicitVendor, $profiles[$explicitVendor], 'explicit_vendor');
        }

        $matched = $normalizedModel !== null ? $this->matchModel($normalizedModel, $profiles) : null;
        if ($matched !== null) {
            return $matched;
        }

        $defaultVendor = $this->normalizeKey((string) config('router_vendors.default_vendor', 'mikrotik')) ?? 'mikrotik';
        if (isset($profiles[$defaultVendor])) {
            return $this->buildProfilePayload($defaultVendor, $profiles[$defaultVendor], 'default_vendor');
        }

        return [
            'supported' => false,
            'vendor' => $explicitVendor,
            'display_name' => null,
            'driver' => null,
            'capability_profile' => null,
            'aliases' => [],
            'model_patterns' => [],
            'matched_by' => null,
            'model' => $normalizedModel,
            'error' => 'No router vendor profile is configured.',
        ];
    }

    public function resolveDriverKey(?string $vendor = null, ?string $model = null): ?string
    {
        return $this->resolve($vendor, $model)['driver'] ?? null;
    }

    public function resolveVendor(?string $vendor = null, ?string $model = null): ?string
    {
        return $this->resolve($vendor, $model)['vendor'] ?? null;
    }

    public function resolveProfileName(?string $vendor = null, ?string $model = null): ?string
    {
        return $this->resolve($vendor, $model)['capability_profile'] ?? null;
    }

    public function supportsVendor(string $vendor): bool
    {
        return isset($this->profiles()[$this->normalizeKey($vendor) ?? '']);
    }

    public function getVendorProfile(string $vendor): ?array
    {
        $key = $this->normalizeKey($vendor);
        if ($key === null) {
            return null;
        }

        $profiles = $this->profiles();
        if (! isset($profiles[$key])) {
            return null;
        }

        return $this->buildProfilePayload($key, $profiles[$key], 'explicit_vendor');
    }

    public function getSupportedVendors(): array
    {
        return array_keys($this->profiles());
    }

    public function resolveForRouter(Router $router): array
    {
        return $this->resolve($router->vendor, $router->model);
    }

    private function profiles(): array
    {
        $profiles = (array) config('router_vendors.vendors', []);
        return array_filter($profiles, static fn ($profile) => is_array($profile));
    }

    private function matchModel(string $model, array $profiles): ?array
    {
        foreach ($profiles as $vendor => $profile) {
            $patterns = array_map('strval', (array) ($profile['model_patterns'] ?? []));
            foreach ($patterns as $pattern) {
                if ($pattern === '') {
                    continue;
                }

                if ($this->matchesPattern($model, $pattern)) {
                    return $this->buildProfilePayload($vendor, $profile, 'model_pattern', $model, $pattern);
                }
            }

            $aliases = array_map('strtolower', array_map('strval', (array) ($profile['aliases'] ?? [])));
            foreach ($aliases as $alias) {
                if ($alias === '') {
                    continue;
                }

                if (str_contains($model, $alias)) {
                    return $this->buildProfilePayload($vendor, $profile, 'alias_match', $model, $alias);
                }
            }
        }

        return null;
    }

    private function buildProfilePayload(string $vendor, array $profile, string $matchedBy, ?string $model = null, ?string $pattern = null): array
    {
        return [
            'supported' => true,
            'vendor' => $vendor,
            'display_name' => (string) ($profile['display_name'] ?? ucfirst($vendor)),
            'driver' => (string) ($profile['driver'] ?? $vendor),
            'capability_profile' => $profile['capability_profile'] ?? null,
            'aliases' => array_values(array_filter(array_map('strval', (array) ($profile['aliases'] ?? [])))),
            'model_patterns' => array_values(array_filter(array_map('strval', (array) ($profile['model_patterns'] ?? [])))),
            'matched_by' => $matchedBy,
            'matched_model' => $model,
            'matched_pattern' => $pattern,
            'supports' => (array) ($profile['supports'] ?? []),
            'error' => null,
        ];
    }

    private function matchesPattern(string $model, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        if (str_starts_with($pattern, 're:')) {
            return (bool) preg_match(substr($pattern, 3), $model);
        }

        return fnmatch($pattern, $model, FNM_CASEFOLD);
    }

    private function normalizeKey(?string $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return $value !== '' ? $value : null;
    }

    private function normalizeModel(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? strtolower($value) : null;
    }
}
