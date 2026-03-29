<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use App\Models\Router;
use App\Models\RouterService;
use App\Models\TenantIpPool;
use App\Models\Tenant;
use App\Models\RouterTenantMap;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Services\MikroTik\ZeroConfigHotspotGenerator;
use App\Services\MikroTik\ZeroConfigHybridGenerator;
use App\Jobs\DeployRouterServiceJob;

// All IDs are valid UUIDs for PostgreSQL uuid columns
const TEST_SCHEMA    = 'ts_testing';
const TEST_TENANT_ID = 'aaaaaaaa-0000-4000-8000-000000000001';
const TEST_ROUTER_ID = '9172e01f-c8b2-4700-a149-3521606e074b';
const TEST_POOL_ID   = 'bb000001-0000-4000-8000-000000000001';
const HS_POOL_ID     = 'bb000002-0000-4000-8000-000000000002';
const PPPOE_POOL_ID  = 'bb000003-0000-4000-8000-000000000003';
const GW_POOL_1      = 'bb000011-0000-4000-8000-000000000011';
const GW_POOL_2      = 'bb000012-0000-4000-8000-000000000012';
const GW_POOL_3      = 'bb000013-0000-4000-8000-000000000013';
const GW_POOL_4      = 'bb000014-0000-4000-8000-000000000014';
const GW_POOL_5      = 'bb000015-0000-4000-8000-000000000015';
const DNS_POOL_1     = 'bb000021-0000-4000-8000-000000000021';

/**
 * Ensure the ts_testing schema and its tables exist (idempotent, once per process).
 * Must run OUTSIDE any DatabaseTransactions wrapper because CREATE SCHEMA / migrations
 * are DDL that must be committed so subsequent reconnected sessions can see the tables.
 * We achieve this by calling DB::purge()/reconnect() which closes the current PDO
 * connection (causing PgBouncer to issue a server-side ROLLBACK if in a transaction)
 * and opens a fresh committed-mode connection.
 */
