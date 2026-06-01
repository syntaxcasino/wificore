<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\RouterController;
use App\Models\Router;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class RouterTroubleshootingEndpointTest extends TestCase
{
    public function test_it_returns_a_deterministic_troubleshooting_report_in_router_details(): void
    {
        $router = Mockery::mock(Router::class)->makePartial();
        $router->setRawAttributes([
            'id' => 902,
            'tenant_id' => 'tenant-troubleshooting',
            'name' => 'Troubleshooting Router',
            'host' => '10.0.0.2',
            'ip_address' => '10.0.0.2',
            'status' => 'online',
            'vpn_status' => 'down',
            'last_seen' => now()->subMinutes(12),
            'model' => 'RB4011',
            'os_version' => '7.18.0',
        ], true);
        $router->setRelation('services', collect([]));
        $router->setRelation('accessPoints', collect([]));
        $router->shouldReceive('refresh')->andReturnSelf();
        $router->shouldReceive('load')->andReturnSelf();

        $request = Request::create('/api/routers/902/details', 'GET', ['with_live' => 0]);
        $request->setUserResolver(fn () => (object) ['tenant_id' => 'tenant-troubleshooting']);

        $controller = app(RouterController::class);
        $response = $controller->getRouterDetails($request, $router);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('VPN tunnel is unhealthy', $payload['troubleshooting']['diagnosis']);
        $this->assertTrue($payload['troubleshooting']['deterministic']);
        $this->assertSame('down', $payload['troubleshooting']['signals']['vpn_status']);
        $this->assertArrayHasKey('inventory_topology', $payload);
        $this->assertSame(0, $payload['inventory_topology']['summary']['access_points']);
        $this->assertArrayHasKey('evidence', $payload['troubleshooting']);
        $this->assertArrayHasKey('next_actions', $payload['troubleshooting']);
    }
}
