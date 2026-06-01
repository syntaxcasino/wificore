<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Router;
use App\Services\MikroTik\MikroTikBinaryApiService;
use App\Services\MikroTik\MikroTikRestApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MikroTikProvisioningIdempotencyTest extends TestCase
{
    public function test_rest_add_vlan_updates_existing_vlan_instead_of_adding_duplicate(): void
    {
        $router = $this->makeRouter();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/interface/vlan/print')) {
                return Http::response([".id" => '*1', 'name' => 'vlan10', 'interface' => 'ether2', 'vlan-id' => 10], 200);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/interface/vlan/set')) {
                return Http::response([".id" => '*1', 'name' => 'vlan10', 'interface' => 'ether2', 'vlan-id' => 10], 200);
            }

            return Http::response([], 200);
        });

        $service = new MikroTikRestApiService($router);
        $result = $service->addVlan('vlan10', 10, 'ether2', 'tenant-vlan');

        $this->assertSame('*1', $result['.id']);
        Http::assertSentCount(2);
        Http::assertNotSent(function (Request $request) {
            return $request->method() === 'POST' && str_contains($request->url(), '/interface/vlan/add');
        });
    }

    public function test_rest_add_nat_rule_reuses_existing_rule_by_comment(): void
    {
        $router = $this->makeRouter();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/ip/firewall/nat/print')) {
                return Http::response([".id" => '*2', 'comment' => 'hs-nat-tenant-1'], 200);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/ip/firewall/nat/set')) {
                return Http::response([".id" => '*2', 'comment' => 'hs-nat-tenant-1'], 200);
            }

            return Http::response([], 200);
        });

        $service = new MikroTikRestApiService($router);
        $result = $service->addNatRule([
            'chain' => 'srcnat',
            'action' => 'masquerade',
            'src-address' => '10.10.0.0/24',
            'comment' => 'hs-nat-tenant-1',
        ]);

        $this->assertSame('*2', $result['.id']);
        Http::assertNotSent(function (Request $request) {
            return $request->method() === 'POST' && str_contains($request->url(), '/ip/firewall/nat/add');
        });
    }

    public function test_binary_add_firewall_filter_rule_reuses_existing_rule_by_comment(): void
    {
        $service = new FakeMikroTikBinaryApiService($this->makeRouter());
        $service->responses['/ip/firewall/filter/print|["?comment=hs-fw-tenant-1-EST"]'] = [[
            '.id' => '*3',
            'comment' => 'hs-fw-tenant-1-EST',
        ]];
        $service->responses['/ip/firewall/filter/set'] = [
            '.id' => '*3',
            'comment' => 'hs-fw-tenant-1-EST',
        ];

        $result = $service->addFirewallFilterRule([
            'chain' => 'forward',
            'connection-state' => 'established,related',
            'action' => 'accept',
            'comment' => 'hs-fw-tenant-1-EST',
        ]);

        $this->assertSame('*3', $result['.id']);
        $this->assertTrue($service->called('/ip/firewall/filter/print'));
        $this->assertTrue($service->called('/ip/firewall/filter/set'));
        $this->assertFalse($service->called('/ip/firewall/filter/add'));
    }

    public function test_binary_create_pppoe_server_updates_existing_server_by_name(): void
    {
        $service = new FakeMikroTikBinaryApiService($this->makeRouter());
        $service->responses['/interface/pppoe-server/server/print|["?service-name=pppoe-svc-1"]'] = [[
            '.id' => '*4',
            'service-name' => 'pppoe-svc-1',
        ]];
        $service->responses['/interface/pppoe-server/server/set'] = [
            '.id' => '*4',
            'service-name' => 'pppoe-svc-1',
        ];

        $result = $service->createPppoeServer('pppoe-svc-1', 'ether2', 'pppoe-prof-1');

        $this->assertSame('*4', $result['.id']);
        $this->assertTrue($service->called('/interface/pppoe-server/server/print'));
        $this->assertTrue($service->called('/interface/pppoe-server/server/set'));
        $this->assertFalse($service->called('/interface/pppoe-server/server/add'));
    }


    public function test_rest_upsert_resource_updates_existing_record_by_match_filters(): void
    {
        $router = $this->makeRouter();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/ip/pool/print')) {
                return Http::response([[".id" => '*5', 'name' => 'pool-a', 'ranges' => '10.0.0.10-10.0.0.20']], 200);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/ip/pool/set')) {
                return Http::response([".id" => '*5', 'name' => 'pool-a', 'ranges' => '10.0.0.30-10.0.0.40'], 200);
            }

            return Http::response([], 200);
        });

        $service = new MikroTikRestApiService($router);
        $result = $service->upsertResource('/ip/pool', ['name' => 'pool-a'], [
            'name' => 'pool-a',
            'ranges' => '10.0.0.30-10.0.0.40',
        ]);

        $this->assertSame('*5', $result['.id']);
        Http::assertNotSent(function (Request $request) {
            return $request->method() === 'POST' && str_contains($request->url(), '/ip/pool/add');
        });
    }

    public function test_binary_upsert_resource_updates_existing_record_by_match_filters(): void
    {
        $service = new FakeMikroTikBinaryApiService($this->makeRouter());
        $service->responses['/ip/pool/print|[]'] = [[
            '.id' => '*6',
            'name' => 'pool-a',
            'ranges' => '10.0.0.10-10.0.0.20',
        ]];
        $service->responses['/ip/pool/set'] = [
            '.id' => '*6',
            'name' => 'pool-a',
            'ranges' => '10.0.0.30-10.0.0.40',
        ];

        $result = $service->upsertResource('/ip/pool', ['name' => 'pool-a'], [
            'name' => 'pool-a',
            'ranges' => '10.0.0.30-10.0.0.40',
        ]);

        $this->assertSame('*6', $result['.id']);
        $this->assertTrue($service->called('/ip/pool/print'));
        $this->assertTrue($service->called('/ip/pool/set'));
        $this->assertFalse($service->called('/ip/pool/add'));
    }

    private function makeRouter(): Router
    {
        $router = new Router();
        $router->id = 99;
        $router->name = 'test-router';
        $router->username = 'admin';
        $router->password = Crypt::encrypt('secret');
        $router->ip_address = '10.0.0.2';
        $router->api_port = 8729;

        return $router;
    }
}

class FakeMikroTikBinaryApiService extends MikroTikBinaryApiService
{
    public array $calls = [];
    public array $responses = [];

    public function command(string $command, array $params = []): array
    {
        $this->calls[] = [$command, $params];
        return $this->responseFor($command, $params);
    }

    public function commandOne(string $command, array $params = []): array
    {
        $this->calls[] = [$command, $params];
        return $this->responseFor($command, $params);
    }

    public function called(string $command): bool
    {
        foreach ($this->calls as [$seenCommand]) {
            if ($seenCommand === $command) {
                return true;
            }
        }

        return false;
    }

    private function responseFor(string $command, array $params): array
    {
        $signature = $command . '|' . json_encode($params);

        if (array_key_exists($signature, $this->responses)) {
            return $this->responses[$signature];
        }

        if (array_key_exists($command, $this->responses)) {
            return $this->responses[$command];
        }

        return [];
    }
}
