<?php

use App\Http\Controllers\Api\RouterController;
use App\Models\Router;
use Illuminate\Support\Facades\Crypt;

it('builds bootstrap complete script with VPN-scoped API and SSH management', function () {
    config()->set('vpn.subnet.base', '10.0.0.0/8');
    config()->set('telegraf.snmp_community', 'traidnet-monitor');

    $router = new Router([
        'name' => 'ggn-hsp-02',
        'port' => 8728,
        'username' => 'traidnet_user',
        'password' => Crypt::encrypt('super-secret'),
    ]);

    $vpnScript = <<<'SCRIPT'
/interface wireguard add name=wg-1c88e82c listen-port=51830 private-key="private"
/ip address add address=10.100.1.2/32 interface=wg-1c88e82c
/interface wireguard peers add interface=wg-1c88e82c public-key="pub" preshared-key="psk" endpoint-address=144.91.71.208 endpoint-port=51830 allowed-address=0.0.0.0/0 persistent-keepalive=00:00:25
/ip route add dst-address=10.8.0.0/24 gateway=wg-1c88e82c comment="Route to VPN server network"
/ip firewall filter add chain=input action=accept protocol=udp dst-port=51830 comment="Allow WireGuard VPN"
SCRIPT;

    $controller = app(RouterController::class);
    $method = new ReflectionMethod($controller, 'buildBootstrapCompleteScript');
    $method->setAccessible(true);

    $script = $method->invoke($controller, $router, $vpnScript, true);

    expect($script)
        ->toContain('/ip service set api disabled=no port=8728 address=10.0.0.0/8')
        ->toContain('/ip service set ssh disabled=no port=22 address=10.0.0.0/8')
        ->toContain('/snmp community add name="traidnet-monitor" addresses=10.8.0.1/32 security=none read-access=yes write-access=no')
        ->toContain('/ip firewall filter add chain=input action=accept protocol=udp dst-port=51830 comment="Allow WireGuard VPN"')
        ->not->toContain('comment="Allow SSH access"')
        ->not->toContain('comment="Allow SNMP monitoring"');
});

it('omits bootstrap snmp block when generating fetch-config script refresh content', function () {
    config()->set('vpn.subnet.base', '10.0.0.0/8');
    config()->set('telegraf.snmp_community', 'traidnet-monitor');

    $router = new Router([
        'name' => 'ggn-hsp-02',
        'port' => 8728,
        'username' => 'traidnet_user',
        'password' => Crypt::encrypt('super-secret'),
    ]);

    $controller = app(RouterController::class);
    $method = new ReflectionMethod($controller, 'buildBootstrapCompleteScript');
    $method->setAccessible(true);

    $script = $method->invoke($controller, $router, '/interface wireguard add name=wg-test', false);

    expect($script)
        ->toContain('/ip service set ssh disabled=no port=22 address=10.0.0.0/8')
        ->toContain('/interface wireguard add name=wg-test')
        ->not->toContain('/snmp set enabled=yes')
        ->not->toContain('comment="Allow SSH access"')
        ->not->toContain('comment="Allow SNMP monitoring"');
});
