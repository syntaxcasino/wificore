<?php

namespace Tests\Unit\Services;

use App\Models\Router;
use App\Services\RouterProvisioningPreflightService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterProvisioningPreflightServiceTest extends TestCase
{
    #[Test]
    public function it_accepts_valid_interfaces_when_inventory_matches(): void
    {
        $router = new Router();
        $router->id = 'router-1';
        $router->name = 'Core-01';
        $router->os_version = '7.18.0';
        $router->wan_interface = 'ether1';
        $router->interface_list = ['ether1', 'ether2', 'ether3'];

        $service = new RouterProvisioningPreflightService();
        $result = $service->preflight($router, [
            'enable_hotspot' => true,
            'hotspot_interfaces' => ['ether2'],
            'enable_pppoe' => true,
            'pppoe_interfaces' => ['ether3'],
        ], ['ether1', 'ether2', 'ether3']);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertContains('ether2', $result['requested_interfaces']);
        $this->assertContains('ether3', $result['available_interfaces']);
    }

    #[Test]
    public function it_rejects_missing_requested_interfaces(): void
    {
        $router = new Router();
        $router->id = 'router-2';
        $router->name = 'Edge-01';
        $router->os_version = '7.18.0';
        $router->wan_interface = 'ether1';
        $router->interface_list = ['ether1', 'ether2'];

        $service = new RouterProvisioningPreflightService();
        $result = $service->preflight($router, [
            'enable_hotspot' => true,
            'hotspot_interfaces' => ['ether9'],
        ], ['ether1', 'ether2']);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('ether9', implode(' ', $result['errors']));
    }

    #[Test]
    public function it_rejects_requested_interfaces_that_overlap_wan(): void
    {
        $router = new Router();
        $router->id = 'router-2b';
        $router->name = 'Edge-02';
        $router->os_version = '7.18.0';
        $router->wan_interface = 'ether1';
        $router->interface_list = ['ether1', 'ether2'];

        $service = new RouterProvisioningPreflightService();
        $result = $service->preflight($router, [
            'enable_hotspot' => true,
            'hotspot_interfaces' => ['ether1'],
        ], ['ether1', 'ether2']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('WAN interface', implode(' ', $result['errors']));
    }

    #[Test]
    public function it_supports_dry_run_when_validation_passes(): void
    {
        $router = new Router();
        $router->id = 'router-3';
        $router->name = 'Branch-01';
        $router->os_version = '7.18.0';
        $router->wan_interface = 'ether1';
        $router->interface_list = ['ether1', 'ether2'];

        $service = new RouterProvisioningPreflightService();
        $result = $service->preflight($router, [
            'enable_pppoe' => true,
            'pppoe_interfaces' => ['ether2'],
        ], ['ether1', 'ether2']);

        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertSame('live_or_cached', $result['metadata']['inventory_source']);
        $this->assertSame('ros7_18', $result['metadata']['router_os_profile']);
        $this->assertTrue($result['metadata']['router_os_supported']);
    }

    #[Test]
    public function it_rejects_missing_or_unsupported_routeros_versions(): void
    {
        $router = new Router();
        $router->id = 'router-5';
        $router->name = 'Legacy-01';
        $router->os_version = '6.49.10';
        $router->wan_interface = 'ether1';
        $router->interface_list = ['ether1', 'ether2'];

        $service = new RouterProvisioningPreflightService();
        $result = $service->preflight($router, [
            'enable_hotspot' => true,
            'hotspot_interfaces' => ['ether2'],
        ], ['ether1', 'ether2']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('unsupported', strtolower(implode(' ', $result['errors'])));
        $this->assertSame('6.49.10', $result['metadata']['router_os_version']);
        $this->assertFalse($result['metadata']['router_os_supported']);
    }

    #[Test]
    public function it_rejects_explicit_required_interfaces(): void
    {
        $router = new Router();
        $router->id = 'router-4';
        $router->name = 'Backbone-01';
        $router->os_version = '7.18.0';
        $router->wan_interface = 'ether1';
        $router->interface_list = ['ether1', 'ether2', 'wg0'];

        $service = new RouterProvisioningPreflightService();
        $result = $service->preflight($router, [
            'required_interfaces' => ['bridge-lan', 'wg1'],
            'bridge_interface' => 'bridge-lan',
            'wireguard_interface' => 'wg1',
        ], ['ether1', 'ether2', 'wg0']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('bridge-lan', implode(' ', $result['errors']));
        $this->assertStringContainsString('wg1', implode(' ', $result['errors']));
    }

}
