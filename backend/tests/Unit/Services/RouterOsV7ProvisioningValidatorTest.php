<?php

namespace Tests\Unit\Services;

use App\Models\Router;
use App\Services\MikroTik\RouterOsCapabilityRegistry;
use App\Services\MikroTik\RouterOsV7ProvisioningValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterOsV7ProvisioningValidatorTest extends TestCase
{
    #[Test]
    public function it_rejects_missing_routeros_version(): void
    {
        $router = new Router();
        $router->os_version = null;
        $router->interface_list = ['ether1', 'ether2'];

        $validator = new RouterOsV7ProvisioningValidator(new RouterOsCapabilityRegistry());
        $result = $validator->validateScript($router, '/interface/bridge/add name=br-lan');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    #[Test]
    public function it_rejects_unknown_interface_reference(): void
    {
        $router = new Router();
        $router->os_version = '7.15.3';
        $router->interface_list = ['ether1', 'ether2'];

        $script = '/interface/bridge/port/add bridge=br-lan interface=ether9';

        $validator = new RouterOsV7ProvisioningValidator(new RouterOsCapabilityRegistry());
        $result = $validator->validateScript($router, $script);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('ether9', implode(' ', $result['errors']));
    }

    #[Test]
    public function it_tracks_interfaces_created_within_the_same_script(): void
    {
        $router = new Router();
        $router->os_version = '7.18.1';
        $router->interface_list = ['ether1', 'ether2'];

        $script = implode("\n", [
            '/interface/bridge/add name=br-lan',
            '/interface/bridge/port/add bridge=br-lan interface=ether2',
        ]);

        $validator = new RouterOsV7ProvisioningValidator(new RouterOsCapabilityRegistry());
        $result = $validator->validateScript($router, $script);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function it_blocks_dangerous_reset_configuration_commands(): void
    {
        $router = new Router();
        $router->os_version = '7.18.1';
        $router->interface_list = ['ether1', 'ether2'];

        $validator = new RouterOsV7ProvisioningValidator(new RouterOsCapabilityRegistry());
        $result = $validator->validateScript($router, '/system/reset-configuration keep-users=yes');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('reset-configuration', implode(' ', $result['errors']));
    }

    #[Test]
    public function it_accepts_valid_routeros_v7_commands(): void
    {
        $router = new Router();
        $router->os_version = '7.18.1';
        $router->interface_list = ['ether1', 'ether2'];

        $script = implode("\n", [
            '/interface/bridge/add name=br-lan',
            '/interface/bridge/port/add bridge=br-lan interface=ether2',
            '/ip/pool/add name=pool-lan ranges=192.168.88.10-192.168.88.254',
            '/ppp/profile/add name=ppp-default',
            '/interface/pppoe-server/server/add interface=ether2 service-name=pppoe-service',
        ]);

        $validator = new RouterOsV7ProvisioningValidator(new RouterOsCapabilityRegistry());
        $result = $validator->validateScript($router, $script);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }
}