function ensureTestSchemaExists(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    // --- public schema migrations (idempotent) ---
    config(['database.connections.pgsql.search_path' => 'public']);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    DB::connection()->recordsHaveBeenModified();
    Artisan::call('migrate', ['--path' => 'database/migrations', '--force' => true]);

    // --- create ts_testing schema ---
    DB::statement('CREATE SCHEMA IF NOT EXISTS ' . TEST_SCHEMA);

    // --- tenant-schema migrations against ts_testing (idempotent) ---
    config(['database.connections.pgsql.search_path' => TEST_SCHEMA . ',public']);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    DB::connection()->recordsHaveBeenModified();
    Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--force' => true]);

    // --- seed the test tenant row (once; insertOrIgnore is safe on reruns) ---
    config(['database.connections.pgsql.search_path' => 'public']);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    DB::connection()->recordsHaveBeenModified();
    DB::table('public.tenants')->insertOrIgnore([
        'id'             => TEST_TENANT_ID,
        'name'           => 'Test Tenant',
        'slug'           => 'test-tenant',
        'subdomain'      => 'testtenant',
        'schema_name'    => TEST_SCHEMA,
        'schema_created' => true,
        'is_active'      => true,
        'email'          => 'test@example.com',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

function bootstrapTestSchema(): void
{
    ensureTestSchemaExists();

    // Point the active connection at the tenant schema so table-qualified queries work.
    config(['database.connections.pgsql.search_path' => TEST_SCHEMA . ',public']);
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    DB::connection()->recordsHaveBeenModified();
    DB::statement('SET search_path TO ' . TEST_SCHEMA . ',public');
}

function createTestRouter(string $id = TEST_ROUTER_ID): Router
{
    DB::table(TEST_SCHEMA . '.routers')->insertOrIgnore([
        'id'         => $id,
        'name'       => 'test-router',
        'ip_address' => '10.0.0.1',
        'model'      => 'RB750Gr3',
        'username'   => 'admin',
        'password'   => 'admin',
        'status'     => 'online',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('public.router_tenant_map')->insertOrIgnore([
        'router_id'  => $id,
        'tenant_id'  => TEST_TENANT_ID,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return Router::find($id);
}

function createTestPool(string $id, array $overrides = []): TenantIpPool
{
    $defaults = [
        'id'            => $id,
        'tenant_id'     => TEST_TENANT_ID,
        'service_type'  => 'pppoe',
        'pool_name'     => 'test-pool-' . substr($id, 0, 8),
        'network_cidr'  => '100.64.0.0/24',
        'gateway_ip'    => '100.64.0.1',
        'range_start'   => '100.64.0.2',
        'range_end'     => '100.64.0.255',
        'dns_primary'   => '8.8.8.8',
        'dns_secondary' => '8.8.4.4',
        'total_ips'     => 254,
        'allocated_ips' => 0,
        'available_ips' => 254,
        'status'        => 'active',
        'created_at'    => now(),
        'updated_at'    => now(),
    ];
    $attrs = array_merge($defaults, $overrides);
    if (empty($attrs['gateway_ip'])) {
        $parts = explode('/', $attrs['network_cidr'] ?? '100.64.0.0/24');
        $attrs['gateway_ip'] = long2ip(ip2long($parts[0]) + 1) ?: '100.64.0.1';
    }
    DB::table('public.tenant_ip_pools')->insertOrIgnore($attrs);
    $pool = new TenantIpPool();
    $pool->setRawAttributes($attrs);
    $pool->exists = true;
    return $pool;
}

function createTestService(string $routerId, ?string $poolId, array $overrides = []): RouterService
{
    $id = (string) \Illuminate\Support\Str::uuid();
    $defaults = [
        'id'                => $id,
        'router_id'         => $routerId,
        'interface_name'    => 'ether2',
        'service_type'      => RouterService::TYPE_PPPOE,
        'service_name'      => 'test-service',
        'ip_pool_id'        => $poolId,
        'vlan_required'     => false,
        'deployment_status' => RouterService::DEPLOYMENT_PENDING,
        'interfaces'        => json_encode([['name' => 'ether2']]),
        'configuration'     => '{}',
        'status'            => RouterService::STATUS_INACTIVE,
        'enabled'           => true,
        'created_at'        => now(),
        'updated_at'        => now(),
    ];
    DB::table(TEST_SCHEMA . '.router_services')->insert(array_merge($defaults, $overrides));
    return RouterService::with(['router', 'ipPool'])->find($id);
}

function setGeneratorConfig(): void
{
    config()->set('radius.server_ip', '10.8.0.1');
    config()->set('radius.secret', 'testing123');
    config()->set('vpn.subnet.base', '10.0.0.0/8');
    config()->set('vpn.server_ip', '10.8.0.1');
    config()->set('app.url', 'https://app.example.com');
    config()->set('app.base_domain', 'example.com');
}


describe('ZeroConfigPPPoEGenerator', function () {
    uses(DatabaseTransactions::class);
    beforeEach(function () {
        bootstrapTestSchema();
        setGeneratorConfig();
        $this->routerId = '9172e01f-c8b2-4700-a149-3521606e074b';
        $this->poolId   = TEST_POOL_ID;
        createTestRouter($this->routerId);
        createTestPool($this->poolId);
    });
    it('generates non-empty script', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toBeString()->not->toBeEmpty(); });
    it('derives 8-char prefix', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('PPPoE-9172e01f')->toContain('pppoe-pool-9172e01f')->toContain('pppoe-prof-9172e01f')->toContain('pppoe-svc-9172e01f')->toContain('pppoe-br-9172e01f')->toContain('PL-9172e01f')->toContain('PA-9172e01f'); });
    it('enables RADIUS accounting', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('/ppp aaa set use-radius=yes accounting=yes interim-update=5m'); });
    it('RADIUS ports 1812/1813', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('authentication-port=1812 accounting-port=1813'); });
    it('IP pool range', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('ranges=100.64.0.2-100.64.0.255'); });
    it('local-address is gateway', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('local-address=100.64.0.1'); });
    it('dns-server from pool', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('dns-server=8.8.8.8,8.8.4.4'); });
    it('interface-list PA (pppoe active)', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('interface-list=PA-9172e01f'); });
    it('no session-timeout', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->not->toContain('session-timeout'); });
    it('no use-encryption', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->not->toContain('use-encryption'); });
    it('bridge rstp', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('protocol-mode=rstp'); });
    it('chap mschap2 auth', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('authentication=chap,mschap2'); });
    it('MTU MRU 1480', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('max-mtu=1480 max-mru=1480'); });
    it('blocks unauth', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('PPPoE-9172e01f-BLOCK-UNAUTH'); });
    it('allows PA to WAN', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('in-interface-list=PA-9172e01f out-interface-list=WAN action=accept'); });
    it('WAN EST rule', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('pp-wan-est-9172e01f'); });
    it('NAT masquerade not subnet', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('in-interface-list=PA-9172e01f out-interface-list=WAN action=masquerade')->not->toContain('src-address=100.64.0.0/24'); });
    it('MGMT-ALLOW rule', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('PPPoE-9172e01f-MGMT-ALLOW'); });
    it('MGMT-DROP rule', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('PPPoE-9172e01f-MGMT-DROP'); });
    it('SNMP-ALLOW rule', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('PPPoE-9172e01f-SNMP-ALLOW'); });
    it('global default drop', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('GLOBAL-DEFAULT-DROP-IN')->toContain('GLOBAL-DEFAULT-DROP-FWD'); });
    it('no hardcoded rate-limit', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->not->toContain('rate-limit='); });
    it('idempotent fw cleanup', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('/ip firewall filter remove [find comment~'); });
    it('idempotent NAT cleanup', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('/ip firewall nat remove [find comment='); });
    it('idempotent RADIUS cleanup', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('/radius remove [find comment~'); });
    it('START and DONE markers', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('PPPoE-9172e01f-START')->toContain('PPPoE-9172e01f-DONE'); });
    it('connection tracking', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('/ip firewall connection tracking set tcp-established-timeout=1h udp-timeout=30s'); });
    it('VLAN mode', function () { $svc=createTestService($this->routerId,$this->poolId,['vlan_required'=>true,'vlan_id'=>100]); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('vlan100-ether2')->toContain('vlan-id=100'); });
    it('no VLAN when not required', function () { $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->not->toContain('vlan-id='); });
    it('throws when no pool', function () { $svc=createTestService($this->routerId,$this->poolId,['ip_pool_id'=>null]); expect(fn()=>(new ZeroConfigPPPoEGenerator())->generate($svc))->toThrow(\RuntimeException::class,'IP pool not assigned to PPPoE service'); });
    it('throws when null interface', function () { $svc=createTestService($this->routerId,$this->poolId,['interface_name'=>null]); expect(fn()=>(new ZeroConfigPPPoEGenerator())->generate($svc))->toThrow(\RuntimeException::class,'No valid PPPoE interfaces provided'); });
    it('throws on empty JSON array', function () { $svc=createTestService($this->routerId,$this->poolId,['interface_name'=>'[]']); expect(fn()=>(new ZeroConfigPPPoEGenerator())->generate($svc))->toThrow(\RuntimeException::class,'No valid PPPoE interfaces provided'); });
    it('parses plain string', function () { $svc=createTestService($this->routerId,$this->poolId,['interface_name'=>'ether3']); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('ether3'); });
    it('parses comma-separated', function () { $svc=createTestService($this->routerId,$this->poolId,['interface_name'=>'ether2,ether3,ether4']); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('ether2')->toContain('ether3')->toContain('ether4'); });
    it('parses JSON array', function () { $svc=createTestService($this->routerId,$this->poolId,['interface_name'=>json_encode(['ether2','ether3'])]); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('ether2')->toContain('ether3'); });
    it('deduplicates interfaces', function () { $svc=createTestService($this->routerId,$this->poolId,['interface_name'=>json_encode(['ether2','ether2','ether3'])]); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); $lines=array_filter(explode(chr(10),$s),fn($l)=>str_contains($l,'/interface bridge port add')&&str_contains($l,'ether2')); expect(count($lines))->toBe(1); });
    it('valid custom gateway', function () { $pool=createTestPool(GW_POOL_1,['network_cidr'=>'10.11.1.0/24','gateway_ip'=>'10.11.1.5','range_start'=>'10.11.1.2','range_end'=>'10.11.1.254']); $svc=createTestService($this->routerId,$pool->id); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('local-address=10.11.1.5'); });
    it('gateway fallback when network addr', function () { $pool=createTestPool(GW_POOL_2,['network_cidr'=>'10.11.2.0/24','gateway_ip'=>'10.11.2.0','range_start'=>'10.11.2.2','range_end'=>'10.11.2.254']); $svc=createTestService($this->routerId,$pool->id); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('local-address=10.11.2.1'); });
    it('gateway fallback when null', function () { $pool=createTestPool(GW_POOL_3,['network_cidr'=>'10.11.3.0/24','gateway_ip'=>null,'range_start'=>'10.11.3.2','range_end'=>'10.11.3.254']); $svc=createTestService($this->routerId,$pool->id); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('local-address=10.11.3.1'); });
    it('gateway fallback when broadcast', function () { $pool=createTestPool(GW_POOL_4,['network_cidr'=>'10.11.4.0/24','gateway_ip'=>'10.11.4.255','range_start'=>'10.11.4.2','range_end'=>'10.11.4.254']); $svc=createTestService($this->routerId,$pool->id); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('local-address=10.11.4.1'); });
    it('gateway fallback when outside subnet', function () { $pool=createTestPool(GW_POOL_5,['network_cidr'=>'10.11.5.0/24','gateway_ip'=>'192.168.1.1','range_start'=>'10.11.5.2','range_end'=>'10.11.5.254']); $svc=createTestService($this->routerId,$pool->id); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('local-address=10.11.5.1'); });
    it('DNS fallback when null', function () { $pool=createTestPool(DNS_POOL_1,['network_cidr'=>'10.11.6.0/24','gateway_ip'=>'10.11.6.1','range_start'=>'10.11.6.2','range_end'=>'10.11.6.254','dns_primary'=>null,'dns_secondary'=>null]); $svc=createTestService($this->routerId,$pool->id); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('dns-server=8.8.8.8,8.8.4.4'); });
    it('uses radius.server_ip from config', function () { config()->set('radius.server_ip','10.8.0.99'); $svc=createTestService($this->routerId,$this->poolId); $s=(new ZeroConfigPPPoEGenerator())->generate($svc); expect($s)->toContain('address=10.8.0.99'); });
    it('tenant_id resolved via RouterTenantMap DB query', function () { $svc=createTestService($this->routerId,$this->poolId); $router=$svc->router; expect($router->tenant_id)->toBe(TEST_TENANT_ID); });
});

