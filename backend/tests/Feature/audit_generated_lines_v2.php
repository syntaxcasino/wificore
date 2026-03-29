<?php
// Audit GENERATED RouterOS command line lengths at runtime (not PHP source)
putenv('APP_ENV=testing');
putenv('DB_HOST=wificore-postgres');
putenv('DB_PORT=5432');
putenv('DB_DATABASE=wms_testing');
putenv('DB_USERNAME=admin');
putenv('DB_PASSWORD=secret');

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$cfg = config('database.connections.pgsql');
$cfg['host']     = 'wificore-postgres';
$cfg['port']     = 5432;
$cfg['database'] = 'wms_testing';
$cfg['username'] = 'admin';
$cfg['password'] = 'secret';
config(['database.connections.pgsql' => $cfg]);

use Illuminate\Support\Facades\DB;
use App\Models\Router;
use App\Models\RouterService;
use App\Models\TenantIpPool;

DB::statement('SET search_path TO ts_testing,public');

config([
    'radius.server_ip' => '10.8.0.1',
    'radius.secret'    => 'testing123',
    'vpn.subnet.base'  => '10.0.0.0/8',
    'vpn.server_ip'    => '10.8.0.1',
    'app.base_domain'  => 'example.com',
]);

// Build an in-memory Router model with all required attributes
$router = new Router();
$router->id           = '9172e01f-c8b2-4700-a149-3521606e074b';
$router->name         = 'Test Router';
$router->model        = 'RB951Ui-2HnD';
$router->ip_address   = '192.168.1.1';
$router->vpn_ip       = '10.8.0.10';
$router->username     = 'admin';
$router->port         = 8728;

// Stub the RouterTenantMap lookup so tenant_id resolves without DB hit
\App\Models\RouterTenantMap::unguard();
// Pre-insert into router_tenant_map so getTenantIdAttribute() returns a value
DB::table('public.router_tenant_map')->insertOrIgnore([
    'router_id'  => $router->id,
    'tenant_id'  => 'aaaaaaaa-0000-4000-8000-000000000001',
    'ip_address' => '192.168.1.1',
    'vpn_ip'     => '10.8.0.10',
    'created_at' => now(),
    'updated_at' => now(),
]);

// Build a pool model in-memory (no DB needed)
function makePool(string $id, string $cidr = '100.64.0.0/24', string $svcType = 'pppoe'): TenantIpPool {
    $pool = new TenantIpPool();
    $pool->id            = $id;
    $pool->tenant_id     = 'aaaaaaaa-0000-4000-8000-000000000001';
    $pool->service_type  = $svcType;
    $pool->network_cidr  = $cidr;
    $pool->gateway_ip    = explode('/', $cidr)[0] === '100.64.0.0' ? '100.64.0.1' : (explode('/', $cidr)[0] . '1');
    $pool->range_start   = '100.64.0.100';
    $pool->range_end     = '100.64.0.200';
    $pool->dns_primary   = '8.8.8.8';
    $pool->dns_secondary = '8.8.4.4';
    return $pool;
}

function makeSvc(string $routerId, TenantIpPool $pool, Router $router, string $svcType = RouterService::TYPE_PPPOE, array $extra = []): RouterService {
    $svc = new RouterService();
    $svc->id              = \Str::uuid()->toString();
    $svc->router_id       = $routerId;
    $svc->service_type    = $svcType;
    $svc->interface_name  = 'ether2';
    $svc->ip_pool_id      = $pool->id;
    $svc->interfaces      = json_encode([['name' => 'ether2']]);
    $svc->vlan_required   = $extra['vlan_required'] ?? false;
    $svc->vlan_id         = $extra['vlan_id'] ?? null;
    $svc->advanced_config = $extra['advanced_config'] ?? [];
    $svc->setRelation('router', $router);
    $svc->setRelation('ipPool', $pool);
    return $svc;
}

