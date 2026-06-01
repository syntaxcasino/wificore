<?php

namespace Tests\Unit\Services;

use App\Models\Router;
use App\Models\RouterService;
use App\Models\Tenant;
use App\Models\TenantIpPool;
use App\Services\MikroTik\RouterOsCapabilityRegistry;
use App\Services\MikroTik\RouterOsV7ProvisioningValidator;
use App\Services\MikroTik\ZeroConfigHybridGenerator;
use App\Services\MikroTik\ZeroConfigHotspotGenerator;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Services\MikroTik\ServiceTemplateService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterOsProvisioningMatrixTest extends TestCase
{
    #[Test]
    public function it_validates_supported_routeros_versions_in_the_matrix(): void
    {
        $registry = new RouterOsCapabilityRegistry();
        $validator = new RouterOsV7ProvisioningValidator($registry);
        $router = new Router();
        $router->interface_list = ['ether1', 'ether2', 'ether3', 'wg0'];

        $matrix = [
            '7.8.2' => implode("\n", [
                '/log info "PPPoE bootstrap"',
                '/system identity set name=core-01',
                '/interface list add name=WAN',
                '/interface list member add list=WAN interface=ether1',
                '/ip pool add name=pool-lan ranges=192.168.88.10-192.168.88.254',
                '/ip address add address=192.168.88.1/24 interface=ether2',
                '/ip dhcp-server network add address=192.168.88.0/24 gateway=192.168.88.1',
                '/ip dhcp-server add name=dhcp-lan interface=ether2 address-pool=pool-lan lease-time=1h disabled=no',
                '/ppp profile add name=ppp-default',
                '/interface pppoe-server server add interface=ether2 service-name=pppoe-service default-profile=ppp-default authentication=chap,mschap2 one-session-per-host=yes keepalive-timeout=30s max-mtu=1480 max-mru=1480 disabled=no',
                '/ip firewall filter add chain=input connection-state=established,related action=accept',
                '/ip firewall nat add chain=srcnat action=masquerade out-interface-list=WAN',
                '/queue simple add name=queue-1 target=192.168.88.10/32 max-limit=10M/10M',
            ]),
            '7.15.1' => implode("\n", [
                '/log info "Hotspot bootstrap"',
                '/system identity set name=branch-01',
                '/ip hotspot profile add name=hs-prof hotspot-address=192.168.50.1 use-radius=yes html-directory=hotspot dns-name=branch.example.com',
                '/ip hotspot add name=hs-srv interface=ether2 profile=hs-prof address-pool=pool-hs addresses-per-mac=2',
                '/ip hotspot walled-garden add dst-host=branch.example.com action=allow',
                '/system logging add action=memory topics=ppp',
                '/system logging remove numbers=0',
            ]),
            '7.18.0' => implode("\n", [
                '/log info "WireGuard bootstrap"',
                '/interface wireguard add name=wg0 listen-port=51820',
                '/interface wireguard peers add interface=wg0 public-key=ABCDEF1234567890 allowed-address=10.8.0.2/32',
                '/ip address add address=10.8.0.1/24 interface=wg0',
                '/ip firewall filter add chain=forward connection-state=established,related action=accept',
                '/ip firewall nat add chain=srcnat action=masquerade out-interface-list=WAN',
                '/queue simple add name=queue-2 target=10.8.0.2/32 max-limit=20M/20M',
            ]),
        ];

        foreach ($matrix as $version => $script) {
            $router->os_version = $version;

            $profile = $registry->resolveProfile($version);
            $this->assertTrue($profile['supported'], "{$version} should be supported");
            $this->assertNotEmpty($profile['profile']);

            $result = $validator->validateScript($router, $script);
            $this->assertTrue($result['valid'], "{$version} script should validate");
            $this->assertEmpty($result['errors'], "{$version} should not produce validation errors");
        }
    }


    #[Test]
    public function it_blocks_dangerous_commands_and_still_keeps_supported_versions_green(): void
    {
        $registry = new RouterOsCapabilityRegistry();
        $validator = new RouterOsV7ProvisioningValidator($registry);
        $router = new Router();
        $router->os_version = '7.18.0';
        $router->interface_list = ['ether1', 'ether2', 'wg0'];

        $script = implode("
", [
            '/system identity set name=edge-01',
            '/system reset-configuration no-defaults=yes skip-backup=yes',
        ]);

        $result = $validator->validateScript($router, $script);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('reset-configuration', implode(' ', $result['errors']));
    }

    #[Test]
    public function it_warns_when_queue_targets_are_missing_but_does_not_fail_supported_scripts(): void
    {
        $registry = new RouterOsCapabilityRegistry();
        $validator = new RouterOsV7ProvisioningValidator($registry);
        $router = new Router();
        $router->os_version = '7.15.1';
        $router->interface_list = ['ether1', 'ether2'];

        $result = $validator->validateScript($router, implode("
", [
            '/system identity set name=branch-02',
            '/queue simple add name=queue-branch max-limit=10M/10M',
        ]));

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('target', implode(' ', $result['warnings']));
    }

    #[Test]
    public function it_rejects_routeros_version_zero_and_unknown_profiles(): void
    {
        $registry = new RouterOsCapabilityRegistry();
        $router = new Router();
        $router->interface_list = ['ether1'];

        $unsupported = $registry->resolveProfile('6.49.10');
        $invalid = $registry->resolveProfile('abc');
        $missing = $registry->resolveProfile(null);

        $this->assertFalse($unsupported['supported']);
        $this->assertFalse($invalid['supported']);
        $this->assertFalse($missing['supported']);
        $this->assertNotEmpty($unsupported['error']);
        $this->assertNotEmpty($invalid['error']);
        $this->assertNotEmpty($missing['error']);
    }

    #[Test]
    public function it_validates_real_low_end_generator_output_for_supported_routeros_profiles(): void
    {
        $tenant = new Tenant();
        $tenant->setRawAttributes([
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-matrix',
            'subdomain' => 'testtenantmatrix',
            'schema_name' => 'ts_testing_matrix',
            'schema_created' => true,
            'is_active' => true,
            'email' => 'test-matrix@example.com',
        ], true);
        $tenant->exists = true;

        $router = new class extends Router {
            public ?Tenant $tenantShim = null;
            public ?string $tenantIdShim = null;

            public function getTenantIdAttribute(): ?string
            {
                return $this->tenantIdShim;
            }

            public function getTenantAttribute(): ?Tenant
            {
                return $this->tenantShim;
            }
        };
        $router->setRawAttributes([
            'id' => '9172e01f-c8b2-4700-a149-3521606e074b',
            'name' => 'hap-lite-core',
            'model' => 'hAP lite',
            'os_version' => '7.18.0',
            'vpn_ip' => '10.8.0.10',
            'wan_interface' => 'ether1',
            'interface_list' => json_encode(['ether1', 'ether2', 'ether3', 'wg0']),
        ], true);
        $router->exists = true;
        $router->tenantShim = $tenant;
        $router->tenantIdShim = $tenant->id;

        $pool = new TenantIpPool();
        $pool->setRawAttributes([
            'id' => 'cc000001-0000-4000-8000-000000000001',
            'tenant_id' => $tenant->id,
            'service_type' => 'pppoe',
            'pool_name' => 'pppoe-pool-matrix',
            'network_cidr' => '100.64.0.0/24',
            'gateway_ip' => '100.64.0.1',
            'range_start' => '100.64.0.2',
            'range_end' => '100.64.0.254',
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
            'status' => 'active',
        ], true);
        $pool->exists = true;

        $hotspotService = new RouterService();
        $hotspotService->setRawAttributes([
            'id' => 'hs-service-matrix',
            'router_id' => $router->id,
            'service_type' => RouterService::TYPE_HOTSPOT,
            'service_name' => 'hotspot-matrix',
            'interface_name' => 'ether2',
            'wan_interface' => 'ether1',
            'ip_pool_id' => $pool->id,
            'vlan_required' => false,
            'interfaces' => json_encode([['name' => 'ether2']]),
            'advanced_config' => '{}',
            'deployment_status' => RouterService::DEPLOYMENT_PENDING,
            'status' => RouterService::STATUS_INACTIVE,
            'enabled' => true,
        ], true);
        $hotspotService->exists = true;
        $hotspotService->setRelation('router', $router);
        $hotspotService->setRelation('ipPool', $pool);

        $pppoeService = new RouterService();
        $pppoeService->setRawAttributes([
            'id' => 'pppoe-service-matrix',
            'router_id' => $router->id,
            'service_type' => RouterService::TYPE_PPPOE,
            'service_name' => 'pppoe-matrix',
            'interface_name' => 'ether2',
            'wan_interface' => 'ether1',
            'ip_pool_id' => $pool->id,
            'vlan_required' => false,
            'interfaces' => json_encode(['ether2']),
            'advanced_config' => '{}',
            'deployment_status' => RouterService::DEPLOYMENT_PENDING,
            'status' => RouterService::STATUS_INACTIVE,
            'enabled' => true,
        ], true);
        $pppoeService->exists = true;
        $pppoeService->setRelation('router', $router);
        $pppoeService->setRelation('ipPool', $pool);

        $hybridService = new RouterService();
        $hybridService->setRawAttributes([
            'id' => 'hybrid-service-matrix',
            'router_id' => $router->id,
            'service_type' => RouterService::TYPE_HYBRID,
            'service_name' => 'hybrid-matrix',
            'interface_name' => 'ether2',
            'wan_interface' => 'ether1',
            'ip_pool_id' => $pool->id,
            'vlan_required' => false,
            'interfaces' => json_encode([['name' => 'ether2']]),
            'advanced_config' => json_encode([
                'bridge_mode' => true,
                'hotspot_pool_id' => $pool->id,
                'pppoe_pool_id' => $pool->id,
            ]),
            'deployment_status' => RouterService::DEPLOYMENT_PENDING,
            'status' => RouterService::STATUS_INACTIVE,
            'enabled' => true,
        ], true);
        $hybridService->exists = true;
        $hybridService->setRelation('router', $router);
        $hybridService->setRelation('hotspotPool', $pool);
        $hybridService->setRelation('pppoePool', $pool);

        $registry = new RouterOsCapabilityRegistry();
        $validator = new RouterOsV7ProvisioningValidator($registry);

        $generators = [
            'pppoe' => (new ZeroConfigPPPoEGenerator())->generate($pppoeService),
            'hotspot' => (new ZeroConfigHotspotGenerator())->generate($hotspotService),
            'hybrid' => (new ZeroConfigHybridGenerator())->generate($hybridService),
        ];

        foreach ($generators as $label => $script) {
            $result = $validator->validateScript($router, $script);

            $this->assertTrue($result['valid'], "{$label} low-end generator script should validate");
            $this->assertEmpty($result['errors'], "{$label} should not emit validation errors");
        }
    }

    #[Test]
    public function it_validates_real_multi_wan_generator_output_for_supported_routeros_profiles(): void
    {
        $router = new Router();
        $router->os_version = '7.18.0';
        $router->interface_list = ['ether1', 'ether2', 'bridge-lan'];
        $router->wan_interface = 'ether1';
        $router->name = 'multi-wan-core';

        $service = new ServiceTemplateService();
        $registry = new RouterOsCapabilityRegistry();
        $validator = new RouterOsV7ProvisioningValidator($registry);

        $failoverScript = $service->generateFromTemplate($router, 'multi-wan-failover', [
            'primary_wan' => 'ether1',
            'backup_wan' => 'ether2',
            'dns_servers' => '8.8.8.8,1.1.1.1',
        ]);

        $pccScript = $service->generateFromTemplate($router, 'pcc-balanced', [
            'primary_wan' => 'ether1',
            'backup_wan' => 'ether2',
            'lan_interface' => 'bridge-lan',
            'primary_gateway' => '192.0.2.1',
            'backup_gateway' => '198.51.100.1',
        ]);

        $failoverResult = $validator->validateScript($router, $failoverScript);
        $pccResult = $validator->validateScript($router, $pccScript);

        $this->assertTrue($failoverResult['valid'], 'multi-wan failover template should validate');
        $this->assertEmpty($failoverResult['errors']);
        $this->assertTrue($pccResult['valid'], 'pcc-balanced template should validate');
        $this->assertEmpty($pccResult['errors']);
    }

    #[Test]
    public function it_rejects_unsupported_ntp_servers_on_routeros_v7_8(): void
    {
        $registry = new RouterOsCapabilityRegistry();
        $validator = new RouterOsV7ProvisioningValidator($registry);
        $router = new Router();
        $router->os_version = '7.8.2';
        $router->interface_list = ['ether1'];

        $result = $validator->validateScript($router, '/system ntp client set enabled=yes servers=1.1.1.1');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('servers', implode(' ', $result['errors']));
    }
}