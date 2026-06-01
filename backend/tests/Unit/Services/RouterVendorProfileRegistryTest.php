<?php

namespace Tests\Unit\Services;

use App\Models\Router;
use App\Services\RouterDriver\DriverRegistry;
use App\Services\RouterDriver\RouterDriverInterface;
use App\Services\RouterDriver\RouterVendorProfileRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterVendorProfileRegistryTest extends TestCase
{
    #[Test]
    public function it_resolves_vendors_from_model_patterns_without_code_changes(): void
    {
        $registry = new RouterVendorProfileRegistry();

        $mikrotik = $registry->resolve(null, 'hAP ax3');
        $ubiquiti = $registry->resolve(null, 'EdgeRouter 4');
        $tplink = $registry->resolve(null, 'TL-R470T+');
        $juniper = $registry->resolve(null, 'SRX300');

        $this->assertSame('mikrotik', $mikrotik['vendor']);
        $this->assertSame('ubiquiti', $ubiquiti['vendor']);
        $this->assertSame('tplink', $tplink['vendor']);
        $this->assertSame('juniper', $juniper['vendor']);
        $this->assertContains('mikrotik', $registry->getSupportedVendors());
        $this->assertContains('ubiquiti', $registry->getSupportedVendors());
    }

    #[Test]
    public function it_prefers_explicit_vendor_when_present(): void
    {
        $registry = new RouterVendorProfileRegistry();
        $resolved = $registry->resolve('cisco', 'hAP ax3');

        $this->assertSame('cisco', $resolved['vendor']);
        $this->assertSame('explicit_vendor', $resolved['matched_by']);
    }

    #[Test]
    public function it_resolves_driver_registry_by_profile_data(): void
    {
        $driver = $this->createMock(RouterDriverInterface::class);
        $driverRegistry = new DriverRegistry(new RouterVendorProfileRegistry());
        $driverRegistry->register('mikrotik', $driver);

        $router = new Router();
        $router->vendor = null;
        $router->model = 'RB5009UG+S+';

        $this->assertSame($driver, $driverRegistry->getDriverForRouter($router));
        $this->assertSame('ubiquiti', $driverRegistry->detectVendorByModel('EdgeRouter 4'));
    }

    #[Test]
    public function it_supports_config_only_vendor_onboarding_without_controller_changes(): void
    {
        config()->set('router_vendors.vendors.fortinet', [
            'display_name' => 'Fortinet',
            'driver' => 'fortinet',
            'capability_profile' => 'fortinet_fortios',
            'aliases' => ['fortigate', 'fortinet'],
            'model_patterns' => ['fortigate*', 'fg-*'],
            'supports' => [
                'pppoe' => true,
                'hotspot' => false,
                'vlan' => true,
                'rest_api' => true,
                'ssh' => true,
                'snmp' => true,
            ],
        ]);

        $registry = new RouterVendorProfileRegistry();
        $resolved = $registry->resolve(null, 'FortiGate 80F');

        $this->assertSame('fortinet', $resolved['vendor']);
        $this->assertSame('fortinet', $resolved['driver']);
        $this->assertSame('fortinet_fortios', $resolved['capability_profile']);
        $this->assertContains('fortinet', $registry->getSupportedVendors());

        $driverRegistry = new DriverRegistry(new RouterVendorProfileRegistry());
        $driverRegistry->register('mikrotik', $this->createMock(RouterDriverInterface::class));
        $this->assertTrue($driverRegistry->isVendorSupported('fortinet'));
    }
}
