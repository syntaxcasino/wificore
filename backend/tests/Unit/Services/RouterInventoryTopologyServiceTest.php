<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Router;
use App\Services\Inventory\RouterInventoryTopologyService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RouterInventoryTopologyServiceTest extends TestCase
{
    public function test_it_builds_a_deterministic_inventory_topology_snapshot(): void
    {
        $router = new Router();
        $router->setRawAttributes([
            'id' => 77,
            'name' => 'Core Router',
            'vendor' => 'mikrotik',
            'model' => 'RB4011',
            'status' => 'online',
        ], true);

        $accessPoints = new Collection([
            [
                'id' => 1,
                'name' => 'AP-1',
                'vendor' => 'ubiquiti',
                'model' => 'U6-Lite',
                'status' => 'online',
                'active_users' => 7,
            ],
            [
                'id' => 2,
                'name' => 'AP-2',
                'vendor' => 'ubiquiti',
                'model' => 'U6-Lite',
                'status' => 'offline',
                'active_users' => 0,
            ],
        ]);

        $service = app(RouterInventoryTopologyService::class);
        $snapshot = $service->build($router, $accessPoints, [['name' => 'PPPoE']], ['active_connections' => 13]);

        $this->assertSame(1, $snapshot['summary']['routers']);
        $this->assertSame(2, $snapshot['summary']['access_points']);
        $this->assertSame(1, $snapshot['summary']['access_points_online']);
        $this->assertSame(1, $snapshot['summary']['access_points_offline']);
        $this->assertSame(13, $snapshot['summary']['active_connections']);
        $this->assertSame('degraded', $snapshot['health']['status']);
        $this->assertCount(3, $snapshot['nodes']);
        $this->assertCount(2, $snapshot['edges']);
    }
}
