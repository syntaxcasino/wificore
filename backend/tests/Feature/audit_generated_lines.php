<?php
// Audit GENERATED RouterOS command line lengths — not PHP source line lengths
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

DB::statement('SET search_path TO ts_testing,public');

// Fetch real test data
$routerId  = '9172e01f-c8b2-4700-a149-3521606e074b';
$poolId    = 'bb000001-0000-4000-8000-000000000001';
$hybHsPool = 'bb000002-0000-4000-8000-000000000002';
$hybPpPool = 'bb000003-0000-4000-8000-000000000003';

$router = \App\Models\Router::find($routerId);
if (!$router) { die("Router not found\n"); }

$pppoePool = \App\Models\TenantIpPool::withoutGlobalScopes()->find($poolId);
if (!$pppoePool) { die("PPPoE pool not found\n"); }

// Build RouterService models (without saving)
$pppoeSvc = new \App\Models\RouterService();
$pppoeSvc->id             = \Str::uuid();
$pppoeSvc->router_id      = $routerId;
$pppoeSvc->service_type   = \App\Models\RouterService::TYPE_PPPOE;
$pppoeSvc->interface_name = 'ether2';
$pppoeSvc->ip_pool_id     = $poolId;
$pppoeSvc->interfaces     = json_encode([['name' => 'ether2']]);
$pppoeSvc->vlan_required  = false;
$pppoeSvc->advanced_config = [];
$pppoeSvc->setRelation('router', $router);
$pppoeSvc->setRelation('ipPool', $pppoePool);

$hotspotSvc = new \App\Models\RouterService();
$hotspotSvc->id             = \Str::uuid();
$hotspotSvc->router_id      = $routerId;
$hotspotSvc->service_type   = \App\Models\RouterService::TYPE_HOTSPOT;
$hotspotSvc->interface_name = 'ether2';
$hotspotSvc->ip_pool_id     = $poolId;
$hotspotSvc->interfaces     = json_encode([['name' => 'ether2']]);
$hotspotSvc->vlan_required  = false;
$hotspotSvc->advanced_config = [];
$hotspotSvc->setRelation('router', $router);
$hotspotSvc->setRelation('ipPool', $pppoePool);

// Audit function
function auditScript(string $label, string $script): void {
    $lines = explode("\n", $script);
    $violations = [];
    foreach ($lines as $i => $line) {
        $len = strlen($line);
        if ($len > 150) {
            $violations[] = "  Line " . ($i + 1) . " ($len chars): " . substr($line, 0, 80) . '...';
        }
    }
    $total = count($lines);
    $violCount = count($violations);
    echo "\n=== $label ===\n";
    echo "Total lines: $total\n";
    echo "Lines > 150 chars: $violCount\n";
    if ($violations) {
        foreach ($violations as $v) { echo $v . "\n"; }
    } else {
        echo "  PASS: All lines <= 150 chars\n";
    }
}

// PPPoE
try {
    config(['radius.server_ip' => '10.8.0.1', 'radius.secret' => 'testing123']);
    $pppoe = (new \App\Services\MikroTik\ZeroConfigPPPoEGenerator())->generate($pppoeSvc);
    auditScript('PPPoE Generator', $pppoe);
} catch (\Exception $e) {
    echo "PPPoE ERROR: " . $e->getMessage() . "\n";
}

// Hotspot
try {
    $hotspot = (new \App\Services\MikroTik\ZeroConfigHotspotGenerator())->generate($hotspotSvc);
    auditScript('Hotspot Generator', $hotspot);
} catch (\Exception $e) {
    echo "Hotspot ERROR: " . $e->getMessage() . "\n";
}

// Hybrid (bridge mode)
$hsPool = \App\Models\TenantIpPool::withoutGlobalScopes()->find($hybHsPool);
$ppPool = \App\Models\TenantIpPool::withoutGlobalScopes()->find($hybPpPool);

if ($hsPool && $ppPool) {
    $hybridSvc = new \App\Models\RouterService();
    $hybridSvc->id             = \Str::uuid();
    $hybridSvc->router_id      = $routerId;
    $hybridSvc->service_type   = \App\Models\RouterService::TYPE_HYBRID;
    $hybridSvc->interface_name = 'ether2';
    $hybridSvc->interfaces     = json_encode([['name' => 'ether2']]);
    $hybridSvc->vlan_required  = true;
    $hybridSvc->advanced_config = [
        'bridge_mode'      => true,
        'hotspot_pool_id'  => $hybHsPool,
        'pppoe_pool_id'    => $hybPpPool,
    ];
    $hybridSvc->setRelation('router', $router);
    $hybridSvc->setRelation('ipPool', $hsPool);

    try {
        $hybrid = (new \App\Services\MikroTik\ZeroConfigHybridGenerator())->generate($hybridSvc);
        auditScript('Hybrid Generator (Bridge)', $hybrid);
    } catch (\Exception $e) {
        echo "Hybrid ERROR: " . $e->getMessage() . "\n";
    }
} else {
    echo "Hybrid pools not found in DB — skipping\n";
}
