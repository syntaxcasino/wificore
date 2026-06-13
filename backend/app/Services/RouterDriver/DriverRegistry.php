<?php

namespace App\Services\RouterDriver;

/**
 * Router Driver Registry
 * 
 * Manages all available router drivers and provides driver resolution.
 */
class DriverRegistry
{
    /** @var array<string, RouterDriverInterface> */
    private array $drivers = [];

    /**
     * Register a driver instance
     */
    public function register(string $vendor, RouterDriverInterface $driver): void
    {
        $this->drivers[strtolower($vendor)] = $driver;
    }

    /**
     * Get driver for a specific vendor
     */
    public function getDriver(string $vendor): ?RouterDriverInterface
    {
        return $this->drivers[strtolower($vendor)] ?? null;
    }

    /**
     * Get driver for a router instance
     */
    public function getDriverForRouter(\App\Models\Router $router): RouterDriverInterface
    {
        // Check if router has explicit vendor set
        if ($router->vendor && isset($this->drivers[strtolower($router->vendor)])) {
            return $this->drivers[strtolower($router->vendor)];
        }

        // Auto-detect by model name
        $vendor = $this->detectVendorByModel($router->model);
        if ($vendor && isset($this->drivers[strtolower($vendor)])) {
            return $this->drivers[strtolower($vendor)];
        }

        // Default to MikroTik for backward compatibility
        return $this->drivers['mikrotik'] ?? throw new \RuntimeException('No MikroTik driver registered');
    }

    /**
     * Detect vendor from model name
     */
    public function detectVendorByModel(?string $model): ?string
    {
        if (!$model) {
            return null;
        }

        $modelLower = strtolower($model);

        // MikroTik patterns
        if (str_contains($modelLower, 'mikrotik') ||
            str_contains($modelLower, 'routerboard') ||
            str_contains($modelLower, 'hap') ||
            str_contains($modelLower, 'crs') ||
            str_contains($modelLower, 'rb') ||
            str_contains($modelLower, 'sxt') ||
            str_contains($modelLower, 'lhg')) {
            return 'mikrotik';
        }

        // Cisco patterns
        if (str_contains($modelLower, 'cisco') ||
            str_contains($modelLower, 'isr') ||
            str_contains($modelLower, 'asr') ||
            str_contains($modelLower, 'catalyst') ||
            preg_match('/^c\d{4}/', $modelLower)) {
            return 'cisco';
        }

        // Ubiquiti patterns
        if (str_contains($modelLower, 'ubiquiti') ||
            str_contains($modelLower, 'ubnt') ||
            str_contains($modelLower, 'edgeswitch') ||
            str_contains($modelLower, 'edgerouter') ||
            str_contains($modelLower, 'unifi')) {
            return 'ubiquiti';
        }

        // TP-Link patterns
        if (str_contains($modelLower, 'tp-link') ||
            str_contains($modelLower, 'tplink') ||
            str_contains($modelLower, 'omada') ||
            str_contains($modelLower, 'tl-')) {
            return 'tplink';
        }

        // Juniper patterns
        if (str_contains($modelLower, 'juniper') ||
            str_contains($modelLower, 'srx') ||
            str_contains($modelLower, 'mx') ||
            str_contains($modelLower, 'ex')) {
            return 'juniper';
        }

        return null;
    }

    /**
     * Get all registered drivers
     * 
     * @return array<string, RouterDriverInterface>
     */
    public function getAllDrivers(): array
    {
        return $this->drivers;
    }

    /**
     * Get list of supported vendors
     */
    public function getSupportedVendors(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Check if vendor is supported
     */
    public function isVendorSupported(string $vendor): bool
    {
        return isset($this->drivers[strtolower($vendor)]);
    }

    /**
     * Register default drivers from service container
     */
    public function registerDefaults(): void
    {
        // Register MikroTik driver
        if (app()->bound(MikroTikDriver::class)) {
            $this->register('mikrotik', app(MikroTikDriver::class));
        }

        // Register other drivers as they're implemented
        // $this->register('cisco', app(CiscoDriver::class));
        // $this->register('ubiquiti', app(UbiquitiDriver::class));
    }
}