describe('ZeroConfigHotspotGenerator', function () {
    uses(DatabaseTransactions::class);
    beforeEach(function () {
        bootstrapTestSchema();
        setGeneratorConfig();
        $this->routerId = '9172e01f-c8b2-4700-a149-3521606e074b';
        $this->poolId   = TEST_POOL_ID;
        createTestRouter($this->routerId);
        createTestPool($this->poolId);
    });
    it('generates non-empty hotspot script', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toBeString()->not->toBeEmpty(); });
    it('creates bridge with short id prefix', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('br-hs-9172e01f'); });
    it('configures WAN list with ether1', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('list=WAN interface=ether1'); });
    it('hotspot profile uses RADIUS', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('use-radius=yes'); });
    it('hotspot-address set to pool gateway', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('hotspot-address=100.64.0.1'); });
    it('allows authenticated to WAN', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('hotspot=auth out-interface-list=WAN action=accept'); });
    it('drops unauth forward traffic', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('hs-fw-9172e01f-drop'); });
    it('global default drop rules', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('GLOBAL-DEFAULT-DROP-IN')->toContain('GLOBAL-DEFAULT-DROP-FWD'); });
    it('configures DHCP server', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('/ip dhcp-server add'); });
    it('RADIUS server address in script', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('address=10.8.0.1'); });
    it('MGMT-ALLOW and MGMT-DROP rules', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('hs-mgmt-9172e01f-allow')->toContain('hs-mgmt-9172e01f-drop'); });
    it('NAT masquerade rule', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2']])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('action=masquerade'); });
    it('throws when all interfaces are WireGuard', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'wireguard1']]),'interface_name'=>'wireguard1']); expect(fn()=>(new ZeroConfigHotspotGenerator())->generate($svc))->toThrow(\RuntimeException::class,'No valid hotspot access interfaces remaining after excluding VPN interfaces'); });
    it('excludes WireGuard interface from bridge', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2'],['name'=>'wg0']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('ether2'); $wgLines=array_filter(explode(chr(10),$s),fn($l)=>str_contains($l,'/interface bridge port add')&&str_contains($l,'wg0')); expect(count($wgLines))->toBe(0); });
    it('throws when no pool assigned', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'ip_pool_id'=>null,'interfaces'=>json_encode([['name'=>'ether2']])]); expect(fn()=>(new ZeroConfigHotspotGenerator())->generate($svc))->toThrow(\RuntimeException::class,'IP pool not assigned to hotspot service'); });
    it('generates VLAN sub-interface for hotspot', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HOTSPOT,'interfaces'=>json_encode([['name'=>'ether2','vlan_required'=>true,'vlan_id'=>20]])]); $s=(new ZeroConfigHotspotGenerator())->generate($svc); expect($s)->toContain('vlan-hotspot-20-ether2'); });
});

