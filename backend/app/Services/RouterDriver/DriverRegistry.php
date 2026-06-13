<?php

namespace App\Services\RouterDriver;

use App\Models\Router;

/**
 * Router Driver Registry
 * 
 * Manages all available router drivers and provides driver resolution.
 */
class DriverRegistry
{
    /** @var array<string, RouterDriverInterface> */
    private array $drivers = [];

    private readonly RouterVendorProfileRegistry $profileRegistry;

    public function __construct(?RouterVendorProfileRegistry $profileRegistry = null)
    {
        $this->profileRegistry = $profileRegistry ?? new RouterVendorProfileRegistry();
    }

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
    public function getDriverForRouter(Router $router): RouterDriverInterface
    {
        $profile = $this->profileRegistry->resolveForRouter($router);
        $resolvedVendor = strtolower((string) ($profile['vendor'] ?? $router->vendor ?? ''));
        $driverKey = strtolower((string) ($profile['driver'] ?? $resolvedVendor));

        if ($resolvedVendor !== '' && isset($this->drivers[$resolvedVendor])) {
            return $this->drivers[$resolvedVendor];
        }

        if ($driverKey !== '' && isset($this->drivers[$driverKey])) {
            return $this->drivers[$driverKey];
        }

        $explicitVendor = strtolower((string) ($router->vendor ?? ''));
        if ($explicitVendor !== '' && isset($this->drivers[$explicitVendor])) {
            return $this->drivers[$explicitVendor];
        }

        $vendorByModel = $this->detectVendorByModel($router->model);
        if ($vendorByModel && isset($this->drivers[strtolower($vendorByModel)])) {
            return $this->drivers[strtolower($vendorByModel)];
        }

        // Default to MikroTik for backward compatibility
        return $this->drivers['mikrotik'] ?? throw new \RuntimeException('No MikroTik driver registered');
    }

    /**
     * Detect vendor from model name
     */
    public function detectVendorByModel(?string $model): ?string
    {
        return $this->profileRegistry->resolve(null, $model)['vendor'] ?? null;
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
        return array_values(array_unique(array_merge(
            array_keys($this->drivers),
            $this->profileRegistry->getSupportedVendors(),
        )));
    }

    /**
     * Check if vendor is supported
     */
    public function isVendorSupported(string $vendor): bool
    {
        return isset($this->drivers[strtolower($vendor)]) || $this->profileRegistry->supportsVendor($vendor);
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
