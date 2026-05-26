<?php

use App\Models\Router;
use App\Models\RouterService;
use App\Models\TenantIpPool;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;

it('generates a deployment-ready PPPoE script that blocks unauthenticated access and only NATs authenticated sessions', function () {
    config()->set('radius.server_ip', '10.8.0.1');
    config()->set('radius.secret', 'testing123');
    config()->set('vpn.subnet.base', '10.0.0.0/8');

    $router = (new Router())->forceFill([
        'id' => '1c88e82c-1111-2222-3333-444444444444',
        'name' => 'ggn-hsp-02',
        'model' => 'RB750Gr3',
    ]);

    $pool = new TenantIpPool([
        'network_cidr' => '100.64.0.0/24',
        'gateway_ip' => '100.64.0.1',
        'range_start' => '100.64.0.2',
        'range_end' => '100.64.0.255',
        'dns_primary' => '8.8.8.8',
        'dns_secondary' => '8.8.4.4',
    ]);

    $service = new RouterService([
        'interface_name' => json_encode(['ether2', 'ether3', 'ether4']),
        'vlan_required' => false,
        'vlan_id' => null,
    ]);
    $service->setRelation('router', $router);
    $service->setRelation('ipPool', $pool);

    $script = app(ZeroConfigPPPoEGenerator::class)->generate($service);

    expect($script)
        ->toContain('/ppp aaa set use-radius="yes" accounting="yes" interim-update="5m"')
        ->toContain('interface-list="PA-1c88e82c"')
        ->toContain('disable-running-check=no')
        ->toContain('comment="PPPoE-1c88e82c-BLOCK-UNAUTH"')
        ->toContain('in-interface-list="PA-1c88e82c" out-interface-list="WAN" action="accept" comment="PPPoE-1c88e82c-INET-AUTH"')
        ->toContain('in-interface-list="WAN" out-interface-list="PA-1c88e82c" connection-state="established,related" action="accept" comment="PPPoE-1c88e82c-WAN-EST"')
        ->toContain('/ip firewall nat add chain="srcnat" out-interface-list="WAN" action="masquerade" comment="PPPoE-1c88e82c"')
        ->not->toContain('src-address=100.64.0.0/24')
        ->toContain('rate-limit=""')
        ->not->toContain('rate-limit="5M')
        ->not->toContain('rate-limit="10M');
});

it('restricts PPPoE management and SNMP access to VPN sources only', function () {
    config()->set('radius.server_ip', '10.8.0.1');
    config()->set('radius.secret', 'testing123');
    config()->set('vpn.subnet.base', '10.0.0.0/8');

    $router = (new Router())->forceFill([
        'id' => '1c88e82c-1111-2222-3333-444444444444',
        'name' => 'ggn-hsp-02',
        'model' => 'RB750Gr3',
    ]);

    $pool = new TenantIpPool([
        'network_cidr' => '100.64.0.0/24',
        'gateway_ip' => '100.64.0.1',
        'range_start' => '100.64.0.2',
        'range_end' => '100.64.0.255',
        'dns_primary' => '8.8.8.8',
        'dns_secondary' => '8.8.4.4',
    ]);

    $service = new RouterService([
        'interface_name' => json_encode(['ether2', 'ether3', 'ether4']),
        'vlan_required' => false,
        'vlan_id' => null,
    ]);
    $service->setRelation('router', $router);
    $service->setRelation('ipPool', $pool);

    $script = app(ZeroConfigPPPoEGenerator::class)->generate($service);

    expect($script)
        ->toContain('dst-port="22,8291,8728,8729" src-address="10.0.0.0/8" action="accept" comment="PPPoE-1c88e82c-MGMT"')
        ->toContain('dst-port="161" src-address="10.0.0.0/8" action="accept" comment="PPPoE-1c88e82c-SNMP"')
        ->not->toContain('comment="Allow SSH access"')
        ->not->toContain('comment="Allow SNMP monitoring"');
});