describe('ZeroConfigHybridGenerator', function () {
    uses(DatabaseTransactions::class);
    beforeEach(function () {
        bootstrapTestSchema();
        setGeneratorConfig();
        $this->routerId    = '9172e01f-c8b2-4700-a149-3521606e074b';
        $this->poolId      = TEST_POOL_ID;
        $this->hsPoolId    = HS_POOL_ID;
        $this->pppoePoolId = PPPOE_POOL_ID;
        createTestRouter($this->routerId);
        createTestPool($this->poolId);
        createTestPool($this->hsPoolId, [
            'service_type' => 'hotspot',
            'pool_name'    => 'hyb-hotspot-pool',
            'network_cidr' => '192.168.10.0/24',
            'gateway_ip'   => '192.168.10.1',
            'range_start'  => '192.168.10.100',
            'range_end'    => '192.168.10.200',
        ]);
        // PPPOE_POOL_ID uses different CIDR to avoid unique(tenant_id,service_type,network_cidr)
        createTestPool($this->pppoePoolId, [
            'service_type' => 'pppoe',
            'pool_name'    => 'hyb-pppoe-pool',
            'network_cidr' => '100.64.1.0/24',
            'gateway_ip'   => '100.64.1.1',
            'range_start'  => '100.64.1.2',
            'range_end'    => '100.64.1.254',
        ]);
    });
    it('generates non-empty hybrid script', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toBeString()->not->toBeEmpty(); });
    it('creates hybrid bridge prefix', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('hyb-br-9172e01f'); });
    it('contains hotspot and PPPoE server commands', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('/ip hotspot add')->toContain('/interface pppoe-server server add'); });
    it('PPP profile has no use-radius=yes', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); $lines=array_filter(explode(chr(10),$s),fn($l)=>str_contains($l,'/ppp profile add')); foreach($lines as $l){expect($l)->not->toContain('use-radius=yes');} });
    it('RADIUS service=hotspot and service=ppp entries', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('service=hotspot')->toContain('service=ppp'); });
    it('PPPOE-ACTIVE-HYB interface list', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('PPPOE-ACTIVE-HYB-9172e01f'); });
    it('global default drop rules in hybrid', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('GLOBAL-DEFAULT-DROP-IN')->toContain('GLOBAL-DEFAULT-DROP-FWD'); });
    it('MGMT rules present in hybrid', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('hyb-mgmt-9172e01f-allow')->toContain('hyb-mgmt-9172e01f-drop'); });
    it('RADIUS server IP in hybrid', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('10.8.0.1'); });
    it('hotspot pool IP range in hybrid', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('ranges=192.168.10.100-192.168.10.200'); });
    it('PPPoE pool IP range in hybrid', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('ranges=100.64.1.2-100.64.1.254'); });
    it('throws when pool IDs are null', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>null,'pppoe_pool_id'=>null]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $threw=false; try{(new ZeroConfigHybridGenerator())->generate($svc);}catch(\Throwable $e){$threw=true;} expect($threw)->toBeTrue(); });
    it('hotspot gateway IP', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('192.168.10.1'); });
    it('PPPoE gateway IP in hybrid', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('100.64.1.1'); });
    it('Bridge Mode log markers', function () { $svc=createTestService($this->routerId,$this->poolId,['service_type'=>RouterService::TYPE_HYBRID,'advanced_config'=>json_encode(['bridge_mode'=>true,'hotspot_pool_id'=>$this->hsPoolId,'pppoe_pool_id'=>$this->pppoePoolId]),'interfaces'=>json_encode([['name'=>'ether2']]),'interface_name'=>'ether2']); $s=(new ZeroConfigHybridGenerator())->generate($svc); expect($s)->toContain('Hybrid Deployment (Bridge Mode)')->toContain('Hybrid Deployment Complete - Bridge Mode'); });
});