// Audit function
function auditScript(string $label, string $script): void {
    $lines = explode("\n", $script);
    $violations = [];
    $longest = 0;
    foreach ($lines as $i => $line) {
        $len = strlen($line);
        if ($len > $longest) { $longest = $len; }
        if ($len > 150) {
            $violations[] = sprintf("  Line %d (%d chars): %s", $i + 1, $len, substr($line, 0, 100) . '...');
        }
    }
    $total = count($lines);
    $violCount = count($violations);
    echo "\n=== $label ===\n";
    echo "Total commands: $total | Longest line: $longest chars | Violations (>150): $violCount\n";
    if ($violations) {
        foreach ($violations as $v) { echo $v . "\n"; }
    } else {
        echo "  PASS: All generated lines <= 150 chars (hAP Lite safe)\n";
    }
}

$pool1 = makePool('bb000001-0000-4000-8000-000000000001');
$pool2 = makePool('bb000002-0000-4000-8000-000000000002', '192.168.100.0/24', 'hotspot');
$pool3 = makePool('bb000003-0000-4000-8000-000000000003', '192.168.200.0/24', 'pppoe');

// --- PPPoE ---
try {
    $svc = makeSvc($router->id, $pool1, $router, RouterService::TYPE_PPPOE);
    $script = (new \App\Services\MikroTik\ZeroConfigPPPoEGenerator())->generate($svc);
    auditScript('PPPoE (no VLAN)', $script);
} catch (\Exception $e) {
    echo "PPPoE ERROR: " . $e->getMessage() . "\n";
}

// PPPoE + VLAN
try {
    $svc = makeSvc($router->id, $pool1, $router, RouterService::TYPE_PPPOE, ['vlan_required' => true, 'vlan_id' => 100]);
    $script = (new \App\Services\MikroTik\ZeroConfigPPPoEGenerator())->generate($svc);
    auditScript('PPPoE (VLAN 100)', $script);
} catch (\Exception $e) {
    echo "PPPoE+VLAN ERROR: " . $e->getMessage() . "\n";
}

// --- Hotspot ---
try {
    $svc = makeSvc($router->id, $pool2, $router, RouterService::TYPE_HOTSPOT);
    $script = (new \App\Services\MikroTik\ZeroConfigHotspotGenerator())->generate($svc);
    auditScript('Hotspot (no VLAN)', $script);
} catch (\Exception $e) {
    echo "Hotspot ERROR: " . $e->getMessage() . "\n";
}

// Hotspot + VLAN
try {
    $svcData = new RouterService();
    $svcData->id             = \Str::uuid()->toString();
    $svcData->router_id      = $router->id;
    $svcData->service_type   = RouterService::TYPE_HOTSPOT;
    $svcData->interface_name = 'ether2';
    $svcData->ip_pool_id     = $pool2->id;
    $svcData->interfaces     = json_encode([['name' => 'ether2', 'vlan_required' => true, 'vlan_id' => 200]]);
    $svcData->vlan_required  = false;
    $svcData->advanced_config = [];
    $svcData->setRelation('router', $router);
    $svcData->setRelation('ipPool', $pool2);
    $script = (new \App\Services\MikroTik\ZeroConfigHotspotGenerator())->generate($svcData);
    auditScript('Hotspot (per-iface VLAN 200)', $script);
} catch (\Exception $e) {
    echo "Hotspot+VLAN ERROR: " . $e->getMessage() . "\n";
}

// --- Hybrid Bridge ---
try {
    $svc = makeSvc($router->id, $pool2, $router, RouterService::TYPE_HYBRID, [
        'advanced_config' => [
            'bridge_mode'     => true,
            'hotspot_pool_id' => $pool2->id,
            'pppoe_pool_id'   => $pool3->id,
        ],
    ]);
    $svc->setRelation('ipPool', $pool2);
    $script = (new \App\Services\MikroTik\ZeroConfigHybridGenerator())->generate($svc);
    auditScript('Hybrid (Bridge Mode)', $script);
} catch (\Exception $e) {
    echo "Hybrid Bridge ERROR: " . $e->getMessage() . "\n";
}

echo "\nAudit complete.\n";