describe('DeployRouterServiceJob dispatch', function () {
    it('dispatches to router-provisioning queue', function () { Queue::fake(); DeployRouterServiceJob::dispatch('service-uuid-001','tenant-uuid-001'); Queue::assertPushedOn('router-provisioning',DeployRouterServiceJob::class); });
    it('queues independently for two service IDs', function () { Queue::fake(); DeployRouterServiceJob::dispatch('service-001','tenant-001'); DeployRouterServiceJob::dispatch('service-002','tenant-001'); Queue::assertPushed(DeployRouterServiceJob::class,2); });
    it('has 300-second timeout', function () { $job=new DeployRouterServiceJob('service-uuid','tenant-uuid'); expect($job->timeout)->toBe(300); });
    it('retryUntil returns DateTime at least 15 min ahead', function () { $job=new DeployRouterServiceJob('service-uuid','tenant-uuid'); expect($job->retryUntil())->toBeInstanceOf(\DateTime::class); expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(14)->getTimestamp()); });
    it('maxExceptions is 3', function () { $job=new DeployRouterServiceJob('service-uuid','tenant-uuid'); expect($job->maxExceptions)->toBe(3); });
    it('backoff is an array of 3 increasing values', function () { $job=new DeployRouterServiceJob('service-uuid','tenant-uuid'); expect($job->backoff)->toBeArray()->toHaveCount(3); expect($job->backoff[1])->toBeGreaterThanOrEqual($job->backoff[0]); expect($job->backoff[2])->toBeGreaterThanOrEqual($job->backoff[1]); });
    it('tries is 0 meaning retryUntil governs', function () { $job=new DeployRouterServiceJob('service-uuid','tenant-uuid'); expect($job->tries)->toBe(0); });
});

describe('RouterService model helpers', function () {
    uses(DatabaseTransactions::class);
    beforeEach(function () {
        bootstrapTestSchema();
        setGeneratorConfig();
        $this->routerId = '9172e01f-c8b2-4700-a149-3521606e074b';
        $this->poolId   = TEST_POOL_ID;
        createTestRouter($this->routerId);
        createTestPool($this->poolId);
    });
    it('isActive true only when status=active AND enabled=true', function () { expect((new RouterService(['status'=>RouterService::STATUS_ACTIVE,'enabled'=>true]))->isActive())->toBeTrue(); expect((new RouterService(['status'=>RouterService::STATUS_ACTIVE,'enabled'=>false]))->isActive())->toBeFalse(); expect((new RouterService(['status'=>RouterService::STATUS_INACTIVE,'enabled'=>true]))->isActive())->toBeFalse(); });
    it('isDeployed true only for DEPLOYMENT_DEPLOYED', function () { expect((new RouterService(['deployment_status'=>RouterService::DEPLOYMENT_DEPLOYED]))->isDeployed())->toBeTrue(); expect((new RouterService(['deployment_status'=>RouterService::DEPLOYMENT_PENDING]))->isDeployed())->toBeFalse(); });
    it('requiresVlan true for hybrid regardless of vlan_required', function () { expect((new RouterService(['service_type'=>RouterService::TYPE_HYBRID,'vlan_required'=>false]))->requiresVlan())->toBeTrue(); });
    it('requiresVlan true for pppoe with vlan_required=true', function () { expect((new RouterService(['service_type'=>RouterService::TYPE_PPPOE,'vlan_required'=>true]))->requiresVlan())->toBeTrue(); });
    it('requiresVlan false for pppoe with vlan_required=false', function () { expect((new RouterService(['service_type'=>RouterService::TYPE_PPPOE,'vlan_required'=>false]))->requiresVlan())->toBeFalse(); });
    it('getTypeLabel PPPoE', function () { expect((new RouterService(['service_type'=>RouterService::TYPE_PPPOE]))->getTypeLabel())->toBe('PPPoE'); });
    it('getTypeLabel Hotspot', function () { expect((new RouterService(['service_type'=>RouterService::TYPE_HOTSPOT]))->getTypeLabel())->toBe('Hotspot'); });
    it('deployment status constants are distinct', function () { $s=[RouterService::DEPLOYMENT_PENDING,RouterService::DEPLOYMENT_IN_PROGRESS,RouterService::DEPLOYMENT_DEPLOYED,RouterService::DEPLOYMENT_FAILED]; expect(array_unique($s))->toHaveCount(count($s)); });
    it('service type constants are distinct', function () { $t=[RouterService::TYPE_PPPOE,RouterService::TYPE_HOTSPOT,RouterService::TYPE_HYBRID]; expect(array_unique($t))->toHaveCount(3); });
    it('markAsDeployed persists deployed status', function () { $svc=createTestService($this->routerId,$this->poolId); $svc->markAsDeployed(); $fresh=$svc->fresh(); expect($fresh->deployment_status)->toBe(RouterService::DEPLOYMENT_DEPLOYED); expect($fresh->deployed_at)->not->toBeNull(); });
    it('markAsFailed persists failed status', function () { $svc=createTestService($this->routerId,$this->poolId); $svc->markAsFailed(); $fresh=$svc->fresh(); expect($fresh->deployment_status)->toBe(RouterService::DEPLOYMENT_FAILED); });
});

afterEach(fn () => \Mockery::close());
